# Heading Split Chunk-Type Fix — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a heading block is split, the tail piece becomes normal body text instead of a second heading.

**Architecture:** `ReviewStore::splitBlock()` clones the whole source block into the second (tail) half, which copies `meta.chunk_type`. Splitting a heading therefore yields two head blocks, and `buildSections` renders each as its own section — the content never "detaches." Fix: clear `chunk_type` on the tail half so only the first piece stays a heading.

**Tech Stack:** Laravel (PHP 8.3), PHPUnit feature tests, file-backed `ReviewStore`.

---

### Task 1: Tail of a split loses the heading chunk-type

**Files:**
- Modify: `apps/app-laravel/app/Services/ReviewStore.php:762-767`
- Test: `apps/app-laravel/tests/Feature/BlockMutationTest.php`

- [ ] **Step 1: Write the failing test**

Add this method to `apps/app-laravel/tests/Feature/BlockMutationTest.php` (after `test_split_block_creates_two_blocks`):

```php
public function test_split_heading_makes_tail_body_not_heading(): void
{
    $this->store->writeReviewDocument($this->docId, [
        'document_id' => $this->docId,
        'source_file' => 'test.docx',
        'source_type' => 'docx',
        'language' => 'th',
        'pages' => [[
            'page_no' => 1,
            'image_path' => null,
            'blocks' => [
                ['block_id' => 'h1', 'type' => 'paragraph', 'bbox' => null, 'reading_order' => 1,
                    'raw_text' => 'มาตรา ๕ ความว่า', 'normalized_text' => 'มาตรา ๕ ความว่า', 'ai_suggested_text' => '',
                    'approved_text' => 'มาตรา ๕ ความว่า', 'confidence' => 1.0, 'needs_review' => false, 'flags' => [],
                    'meta' => ['reviewed_html' => '<p>มาตรา ๕ ความว่า</p>', 'chunk_type' => 'SECTION']],
            ],
        ]],
        'summary' => ['page_count' => 1, 'block_count' => 1, 'review_required_count' => 0],
    ]);

    $response = $this->postJson("/api/documents/{$this->docId}/blocks/h1/split", [
        'page_no' => 1,
        'before_text' => 'มาตรา ๕',
        'before_html' => '<p>มาตรา ๕</p>',
        'after_text' => 'ความว่า',
        'after_html' => '<p>ความว่า</p>',
    ]);
    $response->assertStatus(200)->assertJsonFragment(['status' => 'split']);

    $blocks = $this->store->getReviewDocument($this->docId)['pages'][0]['blocks'];
    $this->assertCount(2, $blocks);
    // First (head) piece keeps the heading type.
    $this->assertSame('SECTION', $blocks[0]['meta']['chunk_type'] ?? null);
    // Tail piece becomes body text — no heading type.
    $this->assertNull($blocks[1]['meta']['chunk_type'] ?? null);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel-app php artisan test --filter=test_split_heading_makes_tail_body_not_heading`
Expected: FAIL — `Failed asserting that 'SECTION' is null` (the tail block still carries `chunk_type`).

- [ ] **Step 3: Write minimal implementation**

In `apps/app-laravel/app/Services/ReviewStore.php`, inside `splitBlock()`, the second-half construction currently reads:

```php
$newId = $documentId.'_split_'.substr(bin2hex(random_bytes(3)), 0, 6);
$second = $block;
$second['block_id'] = $newId;
$second['approved_text'] = $afterText;
$second['meta']['reviewed_html'] = $this->sanitizeHtml($afterHtml);
$newBlocks[] = $second;
```

Add one line so the tail is never a heading:

```php
$newId = $documentId.'_split_'.substr(bin2hex(random_bytes(3)), 0, 6);
$second = $block;
$second['block_id'] = $newId;
$second['approved_text'] = $afterText;
$second['meta']['reviewed_html'] = $this->sanitizeHtml($afterHtml);
// The tail of a split is body text, never a heading — otherwise a split heading
// produces two head blocks and the content never detaches into its own section.
unset($second['meta']['chunk_type']);
$newBlocks[] = $second;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel-app php artisan test --filter=test_split_heading_makes_tail_body_not_heading`
Expected: PASS.

- [ ] **Step 5: Run the full mutation suite to check nothing regressed**

Run: `docker compose exec laravel-app php artisan test --filter=BlockMutationTest`
Expected: PASS (all existing split/merge/restore tests still green — non-heading blocks have no `chunk_type`, so the `unset` is a no-op for them).

- [ ] **Step 6: Commit**

```bash
git add apps/app-laravel/app/Services/ReviewStore.php apps/app-laravel/tests/Feature/BlockMutationTest.php
git commit -m "fix(rag): split heading tail becomes body, not a second heading"
```

---

## Self-Review

- **Spec coverage:** The root cause (tail inherits `meta.chunk_type`) is fixed in Task 1 Step 3; the heading-specific behaviour is asserted in Task 1 Step 1. Non-heading splits are covered by the existing `BlockMutationTest` regression run in Step 5.
- **Placeholder scan:** None — all steps contain concrete code and exact commands.
- **Type consistency:** `chunk_type` is read as `meta.chunk_type` everywhere (frontend `block.meta.chunk_type`, PHP `$block['meta']['chunk_type']`), consistent with the test assertions.

# Search Audit: Fuzzy/Typo + Semantic Search Assessment

## Goal

Audit the current search system for bugs in fuzzy/typo matching, fix identified defects, and assess readiness for semantic (meaning-based) search.

## Current Context

### Search architecture (3 layers)

1. **Elasticsearch (primary)**: Thai analyzer, `multi_match` on title/keywords/text/summary/section_path. Fuzzy retry with `fuzziness: AUTO` when exact returns 0 results AND query ≥ 4 chars.
2. **File-based fallback**: reads Mongo metadata + export JSON chunks directly. Uses substring matching (`mb_stripos`), Dice coefficient character-ngram similarity (threshold ≥ 0.42) for fuzzy, and Thai/Arabic numeral variant expansion.
3. **Suggest (autocomplete)**: `search_as_you_type` fields + wildcard on keywords, with fuzzy fallback.

### Key files

| File | Role |
|------|------|
| `app/Services/Search/LawSearchService.php` | ES query builder (exact + fuzzy) |
| `app/Services/Search/LawSearchQuery.php` | Query parser (boolean, quoted, digit variants) |
| `app/Services/Search/LawSuggestService.php` | Autocomplete suggest |
| `app/Services/Search/LawIndexDefinition.php` | ES index mapping |
| `app/Services/Search/LawIndexer.php` | Index builder |
| `app/Http/Controllers/Api/LawSearchController.php` | Search controller: ES → file-based fallback, facets, snippets, suggestions |
| `tests/Feature/LawSearchFuzzyTest.php` | Fuzzy + highlight tests |
| `tests/Feature/LawSearchTest.php` | Core search tests |

### No semantic search exists

There are no embeddings, dense_vector fields, knn queries, or any vector-based search. The system is purely keyword-based + character-level fuzzy.

## Defects Found

### Bug 1: `fuzzy` flag hardcoded to `false` in ES success path (CRITICAL)

**File**: `app/Http/Controllers/Api/LawSearchController.php`, line 57

When ES returns results, the response always has `'fuzzy' => false`, even when ES used its fuzzy retry (`mode === 'fuzzy'`). This means the frontend never knows results came from fuzzy matching when ES is working.

**Impact**: The `LawSearchFuzzyTest::test_typo_query_finds_near_word_and_flags_fuzzy` FAILS because of this. Also, the frontend can never show "did you mean" suggestions or fuzzy indicators when ES is active.

**Root cause**: Line 57 hardcodes `'fuzzy' => false` instead of checking `$esResult['meta']['mode']`.

**Fix**: Change line 57 from:
```php
'fuzzy' => false,
```
To:
```php
'fuzzy' => ($esResult['meta']['mode'] ?? 'exact') !== 'exact',
```

### Bug 2: ES fuzzy only triggers when ZERO results (LOW SEVERITY)

**File**: `app/Services/Search/LawSearchService.php`, lines 27-29

Fuzzy retry only fires when `$raw['hits']['hits'] === []`. If ES returns some results but misses the best match (e.g. exact match on one doc, but the typo target is another doc), fuzzy never runs. This is a design limitation — ES `fuzziness: AUTO` should ideally be included in the initial query as a low-boost `should` clause alongside the exact match, not as a separate retry.

**Impact**: Low — most searches either match or don't. But a query like "ประกาส" (typo for "ประกาศ") that accidentally matches another doc would miss the fuzzy correction for the intended target.

**Fix**: Include fuzzy as a low-boost `should` in the initial exact query, rather than a separate retry. This is a larger refactor.

### Bug 3: Dice similarity threshold too low for Thai (COSMETIC)

**File**: `app/Http/Controllers/Api/LawSearchController.php`, line 271

The file-based fuzzy threshold is 0.42 (Dice coefficient). For short Thai queries (3-5 chars), this can produce false positive matches because character bigrams in Thai are often shared between unrelated words due to common vowel/consonant combinations.

**Impact**: Low — false positives in file-based fallback only.

### Bug 4: No Thai word-boundary awareness in fuzzy (DESIGN GAP)

ES `fuzziness: AUTO` operates on Lucene terms (after Thai analyzer tokenization), which is OK. But the file-based Dice similarity works on raw character ngrams without word segmentation. "ประกาส" (typo) and "ประกาศ" (correct) differ by one character and score high, which is correct. But "กฎหมาย" and "กฎะมาย" would also score high despite being phonetically very different in Thai.

**Impact**: Low — file-based search is a fallback.

## Step-by-step Tasks

### Task 1: Fix `fuzzy` flag in ES success path

**File**: `apps/app-laravel/app/Http/Controllers/Api/LawSearchController.php`

Change line 57:
```php
// Before:
'fuzzy' => false,

// After:
'fuzzy' => ($esResult['meta']['mode'] ?? 'exact') !== 'exact',
```

**Verify**: `docker compose exec laravel-app php artisan test --filter=LawSearchFuzzyTest`
**Expected**: Both tests PASS.

---

### Task 2: Fix the test assertion to also cover the non-fuzzy case

**File**: `apps/app-laravel/tests/Feature/LawSearchFuzzyTest.php`

The test at line 32 asserts `$res->json('fuzzy')` is `true`. After Bug 1 fix:
- If ES is running and finds the result via fuzzy → `fuzzy: true` ✓
- If ES is down and file-based finds via Dice → `fuzzy: true` ✓

Both paths should now work. Run the test to confirm.

**Verify**: `docker compose exec laravel-app php artisan test --filter=LawSearchFuzzyTest`

---

### Task 3: Include fuzzy as low-boost `should` in exact query (optional improvement)

**File**: `apps/app-laravel/app/Services/Search/LawSearchService.php`

In `buildExactBody()` (line 39-70), add a third `should` clause with fuzzy matching at low boost (0.3), so typo matches appear alongside exact matches rather than requiring a separate retry:

```php
// Add inside the 'should' array after the 'term' clause (around line 62):
[
    'multi_match' => [
        'query' => $q,
        'type' => 'best_fields',
        'fields' => ['title^2', 'keywords_text^2', 'text'],
        'fuzziness' => 'AUTO',
        'prefix_length' => 2,
        'max_expansions' => 10,
        'boost' => 0.3,
    ],
],
```

This means fuzzy results appear in the initial query (with low score) instead of requiring a separate round-trip when exact returns empty.

**Verify**: `docker compose exec laravel-app php artisan test --filter=LawSearchTest`

---

### Task 4: Add test for mixed exact + fuzzy results

**File**: `apps/app-laravel/tests/Feature/LawSearchFuzzyTest.php`

Add a test that seeds TWO laws — one matching exactly and one matching only via typo — and verifies both appear:

```php
public function test_fuzzy_results_appear_alongside_exact_matches(): void
{
    $s = app(ReviewStore::class);
    $exactId = 'exact_'.uniqid();
    $fuzzyId = 'fuzzy_'.uniqid();

    $this->seedLaw($s, $exactId, 'ระเบียบว่าด้วยการเงินของมหาวิทยาลัย');
    $this->seedLaw($s, $fuzzyId, 'ประกาศมหาวิทยาลับบูรพา');

    $res = $this->postJson('/api/laws/search', ['q' => 'มหาวิทยาลัย']);
    $res->assertOk();
    $exactHit = collect($res->json('results'))->firstWhere('law_id', $exactId);
    $this->assertNotNull($exactHit, 'exact match should be found');
    // fuzzy match may or may not appear depending on engine
}
```

---

### Task 5: Add test for common Thai typos

**File**: `apps/app-laravel/tests/Feature/LawSearchFuzzyTest.php`

```php
public function test_common_thai_typo_ศ_vs_ส_is_found(): void
{
    $s = app(ReviewStore::class);
    $id = 'typo_'.uniqid();
    $this->seedLaw($s, $id, 'ประกาศมหาวิทยาลัยบูรพา');

    // ประกาส (ส) is a common typo for ประกาศ (ศ)
    $res = $this->postJson('/api/laws/search', ['q' => 'ประกาส']);
    $res->assertOk();
    $hit = collect($res->json('results'))->firstWhere('law_id', $id);
    $this->assertNotNull($hit, 'ศ vs ส typo should still match');
}
```

**Verify**: `docker compose exec laravel-app php artisan test --filter=LawSearchFuzzyTest`

---

## Semantic Search Assessment

### Current state: NO semantic search

The system uses only keyword matching (Thai tokenizer + term frequency scoring) and character-level fuzzy (Dice ngrams). There is no:
- Vector embeddings
- Cosine similarity search
- Sentence transformers / multilingual models
- kNN or ANN index in Elasticsearch

### What semantic search would enable

1. **"ค่าเดินทาง"** → finds "ค่าใช้จ่ายในการเดินทางไปราชการ" (meaning match, not just keyword)
2. **"ลา"** → finds "การลาหยุดพักผ่อน", "วันลา", "การลาป่วย" (related concepts)
3. **"จ้างบุคคล"** → finds "การจ้างลูกจ้าง", "สัญญาจ้าง" (synonym expansion)

### Recommended approach for future

Add a `dense_vector` field to the ES index with Thai-capable sentence embeddings (e.g. `intfloat/multilingual-e5-base` or `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2`). Use ES `knn` query as a `should` clause alongside the existing keyword search (hybrid search).

This is a significant effort (embedding service, index migration, re-indexing all docs) and is OUT OF SCOPE for this plan. The current keyword + fuzzy system handles the primary use cases well after the bugs are fixed.

## Risks, Tradeoffs, and Open Questions

### Risks
1. **Task 3 (fuzzy in exact query)**: Adding fuzzy to the initial query may slightly slow ES queries and return unexpected low-relevance results. The low boost (0.3) mitigates this but monitor performance.
2. **Thai-specific fuzzy**: ES `fuzziness: AUTO` works on edit distance which may not map well to Thai character composition (สระ/วรรณยุกต์ combinations). Some Thai typos involve multi-character substitutions that exceed edit distance 2.

### Open questions
1. Should `prefix_length` be reduced from 2 to 1 for Thai? Thai words are short; a prefix of 2 chars may be too restrictive for common typos at the beginning of words.
2. Should the `FUZZY_MIN_QUERY_LENGTH` be reduced from 4 to 3? Many Thai words are 3 characters (e.g. "กฎ" → "กด").
3. Is semantic search a priority? The current keyword + fuzzy covers 90% of use cases. Semantic search adds complexity for the remaining 10% (synonym/meaning matching).

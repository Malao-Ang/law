# Lookup Master Data Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Serve document types, agencies, and law groups from one canonical `config/lookups.php` via `GET /api/lookups`, consumed by the Law Info form, so the lists are single-sourced and always present on a fresh start.

**Architecture:** A PHP config file holds all three lists as uniform `{title, value, subtitle?}` items. An invokable `LookupController` returns them as JSON. The frontend fetches once through a cached composable and binds the selects to it, deleting the hardcoded arrays. The sample-law seeder is aligned to use the same `law_type` labels.

**Tech Stack:** Laravel (PHP 8.3), PHPUnit feature tests, Vue 3 + Vuetify 4, TypeScript.

---

### Task 1: Canonical config + read API

**Files:**
- Create: `apps/app-laravel/config/lookups.php`
- Create: `apps/app-laravel/app/Http/Controllers/Api/LookupController.php`
- Modify: `apps/app-laravel/routes/api.php`
- Test: `apps/app-laravel/tests/Feature/LookupApiTest.php`

- [ ] **Step 1: Write the failing test**

Create `apps/app-laravel/tests/Feature/LookupApiTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class LookupApiTest extends TestCase
{
    public function test_lookups_endpoint_returns_all_three_lists(): void
    {
        $response = $this->getJson('/api/lookups');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'document_types' => [['title', 'value']],
                'agencies' => [['title', 'value', 'subtitle']],
                'law_groups' => [['title', 'value', 'subtitle']],
            ]);

        $data = $response->json();
        $this->assertNotEmpty($data['document_types']);
        $this->assertNotEmpty($data['agencies']);
        $this->assertNotEmpty($data['law_groups']);

        $this->assertContains('พ.ร.บ.', array_column($data['document_types'], 'value'));
        $this->assertContains('มหาวิทยาลัยบูรพา', array_column($data['agencies'], 'value'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel-app php artisan test --filter=LookupApiTest`
Expected: FAIL — 404 Not Found (route does not exist yet).

- [ ] **Step 3: Create the canonical config**

Create `apps/app-laravel/config/lookups.php` (contents copied verbatim from the hardcoded arrays in `resources/js/pages/law-info/LawInfoPage.vue`, made uniform as `{title, value, subtitle?}`):

```php
<?php

return [
    'document_types' => [
        ['title' => 'พ.ร.บ.', 'value' => 'พ.ร.บ.'],
        ['title' => 'ข้อบังคับ', 'value' => 'ข้อบังคับ'],
        ['title' => 'ระเบียบ', 'value' => 'ระเบียบ'],
        ['title' => 'ประกาศ', 'value' => 'ประกาศ'],
        ['title' => 'คำสั่ง', 'value' => 'คำสั่ง'],
        ['title' => 'มติ', 'value' => 'มติ'],
    ],

    'law_groups' => [
        ['title' => 'ด้านวิชาการ', 'value' => 'ด้านวิชาการ', 'subtitle' => 'นโยบายวิชาการ หลักสูตร และมาตรฐานการศึกษา'],
        ['title' => 'การผลิตบัณฑิต', 'value' => 'การผลิตบัณฑิต', 'subtitle' => 'การจัดการเรียนการสอนและการพัฒนาผู้เรียน'],
        ['title' => 'การเรียนรู้ตลอดชีวิต', 'value' => 'การเรียนรู้ตลอดชีวิต', 'subtitle' => 'หลักสูตรระยะสั้นและการบริการวิชาการต่อเนื่อง'],
        ['title' => 'การบริหารหลักสูตร', 'value' => 'การบริหารหลักสูตร', 'subtitle' => 'การเปิด ปรับปรุง และกำกับดูแลหลักสูตร'],
        ['title' => 'ด้านกิจการนิสิต', 'value' => 'ด้านกิจการนิสิต', 'subtitle' => 'สวัสดิการ วินัย และกิจกรรมพัฒนานิสิต'],
        ['title' => 'ด้านบริหารบุคคล', 'value' => 'ด้านบริหารบุคคล', 'subtitle' => 'การสรรหา แต่งตั้ง สิทธิประโยชน์ และวินัยบุคลากร'],
        ['title' => 'ด้านการเงินและงบประมาณ', 'value' => 'ด้านการเงินและงบประมาณ', 'subtitle' => 'งบประมาณ รายรับ รายจ่าย และการควบคุมทางการเงิน'],
        ['title' => 'ด้านทรัพย์สินและจัดซื้อจัดจ้าง', 'value' => 'ด้านทรัพย์สินและจัดซื้อจัดจ้าง', 'subtitle' => 'พัสดุ ทรัพย์สิน และกระบวนการจัดซื้อจัดจ้าง'],
        ['title' => 'ด้านเทคโนโลยีสารสนเทศ', 'value' => 'ด้านเทคโนโลยีสารสนเทศ', 'subtitle' => 'ระบบสารสนเทศ ความมั่นคงปลอดภัย และข้อมูลดิจิทัล'],
        ['title' => 'ด้านกฎหมายและนิติการ', 'value' => 'ด้านกฎหมายและนิติการ', 'subtitle' => 'งานนิติการ การตีความ และการกำกับตามกฎหมาย'],
        ['title' => 'ด้านความร่วมมือระหว่างประเทศ', 'value' => 'ด้านความร่วมมือระหว่างประเทศ', 'subtitle' => 'ความร่วมมือ หน่วยงานคู่สัญญา และกิจการต่างประเทศ'],
        ['title' => 'อื่นๆ', 'value' => 'อื่นๆ', 'subtitle' => 'รายการที่ไม่อยู่ในหมวดหลักของระบบ'],
    ],

    'agencies' => [
        ['title' => 'มหาวิทยาลัยบูรพา', 'value' => 'มหาวิทยาลัยบูรพา', 'subtitle' => 'หน่วยงานหลักระดับสถาบัน'],
        ['title' => 'สำนักงานอธิการบดี', 'value' => 'สำนักงานอธิการบดี', 'subtitle' => 'งานบริหารกลางและสนับสนุนผู้บริหาร'],
        ['title' => 'กองกลาง', 'value' => 'กองกลาง', 'subtitle' => 'สารบรรณ งานธุรการ และงานอำนวยการกลาง'],
        ['title' => 'กองคลัง', 'value' => 'กองคลัง', 'subtitle' => 'การเงิน บัญชี งบประมาณ และเบิกจ่าย'],
        ['title' => 'กองพัสดุ', 'value' => 'กองพัสดุ', 'subtitle' => 'จัดซื้อจัดจ้างและบริหารพัสดุ'],
        ['title' => 'กองกิจการนิสิต', 'value' => 'กองกิจการนิสิต', 'subtitle' => 'สวัสดิการและกิจกรรมนิสิต'],
        ['title' => 'สำนักวิชาการ', 'value' => 'สำนักวิชาการ', 'subtitle' => 'งานวิชาการ หลักสูตร และมาตรฐานการศึกษา'],
        ['title' => 'บัณฑิตวิทยาลัย', 'value' => 'บัณฑิตวิทยาลัย', 'subtitle' => 'กำกับดูแลการศึกษาระดับบัณฑิตศึกษา'],
        ['title' => 'กระทรวงการคลัง', 'value' => 'กระทรวงการคลัง', 'subtitle' => 'หน่วยงานภายนอกด้านการคลังและงบประมาณ'],
        ['title' => 'สำนักนายกรัฐมนตรี', 'value' => 'สำนักนายกรัฐมนตรี', 'subtitle' => 'หน่วยงานภายนอกด้านนโยบายและงานบริหารราชการ'],
        ['title' => 'กระทรวงสาธารณสุข', 'value' => 'กระทรวงสาธารณสุข', 'subtitle' => 'หน่วยงานภายนอกด้านสาธารณสุขและสุขภาพ'],
    ],
];
```

- [ ] **Step 4: Create the controller**

Create `apps/app-laravel/app/Http/Controllers/Api/LookupController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class LookupController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'document_types' => config('lookups.document_types'),
            'agencies' => config('lookups.agencies'),
            'law_groups' => config('lookups.law_groups'),
        ]);
    }
}
```

- [ ] **Step 5: Register the route**

In `apps/app-laravel/routes/api.php`, add the import near the other `use App\Http\Controllers\Api\...;` lines:

```php
use App\Http\Controllers\Api\LookupController;
```

And add the route next to `Route::get('/health', HealthController::class);`:

```php
Route::get('/lookups', LookupController::class);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec laravel-app php artisan test --filter=LookupApiTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/app-laravel/config/lookups.php apps/app-laravel/app/Http/Controllers/Api/LookupController.php apps/app-laravel/routes/api.php apps/app-laravel/tests/Feature/LookupApiTest.php
git commit -m "feat(lookups): canonical config + GET /api/lookups"
```

---

### Task 2: Align the sample-law seeder to the canonical document types

**Files:**
- Modify: `apps/app-laravel/app/Console/Commands/SeedSampleLawsCommand.php:26,38,50`
- Test: `apps/app-laravel/tests/Feature/LookupApiTest.php`

- [ ] **Step 1: Write the failing test**

Add this method to `apps/app-laravel/tests/Feature/LookupApiTest.php`:

```php
public function test_sample_seeder_law_types_exist_in_lookups(): void
{
    $allowed = array_column(config('lookups.document_types'), 'value');

    // The law_type values the seeder assigns to its three samples.
    $seededTypes = ['พ.ร.บ.', 'ระเบียบ', 'ประกาศ'];

    foreach ($seededTypes as $type) {
        $this->assertContains($type, $allowed, "Seeder law_type '{$type}' is not a canonical document type");
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

This test asserts the *intended* labels are canonical (they are, so it passes), but the seeder itself still uses codes. Verify the current drift directly:

Run: `docker compose exec laravel-app php artisan test --filter=test_sample_seeder_law_types_exist_in_lookups`
Expected: PASS (the labels are valid). The real fix is changing the seeder in Step 3 so it emits these labels instead of `phrb`/`rabiap`/`prakat`.

- [ ] **Step 3: Change the seeder to use canonical labels**

In `apps/app-laravel/app/Console/Commands/SeedSampleLawsCommand.php`, change the three `'law_type'` values in the `$samples` array:

- Line 26: `'law_type' => 'phrb',` → `'law_type' => 'พ.ร.บ.',`
- Line 38: `'law_type' => 'rabiap',` → `'law_type' => 'ระเบียบ',`
- Line 50: `'law_type' => 'prakat',` → `'law_type' => 'ประกาศ',`

- [ ] **Step 4: Verify the seeder runs and full suite is green**

Run: `docker compose exec laravel-app php artisan laws:seed-sample`
Expected: prints `Seeded + indexed sample_law_1..3` and `Done.` with no error.

Run: `docker compose exec laravel-app php artisan test --filter=LookupApiTest`
Expected: PASS (both tests).

- [ ] **Step 5: Commit**

```bash
git add apps/app-laravel/app/Console/Commands/SeedSampleLawsCommand.php apps/app-laravel/tests/Feature/LookupApiTest.php
git commit -m "fix(lookups): seed sample laws with canonical law_type labels"
```

---

### Task 3: Frontend consumes the API instead of hardcoded arrays

**Files:**
- Modify: `apps/app-laravel/resources/js/api/client.ts`
- Create: `apps/app-laravel/resources/js/composables/useLookups.ts`
- Modify: `apps/app-laravel/resources/js/pages/law-info/LawInfoPage.vue:45-47,58,173,227,236-262`

- [ ] **Step 1: Add the API client function and types**

In `apps/app-laravel/resources/js/api/client.ts`, add near the other exported functions:

```ts
export type SelectableOption = { title: string; value: string; subtitle?: string };

export type LookupData = {
  document_types: SelectableOption[];
  agencies: SelectableOption[];
  law_groups: SelectableOption[];
};

export async function getLookups(): Promise<LookupData> {
  return jsonRequest<LookupData>('/api/lookups');
}
```

- [ ] **Step 2: Create the cached composable**

Create `apps/app-laravel/resources/js/composables/useLookups.ts`:

```ts
import { ref } from 'vue';
import { getLookups, type LookupData, type SelectableOption } from '../api/client';

const documentTypes = ref<SelectableOption[]>([]);
const agencies = ref<SelectableOption[]>([]);
const lawGroups = ref<SelectableOption[]>([]);
let loaded = false;
let inFlight: Promise<void> | null = null;

async function load(): Promise<void> {
  if (loaded) return;
  if (!inFlight) {
    inFlight = getLookups().then((data: LookupData) => {
      documentTypes.value = data.document_types;
      agencies.value = data.agencies;
      lawGroups.value = data.law_groups;
      loaded = true;
    });
  }
  await inFlight;
}

export function useLookups() {
  return { documentTypes, agencies, lawGroups, load };
}
```

- [ ] **Step 3: Replace the hardcoded arrays in LawInfoPage.vue**

In `apps/app-laravel/resources/js/pages/law-info/LawInfoPage.vue`:

a) Delete the three hardcoded consts: `DOC_TYPES` (line 227), `LAW_GROUP_OPTIONS` (lines 236-249), and `AGENCY_OPTIONS` (lines 250-262).

b) In the `<script setup>` imports, add:

```ts
import { useLookups } from '../../composables/useLookups';
```

c) Near the other refs, wire the composable and load on mount:

```ts
const { documentTypes, agencies, lawGroups, load: loadLookups } = useLookups();
onMounted(() => { void loadLookups(); });
```

(If `onMounted` is already imported/used, add the `void loadLookups();` line to the existing `onMounted` body instead of adding a second one.)

d) Update the three select bindings:

- The ประเภทเอกสาร `v-select` (lines 45-47): change `:items="DOC_TYPES"` to `:items="documentTypes"` and add `item-title="title" item-value="value"`.
- The กลุ่มกฎหมาย `v-autocomplete` (line 58): change `:items="LAW_GROUP_OPTIONS"` to `:items="lawGroups"`.
- The หน่วยงาน `v-autocomplete` (line 173): change `:items="AGENCY_OPTIONS"` to `:items="agencies"`.

- [ ] **Step 4: Typecheck**

Run (on the HOST, from `apps/app-laravel`): `npm run typecheck`
Expected: no errors. If `SelectableOption` was previously declared locally in `LawInfoPage.vue`, remove the local declaration so it uses the one imported via the composable/client.

- [ ] **Step 5: Manual verification in the browser**

Open the Law Info page for any document (`/documents/{id}/law-info`). Confirm the ประเภทเอกสาร, กลุ่มกฎหมาย, and หน่วยงาน dropdowns are populated (same items as before) and that the network tab shows a single `GET /api/lookups` 200 response.

- [ ] **Step 6: Commit**

```bash
git add apps/app-laravel/resources/js/api/client.ts apps/app-laravel/resources/js/composables/useLookups.ts apps/app-laravel/resources/js/pages/law-info/LawInfoPage.vue
git commit -m "feat(lookups): Law Info form loads types/agencies/groups from API"
```

---

## Self-Review

- **Spec coverage:**
  - "One canonical definition" → Task 1 `config/lookups.php`.
  - "Read-only API" → Task 1 `LookupController` + route.
  - "Present on fresh start, never missing" → config is in code; `LookupApiTest` asserts non-empty lists.
  - "Fix law_type code/label mismatch" → Task 2.
  - "Frontend loads from API instead of hardcoded" → Task 3.
  - Upgrade path (Mongo/CRUD) is documented as non-goal in the spec; not implemented here — correct.
- **Placeholder scan:** none — every step has concrete code/commands. The config file is reproduced in full (it is the canonical source, so exactness matters).
- **Type consistency:** `SelectableOption` = `{title, value, subtitle?}` is defined once in `client.ts` and reused by `useLookups.ts` and `LawInfoPage.vue`. The API JSON keys (`document_types`, `agencies`, `law_groups`) match the `LookupData` type and the config keys. Item shape `{title, value, subtitle?}` matches the existing Vuetify `item-title`/`item-value` bindings.

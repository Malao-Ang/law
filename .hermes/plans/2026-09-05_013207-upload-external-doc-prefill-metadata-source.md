# Plan: upload เอกสารนอกแล้วให้หน้า metadata ตั้งเป็นเอกสารนอกอัตโนมัติ

## Goal
เมื่อผู้ใช้ upload เอกสารนอก ให้ระบบบันทึก `source = external` ตั้งแต่ upload และเมื่อเปิดหน้า metadata (`/documents/:id/law-info`) ต้องเห็นว่าเป็นเอกสารนอก/ภายนอกทันที ไม่ต้องเลือกเองซ้ำ

## Current context / assumptions
- หน้า upload อยู่ที่ `apps/app-laravel/resources/js/pages/admin/AdminUploadPage.vue`.
- Upload flow frontend เรียก `uploadStore.upload(...)` ที่ `apps/app-laravel/resources/js/stores/uploadStore.ts` แล้วส่งต่อไป `uploadDocument(...)` ใน `apps/app-laravel/resources/js/api/client.ts`.
- Backend รับ field นี้อยู่แล้วใน `apps/app-laravel/app/Http/Requests/StoreDocumentRequest.php`:
  - `document_type`: `new | old`
  - `source`: `internal | external`
  - `law_type`: validated against `config/lookups.php`
- Backend old-doc path ใน `apps/app-laravel/app/Http/Controllers/Api/UploadController.php` เรียก `ReviewStore::createHistoricalStub(...)` และส่ง `source`/`law_type` เข้า stub อยู่แล้ว:
  ```php
  $this->reviewStore->createHistoricalStub($documentId, $storedFile['source_file'], [
      'source' => (string) $request->input('source', ''),
      'law_type' => (string) $request->input('law_type', ''),
  ]);
  ```
- หน้า metadata อยู่ที่ `apps/app-laravel/resources/js/pages/law-info/LawInfoPage.vue`.
- `LawInfoPage.vue` ใช้ `form.value.source` สำหรับเอกสารเก่า และกรองประเภทกฎหมายตาม source:
  ```ts
  const filteredDocumentTypes = computed(() => {
    const items = isOld.value
      ? selectableDocumentTypes.value.filter((t) => hasText(form.value.source) && t.source === form.value.source)
      : selectableDocumentTypes.value;
    ...
  });
  ```
- ดังนั้น root cause น่าจะอยู่ที่ frontend upload: ตอนนี้ `AdminUploadPage.vue` ส่งแค่ `{ documentType: uploadMode.value }` ไม่ได้ส่ง `source: 'external'`.
- งานนี้เป็นคนละงานกับแผน table display `/admin/upload` ก่อนหน้า อย่าปน commit กัน
- ตอน inspect ล่าสุด repo มี uncommitted UI งานอื่น (`EditHubWorkspace.vue`, `PublishConfirmDialog.vue`, `DocumentPipelineTable.vue`) ให้ implementer ระวังแยก commit เฉพาะไฟล์ของงานนี้หรือถามก่อน commit ถ้ามี dirty tree

## Architecture / proposed approach
เพิ่มแนวคิด upload preset ใน `AdminUploadPage.vue`: ผู้ใช้เลือกประเภท upload แล้วระบบรู้ว่าควรส่ง `document_type` และ `source` อะไรไป backend. สำหรับ “เอกสารนอก” ให้ส่ง `{ documentType: 'old', source: 'external' }` เพื่อให้ historical stub มี `law_meta.source = external` ตั้งแต่แรก แล้ว LawInfoPage จะเปิดมาด้วย source ภายนอกและ filter เฉพาะประเภทกฎหมายภายนอกทันที

ไม่ต้องแก้ backend contract เพราะรองรับ `source` อยู่แล้ว แต่ต้องเพิ่ม test backend เพื่อ lock behavior ว่า old external upload สร้าง review stub ที่มี `source = external`. ฝั่ง frontend ให้แก้ minimal: เพิ่ม preset/card หรือปรับ existing card ให้ชัดเจน และส่ง opts ให้ครบ

## Step-by-step tasks

### Task 1 — RED: เพิ่ม backend feature test ว่า old external upload seed metadata source external

ไฟล์ที่ต้องอ่านก่อนแก้:
- `apps/app-laravel/tests/Feature/OldDocumentMinioUploadTest.php`
- `apps/app-laravel/tests/Unit/ReviewStoreHistoricalTest.php`
- `apps/app-laravel/app/Http/Controllers/Api/UploadController.php`

เพิ่ม test ใหม่ในไฟล์ที่เหมาะสมที่สุด:
`apps/app-laravel/tests/Feature/OldDocumentMinioUploadTest.php`

ถ้าไฟล์นี้มี setup/mock MinIO อยู่แล้ว ให้ใช้ pattern เดิม ห้ามสร้าง setup ใหม่ซ้ำ

Test behavior ที่ต้องมี:
```php
public function test_old_external_upload_prefills_metadata_source_external(): void
{
    Storage::fake('local');

    $file = UploadedFile::fake()->create('external-law.pdf', 128, 'application/pdf');

    $response = $this->postJson('/api/documents', [
        'file' => $file,
        'document_type' => 'old',
        'source' => 'external',
    ]);

    $response->assertAccepted();

    $documentId = $response->json('document_id');
    $review = app(ReviewStore::class)->getReviewDocument($documentId);

    $this->assertSame('old', $review['law_meta']['document_type']);
    $this->assertSame('external', $review['law_meta']['source']);
    $this->assertSame('', $review['law_meta']['law_type']);
}
```

Imports ที่อาจต้องมี:
```php
use App\Services\ReviewStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
```

Run RED:
```bash
cd D:/workspace/outside/docling-thai-poc
docker compose exec laravel-app php artisan test --filter=old_external_upload_prefills_metadata_source_external
```

Expected output:
- ถ้าปัจจุบัน backend รับ source อยู่แล้ว test อาจ pass ทันที — ถ้า pass ทันที ให้ถือว่า backend behavior มีแล้ว และไม่ต้องแก้ backend
- ถ้า fail เพราะ MinIO mock/setup ให้ย้าย test ไป `tests/Unit/ReviewStoreHistoricalTest.php` และ test `createHistoricalStub(..., ['source' => 'external'])` แทน
- ถ้า fail เพราะ source ไม่ถูกเขียนจริง ให้แก้ `UploadController.php`/`ReviewStore.php` แบบ minimal ให้ pass

Commit ถ้ามีเฉพาะ test/back-end fix:
```bash
git add apps/app-laravel/tests/Feature/OldDocumentMinioUploadTest.php apps/app-laravel/app/Http/Controllers/Api/UploadController.php apps/app-laravel/app/Services/ReviewStore.php
git commit -m "test(upload): cover external source prefill for historical documents"
```

ถ้า test pass โดยไม่ต้องแก้ production code ให้ commit เฉพาะ test หรือรวมกับ frontend commit ถ้าทีมไม่ต้องการ test-only commit

### Task 2 — เพิ่ม upload preset model ใน `AdminUploadPage.vue`

ไฟล์: `apps/app-laravel/resources/js/pages/admin/AdminUploadPage.vue`

เปลี่ยน state จากแค่ `uploadMode` เป็น preset ที่เก็บ `documentType` และ `source`

เพิ่ม type:
```ts
type UploadPresetKey = 'new' | 'old-internal' | 'old-external';

interface UploadPreset {
  key: UploadPresetKey;
  documentType: 'new' | 'old';
  source?: 'internal' | 'external';
}
```

เพิ่ม const:
```ts
const uploadPreset = ref<UploadPreset>({ key: 'new', documentType: 'new' });
```

แทน `uploadMode` เดิม หรือถ้าต้องลด diff ให้คง `uploadMode` ไว้ชั่วคราวแต่เพิ่ม `uploadSource`; แนะนำแบบนี้เพื่อลด ambiguity:
```ts
const uploadMode = computed(() => uploadPreset.value.documentType);
```

แก้ `pickFiles` signature:
```ts
function pickFiles(preset: UploadPreset): void {
  uploadPreset.value = preset;
  pendingItems.value = [];
  fileInputEl.value?.click();
}
```

Verification:
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel
npm run typecheck
```

Expected output:
```text
> typecheck
> tsc --noEmit
```

### Task 3 — แก้ UI card ให้มี “เอกสารนอก” ชัดเจนและส่ง preset external

ไฟล์: `apps/app-laravel/resources/js/pages/admin/AdminUploadPage.vue`

แนวทาง UI ที่แนะนำ:
- คง card `เอกสารใหม่` สำหรับ documentType `new`
- แยก historical/imported PDF เป็น 2 card หรือเพิ่มปุ่มใน card เดิม:
  1. `เอกสารเก่าภายใน` → `{ documentType: 'old', source: 'internal' }`
  2. `เอกสารนอก` / `เอกสารเก่าภายนอก` → `{ documentType: 'old', source: 'external' }`

ถ้าต้อง minimal และไม่เปลี่ยน layout เยอะ ให้แก้ card ที่สองให้มี 2 ปุ่ม:
```vue
<div class="d-flex ga-2 flex-column flex-sm-row">
  <v-btn
    class="flex-1-1"
    color="success"
    variant="flat"
    @click="pickFiles({ key: 'old-internal', documentType: 'old', source: 'internal' })"
  >เอกสารเก่าภายใน</v-btn>
  <v-btn
    class="flex-1-1"
    color="purple"
    variant="flat"
    @click="pickFiles({ key: 'old-external', documentType: 'old', source: 'external' })"
  >เอกสารนอก</v-btn>
</div>
```

หรือถ้าอยากชัดกว่า ให้ทำ 3 cards:
```vue
<v-card class="flex-1-1 pa-6" elevation="0" rounded="lg" style="min-width:300px">
  <v-icon size="32" color="purple" class="mb-2">mdi-file-upload-outline</v-icon>
  <h3 class="text-h6">เอกสารนอก</h3>
  <p class="text-body-2 text-medium-emphasis">
    อัปโหลด PDF กฎหมายภายนอก เช่น พระราชบัญญัติ พระราชกำหนด กฎกระทรวง หรือประกาศกระทรวง และตั้ง metadata เป็นภายนอกให้อัตโนมัติ
  </p>
  <v-btn block color="purple" variant="flat" @click="pickFiles({ key: 'old-external', documentType: 'old', source: 'external' })">
    ดำเนินการต่อ
  </v-btn>
</v-card>
```

ข้อกำหนด:
- `เอกสารนอก` ต้อง accept เฉพาะ `.pdf` เหมือน old doc
- Dialog ควรแสดง hint ว่า “ตั้งค่า metadata เป็นเอกสารภายนอกอัตโนมัติ” เพื่อให้ผู้ใช้รู้ว่าไม่ต้องเลือกซ้ำ

Verification:
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel
npm run typecheck
```

Expected output:
```text
> typecheck
> tsc --noEmit
```

### Task 4 — ส่ง source ไป backend ตอน upload

ไฟล์: `apps/app-laravel/resources/js/pages/admin/AdminUploadPage.vue`

แก้ `uploadAll()` จาก:
```ts
await uploadStore.upload(item.file, item.scanMode, engineFor(item), { documentType: uploadMode.value });
```

เป็น:
```ts
await uploadStore.upload(item.file, item.scanMode, engineFor(item), {
  documentType: uploadPreset.value.documentType,
  source: uploadPreset.value.source,
});
```

ถ้าใช้ computed `uploadMode` สำหรับ accept/multiple/old UI อยู่แล้ว ให้ไม่ต้องเปลี่ยนจุดอื่น

Verification:
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel
npm run typecheck
```

Expected output:
```text
> typecheck
> tsc --noEmit
```

### Task 5 — ตรวจ LawInfoPage ว่าเปิดมาแล้ว source เป็น external และเลือก type ได้เฉพาะ external

ไฟล์: `apps/app-laravel/resources/js/pages/law-info/LawInfoPage.vue`

ก่อนแก้ code ให้ตรวจ logic ปัจจุบัน:
- `isOld` true เมื่อ `law_meta.document_type === 'old'`
- `form.value.source` มาจาก `review.law_meta.source`
- `filteredDocumentTypes` กรองด้วย `t.source === form.value.source`

ถ้า logic นี้ยังเหมือนตอน inspect ไม่ต้องแก้ `LawInfoPage.vue`.

ถ้าพบว่า source external ไม่โชว์ใน field เพราะ field binding ไม่อ่าน `form.source`, ให้แก้เฉพาะ binding ของ source select เท่านั้น ห้าม hardcode route/query

Manual expected behavior:
1. upload ด้วย card `เอกสารนอก`
2. เปิด row action ไปหน้า metadata/law-info
3. field แหล่งที่มาแสดง `เอกสารภายนอกหน่วยงาน` หรือ `ภายนอก`
4. dropdown ประเภทกฎหมายแสดงเฉพาะ external:
   - `พระราชกำหนด`
   - `พระราชบัญญัติ`
   - `กฎกระทรวง`
   - `ประกาศกระทรวง`
5. ต้องไม่แสดง internal types:
   - `ประกาศ`
   - `ระเบียบ`
   - `ข้อบังคับ`
   - `ประกาศที่ออกโดยมหาวิทยาลัย`
   - `ประกาศที่ออกโดยสภามหาวิทยาลัย`

Verification:
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel
npm run typecheck
npm run build
```

Expected output:
```text
> typecheck
> tsc --noEmit
```

และ build จบด้วย:
```text
✓ built in ...
```

Commit:
```bash
git add apps/app-laravel/resources/js/pages/admin/AdminUploadPage.vue apps/app-laravel/resources/js/pages/law-info/LawInfoPage.vue
git commit -m "feat(upload): prefill external metadata for outside documents"
```

## Tests / validation

### Automated checks

Backend focused test:
```bash
cd D:/workspace/outside/docling-thai-poc
docker compose exec laravel-app php artisan test --filter=old_external_upload_prefills_metadata_source_external
```

Frontend checks:
```bash
cd D:/workspace/outside/docling-thai-poc/apps/app-laravel
npm run typecheck
npm run build
```

Expected frontend output:
```text
> typecheck
> tsc --noEmit
```

Build expected final line:
```text
✓ built in ...
```

### Manual UI validation

1. เปิด `/admin/upload`
2. กด upload แบบ `เอกสารนอก`
3. เลือก PDF 1 ไฟล์
4. กด upload
5. ใน table กด action เพื่อไป metadata/edit flow
6. ตรวจว่า metadata page ตั้ง source เป็น external แล้ว
7. ตรวจว่า dropdown ประเภทกฎหมายมีเฉพาะ external law types
8. กลับไป upload table แล้วตรวจ row:
   - แหล่งที่มา: `ภายนอก`
   - ประเภท: ถ้ายังไม่เลือก law_type ให้แสดง `รอกรอกข้อมูล` ตามแผน table display ก่อนหน้า
   - ไม่มี `—`

## Risks, tradeoffs, and open questions

- Risk: ถ้าเลือกใช้ 3 cards หน้า upload อาจแน่นบนจอเล็ก ต้องใช้ `flex-column flex-md-row` และ `min-width` ที่เหมาะสม
- Risk: ถ้ายังไม่ได้ implement แผน `/admin/upload` table display ก่อนหน้า table อาจยังไม่ derive source/law_type สวยงาม ถึง metadata จะถูกแล้ว งานนี้ควรทำหลัง/พร้อมแผนนั้นตามที่ผู้ใช้เคยบอก
- Risk: ถ้า backend MinIO เปิดอยู่ feature test อาจต้อง mock/disable MinIO ตาม pattern existing tests เพื่อไม่ยิง external API
- Open question: คำว่า “เอกสารนอก” ต้องใช้ label เต็มว่า `เอกสารภายนอกหน่วยงาน` หรือสั้นว่า `เอกสารนอก`; แนะนำ UI card ใช้ `เอกสารนอก` และคำอธิบายใช้ `เอกสารภายนอกหน่วยงาน`
- Open question: เอกสารนอกควรบังคับ `document_type = old` เสมอหรือมี “เอกสารใหม่ภายนอก” ด้วย? จาก flow ปัจจุบัน external PDF เป็น imported/historical มากกว่า จึงแนะนำให้ใช้ `document_type = old`, `source = external`

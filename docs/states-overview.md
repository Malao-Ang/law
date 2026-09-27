# สรุปสถานะทั้งหมดในระบบ (States Overview)

ภาพรวมหน้าเดียวของ "สถานะ" ทุกชุดในระบบ — มีกี่แกน แต่ละแกนมีค่าอะไรบ้าง เก็บที่ไหน
และเกี่ยวข้องกันยังไง. รายละเอียดค่าเต็ม ๆ ดูเอกสารที่ลิงก์ไว้ท้ายแต่ละหัวข้อ.

> ⚠️ กฎเหล็ก: มี **หลายแกนสถานะที่เป็นอิสระต่อกัน** อย่า derive แกนหนึ่งจากอีกแกน
> (มีข้อยกเว้นเดียวคือตอน publish — ดูหัวข้อ 5).

---

## แผนที่สถานะ (มีอะไรบ้าง)

| # | แกนสถานะ | ตอบคำถาม | เก็บที่ |
|---|---|---|---|
| 1 | **สถานะบังคับใช้** (`status`) | กฎหมายมีผลทางกฎหมายไหม | `LawMeta.status` |
| 2 | **การเผยแพร่** (`published_date`) | สาธารณะเห็นไหม | `LawMeta.published_date` |
| 3 | **การเข้าถึง** (`access_scope`) | ใครเห็นได้บ้าง | `LawMeta.access_scope` + `permission_group_ids` |
| 4 | **การเปลี่ยนแปลง** (`change_status`) | เวอร์ชันนี้แก้อะไรจากของเดิม | `LawMeta.change_status` + `change_details` |
| 5 | **สถานะ e-Sign** (`esign_*`) | ลงนามอิเล็กทรอนิกส์ถึงขั้นไหน | `DocumentStatus.esign_*` |
| 6 | **สถานะ pipeline** (`status`) | OCR/extract ประมวลผลถึงไหน | `DocumentStatus.status` |
| 7 | **สถานะแก้คำ** (`correction_status`) | spellcheck/แก้คำถึงไหน | `DocumentStatus.correction_status` |
| 8 | **ขั้นตอน workflow** (`workflow_completed_step`) | ทำ flow นำเข้าถึงขั้นที่เท่าไร | `DocumentStatus.workflow_*` |

**สำคัญ:** แกน 1–4 อยู่บน `LawMeta` (ความจริงเชิงกฎหมาย). แกน 5–8 อยู่บน `DocumentStatus`
(สถานะการทำงาน/pipeline) — **คนละ object กัน** โดยเฉพาะ `LawMeta.status` (กฎหมาย) ≠
`DocumentStatus.status` (pipeline).

---

## 1. สถานะบังคับใช้ — `LawMeta.status`

สถานะทางกฎหมายที่แสดงบนหน้าเว็บ.

| ค่า | ความหมาย |
|---|---|
| `ร่าง` | ยังเป็นร่าง ยังไม่มีผล |
| `มีผลบังคับใช้` | บังคับใช้อยู่ |
| `ยกเลิกการใช้งาน` | ถูกยกเลิก ไม่บังคับใช้แล้ว |

---

## 2. การเผยแพร่ — `LawMeta.published_date`

ไม่มี boolean แยก — ดูจากว่ามีวันที่หรือไม่.

| ค่า | สถานะ |
|---|---|
| ว่าง / null | **ไม่เผยแพร่** (สาธารณะมองไม่เห็น) |
| มีวันที่ | **เผยแพร่แล้ว** |

---

## 3. การเข้าถึง — `LawMeta.access_scope` (สาธารณะ / ส่วนตัว)

| ค่า | ความหมาย |
|---|---|
| `public` | ทุกคนเห็น (รวม guest ที่ไม่ login) |
| `private` | เฉพาะกลุ่มที่กำหนดใน `permission_group_ids` |

**หมายเหตุ:** ฝั่ง search/index มีค่า derived `visibility` = `public` → `public`,
`private` → `restricted` (`LawMetaNormalizer::effectiveVisibility`). ต้นทางที่แก้ได้จริงคือ
`access_scope`.

---

## 4. การเปลี่ยนแปลง — `LawMeta.change_status` (+ `change_details`)

`change_status` เป็น string หลัก, `change_details: string[]` เป็นตัวเลือกย่อย.

| `change_status` | ใช้กับ | มีตัวเลือกย่อย |
|---|---|---|
| กฎหมายใหม่ | both | – |
| ปรับปรุงทั้งฉบับ | both | – |
| ปรับปรุงรายข้อ | internal | ✓ (ยกเลิกข้อ / เพิ่มข้อความ / แก้ไขข้อความ) |
| ปรับปรุงรายมาตรา | external | ✓ (ยกเลิกมาตรา / เพิ่มข้อความ / แก้ไขข้อความ) |

---

## 5. สถานะ e-Sign — `DocumentStatus.esign_*`

state machine ของการลงนามอิเล็กทรอนิกส์ (`deriveEsignStage`). รหัสจริงจาก BUU e-Sign
เก็บใน `esign_sign_status` (Y/N/C); stage ที่ UI ใช้ derive มาจากรหัส + timestamp + `published_date`.

| Stage | ป้ายไทย | เงื่อนไข | สี |
|---|---|---|---|
| `draft` | เตรียมส่งลงนาม | ยังไม่ได้ส่ง (ไม่มี `esign_submitted_at`) | admin-primary |
| `waiting` | รอลงนาม | ส่งแล้ว (`esign_submitted_at`) ยังไม่มีผล | warning |
| `signed` | ลงนามเสร็จ | `esign_sign_status = 'Y'` หรือมี `esign_confirmed_at`/`esign_signed_at` | success |
| `rejected` | ถูกปฏิเสธการลงนาม | `esign_sign_status = 'N'` | error |
| `cancelled` | ยกเลิกการส่ง | `esign_sign_status = 'C'` | warning |
| `published` | เผยแพร่แล้ว | มี `published_date` (ชนะทุก stage) | success |

**รหัส `esign_sign_status`:** `Y` = ลงนามแล้ว · `N` = ถูกปฏิเสธ · `C` = ยกเลิกการส่ง ·
ว่างแต่มี `esign_submitted_at` = รอลงนาม · ว่างทั้งหมด = ยังไม่ส่ง.

> เอกสารนำเข้าเก่า (`document_type = 'old'`) ไม่ผ่าน e-Sign → แสดง `–`.

**Publish gate:** เผยแพร่ได้ต่อเมื่อ `esign_sign_status = 'Y'` เท่านั้น (ยืนยันใน
`ReviewController` + `usePublishGates`). ตอน publish สำเร็จจะ set `status = 'มีผลบังคับใช้'`
**พร้อม** `published_date = now` — **นี่คือที่เดียว** ที่ 2 แกนขยับพร้อมกัน.

---

## 6. สถานะ pipeline — `DocumentStatus.status`

สถานะการประมวลผล OCR/extraction (ไม่ใช่สถานะทางกฎหมาย).

`queued` → `processing` → `done` → (`exported`) → `ingesting` → `ingested` · หรือ `failed`

| ค่า | ความหมาย |
|---|---|
| `queued` | เข้าคิวรอประมวลผล |
| `processing` | กำลัง extract/OCR |
| `done` | extract เสร็จ พร้อมรีวิว (= อัปโหลดสำเร็จ) |
| `failed` | ล้มเหลว (ข้อความใน `error`) |
| `exported` | export RAG แล้ว |
| `ingesting` / `ingested` | กำลัง / เสร็จ ingest เข้า index |

---

## 7. สถานะแก้คำ — `DocumentStatus.correction_status`

`not_required` / `pending` / `in_progress` / `done` / `failed` — สถานะ spellcheck/แก้คำ
(ทำงานหลัง extract บน fast path, non-fatal).

---

## 8. ขั้นตอน workflow นำเข้าเอกสาร — `workflow_completed_step`

6 ขั้น (เอกสาร `old` ใช้ flow สั้นกว่า, เลขในวงเล็บ).

| ขั้น (new/old) | ชื่อ | route |
|---|---|---|
| 1 | อัปโหลด | `/admin/upload` |
| 2 | ตรวจทาน (แก้ไขเอกสาร) | `/documents/:id/review` |
| 3 | จัดลำดับเนื้อหา (RAG) | `/documents/:id/rag` |
| 4 (old 2) | ข้อมูล (metadata) | `/documents/:id/law-info` |
| 5 (old 3) | ความสัมพันธ์เอกสาร | `/documents/:id/relations` |
| 6 (old 4) | กำหนดสิทธิ์ (`access_scope`) | `/documents/:id/permissions` |
| — | ลงนาม e-Sign | `/documents/:id/esign` |
| — | เผยแพร่ | (หลัง e-Sign) |

---

## วงจรชีวิตเอกสาร (states ประกอบกันยังไง)

```
อัปโหลด        →  pipeline: queued → processing → done
ตรวจทาน/RAG    →  workflow step 2–3
กรอกข้อมูล     →  LawMeta.status = "ร่าง", published_date = ""      (step 4)
ความสัมพันธ์   →  step 5
กำหนดสิทธิ์    →  access_scope = public/private                     (step 6)
ส่งลงนาม       →  e-Sign: draft → waiting
ลงนามเสร็จ     →  e-Sign: signed (esign_sign_status = "Y")
เผยแพร่        →  status = "มีผลบังคับใช้" + published_date = now  → e-Sign: published
```

แต่ละบรรทัดขยับคนละแกน — เช่นเอกสารหนึ่งอาจ `status = "ร่าง"`, `access_scope = "private"`,
e-Sign `waiting`, pipeline `done` พร้อมกันได้ (แกนอิสระต่อกัน).

---

## อ้างอิงเต็ม

- ค่าออปชันทั้งหมด (law_type / status / agency / group …): [`docs/law-types-and-statuses.md`](law-types-and-statuses.md)
- กติกา field ไหน canonical / derived / mirror: [`docs/law-metadata-reference.md`](law-metadata-reference.md)
- source of truth ของออปชัน: `apps/app-laravel/config/lookups.php`
- ชนิดฝั่ง frontend: `apps/app-laravel/resources/js/types/document.ts`
- e-Sign stage: `apps/app-laravel/resources/js/composables/useEsignStage.ts`

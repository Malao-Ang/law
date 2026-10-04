# ประเภทกฎหมาย & สถานะทั้งหมด — คู่มือสำหรับผู้เขียน/ผู้พัฒนา

เอกสารนี้รวม **ค่าที่เลือกได้จริงทั้งหมด** ของฟิลด์ประเภท/สถานะบน `LawMeta` พร้อมตัวอย่าง
เพื่อให้ผู้เขียนเนื้อหาและ dev เข้าใจตรงกัน

- **แหล่งความจริงของค่า (options):** master data ผ่าน `/api/master-data/{law_family|law_type|issuer|law_category}` และ `/api/lookups`
- **กติกาความสัมพันธ์ระหว่างฟิลด์ (อันไหน derived/mirror):** [`docs/law-metadata-reference.md`](law-metadata-reference.md) — อ่านคู่กัน
- **ชนิดฝั่ง frontend:** `apps/app-laravel/resources/js/types/document.ts` (`LawMeta`)

> ค่าที่ list ในเอกสารนี้อ้างจาก seed master data ณ ตอนเขียน หากเพิ่ม/แก้ options ให้จัดการที่หน้า
> `/admin/master/law-types` หรือ `/admin/master/categories` หรือ migration/seed ของ master data แล้วอัปเดตตารางที่นี่

---

## 1. ประเภทกฎหมาย — `law_type` (ประเภทเอกสาร)

ฟิลด์หลักที่บอกว่าเอกสารเป็นกฎหมายชนิดใด **เป็น code จาก master data `law_type`**
และเชื่อมกับ `law_family` ผ่าน `family_code`:

| code | title | family | `requires_issuer` | หมายเหตุ |
|---|---|---|---|---|
| `LTY01` | ประกาศ | `LFM03` ประกาศ | yes | ต้องระบุ `issuer` |
| `LTY02` | ระเบียบ | `LFM02` ระเบียบ | no | |
| `LTY03` | ข้อบังคับ | `LFM01` ข้อบังคับ | no | |
| `LTY04` | พระราชกำหนด | `LFM04` กฎหมายภายนอก | no | |
| `LTY05` | พระราชบัญญัติ | `LFM04` กฎหมายภายนอก | no | aliases: `พ.ร.บ.`, `พรบ` |
| `LTY06` | กฎกระทรวง | `LFM04` กฎหมายภายนอก | no | |
| `LTY07` | ประกาศกระทรวง | `LFM04` กฎหมายภายนอก | no | |
| `LTY08` | กฎหมายภายนอกอื่น ๆ | `LFM04` กฎหมายภายนอก | no | legacy `กฎหมายภายนอก` |

**กติกา**
- `source` และคำหน่วย derive จาก `law_family`: internal → **ข้อ**, external → **มาตรา**
- `ประกาศที่ออกโดยมหาวิทยาลัย` / `คำสั่ง` → `LTY01` + `issuer=ISS01`
- `ประกาศที่ออกโดยสภามหาวิทยาลัย` / `มติ` → `LTY01` + `issuer=ISS02`
- external มักเป็น PDF นำเข้า → มักนับจำนวนข้อไม่ได้ (ไม่แสดงจำนวน)

---

## 2. ที่มาเอกสาร — `source` (ภายใน/ภายนอก)

**derived จาก `law_type.family_code → law_family.source`** — อย่า set เอง สำหรับเอกสารที่สร้างในระบบ

| value | ความหมาย |
|---|---|
| `internal` | เอกสารภายในหน่วยงาน |
| `external` | เอกสารภายนอกหน่วยงาน |

(สำหรับเอกสารเก่า/นำเข้า ผู้ใช้เลือกเองได้ และค่าที่เก็บใช้เป็น fallback)

---

## 3. ที่มา/วิธีสร้าง — `document_type`

**คนละอันกับ `law_type`** — บอกว่าเอกสารถูกสร้างยังไง ไม่ใช่ชนิดกฎหมาย

| value | ความหมาย |
|---|---|
| `new` | สร้างในระบบ (พิมพ์/แก้ผ่าน editor) |
| `old` | นำเข้า PDF ของเก่า (แสดงเป็น iframe PDF ต้นฉบับ ไม่มีสารบัญ/บล็อก) |

---

## 4. หน่วยงาน — `agency` / `agencies`

`agencies: string[]` เป็นค่าหลัก (multi-select), `agency` เป็น mirror = `agencies[0]`

รายการ (จาก `lookups.agencies`):
มหาวิทยาลัยบูรพา · สภามหาวิทยาลัยบูรพา · สำนักงานอธิการบดี · กองกลาง · กองคลัง · กองพัสดุ ·
กองกิจการนิสิต · สำนักวิชาการ · บัณฑิตวิทยาลัย · กระทรวงการคลัง · สำนักนายกรัฐมนตรี · กระทรวงสาธารณสุข

---

## 5. ออกโดย — `issuer` (ผู้ออกประกาศ)

ใช้เมื่อ `law_type.requires_issuer = true` (ปัจจุบันคือ `LTY01` ประกาศ)

| code | title | แสดงผล |
|---|---|---|
| `ISS01` | มหาวิทยาลัย | ออกโดยมหาวิทยาลัย |
| `ISS02` | สภามหาวิทยาลัย | ออกโดยสภามหาวิทยาลัย |

- `issuer` เป็น master data เพิ่มได้ที่ `/admin/master/law-types?tab=issuers`
- เลือก `issuer` ได้เฉพาะประเภทเอกสารที่ต้องระบุผู้ออกประกาศ และประเภทนั้นต้องอยู่ในกลุ่มภายใน

> `signer_group` (กลุ่มผู้ลงนาม) เป็นฟิลด์ optional แยกต่างหาก (nullable) — ไม่บังคับ

---

## 6. กลุ่มกฎหมาย — `law_groups` / `law_group`

`law_groups: string[]` เป็นค่าหลัก (multi-select), `law_group` เป็น mirror = `law_groups[0]`

**ตั้งแต่ Phase 3:** ค่าที่เก็บใน DB เป็น **รหัสหมวด (`DCTxxx`)** จาก master data `law_category`
(ข้อมูลเก่าก่อน migrate อาจยังเป็นชื่อเต็มภาษาไทยหรือ slug — frontend `useLawCategory` resolve ได้ทั้งสองแบบ)

- **Admin page:** `/admin/master/categories` — จัดการหมวดเอกสาร
- **Migrate command:** `php artisan master-data:migrate law-category`

รายการ (จาก `/api/lookups` `law_groups`, `value=code`):

| code | title |
|---|---|
| `DCT001` | ด้านวิชาการ การผลิตบัณฑิต การเรียนรู้ตลอดชีวิต และการบริหารหลักสูตร |
| `DCT002` | ด้านกิจการนิสิต |
| `DCT003` | ด้านการวิจัย นวัตกรรม และการนำไปใช้ประโยชน์ |
| `DCT004` | ด้านบริการวิชาการ |
| `DCT005` | ด้านการทะนุบำรุงศิลปวัฒนธรรม |
| `DCT006` | ด้านโครงสร้างองค์กรและระบบการบริหาร |
| `DCT007` | ด้านการบริหารงานบุคคล สิทธิประโยชน์ วินัยและจรรยาบรรณ |
| `DCT008` | ด้านการเงินและทรัพย์สิน พัสดุ การตรวจสอบ และการบริหารความเสี่ยง |
| `DCT009` | ด้านการพัฒนารายได้ |
| `DCT010` | ด้านการรักษาพยาบาล |
| `DCT011` | ด้านการบริการเฉพาะด้าน เช่น ทันตกรรม |
| `DCT012` | ด้านอื่น ๆ |

---

## 7. สถานะการบังคับใช้ — `status` (สถานะทางกฎหมาย)

**นี่คือ "สถานะ" ที่แสดงบนหน้าเว็บ** แต่ค่าที่เก็บใน `LawMeta.status` เป็นรหัสจาก master data `enforcement_status`
ไม่ใช่ข้อความภาษาไทยโดยตรง

| code | label | role | color |
|---|---|---|---|
| `STA01` | มีผลบังคับใช้ | `in_force` | `success` |
| `STA02` | ยกเลิกการใช้งาน | `repealed` | `error` |
| `STA03` | ร่าง | `draft` | `grey` |

ชื่อ/alias เก่าถูก normalize เป็น code ผ่าน master data service; โค้ดใหม่ควรอ่าน/เขียนเป็น `STA01`–`STA03`.

> ⚠️ อย่าสับสนกับ `DocumentStatus.status` (สถานะ pipeline OCR — ดูข้อ 11) คนละ object กัน

---

## 8. การเผยแพร่ — `published_date` (เผยแพร่/ไม่เผยแพร่)

ไม่มี field boolean แยก — ดูจาก `published_date`

| ค่า `published_date` | สถานะ |
|---|---|
| ว่าง (empty/null) | **ไม่เผยแพร่** (สาธารณะมองไม่เห็น) |
| มีวันที่ | **เผยแพร่แล้ว** |

- ตอน publish (หลัง e-sign) จะ set `status = 'STA01'` **พร้อม** `published_date = ตอนนี้` (เป็นที่เดียวที่ 2 แกนขยับพร้อมกัน)

---

## 9. การเข้าถึง — `access_scope` (ใครเห็น)

| value | ความหมาย |
|---|---|
| `public` | ทุกคนเห็น |
| `private` | เฉพาะกลุ่มที่กำหนดใน `permission_group_ids` |

---

## 10. สถานะการเปลี่ยนแปลง — `change_status` + `change_details`

บอกว่าเวอร์ชันนี้แก้อะไรเทียบเวอร์ชันก่อน `change_status` เป็น string (parent),
`change_details: string[]` เป็นตัวเลือกย่อย (เลือกได้หลายรายการ)

**ตัวเลือกหลัก (`change_status_types`)** — `source` บอกว่าใช้กับ internal/external/both:

| value | ใช้กับ source | มีตัวเลือกย่อย |
|---|---|---|
| กฎหมายใหม่ | both | – |
| ปรับปรุงทั้งฉบับ | both | – |
| ปรับปรุงรายข้อ | internal | ✓ |
| ปรับปรุงรายมาตรา | external | ✓ |

**ตัวเลือกย่อย (`change_status_details`)** — โผล่เมื่อ parent = ปรับปรุงราย…:

| value | ใช้กับ source |
|---|---|
| ยกเลิกข้อ | internal |
| ยกเลิกมาตรา | external |
| เพิ่มข้อความ | both |
| แก้ไขข้อความ | both |

> internal ใช้คำ "ข้อ", external ใช้คำ "มาตรา" — ตัวเลือกที่โชว์กรองตาม source ของเอกสาร

---

## 11. สถานะ pipeline — `DocumentStatus.status` (คนละอันกับข้อ 7)

สถานะการประมวลผล OCR/extraction (ไม่ใช่สถานะทางกฎหมาย) จาก `types/document.ts`:

`queued` → `processing` → `done` → (`exported`) → `ingesting` → `ingested` · หรือ `failed`

| value | ความหมาย |
|---|---|
| `queued` | เข้าคิวรอประมวลผล |
| `processing` | กำลัง extract/OCR |
| `done` | extract เสร็จ พร้อมรีวิว |
| `failed` | ล้มเหลว |
| `exported` | export RAG แล้ว |
| `ingesting` | กำลัง ingest เข้า index |
| `ingested` | ingest เสร็จ |

**อัปโหลดสำเร็จ/ล้มเหลว** — ดูจาก `status`: สำเร็จ → `done` (เข้าตรวจทานต่อได้), ล้มเหลว → `failed` (มีข้อความใน `error`)

ฟิลด์ประกอบ (`DocumentStatus`):

| ฟิลด์ | ความหมาย |
|---|---|
| `error` | ข้อความ error เมื่อ `failed` |
| `fast_fallback_reason` | เหตุที่ fast path (PHP) ตกไปใช้ standard pipeline |
| `timings` | เวลาที่ใช้แต่ละขั้น (ms) |
| `correction_status` | `not_required` / `pending` / `in_progress` / `done` / `failed` — สถานะ spellcheck/แก้คำ |

---

## 12. ความสัมพันธ์กฎหมาย — `LawRelation`

**ขอบเขต (`scope`)**

| value | ความหมาย |
|---|---|
| `document` | ความสัมพันธ์ระดับทั้งฉบับ (แสดงในกล่อง "กฎหมายแม่/เกี่ยวข้องทั้งฉบับ") |
| `section` | ความสัมพันธ์ระดับข้อ/มาตรา (แสดงในข้อนั้น) |

**ประเภท (`type`)**

| value | ป้ายภาษาไทย |
|---|---|
| `related` | เกี่ยวข้อง |
| `repeals` | ยกเลิก |
| `amends` | แก้ไขเพิ่มเติม |
| `issued_under` | ออกตามอำนาจ |
| `supersedes` | แทนที่ |

---

## 13. ขั้นตอนการทำงาน (workflow) นำเข้าเอกสาร — end-to-end

ลำดับ 6 ขั้น (`WORKFLOW_STEPS`) ติดตามด้วย `workflow_completed_step` / `workflow_current_step` / `workflow_updated_at`
เอกสารนำเข้าเก่าแบบ `old` ใช้ flow สั้นกว่า (เลขขั้นในวงเล็บ)

| ขั้น (new/old) | ชื่อ | หน้า / route | ทำอะไร |
|---|---|---|---|
| 1 | อัปโหลด | `/admin/upload` (AdminUploadPage) | อัปโหลดไฟล์ → extract → **สำเร็จ `done` / ล้มเหลว `failed`** (ดู §11) |
| 2 | ตรวจทาน = **แก้ไขเอกสาร** | `/documents/:id/review` (ReviewPage) | แก้เนื้อหาทั้งเอกสารใน editor |
| 3 | จัดลำดับเนื้อหา | `/documents/:id/rag` (RagManageWorkspace) | เลือก/รวม/ลบ/จัดลำดับบล็อกก่อนทำ RAG |
| 4 (old 2) | ข้อมูล | `/documents/:id/law-info` (LawInfoPage) | กรอก metadata (law_type / สถานะ / หน่วยงาน …) — `completeWorkflowStep(4)` |
| 5 (old 3) | เอกสารที่เกี่ยวข้อง = **ความสัมพันธ์เอกสาร** | `/documents/:id/relations` (LawRelationsPage) | เพิ่มกฎหมายที่เกี่ยวข้อง/ระดับข้อ (ดู §12) — `completeWorkflowStep(5)` |
| 6 (old 4) | กำหนดสิทธิ์ | `/documents/:id/permissions` (PermissionAccessPage) | ตั้ง `access_scope` + กลุ่มสิทธิ์ — `completeWorkflowStep(6)` |
| — | ลงนาม (E-Sign) | `/documents/:id/esign` (+ `/esign/preview`, `/esign/status`) | ส่งลงนามอิเล็กทรอนิกส์ |
| — | เผยแพร่ | (หลัง e-sign) | set `status='STA01'` + `published_date` (ดู §7–8) |

**map ศัพท์ที่มักถาม → อยู่ตรงไหน**
- **แก้ไขเอกสาร** = ขั้น 2 ตรวจทาน → `/documents/:id/review`
- **ความสัมพันธ์เอกสาร** = ขั้น 5 → `/documents/:id/relations` (ประเภท/ขอบเขต ดู §12)
- **upload สำเร็จ/ล้มเหลว** = `DocumentStatus.status` = `done` / `failed` (ดู §11)

---

## 14. ตัวอย่างจริง

### ตัวอย่าง A — กฎหมายภายนอก (พ.ร.บ.)

```jsonc
{
  "law_type": "LTY05",            // พระราชบัญญัติ → source = external → ใช้คำ "มาตรา"
  "document_type": "old",         // นำเข้า PDF เก่า
  "title": "พระราชบัญญัติมหาวิทยาลัยบูรพา พ.ศ. 2550",
  "status": "STA01",
  "published_date": "2026-01-19", // มีวันที่ = เผยแพร่แล้ว
  "access_scope": "public",
  "change_status": "กฎหมายใหม่",
  "change_details": [],
  "agencies": ["มหาวิทยาลัยบูรพา"],
  "law_groups": ["DCT006"],  // ด้านโครงสร้างองค์กรและระบบการบริหาร (DCT code)
  "issuer": null                  // requires_issuer=false → ไม่ต้องมี
}
```

### ตัวอย่าง B — กฎหมายภายใน (ประกาศ) ที่ปรับปรุงรายข้อ

```jsonc
{
  "law_type": "LTY01",                         // ประกาศ → source = internal → ใช้คำ "ข้อ"
  "document_type": "new",                       // สร้างในระบบ
  "title": "ประกาศ เรื่อง อัตราค่าตอบแทนผู้ทรงคุณวุฒิ",
  "status": "STA03",                            // ยังเป็นร่าง
  "published_date": "",                          // ว่าง = ยังไม่เผยแพร่
  "access_scope": "private",
  "permission_group_ids": ["hr-team"],
  "issuer": "ISS02",                             // สภามหาวิทยาลัย
  "change_status": "ปรับปรุงรายข้อ",             // internal → "ข้อ"
  "change_details": ["ยกเลิกข้อ", "แก้ไขข้อความ"],
  "agencies": ["สภามหาวิทยาลัยบูรพา"],
  "law_groups": ["DCT007"]  // ด้านการบริหารงานบุคคล สิทธิประโยชน์ วินัยและจรรยาบรรณ (DCT code)
}
```

---

## สรุปย่อ (cheat sheet)

- **ชนิดเอกสาร** = `law_type` code (`LTYxx`) → `law_family` (`LFMxx`) → กำหนด `source` และคำ **ข้อ/มาตรา**
- **ออกโดย** = `issuer` code (`ISSxx`) เมื่อประเภทเอกสาร `requires_issuer`
- **สถานะกฎหมาย** = `status` (`STA03` / `STA01` / `STA02`)
- **เผยแพร่หรือไม่** = ดู `published_date` (ว่าง = ไม่เผยแพร่)
- **ใครเห็น** = `access_scope` (public / private)
- **แก้อะไรจากเวอร์ชันก่อน** = `change_status` (+ `change_details`)
- 4 แกนสถานะ (`status`, `published_date`, `access_scope`, `change_status`) **เป็นอิสระต่อกัน** — ดู [`law-metadata-reference.md`](law-metadata-reference.md)

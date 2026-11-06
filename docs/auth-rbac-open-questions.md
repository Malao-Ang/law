# Permission / สิทธิ์การใช้งาน — สรุปการตัดสินใจ + ข้อเสนอแนะ

เอกสารนี้เดิมเป็นชุดคำถามเพื่อยืนยันกับลูกค้า ตอนนี้ **ลูกค้าตอบแล้ว** จึงปรับเป็น
บันทึกการตัดสินใจ (decision log) + ข้อเสนอแนะดีไซน์ ในจุดที่ลูกค้าขอให้แนะนำ.

> อ้างอิงดีไซน์เต็ม (ON HOLD จนกว่าจะรวมข้อสรุปนี้เข้าไป):
> `docs/superpowers/specs/2026-09-26-auth-rbac-api-middleware-design.md`
> อัปเดตล่าสุด: 2026-09-26

---

## สถานะปัจจุบันของระบบ (ก่อนทำ auth จริง)

- **ยังไม่มี login จริง** — mock login เก็บใน browser เท่านั้น, backend ไม่รู้จักผู้ใช้
- **คน upload = คนเดียวที่แก้เอกสารนั้นได้** (ownership = ผู้อัปโหลด, ยังโอนให้ใครไม่ได้)
- Visibility 3 ระดับที่ตั้งใจไว้: `public` (ทุกคน) / `private` (login ก็เห็น) / `private + group`
- การกรองสิทธิ์ตอนนี้อยู่ที่ **ฝั่ง browser เท่านั้น** (ยังไม่ปลอดภัย — เรียก API ตรงเห็นได้หมด)

---

## ✅ สรุปการตัดสินใจจากลูกค้า

| # | ประเด็น | คำตอบลูกค้า |
|---|---|---|
| 1 | role เป็นระดับทั้งระบบ หรือต่อคณะ? | **ไม่ผูกกับคณะ** — role เป็นระดับทั้งระบบ. การเข้าถึงเอกสารคุมด้วย **group ที่สร้างขึ้นได้อย่างอิสระ** ผ่าน "หน้า permission" ที่กำหนดได้เองว่าใครเข้าเอกสารนั้นได้ |
| 2 | "private ธรรมดา (login ก็เห็น)" เห็นข้ามคณะไหม? | **เห็นได้หมด ไม่จำกัดคณะ** ขอแค่ login |
| 3 | Editor แก้ของคนอื่นได้ไหม? | **ไม่ได้** — แก้ได้เฉพาะเอกสารของตัวเอง (ต่อยอดด้วยการเพิ่มผู้ช่วยแก้ ดูข้อ 4–5) |
| 8 | 3 role พอไหม / ต้องมี Approver แยก? | **3 role พอ**. ถ้ามีขั้นอนุมัติ ให้ **Super Admin เป็นผู้อนุมัติ** (ไม่ตั้ง role Approver ใหม่) |
| 10–11 | ลบ / audit log | **soft-delete** + **ต้องมี audit log** ที่ดูย้อนหลังได้ **ตั้งแต่เริ่มจนปัจจุบัน** (เก็บถาวร ไม่หมดอายุ) |
| 12 | LDAP `facultyname` แทน "หน่วยงาน" พอไหม? | **ไม่พอ** — คณะ (faculty) ใหญ่เกิน มีหน่วยย่อย (ฝ่าย). ใช้ **SPerson API** ดึงลำดับชั้นจริง (คณะ→ฝ่าย→ภาควิชา + ตำแหน่ง). ดูหัวข้อ "🧩 SPerson API" |
| 13 | สมาชิก group มาจากไหน? | **Super Admin จัดมือ** (จะมีเมนูให้จัด). เปิดทางให้ **Editor สร้าง/กำหนด group ได้ด้วย** ไม่จำเป็นต้องเป็น Super Admin เท่านั้น |
| 14 | user หลาย group + เอกสารผูกหลาย group | **เห็นถ้าอยู่อย่างน้อย 1 group** — เห็นตามสิทธิ์ของเอกสารนั้น ๆ |
| 15 | Guest ดาวน์โหลด PDF public ได้ไหม? | **ได้** — คนไม่ login ดาวน์โหลด PDF ของกฎหมาย public ได้ |
| 16 | full-text ของ public เปิดให้ search engine index? | **เปิด** ให้ index ได้ |

---

## 💡 ข้อเสนอแนะ: Ownership, ผู้ช่วยแก้, และการโอนสิทธิ์ (ข้อ 4–7)

ลูกค้าบอกว่า "ควรโอนสิทธิ์ได้ แต่ยังไม่รู้วิธี" และ "อยากเพิ่มคนช่วยแก้ กลัวโครงสร้างวุ่น".
**ข่าวดี: ข้อ 3, 4, 5, 6, 14 ยุบเป็นคำถามเดียว** — "จะบันทึก *ใครแตะเอกสารนี้ได้* ยังไง".
ตอบด้วยตารางเดียว ไม่ทำให้โครงสร้างวุ่น เพราะเป็นตารางเดียวกับที่ "หน้า permission" ต้องใช้อยู่แล้ว.

### โมเดลที่เสนอ (ตารางเดียว backs ทั้ง read, write และหน้า permission)

```
documents
  owner_id        -- ผู้สร้าง/ผู้รับผิดชอบหลัก (ใช้ตอน accountability + default write)
  visibility      -- public | login | restricted

document_permissions   -- นี่คือ backing store ของ "หน้า permission" ที่ลูกค้าอยากได้
  document_id
  principal_type  -- user | group
  principal_id
  can_read
  can_write
```

**กติกาการเห็น (read):**
- `public` → ทุกคนเห็น (รวม guest) + ดาวน์โหลดได้ (ตอบข้อ 15–16)
- `login` → login แล้วเห็นได้หมด ข้ามคณะ = "private ธรรมดา" (ตอบข้อ 2)
- `restricted` → เห็นเฉพาะ owner + แถวใน `document_permissions` ที่ตรง user หรือ group ที่ตนสังกัด
  (อยู่อย่างน้อย 1 group ก็เห็น — ตอบข้อ 14)

**กติกาการแก้ (write):** owner **หรือ** มีแถว `can_write` (ตรง user หรือผ่าน group) **หรือ** Super Admin.

### สิ่งที่หลุดออกมาจากโมเดลนี้เอง

- **ข้อ 5 เพิ่มผู้ช่วยแก้** → เพิ่มแถว `can_write` ให้ user หรือทั้ง group. ไม่วุ่น เพราะเป็น
  ตารางเดียวที่ใช้อยู่แล้วสำหรับ read. หน้า permission = UI ของตารางนี้.
- **ข้อ 4 โอนสิทธิ์** — แยกให้ชัด 2 แบบ:
  - **เพิ่มผู้ร่วมแก้** = เพิ่มแถว `can_write` (เจ้าของเดิมยังแก้ได้)
  - **โอนขาด** = เปลี่ยน `owner_id` เป็นคนใหม่ + ถอน write ของเจ้าของเดิม
  - **ใครโอนได้:** เจ้าของปัจจุบัน หรือ Super Admin — **บันทึก audit ทุกครั้ง**
- **ข้อ 3** → ไม่ต้องมีกฎ "แก้ข้ามคณะ" พิเศษ. Editor แก้ได้เฉพาะเอกสารที่ตนเป็น owner
  หรือถูกเพิ่มใน `can_write` เท่านั้น. ตรงกับที่ลูกค้าตอบ.

---

## ❓ คำถามที่ยังเปิดอยู่ (พร้อมข้อเสนอแนะ)

### ข้อ 6 — เจ้าของลาออก/ย้าย ใครรับช่วง? *(ลูกค้าขอเก็บเป็นคำถาม แต่ขอข้อเสนอ)*

⚠️ ข้อควรระวัง: มีเอกสารอ่อนไหว (เช่น สัญญาเงินเดือน) ปนอยู่.

**ข้อเสนอ: อย่าให้หัวหน้าหน่วยงานรับอัตโนมัติ** — เสี่ยงมอบสิทธิ์เห็นเอกสารอ่อนไหว
ให้คนที่ไม่ควรเห็น. แนะนำแทน:
1. Job ตรวจ owner ที่ถูกปิดสถานะใน LDAP → ยกเอกสารขึ้นรายการ **"เอกสารไร้เจ้าของ"**
2. **Super Admin โอนมือ** (deliberate + audit) — ไม่ใช่โอนอัตโนมัติ
3. ระหว่างรอโอน เอกสารยังปลอดภัย: ไม่มีใครแก้ได้นอกจาก Super Admin (deny by default)

### ข้อ 7 — เอกสารเก่ายังไม่มีเจ้าของ (backfill)

**ข้อเสนอ:** ตั้ง `owner_id` จากผู้อัปโหลดเดิมถ้ามีบันทึกไว้; ถ้าไม่มี ให้ Super Admin เป็น
ผู้ดูแลชั่วคราว (custodian) จนกว่าจะรีวิวและโอนให้เจ้าของจริง.

### ข้อ 9 — คนหนึ่งมีได้หลาย role ไหม? *(ยังไม่ตอบ)*

**ข้อเสนอ:** role เป็นระดับระบบ (ข้อ 1) จึงพอมี role เดียวต่อคน. ขอบเขต "คณะ" มาจาก group
ไม่ใช่ role — ไม่ต้องมี "Editor คณะ A + Viewer คณะ B". เก็บ role ให้ง่ายไว้ก่อน.

---

## 🔐 โมเดลสิทธิ์ที่สรุปได้ (capability matrix)

Role = ระดับความสามารถของระบบ. การเข้าถึง *เอกสารรายชิ้น* คุมด้วย ownership + group + visibility.

| Action | Viewer | Editor | Super Admin |
|---|---|---|---|
| ดู (ตาม visibility/group) | ✓ | ✓ | ✓ (ทุกชิ้น) |
| ดาวน์โหลด | ✓ | ✓ | ✓ |
| แก้ไข / soft-delete | ✗ | ✓ เฉพาะที่เป็น owner หรือมี `can_write` | ✓ |
| เปลี่ยน permission ของเอกสาร | ✗ | เฉพาะ owner | ✓ |
| โอนเจ้าของ | ✗ | เฉพาะ owner (โอนให้คนอื่น) | ✓ |
| สร้าง/จัด group | ✗ | ✓ (ตามที่ลูกค้าเปิดทางในข้อ 13) | ✓ |
| จัดการ users / role | ✗ | ✗ | ✓ |
| อนุมัติ (ถ้ามี workflow) | ✗ | ✗ | ✓ (ข้อ 8) |

หลักการที่ยึด: **แยก read/write**, **deny by default**, **audit ทุกการแก้/ลบ/โอน/เปลี่ยนสิทธิ์**,
**draft ≠ published** (ร่างไม่โผล่ public แม้ตั้ง public).

---

## 🚨 ช่องโหว่ + แผนปิด (ลำดับการใส่ login backend)

ลูกค้าระบุ: "ช่องโหว่จะแก้ตอนใส่ระบบ login เบื้องหลัง — ขอข้อเสนอแนะ". นี่คือลำดับที่แนะนำ,
เรียงจากวิกฤตสุดก่อน:

### 🔴 วิกฤต (ต้องปิดพร้อม login เฟสแรก)
1. **ใส่ auth backend จริง** (session หรือ token) แล้ว **gate ทุก `/api` route ด้วย middleware, deny by default** — ปิดช่อง "เรียก API ตรงเห็น/แก้ได้หมด"
2. **ย้ายการกรองสิทธิ์มาไว้ฝั่ง server** ตามตาราง `document_permissions` — browser ห้ามเป็นด่านเดียว
3. **ป้องกันไฟล์/รูป/zip ด้วยเช็คสิทธิ์เดียวกัน** — ปิด IDOR (เดา document id แล้วโหลด)
4. **เซ็น callback Python→Laravel** ด้วย shared secret / HMAC — กันปลอม callback

### 🟠 ต้องออกแบบให้ดี (เฟรมพร้อมโมเดลข้างบน)
5. ผูก visibility กับสถานะ workflow — draft ไม่ leak แม้ตั้ง public
6. Orphan owner → ตามข้อ 6 (job + โอนมือ)
7. **กัน Super Admin คนสุดท้ายถูกลด/ลบสิทธิ์** จนไม่เหลือคนดูแลระบบ

### 🟡 ควรมี
8. CSRF (ถ้าใช้ cookie session), rate limit หน้า login
9. ไม่ log รหัสผ่าน / เลขบัตรประชาชน
10. Audit log เก็บถาวร (ตามข้อ 10–11) — ใครแก้/ลบ/โอน/เปลี่ยนสิทธิ์ + เมื่อไหร่

### เส้นแบ่ง public vs ต้อง login
- **ไม่ต้อง login:** ดู + ดาวน์โหลดกฎหมาย **public ที่ประกาศแล้ว**, full-text search ของ public, ให้ search engine index
- **ต้อง login:** ทุกอย่างที่เหลือ — เอกสาร `login`/`restricted`, การแก้/ลบ/โอนทั้งหมด, หน้า admin

---

## 🧩 SPerson API — resolve หน่วยงาน + สมาชิก group จากระบบบุคลากร

LDAP ใช้แค่ **ยืนยันตัวตน (login)**. ส่วน "หน่วยงาน" และการ map user → group ให้ดึงจาก
**ระบบบุคลากร (SPerson)** เพราะให้ลำดับชั้นองค์กรจริง (คณะใหญ่เกิน ต้องลงถึงฝ่าย).

**Auth (OAuth2):**
- Domain: `api-gw.buu.ac.th`
- Token: `POST https://{DOMAIN}/service-api/ums.SPerson/oauth2/token` → ได้ access token
- ค้นบุคลากร: `POST https://{DOMAIN}/service-api/ums.SPerson`
  Authorization: `Bearer <token>`, body: raw JSON (ค้นด้วยชื่อ/นามสกุล)

**ลำดับชั้นองค์กรจาก response (ใช้เป็น scope ของ permission group ได้):**

| ระดับ | field | ตัวอย่าง |
|---|---|---|
| วิทยาเขต | `cap_id` / `cap_nameth` | บางแสน |
| คณะ/สำนัก | `fac_id` / `fac_nameth` | สำนักคอมพิวเตอร์ |
| **ฝ่าย/แผนก** | `dep_id` / `dep_nameth` | ฝ่ายพัฒนาระบบ ← หน่วยย่อยที่ facultyname ไม่มี |
| ภาควิชา/สาขา | `maj_id` / `maj_nameth` | (null ได้) |
| ตำแหน่ง/สายงาน | `liw_nameth`, `grl_nameth` | นักวิชาการคอมพิวเตอร์ / สนับสนุนวิชาการ |
| ตำแหน่งบริหาร | `positions[].adm_name_fac_name` | ผู้ช่วยผู้อำนวยการ สำนักคอมพิวเตอร์ |

**ตัวระบุตัวบุคคล:** `psn_id`, `psn_idcard` (เลขบัตร ปชช), `uslogin` (username ตรงกับ LDAP)

**ผลต่อดีไซน์สิทธิ์:**
- permission group ผูก scope ได้ทั้งระดับ **คณะ (`fac_id`)** และ **ฝ่าย (`dep_id`)** — ยืดหยุ่นกว่า facultyname อย่างเดียว
- ตอน login: LDAP ยืนยันตัวตน → เรียก SPerson ด้วย username/ชื่อ → cache `fac_id`/`dep_id`/positions ไว้กับ user record → ใช้ตัดสินสมาชิก group ฝั่ง server (ข้อ 14: อยู่อย่างน้อย 1 group ก็เห็น)
- ลด/เลิกการจัด group ด้วยมือทั้งหมด — สมาชิกบางส่วน derive จาก `dep_id`/`fac_id` อัตโนมัติได้ (Super Admin ยังoverride/เพิ่มรายคนได้)

**คำถามที่ตามมา (ใส่ในลิสต์ล่างสุดด้วย):** group ควรผูกที่ระดับไหนเป็นหลัก (คณะ/ฝ่าย/ผสม), และสมาชิก derive อัตโนมัติจาก `dep_id`/`fac_id` หรือจัดมือ

---

## 🗂️ หน้า permissions ของเอกสาร (`/documents/{id}/permissions`) — โหมดการเลือก + แหล่งข้อมูล

**สถานะปัจจุบัน:** group เก็บ `unit_ids` (หน่วยงาน) + `user_ids` (รายคน).
directory (รายชื่อหน่วยงาน/คน) ตอนนี้ **hardcode** ใน `PermissionStore->directory()`
(`unit_legal`, `user_somchai` …) — ยังไม่ได้ต่อ SPerson API.

### 2 โหมดการเลือก
1. **หน่วยงาน (unit)** — เลือกทั้งหน่วยงาน
2. **รายคน (person)** — เลือกทีละคน

### ความหมายของการเลือก (semantics) — ต้องตัดสิน

**คำถามหลัก:** เลือกหน่วยงาน = ทุกคนในหน่วยงานนั้นเห็นเลยไหม, และถ้าเลือกรายคนเพิ่ม รวมกันยังไง?
3 โมเดลที่เป็นไปได้:

| โมเดล | หน่วยงาน | รายคน | เหมาะเมื่อ |
|---|---|---|---|
| **A. Union (บวกกัน)** ✅ แนะนำ | ทุกคนในหน่วยงานเห็น | เพิ่มคนนอกหน่วยงานทีละคน | สิทธิ์การเห็น = ทั้งหน่วยงาน ∪ รายคน. ง่าย ตรงกับ `unit_ids ∪ user_ids` ที่มีอยู่ |
| **B. เลือกหน่วย → ติ๊กเฉพาะบางคน** | หน่วยงานเป็นตัวกรอง แล้วเลือก subset | — | บางคนในหน่วยงาน**ไม่ควรเห็น** (ตรงกับที่กังวล) — คุมละเอียดแต่คลิกเยอะ |
| **C. Union + exclude list** | ทั้งหน่วยงาน ลบคนที่ยกเว้น | เพิ่มรายคน | ยืดหยุ่นสุด แต่ซับซ้อนสุด (YAGNI ตอนนี้) |

**ข้อเสนอ:** ใช้ **A (Union)** เป็นหลัก — "เลือกหน่วยงาน = ทั้งหน่วยงานเห็น".
ถ้าต้องการให้เห็นแค่บางคน → **ไม่เลือกทั้งหน่วยงาน แต่ใช้โหมดรายคนแทน**.
(เก็บ exclude-list ของโมเดล C ไว้ทีหลังถ้าจำเป็นจริง)
→ **ถ้าลูกค้าย้ำว่า "เลือกหน่วยแล้วมาเลือกคนอีกที" จำเป็น → ไปโมเดล B**

### แหล่งข้อมูล — API vs hardcode
- **ตอนนี้ hardcode** → ต้องเปลี่ยนเป็นดึงจาก **SPerson API**
- **หน่วยงาน:** ⚠️ SPerson ที่ให้มาเป็น *ค้นรายบุคคล* (ยังไม่มี endpoint list หน่วยงาน) —
  ต้องถามว่ามี API list คณะ/ฝ่าย ไหม หรือ derive จาก `fac_id`/`dep_id` ของบุคลากร
- **รายคน:** เรียก SPerson ค้นคน — endpoint ที่ต้องยืนยัน:
  - `find by name` (ชื่อ/นามสกุล) — มีแล้ว (`POST service-api/ums.SPerson`)
  - `find by citizen id` (เลขบัตร) — มี endpoint ไหม?
  - `find by faculty/department` (ทั้งหน่วยงาน) — มี endpoint ไหม? (จำเป็นถ้าจะ resolve สมาชิกหน่วยงานตอนบันทึกสิทธิ์)

---

## ⚠️ คำถามที่ยังต้องให้ "ลูกค้า" ยืนยัน (คำตอบด้านบนเป็นการตอบเบื้องต้นภายใน — ลูกค้ายังไม่ได้ยืนยัน)

> คำตอบในส่วน "✅ สรุปการตัดสินใจ" และ "💡 ข้อเสนอแนะ" ด้านบน เป็นการตอบเบื้องต้นกันเองในทีม
> ยังไม่ผ่านลูกค้าจริง. รายการข้างล่างนี้คือสิ่งที่ต้องเอาไปถามลูกค้าให้ยืนยัน (ติ๊กเมื่อลูกค้าตอบแล้ว)

**A. Role & การเข้าถึง**
- [ ] role เป็นระดับทั้งระบบ (ไม่ผูกคณะ) การเข้าถึงคุมด้วย group อิสระ — ยืนยัน?
- [ ] "private ธรรมดา" = login แล้วเห็นได้หมดข้ามคณะ — ยืนยัน?
- [ ] Editor แก้ได้เฉพาะเอกสารของตัวเอง (+ ผู้ที่ถูกเพิ่ม `can_write`) — ยืนยัน?
- [ ] 3 role พอ, ขั้นอนุมัติให้ Super Admin เป็นผู้อนุมัติ (ไม่มี role Approver แยก) — ยืนยัน?
- [ ] คนหนึ่งมีได้ role เดียว (ข้อ 9 — ยังไม่ตอบ) — ยืนยัน?

**B. Ownership / ผู้ช่วยแก้ / การโอน**
- [ ] เพิ่มผู้ช่วยแก้ = เพิ่มแถว `can_write` (เจ้าของเดิมยังแก้ได้) — ยืนยันวิธี?
- [ ] โอนขาด = เปลี่ยน owner + ถอน write เจ้าของเดิม; ใครโอนได้ = เจ้าของ/Super Admin — ยืนยัน?
- [ ] เอกสารหนึ่งมีผู้แก้ได้หลายคนผ่าน `can_write` — ยืนยันว่ารับได้?
- [ ] เจ้าของลาออก/ย้าย: ใช้ job + Super Admin โอนมือ (ไม่โอนอัตโนมัติให้หัวหน้า) — ยืนยัน?
- [ ] เอกสารเก่าไม่มีเจ้าของ: backfill จากผู้อัปโหลดเดิม, ไม่มีก็ให้ Super Admin เป็น custodian — ยืนยัน?

**C. Group & LDAP / SPerson**
- [x] ~~ใช้ `facultyname` จาก LDAP พอไหม~~ → **ไม่พอ, ใช้ SPerson API** (คณะ→ฝ่าย) — สรุปแล้ว
- [ ] group ผูก scope ที่ระดับไหนเป็นหลัก — **คณะ (`fac_id`) / ฝ่าย (`dep_id`) / ผสม**?
- [ ] สมาชิก group derive อัตโนมัติจาก `dep_id`/`fac_id` (SPerson) หรือ Super Admin จัดมือ หรือทั้งคู่?
- [ ] Super Admin จัด group มือ + เปิดให้ Editor สร้าง/จัด group ได้ด้วย — ยืนยันขอบเขต?
- [ ] user อยู่หลาย group + เอกสารผูกหลาย group → เห็นถ้าอยู่อย่างน้อย 1 group — ยืนยัน?
- [ ] SPerson token/credential (client id/secret สำหรับ OAuth2) — ใครออกให้ / มี rate limit ไหม?

**C2. หน้า permissions ของเอกสาร (โหมดเลือก)**
- [ ] เลือก "หน่วยงาน" = ทุกคนในหน่วยงานเห็น (โมเดล A Union) — ยืนยัน? หรือต้อง "เลือกหน่วยแล้วติ๊กเฉพาะบางคน" (โมเดล B)?
- [ ] เลือกรายคน = บวกเพิ่มจากหน่วยงาน (union) — ยืนยัน?
- [ ] มี API list หน่วยงาน (คณะ/ฝ่าย) ไหม หรือ derive จาก `fac_id`/`dep_id` ของบุคลากร?
- [ ] SPerson มี endpoint `find by citizen id` และ `find by faculty/department` ไหม (นอกจาก find by name)?

**D. การลบ / audit / draft**
- [ ] ลบ = soft-delete เท่านั้น (กฎหมายประกาศแล้วห้ามลบจริง ได้แค่ "ยกเลิก") — ยืนยัน?
- [ ] audit log เก็บถาวรตั้งแต่เริ่มจนปัจจุบัน (ไม่หมดอายุ) — ยืนยันขอบเขต/ระยะเก็บ?
- [ ] draft/ร่าง ต้องไม่โผล่ public แม้ตั้ง access_scope=public — ยืนยัน?

**E. สาธารณะ (guest)**
- [ ] guest ดาวน์โหลด PDF ของ public ได้ — ยืนยัน?
- [ ] เปิด full-text ของ public ให้ search engine index — ยืนยัน (มีเอกสารไหนห้าม index ไหม)?

**F. ความปลอดภัย / ลำดับใส่ login**
- [ ] เห็นด้วยกับลำดับปิดช่องโหว่ (auth+middleware → server-side filter → IDOR ไฟล์ → callback secret) — ยืนยัน?
- [ ] วิธี login backend: session-cookie (Sanctum) หรือ token — ลูกค้ามีข้อกำหนดไหม?

## P4b/P5 และลำดับเปิดใช้จริง (ยืนยัน 2026-10-23)

ส่วนนี้ใช้แทนข้อเสนอเก่าด้านบนในเรื่องผู้รับผิดชอบร่วมและการอ่านเอกสาร

- `AUTH_ENFORCED=false` คงพฤติกรรมเดิมของการอ่าน การค้นหา และการตรวจ action; เปิด `true` หลังเตรียมข้อมูลและ index เสร็จ
- `AUTH_DEV_LOGIN=false` เป็นค่าเริ่มต้นใน `.env.example`; เปิดเฉพาะเครื่องพัฒนา ห้ามเปิดบน production แม้เครื่องพัฒนาจะใช้ `APP_ENV=production` ก็ตาม ทุก dev login บันทึก warning
- `SUPER_ADMIN_USERNAMES` เป็นรายชื่อ username คั่นด้วย comma คำนวณใหม่เมื่อ login; dev login ใช้ขั้นตอนบันทึกผู้ใช้ ตรวจบัญชีปิดใช้งาน และเลือก active unit เช่นเดียวกับ LDAP โดย UMS ล้มเหลวได้และใช้ username เป็นชื่อแทน
- `law_meta.co_editors` เป็นผู้รับผิดชอบร่วมพร้อม `actions` รายคน (`edit`, `delete`, `esign`, `publish`) ผู้จัดการได้คือ super admin หรือ inner admin ของหน่วยงานเจ้าของที่เลือก active unit ตรงกัน เจ้าของหลักเพิ่มเองไม่ได้ รายการนี้แก้ผ่าน generic document-review ไม่ได้
- ผู้รับผิดชอบร่วมต้องมี membership เป็น editor/inner_admin/outer_admin ของหน่วยงานเจ้าของ ตอนบันทึกห้ามให้ action เกินสิทธิ์ปัจจุบัน (422 พร้อมชื่อสิทธิ์ภาษาไทย) ตอนใช้งานตรวจส่วนร่วมของสิทธิ์เอกสารกับสิทธิ์ปัจจุบันและ active unit การเพิ่ม/แก้/ถอดบันทึก audit ก่อนและหลัง โอนเจ้าของยังเป็น super admin เท่านั้น ยกเลิก e-sign ใช้สิทธิ์ esign ของเอกสารและยังบันทึกผู้ส่งใน `esign.sent_by`
- เผยแพร่แล้วหมายถึง `workflow_completed_step >= 6` หรือมี `published_date`; public ที่เผยแพร่แล้วอ่านได้ทุกคน private ที่เผยแพร่แล้วอ่านได้โดย super admin, ผู้รับผิดชอบหลัก/ร่วม, สมาชิกทุกบทบาทของหน่วยงานเจ้าของ หรือสมาชิกกลุ่มที่ผูกกับเอกสาร (รหัส DEP หรือ username อย่างใดอย่างหนึ่งตรง)
- ร่างอ่านได้เฉพาะ super admin, ผู้รับผิดชอบหลัก/ร่วม และ inner admin ที่ทำแทนเจ้าของได้ใน active unit ตามกติกาเดิม การเป็นสมาชิกหน่วยงานหรือกลุ่มอย่างเดียวไม่ทำให้อ่านร่างได้ ผู้รับผิดชอบหลัก/ร่วมอ่านได้โดยไม่ต้องสลับ active unit แต่การเขียนยังต้องเลือกหน่วยงานให้ตรง
- `doc.read` ครอบ GET เอกสาร, review, versions, preview, active-children, incoming-relations, file, images, pages/image, related ZIP, related/file, esign signed/preview PDF และ export-pdf/preview; related/file ตรวจทั้งต้นทางและปลายทาง ZIP รวมเฉพาะเอกสารที่อ่านได้ ปฏิเสธด้วย 401 เมื่อยังไม่ login หรือ 403 เมื่อ login แล้ว พร้อมข้อความ `เอกสารนี้จำกัดสิทธิ์การเข้าถึง`
- ค้นหายังเห็นชื่อ private ที่เผยแพร่แล้ว แต่ `restricted`, `requires_permission`, `snippets` คำนวณตามผู้เรียกทั้ง Mongo และ Elasticsearch ร่างที่อ่านไม่ได้ถูกตัดก่อนแบ่งหน้าและนับ facets Suggest คืนเฉพาะ public ที่เผยแพร่แล้วเมื่อเปิด enforcement Export PDF/Word ที่เปลี่ยน scope เป็น public ใช้ action publish
- Permission directory ใช้ UnitStore และ MongoUserStore จริง `unit_ids` ในกลุ่มเป็นรหัส DEP และ `user_ids` เป็น username การแก้/ลบกลุ่ม index เอกสารที่เชื่อมโยงใหม่ ฟิลด์ index เพิ่ม `owner_unit_code`, `responsible_username`, `co_editor_usernames`, `allowed_unit_codes`, `allowed_usernames`, `is_published`

### สัญญา API สำหรับ frontend รอบถัดไป

`GET /api/auth/config` ไม่ต้อง login คืน 200:

```json
{"dev_login":false}
```

`POST /api/auth/dev-login` รับ:

```json
{"username":"example"}
```

เมื่อ flag ปิดคืน 404 เมื่อสำเร็จคืน 200 โดยใช้ session cookie และรูปแบบเดียวกับ LDAP login และ `/api/auth/me` ตัวอย่างผู้ใช้ที่ยังไม่มี membership:

```json
{
  "user": {
    "username":"example", "name":"example", "email":null, "position":null,
    "fac_id":null, "fac_name":null, "dep_id":null, "dep_name":null,
    "ums_fac_id":null, "ums_dep_id":null, "retired":false
  },
  "role":"user", "is_super_admin":false, "memberships":[], "active_unit":null,
  "needs_unit_selection":false, "can_upload":false, "auth_enforced":true
}
```

`PUT /api/documents/{id}/co-editors/{username}` รับ:

```json
{"actions":["edit","esign"]}
```

PUT สร้างหรือแทนรายการ action ของคนเดียว (ส่ง `[]` ได้) DELETE URL เดียวกันถอดรายชื่อโดยไม่ต้องมี body ทั้งคู่คืน 200 รูปแบบ:

```json
{
  "co_editors":[
    {"username":"example","name":"ชื่อผู้ใช้","actions":["edit","esign"],"added_by":"admin","added_at":"2026-10-23T12:00:00+07:00"}
  ],
  "can":{"edit":true,"delete":true,"esign":true,"publish":true,"transfer":false,"cancel_esign":true,"manage_co_editors":true,"read":true}
}
```

`can` ใน GET เอกสารและ review เพิ่ม `manage_co_editors` และ `read`; ค่า boolean ทั้งหมดเป็นสิทธิ์ของผู้เรียก ไม่ใช่ของผู้รับผิดชอบร่วมที่เพิ่งบันทึก `law_meta.co_editors` คืนผ่าน review ด้วย ผลค้นหาคงโครงสร้างเดิม ตัวอย่างฟิลด์ที่ขึ้นกับผู้เรียก:

```json
{"restricted":true,"requires_permission":true,"snippets":[]}
```

```json
{"restricted":false,"requires_permission":false,"snippets":["ข้อความที่ตรงกับคำค้น"]}
```

ไม่มี snippet เมื่อไม่มีคำค้นหรือไม่มีข้อความตรงกันได้ `requires_permission` เป็น true เฉพาะเมื่อ restricted และเอกสารผูกกลุ่มสิทธิ์

### Runbook P6

1. ยืนยัน LDAP ผ่าน Kong และ credential production รวมทั้ง fac/dep ของกองกฎหมายให้ใช้งานได้ กำหนด `AUTH_DEV_LOGIN=false` บน production และคง `AUTH_ENFORCED=false` ระหว่างเตรียมข้อมูล
2. สำรอง Mongo จากนั้นตรวจ `users:migrate-memberships --dry-run`, `documents:backfill-owner --dry-run`, `permissions:reset-groups --dry-run` และตรวจจำนวน/ข้อมูลก่อนรันจริง คำสั่ง reset แทน migrate-groups ตามคำตอบล่าสุด
3. รันคำสั่งจริงตามลำดับเดียวกัน reset สำรอง groups, directory และ review ที่ได้รับผลกระทบลง `storage/app/backups/permissions-reset-*.json` ก่อนล้างกลุ่ม ไดเรกทอรีจำลอง และ id กลุ่มเหล่านั้นจาก `law_meta.permission_group_ids` รันซ้ำได้โดยไม่เปลี่ยนข้อมูลเพิ่มเมื่อไม่มีรายการเดิมแล้ว dry-run ไม่เขียนหรือสำรองข้อมูล
4. สร้างกลุ่มใหม่ด้วยรหัส DEP และ username จริง แล้วรัน `laws:reindex --fresh` เพื่อให้ index ทุกฉบับมีฟิลด์สิทธิ์และสถานะเผยแพร่ใหม่ก่อนเปิด enforcement
5. ตั้ง `SUPER_ADMIN_USERNAMES` และ `AUTH_ENFORCED=true` รีโหลด config ตามวิธี deploy แล้วตรวจ user/editor/inner_admin/outer_admin และ super admin รวมทั้ง guest, ผู้รับผิดชอบร่วม, draft/private/public, snippet, related ZIP และ export publish
6. หากมีปัญหาให้ตั้ง `AUTH_ENFORCED=false` และรีโหลด config เพื่อกลับพฤติกรรมเดิมทันทีโดยไม่ต้อง rollback ข้อมูล ตรวจ index ใหม่หากการ index หลังแก้ metadata ล้มเหลว (มี warning ใน log) การแก้กลุ่มที่ index ไม่สำเร็จจะแจ้งข้อผิดพลาดและต้องแก้ไข/ลองใหม่ก่อนเปิดใช้งาน

รอบนี้ส่งเฉพาะ backend; frontend, การรัน migration/reset/reindex จริง, การเปิด flag บน production, merge และ push เป็นงานรอบถัดไป

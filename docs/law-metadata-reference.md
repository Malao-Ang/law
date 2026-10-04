# LawMeta Field Reference

Canonical reference for the document type/status fields on `LawMeta`
(`apps/app-laravel/resources/js/types/document.ts`). Purpose: stop conflating overlapping fields.
Every field below names its **single source of truth**; anything marked *derived* or *legacy mirror*
must never be treated as authoritative.

> Rule of thumb: if two fields seem to hold the same thing, one of them is a *mirror* or *derived* —
> read this file before adding logic that reads or writes either.

---

## Group 1 — Document kind (3 overlapping fields)

| Field | Meaning | Source of truth |
|---|---|---|
| `law_type` | ประเภทเอกสาร code (`LTYxx`) จาก master data `law_type` | **canonical** |
| `source` | `internal` / `external` | **derived from `law_type.family_code → law_family.source`** |
| `document_type` | `'new'` \| `'old'` = ที่มา (สร้างในระบบ / นำเข้า PDF ของเก่า) | canonical (its own axis) |
| `issuer` | ผู้ออกประกาศ code (`ISSxx`) | canonical when `law_type.requires_issuer = true` |

**Rules**
- `law_type` is the one source of truth for the legal document kind. Store/read the master-data code
  (`LTY01` etc.); labels and old Thai aliases are normalized at API boundaries.
- `source` is **derived** from `law_type.family_code` via master data `law_family.source`.
  Do not treat a stored `source` as authoritative for system-created documents — derive it. A stored
  `source` is kept only for old/imported documents, where the user picks it explicitly.
  Prefer a single helper (e.g. `sourceOf(lawType)`) over re-deriving inline.
- Unit words are also derived from the family: internal = `ข้อ`, external = `มาตรา`.
- `issuer` is required only when the selected law type has `requires_issuer = true` and must be empty
  otherwise. The merged announcement model is `law_type = LTY01` plus `issuer = ISS01|ISS02`.
- `document_type` is **not** the legal kind. `'old'` means "imported legacy PDF" only; `'new'` means
  "created in the system". Read it only for that origin distinction (e.g. show the PDF viewer for
  `'old'`). The name is kept as-is for backward compatibility — do not repurpose it.

---

## Group 2 — Singular ↔ array pairs (same value stored twice)

| Canonical (array) | Legacy mirror (singular) |
|---|---|
| `law_groups: string[]` | `law_group: string` |
| `agencies: string[]` | `agency: string` |
| `parent_document_ids: string[]` | `parent_document_id: string \| null` |

**Rules**
- The **array is canonical**. The singular field is a legacy mirror equal to `array[0]`, kept for
  backward compatibility. Never treat the singular as the source of truth.
- **Write the array.** Keep the singular in sync as `array[0]` at the save boundary
  (`LawInfoPage.buildLawMetaPayload` already sets `law_group = law_groups[0]`,
  `agency = agencies[0]`; the review-load watcher reconstructs `parent_document_ids` from the
  singular for very old records).
- **Read the array first**, falling back to the singular only for records that predate the array.
- **`law_groups` values are `DCTxxx` codes** (master data `law_category`) as of Phase 3.
  Old data migrated with `php artisan master-data:migrate law-category`. Unmigrated records may
  still contain full Thai names or slugs (`academic`, `student-affairs`, …) — `useLawCategory`
  normalizes them; the admin page `/admin/master/categories` manages the master list.

---

## Group 3 — Status axes (4 orthogonal axes; do not derive one from another)

| Field | Axis / question | Values |
|---|---|---|
| `status` | สถานะบังคับใช้ — legal enforcement | ร่าง / มีผลบังคับใช้ / ยกเลิกการใช้งาน |
| `published_date` | เผยแพร่ — visible or not | empty = ไม่เผยแพร่, has date = เผยแพร่ |
| `access_scope` | ใครเห็น — audience | `public` / `private` (+ `permission_group_ids`) |
| `change_status` + `change_details` | แก้อะไรเทียบเวอร์ชันก่อน | parent + multi-select details |

**Rules**
- These four are **independent axes** — each answers a different question. Do not derive one from
  another, with the single documented exception below.
- **Publish transition:** publishing (after e-sign) sets `status = 'มีผลบังคับใช้'` **and**
  `published_date = now`. That is the only place two axes move together.
- `change_status` stays a plain string (parent value); `change_details: string[]` holds the
  sub-selections. The version timeline and search facets read `change_status` as a string — keep it
  that way.

### Not part of LawMeta — do not conflate

| Concept | Where | Note |
|---|---|---|
| pipeline `status` | `DocumentStatus.status` (`queued`…`ingested`/`failed`) | OCR/extraction processing, **a different object** — never mix with `LawMeta.status`. |
| `StageKey` | `data/documentPipeline.ts` | Admin workflow stage; **derived** from workflow step / backend status (and, per the publication design, from `published_date`). Not stored on `LawMeta`. |

---

## Quick guardrails

- Adding a field that overlaps an existing one? Make it *derived* and document it here instead.
- Reading `source` or unit word? Derive from `law_type → law_family`, don't trust a stored value on new docs.
- Reading issuer? Use `issuer` code and resolve the label from master data; do not infer it from the law type label.
- Reading a group/agency/parent? Use the array; the singular is only `array[0]`.
- Saw "status" in code? Check **which** status — `LawMeta.status` (legal) vs `DocumentStatus.status`
  (pipeline) are unrelated.

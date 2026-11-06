# LawMeta Field Reference

Canonical reference for document type/status fields on `LawMeta`
(`apps/app-laravel/resources/js/types/document.ts`). Purpose: stop conflating overlapping fields.
Every field below names its single source of truth; anything marked derived or legacy mirror must not
be treated as authoritative.

## Master data

Metadata option lists that used to live in config are now master data stored in Mongo (`mongo.blob.master`) and read through the master-data services. Codes are canonical on writes; labels, aliases, and legacy URL slugs are accepted only as compatibility input.

| kind | code prefix | notes | admin page |
|---|---|---|---|
| `enforcement_status` | `STA` | legal status values for `LawMeta.status` | `/admin/master-data/enforcement-statuses` |
| `law_family` | `LFM` | family/source/color/unit grouping for law types | `/admin/master-data/law-types` |
| `law_type` | `LTY` | document type values saved in `law_meta.law_type` | `/admin/master-data/law-types` |
| `law_category` | `DCT` | category/group values saved in `law_groups` | `/admin/master-data/law-categories` |
| `legal_structure` | `LST` | RAG heading/chunk types | `/admin/master-data/legal-structures` |
| `change_status` | `CHG` | read-only change-status axis | `/admin/master-data/change-statuses` |
| `change_detail` | `CHD` | read-only change-detail values | `/admin/master-data/change-details` |

`change_status` and `change_detail` are read-only because relation behavior depends on `attrs.role`; do not add, edit, deactivate, or reorder them through the admin API. Other kinds may be administered from their pages, but code prefixes remain fixed.

Alias rule: never persist aliases as canonical values. Services may accept Thai names, old English aliases, legacy slugs, and admin-added aliases, but storage and new examples should use codes. Seed upgrades may add missing seed aliases to existing rows idempotently; they must not remove admin-added aliases or rewrite names.

Runbook:

1. Back up Mongo first: `mongodump --db poc --archive=backups/poc-master-data-$(date +%Y%m%d%H%M%S).archive --gzip`.
2. Audit a kind: `php artisan master-data:migrate <kind> --dry-run`.
3. Apply only after reviewing the dry run: `php artisan master-data:migrate <kind>`.
4. Refresh search after metadata changes: `php artisan laws:reindex --fresh`.

## Document Kind

| Field | Meaning | Source of truth |
|---|---|---|
| `law_type` | ประเภทเอกสาร code (`LTYxx`) from master data `law_type` | canonical when a specific type is known |
| `source` | `internal` / `external` | derived from `law_type.family_code -> law_family.source` |
| `document_type` | `'new'` / `'old'` = created in system / imported legacy PDF | canonical for origin only |

`law_type` stores a type code, not a family code. The display hierarchy is:

```text
law_family (LFMxx) -> law_type (LTYxx)
```

Families drive color, source, unit word, homepage sections, and family-level filters. Types are the
values saved on documents.

Announcement types are now explicit:

| code | title | family |
|---|---|---|
| `LTY01` | ประกาศที่ออกโดยมหาวิทยาลัย | `LFM03` ประกาศ |
| `LTY09` | ประกาศที่ออกโดยสภามหาวิทยาลัย | `LFM03` ประกาศ |

There is no issuer lookup, no `requires_issuer`, and `law_meta.issuer` is ignored by the API. Legacy
payloads may still carry `issuer`; use it only during migration/form-load compatibility.

Bare `ประกาศ` is a family-level legacy value, not a type. It resolves to family `LFM03` for badges,
sections, and unit words, but it does not auto-select `LTY01` or `LTY09`.

## Source And Units

- `source` is derived from the family: `internal` = ภายใน, `external` = ภายนอก.
- Unit words are also derived from the family: `internal` = `ข้อ`, `external` = `มาตรา`.
- For old/imported documents, a stored `source` can be used as fallback while the user selects a
  valid type.

## Legal Structure Headings

RAG section heads store `block.meta.chunk_type` as a legal-structure master-data code (`LSTxxx`).
The old uppercase keys are legacy aliases only; new writes should persist the `LST` code.

| code | title | families | file types | head | counts as section | export key |
|---|---|---|---|---|---|---|
| `LST001` | ชื่อประกาศ | `LFM01`, `LFM02`, `LFM03`, `LFM04` | `word`, `pdf` | yes | no | `TITLE` |
| `LST002` | คำปรารภ | `LFM01`, `LFM02`, `LFM03`, `LFM04` | `word`, `pdf` | yes | no | `PREAMBLE` |
| `LST003` | บทอาศัยอำนาจ | `LFM01`, `LFM02`, `LFM03` | `word`, `pdf` | yes | no | `AUTHORITY` |
| `LST004` | ข้อ | `LFM01`, `LFM02`, `LFM03` | `word`, `pdf` | yes | yes | `CLAUSE` |
| `LST005` | วันบังคับใช้ | `LFM01`, `LFM02`, `LFM03`, `LFM04` | `word`, `pdf` | yes | no | `EFFECTIVE_DATE` |
| `LST006` | บทยกเลิก | `LFM01`, `LFM02` | `word`, `pdf` | yes | no | `REPEAL` |
| `LST007` | บทนิยาม | `LFM01`, `LFM02`, `LFM03`, `LFM04` | `word`, `pdf` | yes | no | `DEFINITION_SECTION` |
| `LST008` | คำนิยาม | `LFM01`, `LFM02`, `LFM03`, `LFM04` | `word`, `pdf` | no | no | `DEFINITION` |
| `LST009` | บทรักษาการ | `LFM01`, `LFM02`, `LFM03`, `LFM04` | `word`, `pdf` | yes | no | `CUSTODIAN` |
| `LST010` | บทเฉพาะกาล | `LFM01`, `LFM02`, `LFM03`, `LFM04` | `word`, `pdf` | yes | no | `TRANSITIONAL_PROVISION` |
| `LST011` | มาตรา | `LFM04` | `word`, `pdf` | yes | yes | `SECTION` |
| `LST012` | หมวด/ส่วน | `LFM01`, `LFM02`, `LFM03`, `LFM04` | `word`, `pdf` | yes | no | `CHAPTER` |

Legal-structure attrs:

| attr | meaning |
|---|---|
| `family_codes` | Law families where the heading is available. `LST004` is internal families only; `LST011` is external law only. |
| `file_types` | Supported source file families: `word` for `doc`/`docx`, `pdf` for `pdf`, `pdf_text`, `pdf_scan`, `pdf_mixed`. |
| `is_head` | Whether the type starts a RAG section. |
| `counts_as_section` | Whether law-info section count includes this head. |
| `is_required` | If true, the RAG step blocks “next” until this supported heading exists and its head block has non-empty text. |
| `color` | Vuetify color used for chips. |
| `export_key` | Legacy/export key included in RAG export metadata. |

Legacy aliases resolve as follows: `ARTICLE`, `PARAGRAPH`, `ITEM`, and `CLAUSE` -> `LST004`;
`SECTION` -> `LST011`; `CHAPTER`, `BOOK`, and `PART` -> `LST012`; the other old keys map to
their matching seed rows above.

## Singular And Array Pairs

| Canonical array | Legacy mirror |
|---|---|
| `law_groups: string[]` | `law_group: string` |
| `agencies: string[]` | `agency: string` |
| `parent_document_ids: string[]` | `parent_document_id: string | null` |

Use the array first. The singular field is a legacy mirror equal to `array[0]`.

`law_groups` values are `DCTxxx` codes from master data `law_category`. Unmigrated records may still
contain Thai names or older slugs; `useLawCategory` normalizes them.

## Status Axes

| Field | Axis / question |
|---|---|
| `status` | legal enforcement state |
| `published_date` | visible/published or not |
| `access_scope` | who can see it |
| `change_status` + `change_details` | what changed versus a previous version |

These axes are independent. The only documented coupled transition is publish: after e-sign,
publishing sets legal `status` to in-force and sets `published_date`.

## Change Status Master Data

`law_meta.change_status` stores a `change_status` master-data code (`CHGxx`). `law_meta.change_details`
stores an array of `change_detail` codes (`CHDxx`) when the selected status has details.

`change_status` rows:

| code | title | source | has_details | role |
|---|---|---|---|---|
| `CHG01` | กฎหมายใหม่ | `both` | no | `new` |
| `CHG02` | ปรับปรุงทั้งฉบับ | `both` | no | `whole` |
| `CHG03` | ปรับปรุงรายข้อ | `internal` | yes | `section` |
| `CHG04` | ปรับปรุงรายมาตรา | `external` | yes | `section` |

`change_detail` rows:

| code | title | source | has_details | role |
|---|---|---|---|---|
| `CHD01` | ยกเลิกข้อ | `internal` | no | `repeals` |
| `CHD02` | ยกเลิกมาตรา | `external` | no | `repeals` |
| `CHD03` | เพิ่มข้อความ | `both` | no | `amends` |
| `CHD04` | แก้ไขข้อความ | `both` | no | `amends` |

Aliases resolve before saving: `ยกเลิกทั้งฉบับ` -> `CHG02`, `ยกเลิกรายมาตรา` -> `CHG04`,
`กฎหมายล่าสุด` -> `CHG01`, `ยกเลิก` -> `CHD01`, `เพิ่ม` -> `CHD03`, and `แก้ไข` -> `CHD04`.

CHG/CHD master data is read-only because relation behavior reads `attrs.role`. The admin API returns
403 for POST, PUT, active PATCH, and reorder PATCH on `change_status` and `change_detail`. Relation
logic must read `role`, not Thai labels. `relations[].change_detail` remains free text for relation
notes/history and is not rewritten by this master-data migration.

## Migration Guardrail

`php artisan master-data:migrate law-type` treats bare `ประกาศ` and legacy `LTY01` without a known
legacy issuer as `NEEDS_FORM`: it reports document id + title and exits successfully without writing
a guessed type. Users must choose either `LTY01` or `LTY09` in the law-info form.

`php artisan master-data:migrate legal-structure --dry-run` audits stored `block.meta.chunk_type`
values and reports the target `LST` code. Run without `--dry-run` to rewrite legacy values in review
documents. Use `--map="legacy value=LSTxxx"` for project-specific legacy labels; unmapped values abort
without writing.

`php artisan master-data:migrate change-status [--dry-run] [--map="legacy=CHGxx"]` audits and rewrites
`law_meta.change_status` and `law_meta.change_details[]` to CHG/CHD codes. Use `--dry-run` before
writing; unmapped values abort without writing.

Legacy mappings that are still deterministic:

| legacy value | target |
|---|---|
| `คำสั่ง`, `ประกาศที่ออกโดยมหาวิทยาลัย`, `ประกาศ`/`LTY01` + legacy `มหาวิทยาลัย`/`ISS01` | `LTY01` |
| `มติ`, `ประกาศที่ออกโดยสภามหาวิทยาลัย`, `ประกาศ`/`LTY01` + legacy `สภามหาวิทยาลัย`/`ISS02` | `LTY09` |

## Quick Guardrails

- Need label/color/source/unit? Resolve `law_type -> law_family`.
- Need relation restrictions? Only `LTY01` and `LTY09` have special parent rules.
- Need announcement issuer? It is part of the selected type title now.
- Saw `status` in code? Check which status: `LawMeta.status` is legal, `DocumentStatus.status` is
  pipeline processing.

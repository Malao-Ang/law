# Lookup Master Data (document types / agencies / law groups) — Design

**Date:** 2026-07-12
**Scope:** Centralize the three lookup lists used in the Law Info form and elsewhere into one canonical source served by a read API.

## Problem

Three reference lists are hardcoded as frontend constants in `LawInfoPage.vue`:

- `DOC_TYPES` (ประเภทเอกสาร) — line 227
- `LAW_GROUP_OPTIONS` (กลุ่มกฎหมาย) — lines 236-249
- `AGENCY_OPTIONS` (หน่วยงาน) — lines 250-262

Consequences:
- No single source of truth — any other screen needing these must re-declare them.
- No API — nothing can read them server-side or from another client.
- Vocabulary mismatch: `SeedSampleLawsCommand` stores `law_type` as **codes**
  (`phrb`, `rabiap`, `prakat`) while the form stores/display the Thai **labels**
  (`พ.ร.บ.`, `ระเบียบ`, `ประกาศ`). A seeded doc's type never matches the dropdown.

## Goals

- One canonical definition of each list, used everywhere (form, seed, future screens).
- A read-only API the frontend loads from instead of hardcoded arrays.
- Present on every fresh start with no seeding step — the data cannot go missing.
- Fix the `law_type` code/label mismatch so seeded docs align with the form.

## Non-goals (explicitly excluded)

- Admin CRUD editing of the lists at runtime.
- Storing the lists in MongoDB.
- Migrating already-stored `law_meta.law_type` values.

**Upgrade path (documented, not built):** if admin runtime-editing is later needed,
move the canonical lists into a MongoDB collection seeded from this same config on
first boot and add CRUD. The API response contract stays identical, so the frontend
does not change.

## Key decision — canonical value

The stored value stays the **Thai string already used by the form today**
(`value` === display label for document types; Thai name for agencies / law groups).
This is already consistent across the form and Elasticsearch for agencies and law
groups. Choosing Thai strings as the canonical value means **no data migration** of
existing documents. The only fix needed is the seed command's `law_type`, which is
changed from codes to the same Thai labels.

(A separate stable `code` key is deliberately *not* introduced now — it would force a
migration of existing `law_type` values for no current benefit. It is part of the
upgrade path above.)

## Architecture

### Server

- **`config/lookups.php`** — canonical arrays. Each item: `{ value, label, subtitle? }`.
  Seed the exact contents currently in `LawInfoPage.vue`:
  - `document_types`: 6 items (`value` === `label`, no subtitle).
  - `agencies`: 11 items (with `subtitle`).
  - `law_groups`: 12 items (with `subtitle`).
- **`LookupController`** (single-action, invokable) → `GET /api/lookups` returns:
  ```json
  { "document_types": [...], "agencies": [...], "law_groups": [...] }
  ```
  Reads straight from `config('lookups')`. No storage, no side effects.
- **Route:** `Route::get('/lookups', LookupController::class);` in `routes/api.php`.

### Frontend

- **`resources/js/api/client.ts`** — add `getLookups(): Promise<LookupData>`.
- **`resources/js/composables/useLookups.ts`** — module-level cached fetch (fetch
  once per page load, share across components). Exposes reactive
  `documentTypes`, `agencies`, `lawGroups`.
- **`LawInfoPage.vue`** — delete the three hardcoded consts; bind the selects to the
  composable's lists. Item shape (`value`/`label`/`subtitle`) matches the existing
  `SelectableOption`, so the templates need no structural change.

### Seed alignment

- **`SeedSampleLawsCommand`** — change the three samples' `law_type` from
  `phrb`/`rabiap`/`prakat` to `พ.ร.บ.`/`ระเบียบ`/`ประกาศ`, matching
  `config('lookups.document_types')`. Their `agency` values already match agency names.

## Files touched

- Create: `apps/app-laravel/config/lookups.php`
- Create: `apps/app-laravel/app/Http/Controllers/Api/LookupController.php`
- Create: `apps/app-laravel/resources/js/composables/useLookups.ts`
- Modify: `apps/app-laravel/routes/api.php` — add route
- Modify: `apps/app-laravel/resources/js/api/client.ts` — add `getLookups`
- Modify: `apps/app-laravel/resources/js/pages/law-info/LawInfoPage.vue` — consume composable
- Modify: `apps/app-laravel/app/Console/Commands/SeedSampleLawsCommand.php` — align `law_type`
- Test: `apps/app-laravel/tests/Feature/LookupApiTest.php`

## Testing

- **PHP:** `GET /api/lookups` returns 200 with all three keys, each a non-empty array
  whose items have `value` and `label`; assert a known item
  (`พ.ร.บ.` in document_types, `มหาวิทยาลัยบูรพา` in agencies) is present. Guarantees
  "never missing on fresh start".
- **PHP:** each seeded sample's `law_type` exists in `config('lookups.document_types')`
  values (no code/label drift).

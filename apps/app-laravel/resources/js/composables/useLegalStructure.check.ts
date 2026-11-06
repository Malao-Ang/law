import { createLegalStructureCatalog } from './useLegalStructure';
import type { LegalStructureOption } from '../api/client';

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error('FAIL: ' + msg);
}

const catalog = createLegalStructureCatalog();

assert(catalog.normalize('CLAUSE') === 'LST004', 'CLAUSE normalizes to LST004');
assert(catalog.normalize('ARTICLE') === 'LST004', 'ARTICLE normalizes to LST004');
assert(catalog.normalize('PARAGRAPH') === 'LST004', 'PARAGRAPH normalizes to LST004');
assert(catalog.normalize('ITEM') === 'LST004', 'ITEM normalizes to LST004');
assert(catalog.normalize('SECTION') === 'LST011', 'SECTION normalizes to LST011');
assert(catalog.normalize('CHAPTER') === 'LST012', 'CHAPTER normalizes to LST012');
assert(catalog.normalize('BOOK') === 'LST012', 'BOOK normalizes to LST012');
assert(catalog.normalize('PART') === 'LST012', 'PART normalizes to LST012');
assert(catalog.label('LST011') === 'มาตรา', 'labels use fallback seeds');
assert(catalog.color('LST004') === 'orange', 'colors use fallback seeds');
assert(catalog.isHead('LST008') === false, 'definition is not a head');
assert(catalog.countsAsSection('LST011') === true, 'LST011 counts as section');
assert(catalog.fileTypeOf('docx') === 'word', 'docx maps to word');
assert(catalog.fileTypeOf('pdf_mixed') === 'pdf', 'pdf_mixed maps to pdf');
assert(catalog.optionsFor('LFM03', 'word').some((item) => item.code === 'LST004'), 'internal announcement supports ข้อ');
assert(!catalog.optionsFor('LFM04', 'pdf').some((item) => item.code === 'LST004'), 'external law does not support ข้อ');
assert(catalog.optionsFor('LFM04', 'pdf').some((item) => item.code === 'LST011'), 'external law supports มาตรา');
assert(catalog.supports('SECTION', 'LFM04', 'word'), 'legacy SECTION supports external laws');
assert(!catalog.supports('SECTION', 'LFM01', 'word'), 'legacy SECTION does not support internal families');

const customItems: LegalStructureOption[] = [
  {
    code: 'LST013',
    value: 'LST013',
    title: 'ภาค',
    family_codes: ['LFM04'],
    file_types: ['pdf'],
    is_head: true,
    counts_as_section: false,
    is_required: true,
    color: 'purple',
    export_key: 'DIVISION',
    sort_order: 99,
  },
];
const customCatalog = createLegalStructureCatalog({ items: customItems, itemsAll: customItems });
assert(customCatalog.normalize('DIVISION') === 'LST013', 'export_key from lookups normalizes');
assert(customCatalog.isRequired('LST013'), 'isRequired reads lookup attrs');
assert(customCatalog.optionsFor('LFM04', 'pdf')[0].code === 'LST013', 'custom options come from active lookups');

console.log('OK: legal structure catalog checks passed');

import { createLawCategoryCatalog } from './useLawCategory';

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error(`FAIL: ${msg}`);
}

const catalog = createLawCategoryCatalog();

// code lookup
assert(catalog.categoryItem('DCT001')?.title === 'ด้านวิชาการ การผลิตบัณฑิต การเรียนรู้ตลอดชีวิต และการบริหารหลักสูตร', 'DCT001 resolves to correct title');
assert(catalog.categoryItem('DCT012')?.code === 'DCT012', 'DCT012 resolves by code');

// slug alias
assert(catalog.categoryItem('academic')?.code === 'DCT001', 'slug academic resolves to DCT001');
assert(catalog.categoryItem('student-affairs')?.code === 'DCT002', 'slug student-affairs resolves to DCT002');
assert(catalog.categoryItem('research-innovation')?.code === 'DCT003', 'slug research-innovation resolves to DCT003');
assert(catalog.categoryItem('academic-service')?.code === 'DCT004', 'slug academic-service resolves to DCT004');
assert(catalog.categoryItem('organization-admin')?.code === 'DCT006', 'slug organization-admin resolves to DCT006');
assert(catalog.categoryItem('hr-discipline')?.code === 'DCT007', 'slug hr-discipline resolves to DCT007');
assert(catalog.categoryItem('finance-assets-risk')?.code === 'DCT008', 'slug finance-assets-risk resolves to DCT008');
assert(catalog.categoryItem('other')?.code === 'DCT012', 'slug other resolves to DCT012');

// full Thai name lookup
assert(catalog.categoryItem('ด้านกิจการนิสิต')?.code === 'DCT002', 'full Thai name resolves to DCT002');
assert(catalog.categoryItem('ด้านอื่น ๆ')?.code === 'DCT012', 'full Thai name ด้านอื่น ๆ resolves to DCT012');

// categoryLabel - falls back to raw input for unknown values
assert(catalog.categoryLabel('DCT001') === 'ด้านวิชาการ การผลิตบัณฑิต การเรียนรู้ตลอดชีวิต และการบริหารหลักสูตร', 'DCT001 label resolves');
assert(catalog.categoryLabel('academic') === 'ด้านวิชาการ การผลิตบัณฑิต การเรียนรู้ตลอดชีวิต และการบริหารหลักสูตร', 'slug academic label resolves');
assert(catalog.categoryLabel('unknown-code') === 'unknown-code', 'unknown code falls back to raw input');
assert(catalog.categoryLabel('') === '', 'empty string returns empty');
assert(catalog.categoryLabel(null) === '', 'null returns empty');

// categoryLabels - dedupes, keeps order
const labels = catalog.categoryLabels(['DCT001', 'DCT002', 'DCT001']);
assert(labels.length === 2, 'categoryLabels dedupes');
assert(labels[0] === 'ด้านวิชาการ การผลิตบัณฑิต การเรียนรู้ตลอดชีวิต และการบริหารหลักสูตร', 'categoryLabels first item correct');
assert(labels[1] === 'ด้านกิจการนิสิต', 'categoryLabels second item correct');

// categoryOptions sorted by sort_order
const opts = catalog.categoryOptions.value;
assert(opts.length === 12, 'categoryOptions has 12 items');
assert(opts[0].code === 'DCT001', 'categoryOptions first is DCT001');
assert(opts[11].code === 'DCT012', 'categoryOptions last is DCT012');

// normalizeCategory
assert(catalog.normalizeCategory('academic') === 'DCT001', 'normalizeCategory slug -> code');
assert(catalog.normalizeCategory('DCT003') === 'DCT003', 'normalizeCategory code -> same code');
assert(catalog.normalizeCategory('ด้านกิจการนิสิต') === 'DCT002', 'normalizeCategory Thai name -> code');
assert(catalog.normalizeCategory('unknown') === 'unknown', 'normalizeCategory unknown -> raw');
assert(catalog.normalizeCategory('') === '', 'normalizeCategory empty -> empty');

console.log('PASS: useLawCategory maps');

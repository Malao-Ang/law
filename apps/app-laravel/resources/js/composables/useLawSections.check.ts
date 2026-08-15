// ponytail: dev-only assertion — no JS test runner in this app. Run:
//   cd apps/app-laravel && npx tsx resources/js/composables/useLawSections.check.ts
import { buildSections } from './useLawSections';
import type { DocumentBlock, ReviewDocument } from '../types/document';

let n = 0;
const mk = (over: Partial<DocumentBlock>): DocumentBlock =>
  ({
    block_id: `b${n++}`,
    type: 'paragraph',
    raw_text: '',
    normalized_text: '',
    ai_suggested_text: '',
    approved_text: '',
    meta: {},
    ...over,
  }) as unknown as DocumentBlock;

const doc = (blocks: DocumentBlock[]): ReviewDocument =>
  ({ pages: [{ page_no: 1, blocks }] }) as unknown as ReviewDocument;

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error('FAIL: ' + msg);
}

// 1. image + header lines + divider + body → one header section, then normal
{
  n = 0;
  const s = buildSections(
    doc([
      mk({ type: 'image' }),
      mk({ approved_text: 'กระทรวงมหาดไทย' }),
      mk({ approved_text: 'ประกาศ เรื่อง ทดสอบ' }),
      mk({ approved_text: '----' }),
      mk({ approved_text: 'มาตรา ๑ ข้อความ' }),
      mk({ approved_text: 'เนื้อหาต่อ' }),
    ]),
  );
  assert(s.length === 2, 'expected header + one body section, got ' + s.length);
  assert(s[0].isHeader === true, 'first section isHeader');
  assert(s[0].badge === 'ชื่อประกาศ', 'header badge is ชื่อประกาศ, got ' + s[0].badge);
  assert(s[0].children.length === 3, 'header absorbs 3 children incl divider, got ' + s[0].children.length);
  assert(s[1].badge.startsWith('มาตรา'), 'body section starts at มาตรา, got ' + s[1].badge);
}

// 2. no image before divider → no header section (unchanged behaviour)
{
  n = 0;
  const s = buildSections(
    doc([
      mk({ approved_text: 'บันทึกข้อความ' }),
      mk({ approved_text: '----' }),
      mk({ approved_text: 'มาตรา ๑ ข้อความ' }),
    ]),
  );
  assert(!s.some((x) => x.isHeader), 'no header section without a leading image');
}

// 3. explicit head chunk_type inside region → region breaks before it
{
  n = 0;
  const s = buildSections(
    doc([
      mk({ type: 'image' }),
      mk({ approved_text: 'ประกาศ', meta: { chunk_type: 'ARTICLE' } as DocumentBlock['meta'] }),
      mk({ approved_text: '----' }),
    ]),
  );
  assert(s[0].isHeader === true, 'still emits a header section');
  assert(s[0].children.length === 0, 'region stops before the promoted head, got ' + s[0].children.length);
}

// 4. divider at index 0 → no image before it → no header section
{
  n = 0;
  const s = buildSections(
    doc([
      mk({ approved_text: '----' }),
      mk({ type: 'image' }),
      mk({ approved_text: 'มาตรา ๑ ข้อความ' }),
    ]),
  );
  assert(!s.some((x) => x.isHeader), 'no header section when divider precedes the image');
}

// 5. image only AFTER the first divider → not part of the header region
{
  n = 0;
  const s = buildSections(
    doc([
      mk({ approved_text: 'บันทึกข้อความ' }),
      mk({ approved_text: '----' }),
      mk({ type: 'image' }),
      mk({ approved_text: 'มาตรา ๑ ข้อความ' }),
    ]),
  );
  assert(!s.some((x) => x.isHeader), 'no header section when the image is after the divider');
}

// 6. legacy ARTICLE and current CLAUSE chunk types both start their own sections
{
  n = 0;
  const s = buildSections(
    doc([
      mk({ approved_text: 'มาตรา ๑ บททั่วไป', meta: { chunk_type: 'ARTICLE' } as DocumentBlock['meta'] }),
      mk({ approved_text: 'เนื้อหามาตรา ๑' }),
      mk({ approved_text: 'ข้อ ๒ รายละเอียด', meta: { chunk_type: 'CLAUSE' } as DocumentBlock['meta'] }),
      mk({ approved_text: 'เนื้อหาข้อ ๒' }),
    ]),
  );
  assert(s.length === 2, 'ARTICLE/CLAUSE should create two sections, got ' + s.length);
  assert(s[0].badge === 'มาตรา ๑', 'legacy ARTICLE badge keeps มาตรา marker, got ' + s[0].badge);
  assert(s[0].children.length === 1, 'ARTICLE section has one child, got ' + s[0].children.length);
  assert(s[1].badge === 'ข้อ ๒', 'CLAUSE badge keeps ข้อ marker, got ' + s[1].badge);
  assert(s[1].children.length === 1, 'CLAUSE section has one child, got ' + s[1].children.length);
}

// 7. legal list markers start sections even when extracted text lacks the marker
{
  n = 0;
  const s = buildSections(
    doc([
      mk({
        approved_text: 'บททั่วไป',
        meta: { list_marker: { type: 'legal-มาตรา', text: 'มาตรา ๓', raw_match: 'มาตรา ๓', level: 0 } } as DocumentBlock['meta'],
      }),
      mk({
        approved_text: 'รายละเอียด',
        meta: { list_marker: { type: 'legal-ข้อ', text: 'ข้อ ๔', raw_match: 'ข้อ ๔', level: 1 } } as DocumentBlock['meta'],
      }),
    ]),
  );
  assert(s.length === 2, 'legal markers should create two sections, got ' + s.length);
  assert(s[0].badge === 'มาตรา ๓', 'uses legal-มาตรา marker, got ' + s[0].badge);
  assert(s[1].badge === 'ข้อ ๔', 'uses legal-ข้อ marker, got ' + s[1].badge);
}

console.log('OK: all header-grouping checks passed');

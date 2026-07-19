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
  assert(s[0].badge === 'ชื่อกฎหมาย', 'header badge is ชื่อกฎหมาย, got ' + s[0].badge);
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

console.log('OK: all header-grouping checks passed');

import type { DocumentBlock, LawRelation, ReviewDocument } from '../types/document';
import { CHUNK_TYPE_LABELS, HEAD_CHUNK_TYPES } from '../types/chunkType';
import type { ChunkType } from '../types/chunkType';

export interface LawSection {
  id: string;
  badge: string;
  headBlock: DocumentBlock;
  headBodyText: string;
  children: DocumentBlock[];
  isChapter: boolean;
}

export interface TocGroup {
  label: string;
  sectionIds: string[];
}

const HEAD_RE = /^(คำปรารภ|บทเฉพาะกาล|หมวด\s*[๐-๙0-9]+|ส่วนที่\s*[๐-๙0-9]+|มาตรา\s*[๐-๙0-9]+(?:\/[๐-๙0-9]+)?|ข้อ\s*[๐-๙0-9]+(?:\.[๐-๙0-9]+)*)/u;
const CHAPTER_RE = /^(หมวด|ส่วนที่|บทเฉพาะกาล)\s*/u;

// Structural heading chunk-types: assigning one makes a block a section head,
// so the following blocks group under it without merging text.
const HEAD_CHUNK_TYPE_SET = new Set<string>(HEAD_CHUNK_TYPES);

function blockText(block: DocumentBlock): string {
  return (block.approved_text || block.normalized_text || block.raw_text || '').trim();
}

function isHead(block: DocumentBlock): boolean {
  const ct = block.meta?.chunk_type;
  if (ct) return HEAD_CHUNK_TYPE_SET.has(ct); // explicit type wins both ways

  if (block.type === 'title' || block.type === 'section_header') return true;

  return HEAD_RE.test(blockText(block));
}

// Content-word → chunk type. First matching rule wins; null when nothing matches.
const SUGGEST_RULES: ReadonlyArray<[RegExp, ChunkType]> = [
  [/^ภาคผนวก/u, 'ANNEX'],
  [/^บทเฉพาะกาล/u, 'TRANSITIONAL_PROVISION'],
  [/^ภาค\s*[๐-๙0-9]/u, 'BOOK'],
  [/^ลักษณะ\s*[๐-๙0-9]/u, 'PART'],
  [/^หมวด\s*[๐-๙0-9]/u, 'CHAPTER'],
  [/^ส่วนที่\s*[๐-๙0-9]/u, 'SECTION'],
  [/^มาตรา\s*[๐-๙0-9]/u, 'ARTICLE'],
  [/^ข้อ\s*[๐-๙0-9]/u, 'ARTICLE'],
  [/^คำปรารภ/u, 'PREAMBLE'],
];

export function suggestChunkType(block: DocumentBlock): ChunkType | null {
  const text = blockText(block);
  for (const [re, type] of SUGGEST_RULES) {
    if (re.test(text)) return type;
  }
  return null;
}

function markerFor(block: DocumentBlock): string {
  const text = blockText(block);
  const match = text.match(HEAD_RE);
  if (match) return match[1].replace(/\s+/g, ' ').trim(); // number comes from the text

  const ct = block.meta?.chunk_type;
  if (ct && CHUNK_TYPE_LABELS[ct as ChunkType]) return CHUNK_TYPE_LABELS[ct as ChunkType];
  if (block.type === 'title') return 'คำปรารภ';

  return blockText(block).slice(0, 24);
}

export function buildSections(review: ReviewDocument | null): LawSection[] {
  if (!review) return [];

  const blocks = review.pages.flatMap((page) => page.blocks);
  const sections: LawSection[] = [];
  let current: LawSection | null = null;

  for (const block of blocks) {
    if (isHead(block) || current === null) {
      const badge = markerFor(block);
      const text = blockText(block);
      current = {
        id: block.block_id,
        badge,
        headBlock: block,
        headBodyText: text.startsWith(badge) ? text.slice(badge.length).trim() : text,
        children: [],
        isChapter: CHAPTER_RE.test(text),
      };
      sections.push(current);
      continue;
    }

    current.children.push(block);
  }

  return sections;
}

export function buildTocGroups(sections: LawSection[]): TocGroup[] {
  const groups: TocGroup[] = [];
  let current: TocGroup = { label: 'คำปรารภ / มาตราทั่วไป', sectionIds: [] };
  groups.push(current);

  for (const section of sections) {
    if (section.isChapter) {
      current = {
        label: `${section.badge} ${section.headBodyText}`.trim(),
        sectionIds: [section.id],
      };
      groups.push(current);
    } else {
      current.sectionIds.push(section.id);
    }
  }

  return groups.filter((group) => group.sectionIds.length > 0);
}

export function relationsForSection(relations: LawRelation[] | undefined, sectionId: string): LawRelation[] {
  return (relations ?? []).filter((r) => r.scope === 'section' && r.block_id === sectionId);
}

export function documentRelations(relations: LawRelation[] | undefined): LawRelation[] {
  return (relations ?? []).filter((r) => r.scope === 'document');
}

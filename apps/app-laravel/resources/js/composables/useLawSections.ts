import type { DocumentBlock, LawRelation, ReviewDocument } from '../types/document';
import type { ChunkType } from '../types/chunkType';
import { normalizeChunkType } from '../types/chunkType';
import { createLegalStructureCatalog } from './useLegalStructure';
import { createLawTypeCatalog } from './useLawType';

export interface LawSection {
  id: string;
  badge: string;
  headBlock: DocumentBlock;
  headBodyText: string;
  children: DocumentBlock[];
  isChapter: boolean;
  isHeader?: boolean;
}

export interface TocGroup {
  label: string;
  sectionIds: string[];
}

const HEAD_RE = /^(คำปรารภ|บทเฉพาะกาล|หมวด\s*[๐-๙0-9]+|ส่วนที่\s*[๐-๙0-9]+|มาตรา\s*[๐-๙0-9]+(?:\/[๐-๙0-9]+)?|ข้อ\s*[๐-๙0-9]+(?:\.[๐-๙0-9]+)*)/u;
const CHAPTER_RE = /^(หมวด|ส่วนที่|บทเฉพาะกาล)\s*/u;
const lawTypes = createLawTypeCatalog();
const legalStructures = createLegalStructureCatalog();

// Structural heading chunk-types: assigning one makes a block a section head,
// so the following blocks group under it without merging text.

// A block that "displays as a divider": its text is only a run of dashes/underscores.
const DIVIDER_RE = /^[-–—_─]{2,}\s*$/u;

function isDivider(block: DocumentBlock): boolean {
  return DIVIDER_RE.test(blockText(block));
}

// Index (inclusive) of the last block of the leading header region, or -1 when
// there is nothing to auto-group. Region = blocks[0 … first divider], but only
// when an image appears at/before that divider. Stops early before any block the
// user has explicitly promoted to a structural head (reversibility escape hatch).
function headerRegionEnd(blocks: DocumentBlock[]): number {
  const dividerIdx = blocks.findIndex(isDivider);
  if (dividerIdx < 0) return -1;
  if (!blocks.slice(0, dividerIdx + 1).some((b) => b.type === 'image')) return -1;
  for (let i = 1; i <= dividerIdx; i += 1) {
    const ct = normalizeChunkType(blocks[i].meta?.chunk_type);
    if (ct && legalStructures.isHead(ct)) return i - 1;
  }
  return dividerIdx;
}

function blockText(block: DocumentBlock): string {
  return (block.approved_text || block.normalized_text || block.raw_text || '').trim();
}

function legalMarkerText(block: DocumentBlock): string | null {
  const marker = block.meta?.list_marker;
  if (marker?.type !== 'legal-มาตรา' && marker?.type !== 'legal-ข้อ') return null;
  return (marker.text || marker.raw_match || '').trim() || null;
}

function isHead(block: DocumentBlock): boolean {
  const ct = block.meta?.chunk_type;
  if (ct) return legalStructures.isHead(ct); // explicit type wins both ways

  if (legalMarkerText(block)) return true;

  if (block.type === 'title' || block.type === 'section_header') return true;

  return HEAD_RE.test(blockText(block));
}

// Content-word → chunk type. First matching rule wins; null when nothing matches.
const SUGGEST_RULES: ReadonlyArray<[RegExp, ChunkType]> = [
  [/^ชื่อประกาศ/u, 'LST001'],
  [/^คำปรารภ/u, 'LST002'],
  [/^บทนิยาม/u, 'LST007'],
  [/^บทเฉพาะกาล/u, 'LST010'],
  [/อาศัยอำนาจ/u, 'LST003'],
  [/(วันบังคับใช้|บังคับใช้|ใช้บังคับ)/u, 'LST005'],
  [/(ให้ยกเลิก|ยกเลิก)/u, 'LST006'],
  [/รักษาการ/u, 'LST009'],
  [/^คำนิยาม/u, 'LST008'],
  [/^นิยาม/u, 'LST008'],
  [/^มาตรา\s*[๐-๙0-9]/u, 'LST011'],
  [/^ข้อ\s*[๐-๙0-9]/u, 'LST004'],
  [/^(หมวด|ส่วนที่)\s*[๐-๙0-9]/u, 'LST012'],
];

export function suggestChunkType(block: DocumentBlock): ChunkType | null {
  const marker = legalMarkerText(block);
  if (marker) return marker.startsWith('มาตรา') ? 'LST011' : 'LST004';

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

  const marker = legalMarkerText(block);
  if (marker) return marker.replace(/\s+/g, ' ').trim();

  const ct = normalizeChunkType(block.meta?.chunk_type);
  if (ct) return legalStructures.label(ct);
  if (block.type === 'title') return 'ชื่อประกาศ';

  return blockText(block).slice(0, 24);
}

export function buildSections(review: ReviewDocument | null): LawSection[] {
  if (!review) return [];

  const blocks = review.pages.flatMap((page) => page.blocks);
  const sections: LawSection[] = [];

  let startIndex = 0;
  const headerEnd = headerRegionEnd(blocks);
  if (headerEnd >= 0) {
    const headBlock = blocks[0];
    const text = blockText(headBlock);
    const badge = 'ชื่อประกาศ';
    sections.push({
      id: headBlock.block_id,
      badge,
      headBlock,
      headBodyText: text.startsWith(badge) ? text.slice(badge.length).trim() : text,
      children: blocks.slice(1, headerEnd + 1),
      isChapter: false,
      isHeader: true,
    });
    startIndex = headerEnd + 1;
  }

  let current: LawSection | null = null;
  for (let i = startIndex; i < blocks.length; i += 1) {
    const block = blocks[i];
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
  let current: TocGroup = { label: 'คำปรารภ / ข้อทั่วไป', sectionIds: [] };
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

/**
 * Derive law source ('internal' | 'external') from law_type via the lookups list.
 * ตาม docs/law-metadata-reference.md — derive จาก law_type; ใช้ stored source เป็น fallback
 * (สำหรับเอกสารเก่าที่เก็บค่าตรงๆ) เท่านั้น. internal เป็น default.
 */
export function sourceOf(
  lawType: string | null | undefined,
  documentTypes: { value: string; source?: string }[],
  fallback?: string | null,
): 'internal' | 'external' {
  const hit = lawTypes.typeItem(lawType)?.source ?? documentTypes.find((t) => t.value === lawType)?.source;
  return (hit ?? fallback) === 'external' ? 'external' : 'internal';
}

// This store exists solely to enforce the api-import boundary: components and pages
// must not import from api/client directly. It has no reactive state by design.
import { defineStore } from 'pinia';
import {
  createBlock,
  deleteBlock,
  mergeBlocks,
  patchBlock,
  patchBlockLayout,
  reorderBlocks,
  reprocessBlock,
  reprocessPageWithGemini,
  restoreBlocks,
  splitBlock,
} from '../api/client';
import { invalidateReview } from './reviewCache';
import type { DocumentBlock, LayoutPatch, ScanExtractionMode } from '../types/document';

export const useBlockStore = defineStore('blocks', () => {
  function invalidate(documentId: string): void {
    invalidateReview(documentId);
  }

  async function patch(
    documentId: string,
    blockId: string,
    payload: Parameters<typeof patchBlock>[2],
  ): Promise<{ status: string }> {
    const response = await patchBlock(documentId, blockId, payload);
    invalidate(documentId);

    return response;
  }

  async function reprocess(
    documentId: string,
    blockId: string,
    pageNo: number,
  ): Promise<void> {
    await reprocessBlock(documentId, blockId, { page_no: pageNo, mode: 'ai_correction' });
    invalidate(documentId);
  }

  async function patchLayout(
    documentId: string,
    blockId: string,
    payload: LayoutPatch,
  ): Promise<{ status: string }> {
    const response = await patchBlockLayout(documentId, blockId, payload);
    invalidate(documentId);

    return response;
  }

  async function remove(
    documentId: string,
    blockId: string,
    pageNo: number,
  ): Promise<{ status: string }> {
    const response = await deleteBlock(documentId, blockId, pageNo);
    invalidate(documentId);

    return response;
  }

  async function merge(
    documentId: string,
    blockIds: string[],
  ): Promise<{ status: string; block: DocumentBlock }> {
    const response = await mergeBlocks(documentId, blockIds);
    invalidate(documentId);

    return response;
  }

  async function restore(
    documentId: string,
    pages: Array<{ page_no: number; blocks: DocumentBlock[] }>,
  ): Promise<{ document_id: string; status: string }> {
    const response = await restoreBlocks(documentId, pages);
    invalidate(documentId);

    return response;
  }

  async function split(
    documentId: string,
    blockId: string,
    payload: Parameters<typeof splitBlock>[2],
  ): Promise<{ status: string; first: DocumentBlock; second: DocumentBlock }> {
    const response = await splitBlock(documentId, blockId, payload);
    invalidate(documentId);

    return response;
  }

  async function create(
    documentId: string,
    payload: Parameters<typeof createBlock>[1],
  ): Promise<{ status: string; block: DocumentBlock }> {
    const response = await createBlock(documentId, payload);
    invalidate(documentId);

    return response;
  }

  async function reprocessPage(
    documentId: string,
    pageNo: number,
    mode: ScanExtractionMode = 'gemini',
  ): Promise<void> {
    await reprocessPageWithGemini(documentId, pageNo, mode);
    invalidate(documentId);
  }

  async function reorder(
    documentId: string,
    blockIds: string[],
  ): Promise<{ document_id: string; status: string; reordered_block_ids: string[] }> {
    const response = await reorderBlocks(documentId, blockIds);
    invalidate(documentId);

    return response;
  }

  async function patchChunkType(
    documentId: string,
    block: DocumentBlock,
    pageNo: number,
    chunkType: string | null,
  ): Promise<void> {
    await patchBlock(documentId, block.block_id, {
      page_no: pageNo,
      approved_text: block.approved_text || block.normalized_text || block.raw_text || '',
      mark_uncertain: block.needs_review,
      chunk_type: chunkType,
    });
    invalidate(documentId);
  }

  return { patch, reprocess, patchLayout, remove, merge, restore, split, create, reprocessPage, reorder, patchChunkType };
});

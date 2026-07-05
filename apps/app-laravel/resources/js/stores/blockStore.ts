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
  reprocessPageWithLandingAI,
  splitBlock,
} from '../api/client';
import type { DocumentBlock, LayoutPatch, ScanExtractionMode } from '../types/document';

export const useBlockStore = defineStore('blocks', () => {
  async function patch(
    documentId: string,
    blockId: string,
    payload: Parameters<typeof patchBlock>[2],
  ): Promise<{ status: string }> {
    return patchBlock(documentId, blockId, payload);
  }

  async function reprocess(
    documentId: string,
    blockId: string,
    pageNo: number,
  ): Promise<void> {
    await reprocessBlock(documentId, blockId, { page_no: pageNo, mode: 'ai_correction' });
  }

  async function patchLayout(
    documentId: string,
    blockId: string,
    payload: LayoutPatch,
  ): Promise<{ status: string }> {
    return patchBlockLayout(documentId, blockId, payload);
  }

  async function remove(
    documentId: string,
    blockId: string,
    pageNo: number,
  ): Promise<{ status: string }> {
    return deleteBlock(documentId, blockId, pageNo);
  }

  async function merge(
    documentId: string,
    blockIds: string[],
  ): Promise<{ status: string; block: DocumentBlock }> {
    return mergeBlocks(documentId, blockIds);
  }

  async function split(
    documentId: string,
    blockId: string,
    payload: Parameters<typeof splitBlock>[2],
  ): Promise<{ status: string; first: DocumentBlock; second: DocumentBlock }> {
    return splitBlock(documentId, blockId, payload);
  }

  async function create(
    documentId: string,
    payload: Parameters<typeof createBlock>[1],
  ): Promise<{ status: string; block: DocumentBlock }> {
    return createBlock(documentId, payload);
  }

  async function reprocessPage(
    documentId: string,
    pageNo: number,
    mode: ScanExtractionMode = 'gemini',
  ): Promise<void> {
    await reprocessPageWithLandingAI(documentId, pageNo, mode);
  }

  async function reorder(
    documentId: string,
    blockIds: string[],
  ): Promise<{ document_id: string; status: string; reordered_block_ids: string[] }> {
    return reorderBlocks(documentId, blockIds);
  }

  return { patch, reprocess, patchLayout, remove, merge, split, create, reprocessPage, reorder };
});

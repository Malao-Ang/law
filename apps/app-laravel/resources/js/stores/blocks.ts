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
import type { DocumentBlock, LayoutPatch, ReviewedTable } from '../types/document';

export interface PatchBlockPayload {
  page_no: number;
  approved_text: string;
  approved_by?: string;
  notes?: string;
  mark_uncertain: boolean;
  type?: string;
  reading_order?: number;
  bbox?: [number, number, number, number] | null;
  reviewed_html?: string;
  table?: ReviewedTable | null;
}

export interface CreateBlockPayload {
  page_no: number;
  after_block_id?: string | null;
  type?: string;
  approved_text?: string;
  reviewed_html?: string;
}

export interface SplitBlockPayload {
  page_no: number;
  before_text: string;
  before_html: string;
  after_text: string;
  after_html: string;
}

export const useBlockStore = defineStore('blocks', () => {
  async function patch(
    documentId: string,
    blockId: string,
    payload: PatchBlockPayload,
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
    payload: SplitBlockPayload,
  ): Promise<{ status: string; first: DocumentBlock; second: DocumentBlock }> {
    return splitBlock(documentId, blockId, payload);
  }

  async function create(
    documentId: string,
    payload: CreateBlockPayload,
  ): Promise<{ status: string; block: DocumentBlock }> {
    return createBlock(documentId, payload);
  }

  async function reprocessPage(
    documentId: string,
    pageNo: number,
    mode: 'landingai' | 'local' | 'auto' = 'landingai',
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

import Table from '@tiptap/extension-table';

export const TableWithBlockIdExtension = Table.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      blockId: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-block-id'),
        renderHTML: (attributes) =>
          attributes.blockId ? { 'data-block-id': attributes.blockId } : {},
      },
      pageNo: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-page-no'),
        renderHTML: (attributes) =>
          attributes.pageNo ? { 'data-page-no': attributes.pageNo } : {},
      },
    };
  },
});

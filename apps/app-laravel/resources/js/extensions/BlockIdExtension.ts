import { Extension } from '@tiptap/core';

// Preserves the server-assigned data-block-id on text blocks through editing,
// so saved draft_html can be mapped back onto document blocks. Mirrors
// TableWithBlockIdExtension, but as a global attribute for paragraph/heading.
export const BlockIdExtension = Extension.create({
  name: 'blockId',

  addGlobalAttributes() {
    return [
      {
        types: ['paragraph', 'heading'],
        attributes: {
          blockId: {
            default: null,
            parseHTML: (element) => element.getAttribute('data-block-id'),
            renderHTML: (attributes) =>
              attributes.blockId ? { 'data-block-id': attributes.blockId } : {},
          },
        },
      },
    ];
  },
});

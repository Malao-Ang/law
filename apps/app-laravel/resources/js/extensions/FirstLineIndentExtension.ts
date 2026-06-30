import { Extension } from '@tiptap/core';

// Preserves first-line indent (CSS text-indent) on text blocks so the extracted
// Thai-legal paragraph indent (indent_first_line, emitted by generated_html as
// `text-indent: NNpt`) survives editing and renders in the editor. Without this,
// TipTap drops the inline text-indent and paragraphs render flush-left.
//
// Round-trips with DocumentHtmlService, which reads/writes `text-indent`.
export const FirstLineIndentExtension = Extension.create({
  name: 'firstLineIndent',

  addGlobalAttributes() {
    return [
      {
        types: ['paragraph', 'heading'],
        attributes: {
          firstLineIndent: {
            default: null,
            parseHTML: (element) => element.style.textIndent || null,
            renderHTML: (attributes) =>
              attributes.firstLineIndent ? { style: `text-indent: ${attributes.firstLineIndent}` } : {},
          },
        },
      },
    ];
  },
});

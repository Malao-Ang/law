import { Extension } from '@tiptap/core';

export const INDENT_STEP = 24;
export const MAX_INDENT = 7;

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    indent: {
      increaseIndent: () => ReturnType;
      decreaseIndent: () => ReturnType;
      setIndentLevel: (level: number) => ReturnType;
    };
  }
}

export const IndentExtension = Extension.create({
  name: 'indent',

  addGlobalAttributes() {
    return [
      {
        types: ['paragraph', 'heading'],
        attributes: {
          indent: {
            default: 0,
            parseHTML: (element) => {
              const raw = element.style.marginLeft;
              if (raw) {
                if (raw.endsWith('px')) {
                  const px = parseInt(raw, 10);
                  return Number.isNaN(px) ? 0 : Math.round(px / INDENT_STEP);
                }
                if (raw.endsWith('pt')) {
                  // 18pt per indent level ≈ INDENT_STEP (24px) at 96 dpi
                  const pt = parseFloat(raw);
                  return Number.isNaN(pt) ? 0 : Math.round(pt / 18);
                }
              }
              // generated_html uses doc-indent-N class
              const m = element.className.match(/\bdoc-indent-(\d+)\b/);
              return m ? parseInt(m[1], 10) : 0;
            },
            renderHTML: (attributes) => {
              if (!attributes.indent) return {};
              return { style: `margin-left: ${attributes.indent * INDENT_STEP}px` };
            },
          },
        },
      },
    ];
  },

  addCommands() {
    return {
      increaseIndent:
        () =>
        ({ tr, state, dispatch }) => {
          const { from, to } = state.selection;
          state.doc.nodesBetween(from, to, (node, pos) => {
            if (node.type.name === 'paragraph' || node.type.name === 'heading') {
              const current = (node.attrs.indent as number) ?? 0;
              if (current < MAX_INDENT) {
                tr.setNodeMarkup(pos, undefined, { ...node.attrs, indent: current + 1 });
              }
            }
          });
          if (dispatch) dispatch(tr);
          return true;
        },

      decreaseIndent:
        () =>
        ({ tr, state, dispatch }) => {
          const { from, to } = state.selection;
          state.doc.nodesBetween(from, to, (node, pos) => {
            if (node.type.name === 'paragraph' || node.type.name === 'heading') {
              const current = (node.attrs.indent as number) ?? 0;
              if (current > 0) {
                tr.setNodeMarkup(pos, undefined, { ...node.attrs, indent: current - 1 });
              }
            }
          });
          if (dispatch) dispatch(tr);
          return true;
        },

      setIndentLevel:
        (level: number) =>
        ({ tr, state, dispatch }) => {
          const clamped = Math.max(0, Math.min(MAX_INDENT, Math.round(level)));
          const { from, to } = state.selection;
          state.doc.nodesBetween(from, to, (node, pos) => {
            if (node.type.name === 'paragraph' || node.type.name === 'heading') {
              tr.setNodeMarkup(pos, undefined, { ...node.attrs, indent: clamped });
            }
          });
          if (dispatch) dispatch(tr);
          return true;
        },
    };
  },

  addKeyboardShortcuts() {
    return {
      Tab: () => this.editor.commands.increaseIndent(),
      'Shift-Tab': () => this.editor.commands.decreaseIndent(),
    };
  },
});

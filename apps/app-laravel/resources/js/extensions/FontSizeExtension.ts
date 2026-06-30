import TextStyle from '@tiptap/extension-text-style';

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    fontSize: {
      setFontSize: (size: string) => ReturnType;
      unsetFontSize: () => ReturnType;
    };
    fontFamily: {
      setFontFamily: (family: string) => ReturnType;
      unsetFontFamily: () => ReturnType;
    };
  }
}

export const FontSizeExtension = TextStyle.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      fontSize: {
        default: null,
        parseHTML: (element) => element.style.fontSize || null,
        renderHTML: (attributes) =>
          attributes.fontSize ? { style: `font-size: ${attributes.fontSize}` } : {},
      },
      fontFamily: {
        default: null,
        parseHTML: (element) => element.style.fontFamily || null,
        renderHTML: (attributes) =>
          attributes.fontFamily ? { style: `font-family: ${attributes.fontFamily}` } : {},
      },
    };
  },

  addCommands() {
    return {
      ...this.parent?.(),
      // setMark (not updateAttributes) so the textStyle mark is ADDED to a
      // selection that has none yet — extracted text carries no textStyle mark,
      // and updateAttributes is a no-op when the mark is absent.
      setFontSize:
        (size: string) =>
        ({ commands }) =>
          commands.setMark('textStyle', { fontSize: size }),
      unsetFontSize:
        () =>
        ({ commands }) =>
          commands.updateAttributes('textStyle', { fontSize: null }),
      setFontFamily:
        (family: string) =>
        ({ commands }) =>
          commands.setMark('textStyle', { fontFamily: family }),
      unsetFontFamily:
        () =>
        ({ commands }) =>
          commands.updateAttributes('textStyle', { fontFamily: null }),
    };
  },
});

import Image from '@tiptap/extension-image';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import ImageNodeView from '../components/review/ImageNodeView.vue';

export const ResizableImageExtension = Image.extend({
  addOptions() {
    return {
      ...this.parent?.(),
      documentId: '',
    };
  },

  addAttributes() {
    return {
      ...this.parent?.(),
      width: {
        default: null,
        parseHTML: (element) => {
          const styleWidth = element.style.width;
          if (styleWidth && styleWidth.endsWith('px')) {
            return parseInt(styleWidth, 10);
          }

          const attrWidth = element.getAttribute('width');
          return attrWidth ? parseInt(attrWidth, 10) : null;
        },
        renderHTML: (attributes) =>
          attributes.width ? { style: `width: ${attributes.width}px; height: auto;` } : {},
      },
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

  addNodeView() {
    return VueNodeViewRenderer(ImageNodeView);
  },
});

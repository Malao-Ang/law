import DOMPurify from 'dompurify';

const BASE_ALLOWED_TAGS = [
  'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'mark',
  'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
  'ul', 'ol', 'li', 'blockquote',
  'table', 'thead', 'tbody', 'tr', 'th', 'td',
  'span', 'div', 'sub', 'sup',
];

const BASE_ALLOWED_ATTR = [
  'class', 'style', 'colspan', 'rowspan',
  'data-block-id', 'data-block-type', 'data-page-no', 'data-reading-order',
];

function isSafeCssLength(value: string, allowNegative: boolean): boolean {
  const pattern = allowNegative
    ? /^-?(?:\d+|\d*\.\d+)(px|pt|em|rem|%)$|^0$/i
    : /^(?:\d+|\d*\.\d+)(px|pt|em|rem|%)$|^0$/i;

  return pattern.test(value);
}

function isSafeLineHeight(value: string): boolean {
  return /^(?:\d+|\d*\.\d+)$|^(?:\d+|\d*\.\d+)(px|pt|em|rem|%)$|^0$/i.test(value);
}

function isSafeFontFamily(value: string): boolean {
  return /^(['"]?[\p{L}\p{N}\s_-]+['"]?)(\s*,\s*['"]?[\p{L}\p{N}\s_-]+['"]?)*$/iu.test(value);
}

function isAllowedStyleValue(property: string, value: string): boolean {
  switch (property) {
    case 'text-align':
      return /^(left|center|right|justify)$/i.test(value);
    case 'margin-left':
      return isSafeCssLength(value, false);
    case 'text-indent':
      return isSafeCssLength(value, true);
    case 'font-size':
      return isSafeCssLength(value, false);
    case 'line-height':
      return isSafeLineHeight(value);
    case 'font-family':
      return isSafeFontFamily(value);
    default:
      return false;
  }
}

function filterInlineStyle(style: string): string {
  const safe: string[] = [];

  style
    .split(';')
    .map((part) => part.trim())
    .filter(Boolean)
    .forEach((decl) => {
      const colon = decl.indexOf(':');
      if (colon === -1) return;

      const property = decl.slice(0, colon).trim().toLowerCase();
      const value = decl.slice(colon + 1).trim();
      if (!value || !isAllowedStyleValue(property, value)) return;

      safe.push(`${property}: ${value}`);
    });

  return safe.join('; ');
}

export function sanitizeRichTextHtml(html: string): string {
  const sanitized = DOMPurify.sanitize(html, {
    ALLOWED_TAGS: BASE_ALLOWED_TAGS,
    ALLOWED_ATTR: BASE_ALLOWED_ATTR,
  });

  if (sanitized.trim() === '') {
    return '';
  }

  const template = document.createElement('template');
  template.innerHTML = sanitized;

  template.content.querySelectorAll('[style]').forEach((node) => {
    const filtered = filterInlineStyle(node.getAttribute('style') ?? '');
    if (filtered === '') {
      node.removeAttribute('style');
    } else {
      node.setAttribute('style', filtered);
    }
  });

  return template.innerHTML;
}

export function reviewedHtmlHasStyle(html: string, property: 'text-align' | 'margin-left' | 'text-indent' | 'line-height'): boolean {
  if (html.trim() === '') {
    return false;
  }

  const template = document.createElement('template');
  template.innerHTML = html;

  return Array.from(template.content.children).some((node) => {
    const style = filterInlineStyle((node as Element).getAttribute('style') ?? '');
    return style
      .split(';')
      .map((part) => part.trim().toLowerCase())
      .some((decl) => decl.startsWith(`${property}:`));
  });
}

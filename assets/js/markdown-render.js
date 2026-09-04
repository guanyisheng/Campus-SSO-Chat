(function (global) {
  'use strict';

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function plainTextToHtml(text) {
    return '<div class="md-body">' + escapeHtml(text).replace(/\n/g, '<br>') + '</div>';
  }

  if (!global.marked || !global.DOMPurify) {
    global.renderMarkdown = function (text) {
      return plainTextToHtml(text || '');
    };
    return;
  }

  marked.setOptions({
    gfm: true,
    breaks: true,
  });

  const purifyOpts = {
    ALLOWED_TAGS: [
      'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
      'p', 'br', 'hr',
      'ul', 'ol', 'li',
      'blockquote',
      'pre', 'code',
      'em', 'strong', 'del', 's',
      'a', 'img',
      'table', 'thead', 'tbody', 'tr', 'th', 'td',
      'div', 'span', 'sup', 'sub',
    ],
    ALLOWED_ATTR: ['href', 'title', 'class', 'target', 'rel', 'alt', 'src'],
    ALLOW_DATA_ATTR: false,
  };

  global.DOMPurify.addHook('afterSanitizeAttributes', function (node) {
    if (node.tagName === 'A') {
      node.setAttribute('target', '_blank');
      node.setAttribute('rel', 'noopener noreferrer');
    }
  });

  global.renderMarkdown = function (text) {
    if (!text) return '';
    try {
      const raw = marked.parse(String(text), { async: false });
      const clean = global.DOMPurify.sanitize(raw, purifyOpts);
      return '<div class="md-body">' + clean + '</div>';
    } catch (e) {
      return plainTextToHtml(text);
    }
  };
})(typeof window !== 'undefined' ? window : globalThis);

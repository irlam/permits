(() => {
  'use strict';

  const skippedTags = new Set(['SCRIPT', 'STYLE', 'TEXTAREA', 'INPUT', 'SELECT', 'OPTION', 'CODE', 'PRE']);

  function ukDate(year, month, day) {
    return `${day}/${month}/${year}`;
  }

  function ukDateTime(year, month, day, hour, minute) {
    return `${day}/${month}/${year} ${hour}:${minute}`;
  }

  function normaliseText(value) {
    if (!value || typeof value !== 'string') return value;

    let output = value.replace(
      /(^|[\s([{>:])((\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::\d{2})?)(?=$|[\s)\]},;<])/g,
      (_match, prefix, _raw, year, month, day, hour, minute) => `${prefix}${ukDateTime(year, month, day, hour, minute)}`
    );

    output = output.replace(
      /(^|[\s([{>:])((\d{4})-(\d{2})-(\d{2}))(?=$|[\s)\]},;<])/g,
      (_match, prefix, _raw, year, month, day) => `${prefix}${ukDate(year, month, day)}`
    );

    return output;
  }

  function shouldSkip(node) {
    const parent = node.parentElement;
    return !parent || skippedTags.has(parent.tagName) || parent.closest('[data-keep-iso-date]');
  }

  function formatTextNode(node) {
    if (node.nodeType !== Node.TEXT_NODE || shouldSkip(node)) return;
    const current = node.nodeValue || '';
    const updated = normaliseText(current);
    if (updated !== current) node.nodeValue = updated;
  }

  function formatSubtree(root) {
    if (!root) return;
    if (root.nodeType === Node.TEXT_NODE) {
      formatTextNode(root);
      return;
    }
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let node;
    while ((node = walker.nextNode())) formatTextNode(node);
  }

  function start() {
    formatSubtree(document.body);

    const observer = new MutationObserver((mutations) => {
      for (const mutation of mutations) {
        if (mutation.type === 'characterData') {
          formatTextNode(mutation.target);
          continue;
        }
        for (const node of mutation.addedNodes) formatSubtree(node);
      }
    });

    observer.observe(document.body, {
      subtree: true,
      childList: true,
      characterData: true,
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
})();

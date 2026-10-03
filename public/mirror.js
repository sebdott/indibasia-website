/* Adapt public snapshot interactions to local URLs; do not submit to the source site. */
(() => {
  'use strict';
  const sourceHosts = new Set(['indiba.com', 'www.indiba.com']);
  let assets = {};
  let translations = {};
  let fontStyles = [];
  const nativeFetch = window.fetch.bind(window);
  const mapUrl = value => {
    if (typeof value !== 'string' || /^(data:|blob:|#|mailto:|tel:|javascript:)/i.test(value)) return value;
    try {
      const url = new URL(value, location.href);
      const canonical = 'https://' + url.host + url.pathname + url.search;
      const asset = assets[canonical] || assets[canonical.replace(/%7C/gi, '|')];
      if (asset) return asset + url.hash;
      if (url.hostname === 'fonts.googleapis.com') {
        const match = fontStyles.find(font => font.family === url.searchParams.get('family'));
        if (match) return match.local;
      }
      if (sourceHosts.has(url.hostname)) return url.pathname + url.search + url.hash;
    } catch (_) {}
    return value;
  };
  window.fetch = (input, init) => {
    if (typeof input === 'string') return nativeFetch(mapUrl(input), init);
    if (input instanceof Request) return nativeFetch(new Request(mapUrl(input.url), input), init);
    return nativeFetch(input, init);
  };
  const originalOpen = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function (method, url, ...rest) {
    return originalOpen.call(this, method, mapUrl(String(url)), ...rest);
  };
  const originalWindowOpen = window.open;
  window.open = function (url, ...rest) { return originalWindowOpen.call(this, mapUrl(url), ...rest); };
  const adapt = root => {
    if (!root.querySelectorAll) return;
    const nodes = [...root.querySelectorAll('[src],[href],[poster],[data-src],[srcset]')];
    if (root.matches?.('[src],[href],[poster],[data-src],[srcset]')) nodes.push(root);
    for (const node of nodes) {
      for (const attr of ['src', 'href', 'poster', 'data-src']) {
        const value = node.getAttribute(attr);
        if (value) { const local = mapUrl(value); if (local !== value) node.setAttribute(attr, local); }
      }
      const srcset = node.getAttribute('srcset');
      if (srcset && !srcset.startsWith('data:')) {
        const local = srcset.split(',').map(part => {
          const pieces = part.trim().split(/\s+/); pieces[0] = mapUrl(pieces[0]); return pieces.join(' ');
        }).join(', ');
        if (local !== srcset) node.setAttribute('srcset', local);
      }
    }
  };
  nativeFetch('/mirror-map.json').then(r => r.json()).then(map => {
    assets = map;
    fontStyles = Object.entries(map).filter(([url]) => url.includes('://fonts.googleapis.com/')).map(([url, local]) => ({ family: new URL(url).searchParams.get('family'), local }));
    adapt(document);
  }).catch(() => {});
  nativeFetch('/translations.json').then(r => r.json()).then(dictionary => { translations = dictionary; }).catch(() => {});
  document.addEventListener('click', event => {
    const link = event.target.closest?.('a[href]');
    if (link) {
      const value = link.getAttribute('href'); const local = mapUrl(value);
      if (local !== value) link.setAttribute('href', local);
    }
  }, true);
  document.addEventListener('submit', event => {
    event.preventDefault(); event.stopImmediatePropagation();
    const form = event.target;
    let message = form.querySelector('.mirror-form-message');
    if (!message) { message = document.createElement('p'); message.className = 'mirror-form-message'; message.setAttribute('role', 'status'); form.append(message); }
    message.textContent = translations.ui_form_unavailable || 'This form is unavailable in the local copy.';
  }, true);
  document.addEventListener('DOMContentLoaded', () => {
    adapt(document);
    new MutationObserver(changes => {
      for (const change of changes) for (const node of change.addedNodes) if (node.nodeType === 1) adapt(node);
    }).observe(document.documentElement, { childList: true, subtree: true });
    // Animations should not hide content if a third-party widget fails to initialize.
    setTimeout(() => document.querySelectorAll('.elementor-invisible').forEach(node => node.classList.remove('elementor-invisible')), 6000);
  });
})();

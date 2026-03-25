/**
 * With JS: blocks context menu and copy/cut/paste (plus Ctrl/Cmd shortcuts) outside form fields;
 * shows a modal. Without JS, /assets/css/content-protection.css still blocks text selection.
 * Easily bypassed (View Source, disable CSS, DevTools) — deterrent only.
 */
(function () {
  'use strict';

  var MSG_FA = 'کپی یا جابه‌جایی این محتوا مجاز نیست.';
  var MSG_EN = 'You cannot copy or paste this content.';
  var modalEl = null;

  function inFormField(target) {
    if (!target || !target.closest) return false;
    var el = target.nodeType === 3 ? target.parentElement : target;
    if (!el) return false;
    if (el.isContentEditable) return true;
    var tag = el.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
    return el.closest('input, textarea, select, [contenteditable="true"], [data-copy-allowed]') !== null;
  }

  function selectionInsideFormField() {
    var sel = document.getSelection();
    if (!sel || sel.rangeCount < 1) return false;
    var node = sel.anchorNode;
    if (!node) return false;
    var el = node.nodeType === 3 ? node.parentElement : node;
    return inFormField(el);
  }

  function ensureModal() {
    if (modalEl) return modalEl;
    var style = document.createElement('style');
    style.textContent =
      '#copy-guard-overlay{position:fixed;inset:0;z-index:2147483000;background:rgba(58,11,71,0.45);display:none;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px);}' +
      '#copy-guard-overlay.is-open{display:flex;}' +
      '#copy-guard-box{max-width:28rem;width:100%;background:#fff;border-radius:18px;padding:28px 24px;text-align:center;box-shadow:0 24px 64px rgba(0,0,0,0.25);border:1px solid rgba(116,32,139,0.2);direction:rtl;}' +
      '#copy-guard-box h2{margin:0 0 12px;font-size:1.15rem;color:#3a0b47;font-weight:800;}' +
      '#copy-guard-box p{margin:0 0 8px;color:#444;font-size:0.95rem;line-height:1.6;}' +
      '#copy-guard-box p.en{direction:ltr;font-size:0.88rem;color:#666;margin-top:12px;}' +
      '#copy-guard-close{margin-top:20px;background:linear-gradient(135deg,#3a0b47,#74208b);color:#fff;border:none;padding:12px 28px;border-radius:12px;font-weight:700;font-size:0.95rem;cursor:pointer;font-family:inherit;}';
    document.head.appendChild(style);

    var overlay = document.createElement('div');
    overlay.id = 'copy-guard-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'copy-guard-title');
    overlay.innerHTML =
      '<div id="copy-guard-box">' +
      '<h2 id="copy-guard-title">محدودیت محتوا</h2>' +
      '<p>' + MSG_FA + '</p>' +
      '<p class="en">' + MSG_EN + '</p>' +
      '<button type="button" id="copy-guard-close">متوجه شدم</button>' +
      '</div>';
    document.body.appendChild(overlay);
    modalEl = overlay;

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closeModal();
    });
    document.getElementById('copy-guard-close').addEventListener('click', closeModal);
    document.addEventListener('keydown', function esc(e) {
      if (e.key === 'Escape' && overlay.classList.contains('is-open')) {
        closeModal();
      }
    });

    return overlay;
  }

  function openModal() {
    var o = ensureModal();
    o.classList.add('is-open');
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    if (!modalEl) return;
    modalEl.classList.remove('is-open');
    document.body.style.overflow = '';
  }

  function block() {
    openModal();
  }

  document.addEventListener(
    'contextmenu',
    function (e) {
      if (inFormField(e.target)) return;
      e.preventDefault();
      block();
    },
    true
  );

  document.addEventListener(
    'copy',
    function (e) {
      if (inFormField(e.target) || selectionInsideFormField()) return;
      e.preventDefault();
      block();
    },
    true
  );

  document.addEventListener(
    'cut',
    function (e) {
      if (inFormField(e.target) || selectionInsideFormField()) return;
      e.preventDefault();
      block();
    },
    true
  );

  document.addEventListener(
    'paste',
    function (e) {
      if (inFormField(e.target)) return;
      e.preventDefault();
      block();
    },
    true
  );

  document.addEventListener(
    'keydown',
    function (e) {
      if (inFormField(e.target)) return;
      if (e.ctrlKey || e.metaKey) {
        var k = e.key && e.key.toLowerCase();
        if (k === 'c' || k === 'x' || k === 'v' || k === 'a') {
          e.preventDefault();
          block();
        }
      }
    },
    true
  );
})();

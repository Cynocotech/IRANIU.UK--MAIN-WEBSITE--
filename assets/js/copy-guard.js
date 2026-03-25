/**
 * With JS: blocks context menu and copy/cut/paste (plus Ctrl/Cmd shortcuts) outside form fields;
 * shows a modal. Best-effort screenshot key detection (PrintScreen, Cmd+Shift+3/4/5 on some browsers).
 * Without JS, /assets/css/content-protection.css still blocks text selection + noscript wall.
 * OS screenshot tools and phone captures are not detectable in the browser — deterrent only.
 */
(function () {
  'use strict';

  var MSG_FA = 'کپی یا جابه‌جایی این محتوا مجاز نیست.';
  var MSG_EN = 'You cannot copy or paste this content.';
  var TITLE_DEFAULT = 'محدودیت محتوا';
  var SCREENSHOT_MSG_FA = 'گرفتن اسکرین‌شات از این صفحه مجاز نیست.';
  var SCREENSHOT_MSG_EN = 'You cannot take a screenshot of this page.';
  var TITLE_SCREENSHOT = 'محدودیت اسکرین‌شات';

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
      '<h2 id="copy-guard-title">' +
      TITLE_DEFAULT +
      '</h2>' +
      '<p id="copy-guard-msg-fa">' +
      MSG_FA +
      '</p>' +
      '<p id="copy-guard-msg-en" class="en">' +
      MSG_EN +
      '</p>' +
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

  function openModal(mode) {
    var title = TITLE_DEFAULT;
    var fa = MSG_FA;
    var en = MSG_EN;
    if (mode === 'screenshot') {
      title = TITLE_SCREENSHOT;
      fa = SCREENSHOT_MSG_FA;
      en = SCREENSHOT_MSG_EN;
    }
    var o = ensureModal();
    document.getElementById('copy-guard-title').textContent = title;
    document.getElementById('copy-guard-msg-fa').textContent = fa;
    document.getElementById('copy-guard-msg-en').textContent = en;
    o.classList.add('is-open');
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    if (!modalEl) return;
    modalEl.classList.remove('is-open');
    document.body.style.overflow = '';
  }

  function blockCopy() {
    openModal('copy');
  }

  /** Best-effort: may not fire before OS captures the screen. */
  function blockScreenshotAttempt(e) {
    try {
      e.preventDefault();
    } catch (err) {}
    openModal('screenshot');
  }

  document.addEventListener(
    'contextmenu',
    function (e) {
      if (inFormField(e.target)) return;
      e.preventDefault();
      blockCopy();
    },
    true
  );

  document.addEventListener(
    'copy',
    function (e) {
      if (inFormField(e.target) || selectionInsideFormField()) return;
      e.preventDefault();
      blockCopy();
    },
    true
  );

  document.addEventListener(
    'cut',
    function (e) {
      if (inFormField(e.target) || selectionInsideFormField()) return;
      e.preventDefault();
      blockCopy();
    },
    true
  );

  document.addEventListener(
    'paste',
    function (e) {
      if (inFormField(e.target)) return;
      e.preventDefault();
      blockCopy();
    },
    true
  );

  document.addEventListener(
    'keydown',
    function (e) {
      var k = e.key || '';
      var code = e.code || '';

      // Print Screen (Windows / some Linux)
      if (k === 'PrintScreen' || code === 'PrintScreen') {
        blockScreenshotAttempt(e);
        return;
      }

      // macOS region/fullscreen capture shortcuts (works only if the browser delivers the event first)
      if (e.metaKey && e.shiftKey) {
        if (k === '3' || k === '4' || k === '5' || k === '6') {
          blockScreenshotAttempt(e);
          return;
        }
      }

      if (inFormField(e.target)) return;

      if (e.ctrlKey || e.metaKey) {
        var kl = k.toLowerCase();
        if (kl === 'c' || kl === 'x' || kl === 'v' || kl === 'a') {
          e.preventDefault();
          blockCopy();
        }
      }
    },
    true
  );
})();

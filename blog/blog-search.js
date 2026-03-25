(function () {
  'use strict';

  var ENDPOINT = '/blog/ajax_search.php';
  var DEBOUNCE_MS = 280;
  var MIN_LEN = 2;

  function debounce(fn, ms) {
    var t;
    return function () {
      var ctx = this;
      var args = arguments;
      clearTimeout(t);
      t = setTimeout(function () {
        fn.apply(ctx, args);
      }, ms);
    };
  }

  /** Only allow same-origin article paths (mitigates poisoned JSON href). */
  function safeArticleHref(u) {
    if (typeof u !== 'string' || !u) return '#';
    u = u.trim();
    if (u[0] !== '/' || u[1] === '/') return '#';
    if (/[\s\0]/.test(u)) return '#';
    if (u.toLowerCase().indexOf('javascript:') !== -1) return '#';
    if (u.toLowerCase().indexOf('data:') === 0) return '#';
    if (u.indexOf('/blog/article/') !== 0) return '#';
    return u;
  }

  function clearList(list) {
    while (list.firstChild) {
      list.removeChild(list.firstChild);
    }
  }

  function initWrap(wrap) {
    var input = wrap.querySelector('.blog-search-input');
    var panel = wrap.querySelector('.blog-search-panel');
    var status = wrap.querySelector('.blog-search-status');
    var list = wrap.querySelector('.blog-search-list');
    if (!input || !panel || !status || !list) return;

    var ctrl = wrap.querySelector('.blog-search-ctrl');
    var abortCtrl = null;
    var open = false;

    function setOpen(v) {
      open = v;
      panel.hidden = !v;
      panel.setAttribute('aria-hidden', v ? 'false' : 'true');
      input.setAttribute('aria-expanded', v ? 'true' : 'false');
    }

    function showStatus(msg, isError) {
      status.textContent = msg || '';
      status.classList.toggle('blog-search-status--err', !!isError);
      status.hidden = !msg;
    }

    function renderItems(items) {
      clearList(list);
      if (!items || !items.length) {
        var empty = document.createElement('p');
        empty.className = 'blog-search-empty';
        empty.textContent = 'نتیجه‌ای پیدا نشد.';
        list.appendChild(empty);
        return;
      }
      items.forEach(function (it) {
        var a = document.createElement('a');
        a.className = 'blog-search-item';
        var href = safeArticleHref(it.url || '');
        a.setAttribute('href', href);
        if (href === '#') a.setAttribute('aria-disabled', 'true');

        var title = document.createElement('span');
        title.className = 'blog-search-item-title';
        title.textContent = it.title || '';

        if (it.category || it.date) {
          var meta = document.createElement('span');
          meta.className = 'blog-search-item-meta';
          if (it.category) {
            var cat = document.createElement('span');
            cat.className = 'blog-search-item-cat';
            cat.textContent = it.category;
            meta.appendChild(cat);
          }
          if (it.date) {
            var dt = document.createElement('span');
            dt.className = 'blog-search-item-date';
            dt.textContent = it.date;
            meta.appendChild(dt);
          }
          a.appendChild(title);
          a.appendChild(meta);
        } else {
          a.appendChild(title);
        }

        if (it.excerpt) {
          var ex = document.createElement('span');
          ex.className = 'blog-search-item-ex';
          ex.textContent = it.excerpt;
          a.appendChild(ex);
        }

        list.appendChild(a);
      });
    }

    function runSearch() {
      var q = (input.value || '').trim();
      if (q.length < MIN_LEN) {
        if (abortCtrl) abortCtrl.abort();
        showStatus('');
        clearList(list);
        setOpen(false);
        return;
      }

      if (abortCtrl) abortCtrl.abort();
      abortCtrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
      showStatus('در حال جستجو…');
      setOpen(true);

      var url = ENDPOINT + '?q=' + encodeURIComponent(q) + '&limit=12';
      var opts = { credentials: 'same-origin', signal: abortCtrl ? abortCtrl.signal : undefined };

      fetch(url, opts)
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          showStatus('');
          if (!data || !data.ok) {
            showStatus('جستجو انجام نشد. بعداً دوباره امتحان کنید.', true);
            clearList(list);
            return;
          }
          renderItems(data.items || []);
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return;
          showStatus('خطا در اتصال.', true);
          clearList(list);
        });
    }

    var debounced = debounce(runSearch, DEBOUNCE_MS);

    input.addEventListener('input', function () {
      debounced();
    });

    input.addEventListener('focus', function () {
      var q = (input.value || '').trim();
      if (q.length >= MIN_LEN && list.children.length) setOpen(true);
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        setOpen(false);
        input.blur();
      }
    });

    document.addEventListener('click', function (e) {
      if (!open) return;
      if (ctrl && !ctrl.contains(e.target)) setOpen(false);
    });
  }

  document.querySelectorAll('[data-blog-search]').forEach(initWrap);
})();

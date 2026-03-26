(function () {
  'use strict';

  var ENDPOINT = '/chat-assistant.php';
  var GREETING = 'سلام! من دستیار ایرانیو هستم. چه کمکی از دستم برمیاد؟';
  var state = {
    history: []
  };

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (typeof text === 'string') n.textContent = text;
    return n;
  }

  function addMsg(log, role, text) {
    var row = el('div', 'iraniu-chat-msg ' + (role === 'user' ? 'iraniu-chat-msg--user' : 'iraniu-chat-msg--bot'), text);
    log.appendChild(row);
    log.scrollTop = log.scrollHeight;
  }

  function buildWidget() {
    var launcher = el('button', 'iraniu-chat-launcher', '💬');
    launcher.type = 'button';
    launcher.setAttribute('aria-label', 'باز کردن چت');

    var panel = el('section', 'iraniu-chat-panel');
    panel.hidden = true;

    var head = el('div', 'iraniu-chat-head');
    var title = el('strong', '', 'چت پشتیبانی ایرانیو');
    var close = el('button', 'iraniu-chat-close', '×');
    close.type = 'button';
    close.setAttribute('aria-label', 'بستن چت');
    head.appendChild(title);
    head.appendChild(close);

    var log = el('div', 'iraniu-chat-log');
    addMsg(log, 'assistant', GREETING);
    state.history.push({ role: 'assistant', content: GREETING });

    var form = el('form', 'iraniu-chat-form');
    var input = el('input', 'iraniu-chat-input');
    input.type = 'text';
    input.placeholder = 'پیام خود را بنویسید...';
    input.maxLength = 1000;
    var send = el('button', 'iraniu-chat-send', 'ارسال');
    send.type = 'submit';
    form.appendChild(input);
    form.appendChild(send);

    var actions = el('div', 'iraniu-chat-actions');
    var wa = el('a', 'iraniu-chat-wa', 'ادامه گفتگو در واتساپ');
    wa.href = 'https://iraniu.uk/Biolink/';
    wa.target = '_blank';
    wa.rel = 'noopener';
    actions.appendChild(wa);

    panel.appendChild(head);
    panel.appendChild(log);
    panel.appendChild(form);
    panel.appendChild(actions);

    document.body.appendChild(launcher);
    document.body.appendChild(panel);

    launcher.addEventListener('click', function () {
      panel.hidden = !panel.hidden;
      if (!panel.hidden) input.focus();
    });
    close.addEventListener('click', function () {
      panel.hidden = true;
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var msg = (input.value || '').trim();
      if (!msg) return;
      input.value = '';
      addMsg(log, 'user', msg);
      state.history.push({ role: 'user', content: msg });
      if (state.history.length > 12) state.history = state.history.slice(-12);

      send.disabled = true;
      fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ message: msg, history: state.history })
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.ok) {
            addMsg(log, 'assistant', 'در حال حاضر پاسخ‌گویی با اختلال مواجه شده. لطفا از واتساپ ادامه دهید.');
            return;
          }
          var reply = (data.reply || '').trim();
          if (!reply) reply = 'برای ادامه، از واتساپ با ما در ارتباط باشید.';
          addMsg(log, 'assistant', reply);
          state.history.push({ role: 'assistant', content: reply });
          if (state.history.length > 12) state.history = state.history.slice(-12);
          if (typeof data.whatsapp_url === 'string' && data.whatsapp_url) {
            wa.href = data.whatsapp_url;
          }
        })
        .catch(function () {
          addMsg(log, 'assistant', 'خطا در اتصال. لطفا از واتساپ ادامه دهید.');
        })
        .finally(function () {
          send.disabled = false;
          input.focus();
        });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', buildWidget);
  } else {
    buildWidget();
  }
})();


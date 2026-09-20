/* Winston 99 — save, wordcount, no-paste, passkeys */
(function () {
  window.showBulkActions = function () {
    var bar = document.getElementById('bulk_actions_div');
    if (!bar) return;
    var open = bar.hidden;
    bar.hidden = !open;
    var tables = document.querySelectorAll('table.list.writ, table.list.bulk');
    for (var i = 0; i < tables.length; i++) {
      tables[i].classList.toggle('bulk-open', open);
    }
  };
  window.toggle = function (source) {
    var cb = document.querySelectorAll('table.list.writ td.bulk_check input[type=checkbox], table.list.bulk td.bulk_check input[type=checkbox]');
    for (var i = 0; i < cb.length; i++) cb[i].checked = source.checked;
  };

  function wordCount(s) {
    s = (s || '').trim();
    if (!s) return 0;
    return s.replace(/[\u2013\u2014]/g, ' ').replace(/\s+/g, ' ').split(' ').length;
  }

  window.pwWord = function (areaId, displayId, hiddenId) {
    var a = document.getElementById(areaId);
    if (!a) return;
    function tick() {
      var n = wordCount(a.value);
      var d = document.getElementById(displayId);
      var h = document.getElementById(hiddenId);
      if (d) d.textContent = n;
      if (h) h.value = n;
    }
    ['input', 'keyup', 'change', 'blur'].forEach(function (ev) {
      a.addEventListener(ev, tick);
    });
    tick();
  };

  window.pwNoPaste = function (id) {
    var a = document.getElementById(id);
    if (!a) return;
    ['paste', 'cut', 'drop', 'drag'].forEach(function (ev) {
      a.addEventListener(ev, function (e) { e.preventDefault(); return false; });
    });
  };

  var pwSaveRetry = {};
  var pwWaitMarkup = '<span class="pw-wait sans">'
    + '<svg class="pw-spin" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">'
    + '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="40 16"/>'
    + '</svg> Waiting for the database…</span>';

  function pwRetryable(x, j, raw) {
    if (j && j.retry) return true;
    var st = x ? x.status : 0;
    if (st === 0 || st === 502 || st === 503 || st === 504) return true;
    var s = ((j && j.error) ? j.error : '') + ' ' + (raw || '');
    return /database connection failed|connection refused|SQLSTATE\[HY000\] \[2002\]|Waiting for the database/i.test(s);
  }

  window.pwAjaxForm = function (formId, postTo, updateId, extra) {
    var form = document.getElementById(formId);
    var box = document.getElementById(updateId);
    if (!form) return;
    if (pwSaveRetry[formId]) {
      clearTimeout(pwSaveRetry[formId]);
      pwSaveRetry[formId] = null;
    }
    function send() {
      var fd = new FormData(form);
      fd.append('ajax', '1');
      if (extra) Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
      var x = new XMLHttpRequest();
      x.open('POST', postTo);
      x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      x.onload = function () {
        var t = x.responseText || '';
        var j = null;
        try { j = JSON.parse(t); } catch (e) { j = null; }
        if (j && j.ok) {
          if (pwSaveRetry[formId]) {
            clearTimeout(pwSaveRetry[formId]);
            pwSaveRetry[formId] = null;
          }
          if (box) {
            box.innerHTML = '<span class="noticegreen noticehide sans">' + (j.msg || 'Saved') + '</span>';
          }
          if (j.source && form.source) form.source.value = j.source;
          if (j.id && form.test_id) form.test_id.value = j.id;
          if (j.work != null && form.work && !String(form.work.value || '').trim()) {
            form.work.value = j.work;
          }
          window.onbeforeunload = null;
          return;
        }
        if (pwRetryable(x, j, t)) {
          if (box) box.innerHTML = pwWaitMarkup;
          window.onbeforeunload = function () { return ''; };
          pwSaveRetry[formId] = setTimeout(send, 10000);
          return;
        }
        if (box) {
          box.innerHTML = (j && j.error)
            ? '<span class="noticered sans">' + j.error + '</span>'
            : (t || '<span class="noticered sans">Save failed</span>');
        }
      };
      x.onerror = function () {
        if (box) box.innerHTML = pwWaitMarkup;
        window.onbeforeunload = function () { return ''; };
        pwSaveRetry[formId] = setTimeout(send, 10000);
      };
      x.send(fd);
    }
    send();
  };

  window.pwBindSave = function (formId, postTo, updateId, extra) {
    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.keyCode === 83)) {
        e.preventDefault();
        pwAjaxForm(formId, postTo, updateId, extra);
      }
    });
  };

  window.pwBindPassLogin = function (cbId, msgId, postTo) {
    var cb = document.getElementById(cbId);
    var box = document.getElementById(msgId);
    if (!cb || !cb.form) return;
    cb.addEventListener('change', function () {
      var fd = new FormData(cb.form);
      fd.append('ajax', '1');
      fd.set('disable_password', cb.checked ? '1' : '0');
      var x = new XMLHttpRequest();
      x.open('POST', postTo);
      x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      x.onload = function () {
        var j = null;
        try { j = JSON.parse(x.responseText || ''); } catch (e) { j = null; }
        if (j && j.ok) {
          if (typeof j.off === 'boolean') cb.checked = j.off;
          if (box) {
            box.innerHTML = '';
            void box.offsetWidth;
            box.innerHTML = '<span class="noticegreen noticehide sans">' + (j.msg || 'Saved') + '</span>';
          }
          return;
        }
        cb.checked = !cb.checked;
        if (typeof j.off === 'boolean') cb.checked = j.off;
        if (box) {
          var err = (j && j.error_html) ? j.error_html : ((j && j.error) || 'Save failed');
          box.innerHTML = '<span class="noticered sans">' + err + '</span>';
        }
      };
      x.onerror = function () {
        cb.checked = !cb.checked;
        if (box) box.innerHTML = '<span class="noticered sans">Save failed</span>';
      };
      x.send(fd);
    });
  };

  var pwOauthCheck = '<span class="id-ico"><svg class="id-svg id-check-svg" viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" d="M5 12.5 10 17.5 19 6.5"/></svg></span>';

  function pwOauthSetRow(table, provider, linked) {
    var btn = table.querySelector('[data-oauth="' + provider + '"]');
    if (!btn) return;
    var row = btn.closest('tr');
    if (!row) return;
    var mark = row.querySelector('.id-mark');
    var act = row.querySelector('.id-act');
    if (mark) mark.innerHTML = linked ? pwOauthCheck : '&nbsp;';
    if (act) {
      if (linked) {
        act.innerHTML = '<button type="button" class="set_gray" data-oauth="' + provider + '" data-act="disconnect" title="Stop using this login">Disconnect</button>';
      } else {
        act.innerHTML = '<button type="button" class="lt_button" data-oauth="' + provider + '" data-act="connect" title="Link this login">Connect</button>';
      }
    }
  }

  window.pwBindOauthLinks = function (tableSel) {
    var table = document.querySelector(tableSel);
    if (!table || table.getAttribute('data-oauth-bound')) return;
    table.setAttribute('data-oauth-bound', '1');
    window.addEventListener('message', function (ev) {
      if (ev.origin !== window.location.origin) return;
      var d = ev.data;
      if (!d || !d.pw99oauth) return;
      if (d.ok) pwOauthSetRow(table, d.provider, true);
    });
    table.addEventListener('click', function (ev) {
      var btn = ev.target.closest ? ev.target.closest('[data-oauth]') : null;
      if (!btn || !table.contains(btn)) return;
      ev.preventDefault();
      var p = btn.getAttribute('data-oauth');
      var act = btn.getAttribute('data-act');
      if (act === 'connect') {
        var w = 520;
        var h = 640;
        var left = window.screenX + Math.max(0, (window.outerWidth - w) / 2);
        var top = window.screenY + Math.max(0, (window.outerHeight - h) / 2);
        window.open(
          'oauth.php?p=' + encodeURIComponent(p) + '&link=1&popup=1',
          'pw99oauth',
          'popup=yes,width=' + w + ',height=' + h + ',left=' + left + ',top=' + top
        );
        return;
      }
      if (act === 'disconnect') {
        var fd = new FormData();
        fd.append('_csrf', table.getAttribute('data-csrf') || '');
        fd.append('ajax', '1');
        fd.append('unlink_oauth', p);
        var x = new XMLHttpRequest();
        x.open('POST', 'ajax/save-oauth.php');
        x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        x.onload = function () {
          var j = null;
          try { j = JSON.parse(x.responseText || ''); } catch (e) { j = null; }
          if (j && j.ok) pwOauthSetRow(table, p, false);
        };
        x.send(fd);
      }
    });
  };

  window.onNavWarn = function () {
    window.onbeforeunload = function () { return ''; };
  };
  window.offNavWarn = function () { window.onbeforeunload = null; };

  function b64uToBuf(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s);
    var u = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) u[i] = bin.charCodeAt(i);
    return u.buffer;
  }
  function bufToB64u(buf) {
    var u = new Uint8Array(buf);
    var s = '';
    for (var i = 0; i < u.length; i++) s += String.fromCharCode(u[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  window.pwPasskeyRegister = async function (optUrl, saveUrl, csrf) {
    var opt = await (await fetch(optUrl, { credentials: 'same-origin' })).json();
    opt.challenge = b64uToBuf(opt.challenge);
    opt.user.id = b64uToBuf(opt.user.id);
    var cred = await navigator.credentials.create({ publicKey: opt });
    var spki = cred.response.getPublicKey();
    var body = new URLSearchParams();
    body.set('_csrf', csrf);
    body.set('id', bufToB64u(cred.rawId));
    body.set('spki', bufToB64u(spki));
    body.set('name', 'Passkey');
    var r = await fetch(saveUrl, { method: 'POST', body: body, credentials: 'same-origin' });
    location.reload();
    return r;
  };

  window.pwPasskeyLogin = async function (optUrl) {
    var opt = await (await fetch(optUrl, { credentials: 'same-origin' })).json();
    opt.challenge = b64uToBuf(opt.challenge);
    if (opt.allowCredentials) {
      opt.allowCredentials.forEach(function (c) { c.id = b64uToBuf(c.id); });
    }
    var cred = await navigator.credentials.get({ publicKey: opt });
    var fd = new FormData();
    fd.set('passkey', '1');
    fd.set('id', bufToB64u(cred.rawId));
    fd.set('clientData', bufToB64u(cred.response.clientDataJSON));
    fd.set('authData', bufToB64u(cred.response.authenticatorData));
    fd.set('sig', bufToB64u(cred.response.signature));
    var x = await fetch('login.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    if (x.redirected) { location.href = x.url; return; }
    location.href = 'login.php';
  };

  window.pwDrawTotpQr = function (elId) {
    var el = document.getElementById(elId);
    if (!el || typeof qrcodegen === 'undefined') return;
    var uri = el.getAttribute('data-otpauth') || '';
    if (!uri) return;
    var qr = qrcodegen.QrCode.encodeText(uri, qrcodegen.QrCode.Ecc.MEDIUM);
    var n = qr.size;
    var q = 4;
    var dim = n + 2 * q;
    var parts = ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + dim + ' ' + dim + '" width="240" height="240" shape-rendering="crispEdges" aria-hidden="true">'];
    parts.push('<rect width="' + dim + '" height="' + dim + '" fill="#ffffff"/>');
    for (var y = 0; y < n; y++) {
      for (var x = 0; x < n; x++) {
        if (qr.getModule(x, y)) {
          parts.push('<rect x="' + (x + q) + '" y="' + (y + q) + '" width="1" height="1" fill="#111111"/>');
        }
      }
    }
    parts.push('</svg>');
    el.innerHTML = parts.join('');
  };

  window.pwPkEdit = function (btn) {
    var form = btn.closest ? btn.closest('.pk-row') : btn.form;
    if (!form) return;
    var rows = document.querySelectorAll('.pk-row.is-editing');
    for (var i = 0; i < rows.length; i++) {
      if (rows[i] !== form) pwPkClose(rows[i]);
    }
    pwPkOpen(form);
  };

  function pwPkOpen(form) {
    form.classList.add('is-editing');
    var label = form.querySelector('.pk-label');
    var input = form.querySelector('.pk-input');
    var edit = form.querySelector('.pk-edit');
    var save = form.querySelector('.pk-save');
    if (label) label.hidden = true;
    if (edit) edit.hidden = true;
    if (save) save.hidden = false;
    if (input) {
      input.setAttribute('data-orig', input.value);
      input.hidden = false;
      input.focus();
      input.select();
    }
  }

  function pwPkClose(form) {
    form.classList.remove('is-editing');
    var label = form.querySelector('.pk-label');
    var input = form.querySelector('.pk-input');
    var edit = form.querySelector('.pk-edit');
    var save = form.querySelector('.pk-save');
    if (label) label.hidden = false;
    if (input) input.hidden = true;
    if (edit) edit.hidden = false;
    if (save) save.hidden = true;
  }

  window.pwPkCancel = function (btn) {
    var form = btn.closest ? btn.closest('.pk-row') : btn.form;
    if (!form) return;
    var input = form.querySelector('.pk-input');
    var orig = input ? input.getAttribute('data-orig') : null;
    if (input && orig !== null) input.value = orig;
    pwPkClose(form);
  };

  function pwConfirmMask() {
    var m = document.getElementById('pw-confirm-mask');
    if (m) return m;
    m = document.createElement('div');
    m.id = 'pw-confirm-mask';
    m.setAttribute('title', 'Cancel');
    document.body.appendChild(m);
    m.addEventListener('click', pwConfirmClose);
    return m;
  }

  var pwConfirmOpenWrap = null;

  function pwConfirmClearPos(el) {
    if (!el) return;
    el.style.top = '';
    el.style.left = '';
  }

  function pwConfirmEls(wrap) {
    return {
      go: wrap.querySelector('.pw-confirm-go'),
      cancel: wrap._pwCancel || wrap.querySelector('.pw-confirm-cancel'),
      yes: wrap._pwYes || wrap.querySelector('.pw-confirm-yes')
    };
  }

  function pwConfirmPlace(wrap) {
    if (!wrap) return;
    var els = pwConfirmEls(wrap);
    if (!els.go || !els.cancel || !els.yes) return;
    var r = els.go.getBoundingClientRect();
    var gap = 8;
    var ch = els.cancel.offsetHeight || r.height;
    var top = r.top - gap - ch;
    if (top < 8) top = r.bottom + gap;
    els.cancel.style.top = top + 'px';
    els.cancel.style.left = r.left + 'px';
    var cr = els.cancel.getBoundingClientRect();
    var yesLeft = cr.right + gap;
    var yesW = els.yes.offsetWidth || r.width;
    if (yesLeft + yesW > window.innerWidth - 8) {
      yesLeft = Math.max(8, window.innerWidth - 8 - yesW);
    }
    els.yes.style.top = cr.top + 'px';
    els.yes.style.left = yesLeft + 'px';
  }

  function pwConfirmPark(wrap, el) {
    if (!el || !wrap) return;
    el.classList.remove('pw-confirm-float');
    el.hidden = true;
    pwConfirmClearPos(el);
    if (el.parentNode !== wrap) wrap.appendChild(el);
  }

  function pwConfirmClose() {
    var nodes = document.querySelectorAll('.pw-confirm-wrap.is-open');
    for (var i = 0; i < nodes.length; i++) {
      var wrap = nodes[i];
      wrap.classList.remove('is-open');
      var els = pwConfirmEls(wrap);
      pwConfirmPark(wrap, els.cancel);
      if (els.yes) {
        els.yes.disabled = true;
        if (els.yes.getAttribute('data-pw-form')) {
          els.yes.removeAttribute('form');
          els.yes.removeAttribute('data-pw-form');
        }
      }
      pwConfirmPark(wrap, els.yes);
      wrap._pwCancel = null;
      wrap._pwYes = null;
    }
    pwConfirmOpenWrap = null;
    var m = document.getElementById('pw-confirm-mask');
    if (m) m.style.display = 'none';
  }

  function pwConfirmOpen(wrap) {
    if (!wrap) return;
    pwConfirmClose();
    pwConfirmMask().style.display = 'block';
    wrap.classList.add('is-open');
    var cancel = wrap.querySelector('.pw-confirm-cancel');
    var yes = wrap.querySelector('.pw-confirm-yes');
    var form = wrap.closest('form');
    if (form && yes) {
      if (!form.id) form.id = 'pw-confirm-form-' + String(Date.now());
      yes.setAttribute('form', form.id);
      yes.setAttribute('data-pw-form', '1');
    }
    if (cancel) {
      cancel.classList.add('pw-confirm-float');
      cancel.hidden = false;
      document.body.appendChild(cancel);
    }
    if (yes) {
      yes.classList.add('pw-confirm-float');
      yes.hidden = false;
      yes.disabled = false;
      document.body.appendChild(yes);
    }
    wrap._pwCancel = cancel;
    wrap._pwYes = yes;
    pwConfirmOpenWrap = wrap;
    pwConfirmPlace(wrap);
  }

  document.addEventListener('click', function (e) {
    var go = e.target.closest ? e.target.closest('.pw-confirm-go') : null;
    if (go) {
      e.preventDefault();
      pwConfirmOpen(go.closest('.pw-confirm-wrap'));
      return;
    }
    if (e.target.closest && e.target.closest('.pw-confirm-cancel')) {
      e.preventDefault();
      pwConfirmClose();
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' || e.keyCode === 27) pwConfirmClose();
  });
  window.addEventListener('scroll', function () {
    if (pwConfirmOpenWrap) pwConfirmPlace(pwConfirmOpenWrap);
  }, true);
  window.addEventListener('resize', function () {
    if (pwConfirmOpenWrap) pwConfirmPlace(pwConfirmOpenWrap);
  });

  function pwThemeLive() {
    var form = document.getElementById('pw-theme-form');
    if (!form) return;
    var saved = form.getAttribute('data-saved') || '';
    var link = document.getElementById('pw-theme-css');
    var keep = document.getElementById('pw-theme-keep');
    function apply() {
      var sel = form.querySelector('input[name=theme]:checked');
      var id = sel ? sel.value : saved;
      if (id && /^theme-[a-z0-9-]+$/.test(id)) {
        if (!link) {
          link = document.createElement('link');
          link.rel = 'stylesheet';
          link.id = 'pw-theme-css';
          link.type = 'text/css';
          document.head.appendChild(link);
        }
        link.href = 'css/' + id + '.css';
      }
      if (keep) {
        var same = id === saved;
        keep.disabled = same;
        keep.className = same ? 'set_writ_disabled' : 'set_writ_green';
      }
    }
    form.addEventListener('change', apply);
    apply();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', pwThemeLive);
  } else {
    pwThemeLive();
  }
})();

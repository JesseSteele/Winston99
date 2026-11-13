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

  function pwEsc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&').replace(/</g, '<').replace(/>/g, '>').replace(/"/g, '"');
  }

  function pwCookie(name) {
    var parts = document.cookie ? document.cookie.split('; ') : [];
    for (var i = 0; i < parts.length; i++) {
      var p = parts[i];
      var eq = p.indexOf('=');
      if (eq > 0 && decodeURIComponent(p.slice(0, eq)) === name) {
        return decodeURIComponent(p.slice(eq + 1));
      }
    }
    return '';
  }

  function pwSetCookie(name, value) {
    document.cookie = encodeURIComponent(name) + '=' + encodeURIComponent(value)
      + '; Max-Age=31536000; Path=/; SameSite=Lax';
  }

  function pwClearCookie(name) {
    document.cookie = encodeURIComponent(name) + '=; Max-Age=0; Path=/; SameSite=Lax';
  }

  function pwBrowserLoad() {
    var all = {};
    try {
      var raw = localStorage.getItem('winston99_browser_save');
      if (raw) all = JSON.parse(raw) || {};
    } catch (e) { all = {}; }
    if (all && Object.keys(all).length) return all;
    var head = pwCookie('winston99_bs');
    if (head.indexOf('chunks:') === 0) {
      var n = parseInt(head.slice(7), 10) || 0;
      var json = '';
      for (var i = 0; i < n; i++) json += pwCookie('winston99_bs_' + i);
      try { all = JSON.parse(json) || {}; } catch (e2) { all = {}; }
    } else if (head) {
      try {
        var one = JSON.parse(head);
        if (one && one.kind) all[one.kind + ':' + one.id] = one;
        else if (one && typeof one === 'object') all = one;
      } catch (e3) { all = {}; }
    }
    return all || {};
  }

  function pwBrowserWrite(all) {
    var json = '{}';
    try { json = JSON.stringify(all); } catch (e) { return; }
    try { localStorage.setItem('winston99_browser_save', json); } catch (e2) {}
    var parts = document.cookie ? document.cookie.split('; ') : [];
    for (var i = 0; i < parts.length; i++) {
      var name = decodeURIComponent(parts[i].split('=')[0]);
      if (name === 'winston99_bs' || name.indexOf('winston99_bs_') === 0) pwClearCookie(name);
    }
    var max = 3200;
    if (json.length <= max) {
      pwSetCookie('winston99_bs', json);
      return;
    }
    var slim = {};
    Object.keys(all).forEach(function (k) {
      var it = all[k] || {};
      slim[k] = { kind: it.kind, id: it.id, title: it.title, href: it.href, at: it.at };
    });
    var slimJson = JSON.stringify(slim);
    if (slimJson.length <= max) {
      pwSetCookie('winston99_bs', slimJson);
      return;
    }
    var chunks = [];
    for (var c = 0; c < json.length && chunks.length < 8; c += max) chunks.push(json.slice(c, c + max));
    pwSetCookie('winston99_bs', 'chunks:' + chunks.length);
    chunks.forEach(function (part, n) { pwSetCookie('winston99_bs_' + n, part); });
  }

  window.pwRememberBrowser = function (item) {
    if (!item || !item.kind) return;
    var all = pwBrowserLoad();
    item.at = item.at || Date.now();
    all[item.kind + ':' + String(item.id == null ? '0' : item.id)] = item;
    pwBrowserWrite(all);
    pwPaintBrowserSaves();
  };

  window.pwForgetBrowser = function (kind, id) {
    var all = pwBrowserLoad();
    delete all[kind + ':' + String(id)];
    pwBrowserWrite(all);
    pwPaintBrowserSaves();
  };

  function pwWritTable(mount) {
    var el = mount.nextElementSibling;
    while (el && el.tagName !== 'TABLE') el = el.nextElementSibling;
    if (el && el.classList && el.classList.contains('writ')) return el;
    return null;
  }

  function pwBrowserActor(href) {
    href = String(href || '');
    if (href.indexOf('review.php') !== -1) return 'editor';
    if (href.indexOf('writ.php') !== -1 || href.indexOf('take-test.php') !== -1) return 'writer';
    return '';
  }

  function pwRecoverButton(it) {
    var href = String(it.href || 'writ.php');
    var action = href.split('?')[0].split('/').pop() || 'writ.php';
    var id = String(it.id == null ? '' : it.id);
    var q = href.split('?')[1] || '';
    q.split('&').forEach(function (part) {
      var kv = part.split('=');
      if (decodeURIComponent(kv[0] || '') === 'w' && kv.length > 1) id = decodeURIComponent(kv.slice(1).join('='));
    });
    return '<form action="' + pwEsc(action) + '" method="get" style="display:inline">'
      + '<input type="hidden" name="w" value="' + pwEsc(id) + '">'
      + '<input type="submit" title="Recover the copy saved in this browser" value="Recover" class="set_writ_orange">'
      + '</form>';
  }

  function pwPaintWritSaves(mount, rows) {
    mount.innerHTML = '';
    var table = pwWritTable(mount);
    var mode = mount.getAttribute('data-mode') || '';
    if (table) {
      var old = table.querySelectorAll('tr.browser-save-extra');
      for (var e = 0; e < old.length; e++) old[e].parentNode.removeChild(old[e]);
      var tinted = table.querySelectorAll('tr.browser_save');
      for (var t = 0; t < tinted.length; t++) {
        tinted[t].classList.remove('browser_save');
        var prev = tinted[t].querySelector('input[data-pw-recover]');
        if (prev) {
          prev.value = prev.getAttribute('data-pw-label') || prev.value;
          prev.removeAttribute('data-pw-recover');
        }
      }
    }
    if (!rows.length || !table) return;
    var header = table.querySelector('tr');
    var cols = header ? header.children.length : 0;
    rows.forEach(function (it) {
      var id = String(it.id == null ? '' : it.id);
      var tr = null;
      var hiddens = table.querySelectorAll('input[name="w"]');
      for (var h = 0; h < hiddens.length; h++) {
        if (hiddens[h].value === id) {
          tr = hiddens[h].closest ? hiddens[h].closest('tr') : null;
          break;
        }
      }
      if (tr) {
        var orange = tr.querySelector('input.set_writ_orange');
        if (!orange) return;
        tr.classList.add('browser_save');
        if (!orange.getAttribute('data-pw-label')) orange.setAttribute('data-pw-label', orange.value);
        orange.value = 'Recover';
        orange.title = 'Recover the copy saved in this browser';
        orange.setAttribute('data-pw-recover', '1');
        return;
      }
      if (pwBrowserActor(it.href) !== mode) return;
      var work = it.fields && it.fields.work ? it.fields.work : '';
      var title = it.title || ('#' + id);
      var html = '<tr class="lr browser_save browser-save-extra"><td>' + pwRecoverButton(it) + '</td>'
        + '<td>' + pwEsc(work) + '</td><td>' + pwEsc(title) + '</td>';
      for (var c = 3; c < cols; c++) html += '<td></td>';
      html += '</tr>';
      if (header) header.insertAdjacentHTML('afterend', html);
    });
  }

  function pwPaintBrowserSaves() {
    var mounts = document.querySelectorAll('.browser-save-mount');
    if (!mounts.length) return;
    var all = pwBrowserLoad();
    for (var m = 0; m < mounts.length; m++) {
      var mount = mounts[m];
      var kind = mount.getAttribute('data-kind');
      var rows = [];
      Object.keys(all).forEach(function (k) {
        if (all[k] && all[k].kind === kind) rows.push(all[k]);
      });
      rows.sort(function (a, b) { return (b.at || 0) - (a.at || 0); });
      if (kind === 'writ') {
        pwPaintWritSaves(mount, rows);
        continue;
      }
      if (!rows.length) { mount.innerHTML = ''; continue; }
      var html = '<table class="list lt sans"><tbody>';
      for (var i = 0; i < rows.length; i++) {
        var it = rows[i];
        var cc = i % 2 ? 'dr' : 'lr';
        html += '<tr class="' + cc + ' browser_save"><td><span class="warning sans">Browser</span></td><td><a class="listed_note" href="'
          + pwEsc(it.href || '#') + '">' + pwEsc(it.title || ('#' + it.id))
          + '</a> <small class="dk">saved in this browser</small></td></tr>';
      }
      html += '</tbody></table>';
      mount.innerHTML = html;
    }
  }

  function pwSnapshot(form) {
    var fields = {};
    if (!form) return fields;
    var fd = new FormData(form);
    fd.forEach(function (v, k) {
      if (typeof v === 'string') fields[k] = v;
    });
    return fields;
  }

  function pwHoldFromForm(form, warning) {
    if (!form) return;
    var kind = form.getAttribute('data-browser-kind') || '';
    var id = form.getAttribute('data-browser-id') || '0';
    if (!kind) return;
    var fields = pwSnapshot(form);
    pwRememberBrowser({
      kind: kind,
      id: id,
      title: fields.title || fields.work || (kind + ' ' + id),
      href: window.location.pathname.split('/').pop() + window.location.search,
      warning: warning,
      fields: fields
    });
  }

  var pwSaveRetry = {};
  var pwDbWarn = "Can't connect to the database; saved in this browser.";

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
          if (j.forget && j.forget.kind) pwForgetBrowser(j.forget.kind, j.forget.id);
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
        if (j && j.browser) {
          pwRememberBrowser(j);
          if (box) box.innerHTML = '<span class="warning sans">' + pwEsc(j.warning || pwDbWarn) + '</span>';
          window.onbeforeunload = function () { return ''; };
          return;
        }
        if (pwRetryable(x, j, t)) {
          pwHoldFromForm(form, pwDbWarn);
          if (box) box.innerHTML = '<span class="warning sans">' + pwEsc(pwDbWarn) + '</span>';
          window.onbeforeunload = function () { return ''; };
          return;
        }
        if (box) {
          var cls = (j && j.cls) || 'noticered';
          box.innerHTML = (j && j.error)
            ? '<span class="' + cls + ' sans">' + pwEsc(j.error) + '</span>'
            : (t || '<span class="noticered sans">Save failed</span>');
        }
      };
      x.onerror = function () {
        pwHoldFromForm(form, pwDbWarn);
        if (box) box.innerHTML = '<span class="warning sans">' + pwEsc(pwDbWarn) + '</span>';
        window.onbeforeunload = function () { return ''; };
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
          var cls = (j && j.cls) || 'error';
          var err = (j && j.error) || 'No database connection; changes not saved!';
          box.innerHTML = '<span class="' + cls + ' sans">' + pwEsc(err) + '</span>';
        }
      };
      x.onerror = function () {
        cb.checked = !cb.checked;
        if (box) box.innerHTML = '<span class="error sans">No database connection; changes not saved!</span>';
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
      if (!d || !d.winston99oauth) return;
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
          'winston99oauth',
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
          else {
            var note = document.createElement('p');
            note.className = 'sans error';
            note.textContent = (j && j.error) || 'No database connection; changes not saved!';
            table.parentNode.insertBefore(note, table);
          }
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
    try {
      var opt = await (await fetch(optUrl, {credentials: 'same-origin'})).json();
      if (!opt || opt.error) throw new Error(opt && opt.error || 'options failed');
      var pub = opt.publicKey;
      pub.challenge = b64uToBuf(pub.challenge);
      pub.user.id = b64uToBuf(pub.user.id);
      if (pub.excludeCredentials) {
        pub.excludeCredentials.forEach(function (c) { c.id = b64uToBuf(c.id); });
      }
      var cred = await navigator.credentials.create({publicKey: pub});
      var fd = new FormData();
      fd.append('_csrf', csrf);
      fd.append('id', bufToB64u(cred.rawId));
      fd.append('spki', bufToB64u(cred.response.getPublicKey ? cred.response.getPublicKey() : new ArrayBuffer(0)));
      fd.append('name', 'Passkey');
      var r = await fetch(saveUrl, {method: 'POST', body: fd, credentials: 'same-origin'});
      if (r.redirected) location.href = r.url;
      else location.reload();
    } catch (e) {
      alert('Passkey registration failed: ' + (e.message || e));
    }
  };

  window.pwPasskeyLogin = async function (optUrl) {
    try {
      var opt = await (await fetch(optUrl, {credentials: 'same-origin'})).json();
      if (!opt || opt.error) throw new Error(opt && opt.error || 'options failed');
      var pub = opt.publicKey;
      pub.challenge = b64uToBuf(pub.challenge);
      if (pub.allowCredentials) {
        pub.allowCredentials.forEach(function (c) { c.id = b64uToBuf(c.id); });
      }
      var cred = await navigator.credentials.get({publicKey: pub});
      var fd = new FormData();
      fd.append('passkey', '1');
      fd.append('id', bufToB64u(cred.rawId));
      fd.append('clientData', bufToB64u(cred.response.clientDataJSON));
      fd.append('authData', bufToB64u(cred.response.authenticatorData));
      fd.append('sig', bufToB64u(cred.response.signature));
      var x = await fetch('login.php', {method: 'POST', body: fd, credentials: 'same-origin'});
      if (x.redirected) location.href = x.url;
      else location.href = 'login.php';
    } catch (e) {
      alert('Passkey failed: ' + (e.message || e));
    }
  };

  function pwConfirmMask() {
    var m = document.getElementById('pw-confirm-mask');
    if (!m) {
      m = document.createElement('div');
      m.id = 'pw-confirm-mask';
      m.className = 'pw-confirm-mask';
      document.body.appendChild(m);
    }
    return m;
  }
  var pwConfirmOpenWrap = null;
  function pwConfirmClose() {
    var m = document.getElementById('pw-confirm-mask');
    if (m) m.style.display = 'none';
    if (pwConfirmOpenWrap) {
      pwConfirmOpenWrap.classList.remove('is-open');
      var c = pwConfirmOpenWrap._pwCancel;
      var y = pwConfirmOpenWrap._pwYes;
      if (c) { c.hidden = true; c.classList.remove('pw-confirm-float'); pwConfirmOpenWrap.appendChild(c); }
      if (y) { y.hidden = true; y.disabled = true; y.classList.remove('pw-confirm-float'); pwConfirmOpenWrap.appendChild(y); }
      pwConfirmOpenWrap = null;
    }
  }
  function pwConfirmPlace(wrap) {
    var go = wrap.querySelector('.pw-confirm-go');
    if (!go) return;
    var r = go.getBoundingClientRect();
    var cancel = wrap._pwCancel;
    var yes = wrap._pwYes;
    if (cancel) {
      cancel.style.left = (r.left) + 'px';
      cancel.style.top = (r.bottom + 8) + 'px';
    }
    if (yes) {
      yes.style.left = (r.right - yes.offsetWidth) + 'px';
      yes.style.top = (r.bottom + 8) + 'px';
    }
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
    var form = document.getElementById('winston99-theme-form');
    if (!form) return;
    var saved = form.getAttribute('data-saved') || '';
    var link = document.getElementById('winston99-theme-css');
    var keep = document.getElementById('winston99-theme-keep');
    function apply() {
      var sel = form.querySelector('input[name=theme]:checked');
      var id = sel ? sel.value : saved;
      if (id && /^theme-[a-z0-9-]+$/.test(id)) {
        if (!link) {
          link = document.createElement('link');
          link.rel = 'stylesheet';
          link.id = 'winston99-theme-css';
          link.type = 'text/css';
          document.head.appendChild(link);
        }
        link.href = 'css/' + id + '.css';
      }
      document.documentElement.classList.toggle('tone-light', (window.pwLightThemes || []).indexOf(id) !== -1);
      if (keep) {
        var same = id === saved;
        keep.disabled = same;
        keep.className = same ? 'lt_button btn-dormant' : 'set_writ_green';
      }
    }
    form.addEventListener('change', apply);
    apply();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      pwThemeLive();
      pwPaintBrowserSaves();
    });
  } else {
    pwThemeLive();
    pwPaintBrowserSaves();
  }

  /* One-shot controls: first click stands, a second click on this page does nothing.
     Save and other stay-on-the-page controls are left alone. No visible change. */
  function pwOnceSave(el) {
    if (!el || !el.getAttribute) return false;
    var name = (el.getAttribute('name') || '').toLowerCase();
    if (name.indexOf('save') !== -1) return true;
    var val = el.tagName === 'INPUT' ? (el.value || '') : (el.textContent || '');
    val = val.replace(/\s+/g, ' ').trim().toLowerCase();
    return val === 'save';
  }

  document.addEventListener('click', function (e) {
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var t = e.target;
    if (!t || !t.closest) return;
    var a = t.closest('a[href]');
    if (!a) return;
    if (a.classList && a.classList.contains('id-btn')) return; // login OAuth buttons
    var href = a.getAttribute('href') || '';
    if (href === '' || href.charAt(0) === '#') return;
    if (a.getAttribute('data-pw-fired') === '1') {
      e.preventDefault();
      e.stopPropagation();
      return;
    }
    a.setAttribute('data-pw-fired', '1');
  }, true);

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.getAttribute) return;
    var action = (form.getAttribute('action') || '').toLowerCase();
    if (action.indexOf('login.php') !== -1) return; // allow login form
    var sub = e.submitter || null;
    if (sub && (pwOnceSave(sub) || (sub.classList && (sub.classList.contains('pw-confirm-go') || sub.classList.contains('pw-confirm-cancel'))))) return;
    var val = sub ? ((sub.value || '') + ' ' + (sub.textContent || '')).toLowerCase() : '';
    if (val.indexOf('log in') !== -1 || val.indexOf('verify') !== -1) return;
    if (form.getAttribute('data-pw-fired') === '1') {
      e.preventDefault();
      e.stopPropagation();
      return;
    }
    form.setAttribute('data-pw-fired', '1');
  }, true);
})();
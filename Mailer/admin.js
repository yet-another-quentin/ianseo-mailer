/*
 * Mailer — administration page. Depends on i18n.js and window.ML_CONTEXT (provided by index.php).
 * Full re-render through innerHTML on state changes, every value through esc()/th(). Typing in the template editor
 * does not re-render the page (it would lose the cursor): only the preview frame is refreshed.
 */
(function () {
  'use strict';
  let ctx = window.ML_CONTEXT;
  const { t, locale } = window.MLi18n.make(ctx.lang);
  const app = document.getElementById('ml-admin');

  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const th = (key, params) => {
    if (!params) return t(key);
    const p = {};
    for (const k in params) p[k] = esc(params[k]);
    return t(key, p);
  };
  const pick = (o, lang) => (o && (o[lang] || o.fr || o.en)) || '';

  const state = {
    busy: false, flash: '', flashErr: false,
    smtp: Object.assign({ password: '' }, ctx.smtp),
    competition: Object.assign({}, ctx.competition),
    testTo: '', test: null,
    editing: null,          // {id, lang, subject, body, custom, defaultSubject, defaultBody, vars}
    preview: null, previewTo: '', previewMsg: '',
    viewing: null,          // html of a sent email
    channel: ctx.channel || 'stable', update: null, updateMsg: '',
  };

  // ---------- API ----------

  async function api(action, body) {
    const r = await fetch('ajax.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ action }, body || {})),
    });
    let j;
    try { j = await r.json(); } catch (e) { throw new Error('HTTP ' + r.status); }
    if (j && j.error && !('ok' in j)) throw new Error(j.error);
    return j;
  }

  async function run(fn, okMsg) {
    if (state.busy) return;
    state.busy = true;
    render();
    try {
      const res = await fn();
      if (res && res.templates && res.tour) ctx = res;
      if (okMsg) flash(typeof okMsg === 'function' ? okMsg(res) : okMsg, false);
    } catch (e) {
      flash(t('admin.failed', { error: e.message }), true);
    } finally {
      state.busy = false;
      render();
    }
  }

  let flashTimer;
  function flash(m, err) {
    state.flash = m || '';
    state.flashErr = !!err;
    clearTimeout(flashTimer);
    if (m) flashTimer = setTimeout(() => { state.flash = ''; render(); }, 7000);
  }

  // ---------- actions ----------

  const saveSmtp = () => run(async () => {
    const res = await api('smtp-save', { smtp: state.smtp });
    state.smtp = Object.assign({ password: '' }, res.smtp);
    return res;
  }, t('admin.saved'));

  const sendTest = () => run(async () => {
    state.test = await api('test', { to: state.testTo.trim(), lang: ctx.tour.lang });
    if (state.test.ok) ctx = await api('context');
    return null;
  });

  const saveCompetition = () => run(() => api('competition-save', { competition: state.competition }), t('admin.saved'));

  async function openTemplate(id, lang) {
    try {
      const res = await api('template', { id, lang });
      state.editing = res.template;
      state.preview = res.preview;
      state.previewMsg = '';
      render();
      const ed = document.getElementById('ml-editor');
      if (ed) ed.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) {
      flash(t('admin.failed', { error: e.message }), true);
      render();
    }
  }

  let previewTimer;
  function schedulePreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(async () => {
      const e = state.editing;
      if (!e) return;
      try {
        const res = await api('preview', { id: e.id, lang: e.lang, subject: e.subject, body: e.body });
        state.preview = res.preview;
        updatePreview();
      } catch (err) { /* keep the previous preview */ }
    }, 400);
  }

  const saveTemplate = reset => run(async () => {
    const e = state.editing;
    const res = await api(reset ? 'template-reset' : 'template-save', { id: e.id, lang: e.lang, subject: e.subject, body: e.body });
    state.editing = res.template;
    state.preview = res.preview;
    ctx.templates = res.templates;
    return null;
  }, reset ? t('tpl.resetDone') : t('admin.saved'));

  const sendPreview = () => run(async () => {
    const e = state.editing;
    const res = await api('preview-send', { id: e.id, lang: e.lang, subject: e.subject, body: e.body, to: state.previewTo.trim() });
    state.previewMsg = res.ok ? t('tpl.previewSent', { to: state.previewTo.trim() }) : t('admin.failed', { error: res.error });
    return null;
  });

  const flushNow = () => run(() => api('flush'), res => t('outbox.flushed', { sent: res.sent, failed: res.failed }));
  const retry = ids => run(() => api('retry', { ids }), t('outbox.retried'));
  const purge = () => {
    const days = parseInt(document.getElementById('ml-purge-days').value, 10);
    if (days >= 0) run(() => api('purge', { days }), res => t('outbox.purged', { n: res.purged }));
  };
  async function viewSent(id) {
    try {
      state.viewing = (await api('outbox-html', { id })).html;
      render();
    } catch (e) {
      flash(t('admin.failed', { error: e.message }), true);
      render();
    }
  }

  async function checkUpdate(force) {
    try {
      state.update = await api('update-check', { force: !!force });
      state.updateMsg = state.update.error ? t('update.checkFailed', { error: state.update.error }) : '';
    } catch (e) {
      state.updateMsg = t('update.checkFailed', { error: e.message });
    }
    render();
  }

  async function setChannel(channel) {
    try {
      await api('update-channel', { channel });
      state.channel = channel;
      await checkUpdate(true);
    } catch (e) {
      flash(t('admin.failed', { error: e.message }), true);
      render();
    }
  }

  const installUpdate = () => run(async () => {
    const res = await api('update-install');
    if (res.error) throw new Error(res.error);
    state.update = null;
    state.updateMsg = t('update.done', { version: res.version });
    return null;
  });

  // ---------- rendering ----------

  const fmtDate = d => (d ? new Date(d).toLocaleString(locale, { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '');
  const tplName = id => { const x = ctx.templates.find(tp => tp.id === id); return x ? pick(x.name, ctx.lang) : id; };

  function render() {
    const focus = document.activeElement && document.activeElement.id;
    app.innerHTML = [
      renderHeader(),
      renderStatus(),
      state.flash ? `<div class="ml-alert ${state.flashErr ? 'ml-err' : 'ml-ok'}">${esc(state.flash)}</div>` : '',
      renderSmtp(),
      renderCompetition(),
      renderTemplates(),
      renderOutbox(),
      state.viewing !== null ? renderViewer() : '',
    ].join('');
    bind();
    updatePreview();
    if (state.viewing !== null) {
      const f = document.getElementById('ml-view-frame');
      if (f) f.srcdoc = state.viewing;
    }
    if (focus) {
      const el = document.getElementById(focus);
      if (el) el.focus();
    }
  }

  function renderHeader() {
    let upd = '';
    if (ctx.isRoot) {
      const u = state.update;
      upd = `<span class="ml-upd">
        <label>${th('update.channel')} <select id="ml-channel" ${state.busy ? 'disabled' : ''}>
          ${['stable', 'nightly'].map(c => `<option value="${c}" ${state.channel === c ? 'selected' : ''}>${th('update.channel.' + c)}</option>`).join('')}
        </select></label>
        ${u && u.available ? `<span>${th('update.available', { latest: u.latest, current: ctx.version })}</span>
          <button type="button" id="ml-update" ${state.busy ? 'disabled' : ''}>${th('update.install')}</button>` : ''}
        ${u && !u.available && !u.error ? `<span class="ml-muted">${th('update.upToDate')}</span>` : ''}
        <button type="button" class="ml-linkbtn" id="ml-update-check">${th('update.check')}</button>
        ${state.updateMsg ? `<span>${esc(state.updateMsg)}</span>` : ''}
      </span>`;
    }
    return `<div class="ml-head"><h2>${th('admin.title')}</h2><span class="ml-muted">v${esc(ctx.version)}</span>${upd}</div>`;
  }

  function renderStatus() {
    if (ctx.ready) return `<div class="ml-alert ml-ok">${th('status.ready', { from: ctx.smtp.fromEmail })}</div>`;
    if (ctx.configured) return `<div class="ml-alert ml-warn">${th('status.untested')}</div>`;
    return `<div class="ml-alert ml-err">${th(ctx.isRoot ? 'status.notConfigured' : 'status.notConfiguredAsk')}</div>`;
  }

  function renderSmtp() {
    const s = state.smtp;
    const dis = ctx.isRoot ? '' : 'disabled';
    const test = state.test;
    return `<div class="ml-box"><h3>${th('smtp.title')}</h3>
      <div class="ml-muted ml-small">${th(ctx.isRoot ? 'smtp.help' : 'smtp.readOnly')}</div>
      <div class="ml-grid">
        <label>${th('smtp.host')}<input type="text" id="ml-host" value="${esc(s.host)}" placeholder="smtp.example.org" autocomplete="off" ${dis}></label>
        <label>${th('smtp.security')}<select id="ml-security" ${dis}>
          ${[['tls', 'smtp.tls'], ['ssl', 'smtp.ssl'], ['', 'smtp.none']].map(([v, k]) => `<option value="${v}" ${s.security === v ? 'selected' : ''}>${th(k)}</option>`).join('')}
        </select></label>
        <label>${th('smtp.port')}<input type="number" id="ml-port" min="1" max="65535" value="${esc(s.port)}" ${dis}></label>
        ${ctx.isRoot ? `<label>${th('smtp.user')}<input type="text" id="ml-user" value="${esc(s.user)}" autocomplete="off"></label>
        <label>${th('smtp.password')}<input type="password" id="ml-password" value="${esc(s.password)}" autocomplete="new-password" placeholder="${s.hasPassword ? esc(t('smtp.passwordKept')) : ''}"></label>` : ''}
        <label>${th('smtp.fromEmail')}<input type="email" id="ml-from-email" value="${esc(s.fromEmail)}" placeholder="ianseo@example.org" ${dis}></label>
        <label>${th('smtp.fromName')}<input type="text" id="ml-from-name" value="${esc(s.fromName)}" ${dis}></label>
      </div>
      ${ctx.isRoot ? `<div class="ml-row"><button type="button" class="ml-primary" id="ml-smtp-save" ${state.busy ? 'disabled' : ''}>${th('admin.save')}</button>
        <span class="ml-muted ml-small">${th('smtp.saveHelp')}</span></div>` : ''}
      <h4>${th('test.title')}</h4>
      <div class="ml-row">
        <input type="email" id="ml-test-to" value="${esc(state.testTo)}" placeholder="${esc(t('test.placeholder'))}">
        <button type="button" id="ml-test" ${state.busy || !ctx.configured ? 'disabled' : ''}>${th('test.button')}</button>
        <span class="ml-muted ml-small">${th('test.help')}</span>
      </div>
      ${test ? `<div class="ml-alert ${test.ok ? 'ml-ok' : 'ml-err'}">${test.ok ? th('test.ok', { to: state.testTo }) : th('test.failed', { error: test.error })}</div>
        ${test.log ? `<details ${test.ok ? '' : 'open'}><summary>${th('test.log')}</summary><pre class="ml-log">${esc(test.log)}</pre></details>` : ''}` : ''}
    </div>`;
  }

  function renderCompetition() {
    const c = state.competition;
    return `<div class="ml-box"><h3>${th('comp.title', { name: ctx.tour.name })}</h3>
      <div class="ml-muted ml-small">${th('comp.help')}</div>
      <div class="ml-grid">
        <label>${th('comp.fromName')}<input type="text" id="ml-c-from" value="${esc(c.fromName)}" placeholder="${esc(ctx.smtp.fromName)}"></label>
        <label>${th('comp.contactName')}<input type="text" id="ml-c-name" value="${esc(c.contactName)}"></label>
        <label>${th('comp.contactEmail')}<input type="email" id="ml-c-email" value="${esc(c.contactEmail)}"></label>
      </div>
      <div class="ml-row"><button type="button" id="ml-c-save" ${state.busy ? 'disabled' : ''}>${th('admin.save')}</button></div>
    </div>`;
  }

  function renderTemplates() {
    const byModule = {};
    for (const tp of ctx.templates) (byModule[tp.module] = byModule[tp.module] || []).push(tp);
    return `<div class="ml-box"><h3>${th('tpl.title')}</h3>
      <div class="ml-muted ml-small">${th('tpl.help')}</div>
      <div class="ml-scroll"><table class="ml-table">
        <tr><th>${th('tpl.name')}</th><th>${th('tpl.module')}</th><th>${th('tpl.to')}</th><th>${th('tpl.custom')}</th><th></th></tr>
        ${Object.keys(byModule).sort().map(mod => byModule[mod].map(tp => `<tr class="${state.editing && state.editing.id === tp.id ? 'ml-current' : ''}">
          <td><b>${esc(pick(tp.name, ctx.lang))}</b><div class="ml-muted ml-small">${esc(pick(tp.description, ctx.lang))}</div></td>
          <td>${esc(mod)}</td><td>${esc(tp.to ? t('tpl.to.' + tp.to) : '')}</td>
          <td>${tp.custom.length ? tp.custom.map(l => `<span class="ml-chip">${esc(l.toUpperCase())}</span>`).join(' ') : `<span class="ml-muted">${th('tpl.default')}</span>`}</td>
          <td><button type="button" data-edit="${esc(tp.id)}">${th('tpl.edit')}</button></td></tr>`).join('')).join('')}
      </table></div>
      ${state.editing ? renderEditor() : ''}
    </div>`;
  }

  function renderEditor() {
    const e = state.editing;
    return `<div class="ml-editor" id="ml-editor">
      <div class="ml-row">
        <h4>${esc(tplName(e.id))}</h4>
        ${['fr', 'en'].map(l => `<button type="button" class="ml-tab ${e.lang === l ? 'on' : ''}" data-lang="${l}">${l.toUpperCase()}</button>`).join('')}
        <span class="ml-muted ml-small">${th(e.custom ? 'tpl.isCustom' : 'tpl.isDefault')}</span>
        <button type="button" class="ml-linkbtn" id="ml-close">${th('tpl.close')}</button>
      </div>
      <div class="ml-cols">
        <div class="ml-col">
          <label>${th('tpl.subject')}<input type="text" id="ml-subject" value="${esc(e.subject)}"></label>
          <label>${th('tpl.body')}<textarea id="ml-body" rows="18">${esc(e.body)}</textarea></label>
          <div class="ml-muted ml-small">${th('tpl.syntax')}</div>
          <div class="ml-vars">${e.vars.map(v => `<button type="button" class="ml-var" data-var="${esc(v.name)}" title="${esc(v.label)}">{${esc(v.name)}}</button>`).join(' ')}</div>
          <div class="ml-row">
            <button type="button" class="ml-primary" id="ml-tpl-save" ${state.busy ? 'disabled' : ''}>${th('admin.save')}</button>
            <button type="button" id="ml-tpl-reset" ${state.busy || !e.custom ? 'disabled' : ''}>${th('tpl.reset')}</button>
          </div>
        </div>
        <div class="ml-col">
          <div class="ml-preview-subject"><span class="ml-muted">${th('tpl.subject')} :</span> <b id="ml-preview-subject"></b></div>
          <iframe id="ml-preview" class="ml-frame" sandbox="allow-same-origin" title="${esc(t('tpl.preview'))}"></iframe>
          <div class="ml-muted ml-small">${th('tpl.previewHelp')}</div>
          <div class="ml-row">
            <input type="email" id="ml-preview-to" value="${esc(state.previewTo)}" placeholder="${esc(t('test.placeholder'))}">
            <button type="button" id="ml-preview-send" ${state.busy || !ctx.configured ? 'disabled' : ''}>${th('tpl.sendPreview')}</button>
          </div>
          ${state.previewMsg ? `<div class="ml-small">${esc(state.previewMsg)}</div>` : ''}
        </div>
      </div>
    </div>`;
  }

  function updatePreview() {
    const f = document.getElementById('ml-preview');
    const s = document.getElementById('ml-preview-subject');
    if (!f || !state.preview) return;
    // sandbox="allow-same-origin" (no scripts); srcdoc keeps the same origin for the logo images
    f.srcdoc = state.preview.html;
    if (s) s.textContent = state.preview.subject;
  }

  const STATUS = { 0: 'queued', 1: 'sent', 2: 'failed', 3: 'queued' };

  function renderOutbox() {
    const c = ctx.counts;
    return `<div class="ml-box"><h3>${th('outbox.title')}</h3>
      <div class="ml-row">
        <span class="ml-chip">${th('outbox.sent', { n: c.sent })}</span>
        <span class="ml-chip">${th('outbox.queued', { n: c.queued })}</span>
        <span class="ml-chip ${c.failed ? 'ml-chip-err' : ''}">${th('outbox.failed', { n: c.failed })}</span>
        <button type="button" id="ml-flush" ${state.busy || !c.queued && !c.failed ? 'disabled' : ''}>${th('outbox.flush')}</button>
      </div>
      ${ctx.outbox.length ? `<div class="ml-scroll"><table class="ml-table">
        <tr><th>${th('outbox.date')}</th><th>${th('outbox.template')}</th><th>${th('outbox.to')}</th><th>${th('tpl.subject')}</th><th>${th('outbox.status')}</th><th></th></tr>
        ${ctx.outbox.map(m => `<tr class="ml-st-${STATUS[m.status]}">
          <td>${esc(fmtDate(m.sent || m.created))}</td><td>${esc(tplName(m.template))}</td><td>${esc(m.to)}</td><td>${esc(m.subject)}</td>
          <td>${th('outbox.st.' + STATUS[m.status])}${m.error ? `<div class="ml-err-text">${esc(m.error)}</div>` : ''}</td>
          <td class="ml-actions"><button type="button" data-view="${m.id}">${th('outbox.view')}</button>
            ${m.status !== 3 ? `<button type="button" data-retry="${m.id}">${th(m.status === 1 ? 'outbox.resend' : 'outbox.retry')}</button>` : ''}</td>
        </tr>`).join('')}
      </table></div>` : `<p class="ml-muted">${th('outbox.none')}</p>`}
      <div class="ml-row ml-small">${th('outbox.purge')}
        <input type="number" id="ml-purge-days" min="0" value="90" style="width:5em"> ${th('outbox.days')}
        <button type="button" id="ml-purge" ${state.busy ? 'disabled' : ''}>${th('outbox.purgeButton')}</button>
        <span class="ml-muted">${th('outbox.cron', { cmd: ctx.cron })}</span></div>
    </div>`;
  }

  function renderViewer() {
    return `<div class="ml-modal" id="ml-modal"><div class="ml-modal-box">
      <div class="ml-row"><b>${th('outbox.view')}</b><button type="button" class="ml-linkbtn" id="ml-view-close">${th('tpl.close')}</button></div>
      <iframe id="ml-view-frame" class="ml-frame ml-frame-tall" sandbox="allow-same-origin" title="${esc(t('outbox.view'))}"></iframe>
    </div></div>`;
  }

  // ---------- events ----------

  function bind() {
    const $ = id => document.getElementById(id);
    const on = (id, ev, fn) => { const el = $(id); if (el) el.addEventListener(ev, fn); };
    const field = (id, obj, key, num) => on(id, 'input', e => { obj[key] = num ? parseInt(e.target.value, 10) || '' : e.target.value; });

    field('ml-host', state.smtp, 'host');
    field('ml-port', state.smtp, 'port', true);
    field('ml-user', state.smtp, 'user');
    field('ml-password', state.smtp, 'password');
    field('ml-from-email', state.smtp, 'fromEmail');
    field('ml-from-name', state.smtp, 'fromName');
    on('ml-security', 'change', e => {
      const v = e.target.value;
      // usual port for the chosen security, unless a custom port was typed
      if ([587, 465, 25].includes(Number(state.smtp.port))) state.smtp.port = v === 'ssl' ? 465 : v === 'tls' ? 587 : 25;
      state.smtp.security = v;
      render();
    });
    on('ml-smtp-save', 'click', saveSmtp);
    field('ml-test-to', state, 'testTo');
    on('ml-test', 'click', sendTest);

    field('ml-c-from', state.competition, 'fromName');
    field('ml-c-name', state.competition, 'contactName');
    field('ml-c-email', state.competition, 'contactEmail');
    on('ml-c-save', 'click', saveCompetition);

    app.querySelectorAll('[data-edit]').forEach(el => el.addEventListener('click', () => openTemplate(el.dataset.edit, ctx.tour.lang)));
    app.querySelectorAll('[data-lang]').forEach(el => el.addEventListener('click', () => openTemplate(state.editing.id, el.dataset.lang)));
    on('ml-close', 'click', () => { state.editing = null; state.preview = null; render(); });
    on('ml-subject', 'input', e => { state.editing.subject = e.target.value; schedulePreview(); });
    on('ml-body', 'input', e => { state.editing.body = e.target.value; schedulePreview(); });
    app.querySelectorAll('[data-var]').forEach(el => el.addEventListener('click', () => {
      const ta = $('ml-body');
      const ins = '{' + el.dataset.var + '}';
      const a = ta.selectionStart, b = ta.selectionEnd;
      ta.value = ta.value.slice(0, a) + ins + ta.value.slice(b);
      ta.focus();
      ta.selectionStart = ta.selectionEnd = a + ins.length;
      state.editing.body = ta.value;
      schedulePreview();
    }));
    on('ml-tpl-save', 'click', () => saveTemplate(false));
    on('ml-tpl-reset', 'click', () => saveTemplate(true));
    field('ml-preview-to', state, 'previewTo');
    on('ml-preview-send', 'click', sendPreview);

    on('ml-flush', 'click', flushNow);
    app.querySelectorAll('[data-retry]').forEach(el => el.addEventListener('click', () => retry([parseInt(el.dataset.retry, 10)])));
    app.querySelectorAll('[data-view]').forEach(el => el.addEventListener('click', () => viewSent(parseInt(el.dataset.view, 10))));
    on('ml-view-close', 'click', () => { state.viewing = null; render(); });
    on('ml-purge', 'click', purge);

    on('ml-channel', 'change', e => setChannel(e.target.value));
    on('ml-update-check', 'click', () => checkUpdate(true));
    on('ml-update', 'click', installUpdate);
  }

  render();
  if (ctx.isRoot) checkUpdate(false);
  // emails waiting (failed, or queued by a request that could not send them) are sent while the page is open
  if (ctx.ready && (ctx.counts.queued || ctx.counts.failed)) api('flush').then(res => { ctx = res; render(); }).catch(() => {});
})();

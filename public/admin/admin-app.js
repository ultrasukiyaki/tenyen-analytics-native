(() => {
  'use strict';
  const shell = document.querySelector('[data-app-shell]');
  if (!shell) return;
  const config = window.TYA_ADMIN_CONFIG || {};
  const locale = config.locale || 'en-US';
  const t = (key, replacements = {}) => {
    let value = config.strings?.[key] || key;
    Object.entries(replacements).forEach(([name, replacement]) => { value = value.replaceAll(`{${name}}`, String(replacement)); });
    return value;
  };
  const content = shell.querySelector('[data-view-content]');
  const loading = shell.querySelector('[data-view-loading]');
  const errorBox = shell.querySelector('[data-view-error]');
  const status = shell.querySelector('[data-global-status]');
  const sidebar = shell.querySelector('[data-sidebar]');
  let request = null, historyInstance = null, sessionsInstance = null, refreshTimer = null, currentPayload = null;

  function initialState() {
    try { return JSON.parse(document.getElementById('tya-initial-state')?.textContent || '{}'); }
    catch (_) { return {}; }
  }

  function setActive(view) {
    shell.querySelectorAll('[data-view-link]').forEach(link => link.classList.toggle('active', link.dataset.viewLink === view));
  }

  function stopWidgets() {
    historyInstance?.destroy?.(); historyInstance = null;
    sessionsInstance?.destroy?.(); sessionsInstance = null;
    window.TYCharts?.clear?.();
    if (refreshTimer) clearInterval(refreshTimer); refreshTimer = null;
  }

  function startWidgets(payload) {
    stopWidgets(); currentPayload = payload;
    window.TYCharts?.render?.(content, payload.chart_data || {});
    if (payload.history_config) historyInstance = window.TYHistory?.init?.(content, payload.history_config) || null;
    sessionsInstance = window.TYSessions?.init?.(content) || null;
    const seconds = Number(payload.refresh_seconds || 0);
    if (seconds > 0) refreshTimer = setInterval(() => {
      if (document.visibilityState === 'visible') load(new URL(location.href), {push:false, silent:true});
    }, seconds * 1000);
  }

  function updateUrl(url, push) {
    if (push) history.pushState({}, '', url);
    document.title = `${currentPayload?.title || 'Tenyen Analytics'} | Tenyen Analytics`;
  }

  async function load(url, {push=true, silent=false} = {}) {
    const target = url instanceof URL ? url : new URL(url, location.href);
    const view = target.searchParams.get('view') || 'dashboard';
    request?.abort();
    const controller = new AbortController();
    request = controller;
    if (!silent) {
      stopWidgets();
      loading.hidden = false;
      errorBox.hidden = true;
      content.setAttribute('aria-busy', 'true');
      status.textContent = t('common.loading');
    }
    const api = new URL('api/view.php', location.href);
    target.searchParams.forEach((value, key) => api.searchParams.set(key, value));
    api.searchParams.set('view', view);
    try {
      const response = await fetch(api, {headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store',signal:controller.signal});
      const payload = await response.json();
      if (response.status === 401) { location.reload(); return; }
      if (!response.ok || payload.ok === false) throw new Error(payload.message || `HTTP ${response.status}`);
      content.innerHTML = payload.html || '';
      currentPayload = payload;
      setActive(view); startWidgets(payload); updateUrl(target, push);
      status.textContent = t('common.ready');
      if (window.innerWidth < 900) shell.classList.remove('menu-open');
      content.focus?.({preventScroll:true});
    } catch (error) {
      if (error.name === 'AbortError') return;
      errorBox.innerHTML = `<strong>${escapeHtml(t('common.failed_view'))}</strong><p>${escapeHtml(error.message || String(error))}</p><button class="button" type="button" data-retry>${escapeHtml(t('common.retry'))}</button>`;
      errorBox.hidden = false; status.textContent = t('common.failed_view');
    } finally {
      if (request === controller) {
        loading.hidden = true;
        content.removeAttribute('aria-busy');
        request = null;
      }
    }
  }

  function escapeHtml(value) { return String(value).replace(/[&<>'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character])); }

  document.addEventListener('click', async event => {
    const link = event.target.closest('[data-view-link]');
    if (link && link.origin === location.origin) {
      event.preventDefault();
      const url = new URL(link.href, location.href);
      url.search = `?view=${encodeURIComponent(link.dataset.viewLink || 'dashboard')}`;
      load(url); return;
    }
    if (event.target.closest('[data-menu-toggle]')) { shell.classList.toggle('menu-open'); return; }
    if (event.target.closest('[data-retry]')) { load(new URL(location.href), {push:false}); return; }
    const copy = event.target.closest('[data-copy-code]');
    if (copy) {
      const text = copy.parentElement?.querySelector('code')?.textContent || '';
      try { await navigator.clipboard.writeText(text); copy.textContent = 'Copied'; setTimeout(() => copy.textContent = 'Copy', 1400); }
      catch (_) { copy.textContent = 'Select and copy'; }
    }
  });

  content.addEventListener('submit', event => {
    const languageForm = event.target.closest('[data-language-form]');
    if (languageForm) {
      event.preventDefault();
      fetch(languageForm.action, {method:'POST',body:new FormData(languageForm),credentials:'same-origin',headers:{Accept:'application/json'}})
        .then(response => response.json().then(payload => ({response,payload})))
        .then(({response,payload}) => {
          if (!response.ok || payload.ok === false) throw new Error(payload.message || `HTTP ${response.status}`);
          location.reload();
        })
        .catch(error => {
          errorBox.textContent = error.message || String(error);
          errorBox.hidden = false;
        });
      return;
    }
    const form = event.target.closest('[data-view-filter]');
    if (!form) return;
    event.preventDefault();
    const url = new URL(location.href);
    const view = url.searchParams.get('view') || currentPayload?.view || 'dashboard';
    url.search = '';
    url.searchParams.set('view', view);
    new FormData(form).forEach((value, key) => { if (String(value) !== '') url.searchParams.set(key, String(value)); });
    load(url);
  });

  window.addEventListener('popstate', () => load(new URL(location.href), {push:false}));
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && Number(currentPayload?.refresh_seconds || 0) > 0) load(new URL(location.href), {push:false, silent:true}); });

  const initial = initialState();
  currentPayload = initial; setActive(initial.view || 'dashboard'); startWidgets(initial);
})();

(() => {
  'use strict';
  const chunkSize = 512 * 1024;
  const uid = () => window.crypto?.randomUUID?.() || `${Date.now().toString(16)}-${Math.random().toString(16).slice(2)}-${Math.random().toString(16).slice(2)}`;
  const format = bytes => bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.ceil(bytes / 1024)} KB`;

  async function uploadFile(form, file, kind, label, base, totalAll) {
    const endpoint = new URL(form.dataset.endpoint || 'api/mmdb-upload.php', location.href);
    const csrf = form.querySelector('input[name="csrf"]')?.value || '';
    const progress = form.querySelector('[data-mmdb-progress]');
    const title = progress?.querySelector('[data-mmdb-title]');
    const detail = progress?.querySelector('[data-mmdb-detail]');
    const bar = progress?.querySelector('[data-mmdb-bar]');
    const id = uid().replace(/[^a-f0-9-]/gi, '').toLowerCase();
    const chunks = Math.ceil(file.size / chunkSize);
    for (let index = 0; index < chunks; index++) {
      const offset = index * chunkSize;
      const blob = file.slice(offset, Math.min(file.size, offset + chunkSize));
      const data = new FormData();
      data.append('csrf', csrf);
      data.append('kind', kind);
      data.append('upload_id', id);
      data.append('chunk_index', String(index));
      data.append('total_chunks', String(chunks));
      data.append('total_size', String(file.size));
      data.append('offset', String(offset));
      data.append('chunk', blob, `${label}.part`);
      const response = await fetch(endpoint, {method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}});
      let payload = {};
      try { payload = await response.json(); }
      catch (_) { throw new Error(`Could not read the server response (HTTP ${response.status}).`); }
      if (response.status === 401) { location.reload(); return; }
      if (!response.ok || payload.ok === false) throw new Error(payload.message || `HTTP ${response.status}`);
      const sent = Math.min(file.size, offset + blob.size);
      const percent = totalAll > 0 ? Math.round((base + sent) / totalAll * 100) : 100;
      if (title) title.textContent = `${label}: ${percent}%`;
      if (detail) detail.textContent = `${format(sent)} / ${format(file.size)} (512 KB chunks)`;
      if (bar) bar.style.width = `${percent}%`;
    }
  }

  document.addEventListener('submit', async event => {
    const form = event.target.closest('[data-mmdb-form]');
    if (!form) return;
    event.preventDefault();
    const city = form.querySelector('input[name="city_database"]')?.files?.[0] || null;
    const asn = form.querySelector('input[name="asn_database"]')?.files?.[0] || null;
    const progress = form.querySelector('[data-mmdb-progress]');
    const title = progress?.querySelector('[data-mmdb-title]');
    const detail = progress?.querySelector('[data-mmdb-detail]');
    const bar = progress?.querySelector('[data-mmdb-bar]');
    if (!city && !asn) {
      progress.hidden = false;
      progress.classList.add('error');
      if (title) title.textContent = t('upload.select');
      if (detail) detail.textContent = '';
      return;
    }
    const queue = [];
    if (city) queue.push([city, 'city', 'GeoLite2-City.mmdb']);
    if (asn) queue.push([asn, 'asn', 'GeoLite2-ASN.mmdb']);
    const total = queue.reduce((sum, item) => sum + item[0].size, 0);
    let base = 0;
    progress.hidden = false;
    progress.classList.remove('error');
    form.querySelectorAll('input[type=file],button').forEach(element => { element.disabled = true; });
    try {
      for (const item of queue) {
        await uploadFile(form, item[0], item[1], item[2], base, total);
        base += item[0].size;
      }
      if (title) title.textContent = `✅ ${t('upload.completed')}`;
      if (detail) detail.textContent = t('upload.reloading');
      if (bar) bar.style.width = '100%';
      setTimeout(() => location.reload(), 600);
    } catch (error) {
      progress.classList.add('error');
      if (title) title.textContent = `❌ ${t('upload.failed')}`;
      if (detail) detail.textContent = error.message || String(error);
      if (bar) bar.style.width = '0%';
      form.querySelectorAll('input[type=file],button').forEach(element => { element.disabled = false; });
    }
  });
})();

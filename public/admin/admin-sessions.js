(() => {
  'use strict';
  const config = window.TYA_ADMIN_CONFIG || {};
  const t = key => config.strings?.[key] || key;
  const escapeHtml = value => String(value).replace(/[&<>'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character]));

  window.TYSessions = {
    init(root) {
      const shell = root.querySelector('[data-sessions]');
      if (!shell) return null;
      const form = shell.querySelector('[data-sessions-filter]');
      const results = shell.querySelector('[data-sessions-results]');
      const status = shell.querySelector('[data-sessions-status]');
      const dialog = shell.querySelector('[data-journey-dialog]');
      const dialogBody = dialog?.querySelector('[data-journey-dialog-body]');
      let controller = null;
      let lastFocus = null;

      const request = async params => {
        controller?.abort();
        controller = new AbortController();
        const url = new URL('api/sessions.php', location.href);
        Object.entries(params).forEach(([key, value]) => { if (String(value) !== '') url.searchParams.set(key, String(value)); });
        const response = await fetch(url, {
          credentials: 'same-origin',
          cache: 'no-store',
          signal: controller.signal,
          headers: {Accept: 'application/json', 'X-CSRF-Token': config.csrf || ''}
        });
        let payload = {};
        try { payload = await response.json(); } catch (_) { throw new Error(`HTTP ${response.status}`); }
        if (response.status === 401) { location.reload(); throw new Error('Authentication expired'); }
        if (!response.ok || payload.ok === false) throw new Error(payload.message || `HTTP ${response.status}`);
        return payload;
      };
      const filters = page => {
        const values = Object.fromEntries(new FormData(form).entries());
        values.action = 'list';
        values.page = page || 1;
        return values;
      };
      const load = async (page = 1) => {
        status.textContent = t('common.loading');
        results.setAttribute('aria-busy', 'true');
        try {
          const payload = await request(filters(page));
          results.innerHTML = payload.html || '';
          status.textContent = t('sessions.count').replace('{count}', Number(payload.total || 0).toLocaleString(config.locale || 'en-US'));
        } catch (error) {
          if (error.name === 'AbortError') return;
          results.innerHTML = `<div class="view-error"><p>${escapeHtml(error.message || String(error))}</p><button class="button" data-sessions-retry>${escapeHtml(t('common.retry'))}</button></div>`;
          status.textContent = t('common.failed_view');
        } finally {
          results.removeAttribute('aria-busy');
        }
      };
      const open = async params => {
        if (!dialog || !dialogBody) return;
        lastFocus = document.activeElement;
        dialogBody.innerHTML = `<p>${escapeHtml(t('common.loading'))}</p>`;
        dialog.showModal();
        try {
          const payload = await request(params);
          dialogBody.innerHTML = payload.html || '';
          dialogBody.querySelector('button')?.focus();
        } catch (error) {
          if (error.name !== 'AbortError') dialogBody.innerHTML = `<p class="view-error">${escapeHtml(error.message || String(error))}</p><button class="button" data-journey-close>${escapeHtml(t('common.close'))}</button>`;
        }
      };
      const close = () => { dialog?.close(); lastFocus?.focus?.(); };

      form.addEventListener('submit', event => { event.preventDefault(); load(); });
      shell.addEventListener('click', event => {
        const page = event.target.closest('[data-session-page]');
        if (page) { load(Number(page.dataset.sessionPage)); return; }
        if (event.target.closest('[data-sessions-retry]')) { load(); return; }
        const session = event.target.closest('[data-session-id]');
        if (session) { open({action: 'detail', session_id: session.dataset.sessionId}); return; }
        const visitor = event.target.closest('[data-visitor-id]');
        if (visitor) { open({action: 'visitor', visitor_id: visitor.dataset.visitorId}); return; }
        if (event.target.closest('[data-journey-close]')) close();
      });
      dialog?.addEventListener('cancel', event => { event.preventDefault(); close(); });
      load().then(() => {
        if (shell.dataset.initialSession) open({action: 'detail', session_id: shell.dataset.initialSession});
        else if (shell.dataset.initialVisitor) open({action: 'visitor', visitor_id: shell.dataset.initialVisitor});
      });
      return {destroy() { controller?.abort(); dialog?.close(); }};
    }
  };
})();

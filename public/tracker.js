(() => {
  'use strict';

  const cfg = window.TYAnalyticsConfig || {};
  if (!cfg.endpoint || !cfg.token) return;

  const cookieName = 'tya_vid';
  const sessionKey = 'tya_sid';
  const now = Date.now();
  const startedAt = performance.now();
  let maxScroll = 0;

  const uuid = () => {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
      const r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  };

  const getCookie = name => document.cookie.split('; ').find(v => v.startsWith(name + '='))?.split('=').slice(1).join('=') || '';
  const setCookie = (name, value, days) => {
    const expires = new Date(Date.now() + days * 864e5).toUTCString();
    document.cookie = `${name}=${encodeURIComponent(value)}; Expires=${expires}; Path=/; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
  };

  let visitorId = decodeURIComponent(getCookie(cookieName) || '');
  if (!visitorId) {
    visitorId = uuid();
    setCookie(cookieName, visitorId, 365);
  }

  let sessionId = sessionStorage.getItem(sessionKey);
  if (!sessionId) {
    sessionId = uuid();
    sessionStorage.setItem(sessionKey, sessionId);
  }

  const base = () => ({
    token: cfg.token,
    visitor_id: visitorId,
    session_id: sessionId,
    path: location.pathname + location.search,
    title: document.title,
    referrer: document.referrer,
    language: navigator.language || '',
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
    screen: `${screen.width}x${screen.height}`,
    viewport: `${innerWidth}x${innerHeight}`,
    ts: now
  });

  const send = (payload, preferBeacon = false) => {
    const body = JSON.stringify({...base(), ...payload});
    if (preferBeacon && navigator.sendBeacon) {
      const blob = new Blob([body], {type: 'application/json'});
      if (navigator.sendBeacon(cfg.endpoint, blob)) return;
    }
    fetch(cfg.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: {'Content-Type': 'application/json'},
      body
    }).catch(() => {});
  };

  const cleanMetadata = value => {
    if (!value || Object.getPrototypeOf(value) !== Object.prototype) return null;
    const keys = Object.keys(value);
    if (keys.length > 12) return null;
    const result = {};
    for (const key of keys) {
      const item = value[key];
      if (!/^[a-zA-Z][a-zA-Z0-9_.-]{0,31}$/.test(key) || !['string', 'number', 'boolean'].includes(typeof item)) return null;
      result[key] = typeof item === 'string' ? item.replace(/[\u0000-\u001f\u007f]/g, '').slice(0, 255) : item;
    }
    return result;
  };

  window.TYAnalytics = Object.freeze({
    trackEvent(name, metadata = {}) {
      try {
        if (!/^[a-z][a-z0-9_.-]{0,63}$/.test(name)) return false;
        const safe = cleanMetadata(metadata);
        if (safe === null) return false;
        send({event: 'custom', event_name: name, metadata: safe});
        return true;
      } catch (_) {
        return false;
      }
    },
    trackNotFound(requestedUrl = location.href) {
      try {
        const url = new URL(requestedUrl, location.href);
        if (!/^https?:$/.test(url.protocol)) return false;
        url.hash = '';
        send({event: 'not_found', event_name: '404', target_url: url.href});
        return true;
      } catch (_) {
        return false;
      }
    }
  });

  const updateScroll = () => {
    const doc = document.documentElement;
    const available = Math.max(1, doc.scrollHeight - innerHeight);
    maxScroll = Math.max(maxScroll, Math.min(100, Math.round(scrollY / available * 100)));
  };

  addEventListener('scroll', updateScroll, {passive: true});
  addEventListener('pagehide', () => {
    updateScroll();
    send({
      event: 'engagement',
      duration_ms: Math.round(performance.now() - startedAt),
      scroll_depth: maxScroll
    }, true);
  });

  document.addEventListener('click', event => {
    const link = event.target.closest?.('a[href]');
    if (link) {
      let url;
      try { url = new URL(link.href, location.href); } catch (_) { return; }
      if (!/^https?:$/.test(url.protocol) || (url.origin === location.origin && url.pathname === location.pathname && url.search === location.search && url.hash)) return;
      url.hash = '';
      const download = link.hasAttribute('download') || /\.(zip|pdf|docx?|xlsx?|pptx?|tar|gz|7z|mp3|mp4)$/i.test(url.pathname);
      if (download) {
        send({event: 'download', event_name: 'download', target_url: url.href}, true);
      } else if (url.origin !== location.origin) {
        send({event: 'outbound', event_name: 'external_link', target_url: url.href}, true);
      } else if (cfg.trackInternalLinks && !url.pathname.startsWith('/admin')) {
        send({event: 'internal_link', event_name: 'internal_link', target_url: url.href}, true);
      }
      return;
    }
    const button = event.target.closest?.('button,[role="button"]');
    if (button && cfg.trackButtons) {
      const name = button.dataset.tenyenEvent;
      if (/^[a-z][a-z0-9_.-]{0,63}$/.test(name || '')) send({event: 'button', event_name: name});
    }
  }, {capture: true});

  document.addEventListener('submit', event => {
    const form = event.target;
    if (!cfg.trackForms || !(form instanceof HTMLFormElement) || !form.hasAttribute('data-tenyen-event')) return;
    if (form.closest('form[action*="login"],form[action*="admin"]') || form.querySelector('input[type="password"],input[autocomplete*="cc-"]')) return;
    const name = form.dataset.tenyenEvent;
    if (/^[a-z][a-z0-9_.-]{0,63}$/.test(name || '')) send({event: 'form_submit', event_name: name}, true);
  }, {capture: true});

  send({event: 'pageview'});
})();

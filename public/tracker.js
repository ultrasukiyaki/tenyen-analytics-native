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
    if (!link) return;
    const url = new URL(link.href, location.href);
    const download = link.hasAttribute('download') || /\.(zip|pdf|docx?|xlsx?|pptx?|tar|gz|7z|mp3|mp4)$/i.test(url.pathname);
    if (download) {
      send({event: 'download', target_url: url.href}, true);
    } else if (url.origin !== location.origin && /^https?:$/.test(url.protocol)) {
      send({event: 'outbound', target_url: url.href}, true);
    }
  }, {capture: true});

  send({event: 'pageview'});
})();

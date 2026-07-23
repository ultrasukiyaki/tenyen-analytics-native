(() => {
  'use strict';

  const palette = ['#2271b1', '#d63638', '#00a32a', '#dba617', '#8c5bd6', '#008a9a', '#e06b31', '#646970'];
  const config = window.TYA_ADMIN_CONFIG || {};
  const locale = config.locale || 'en-US';
  const t = key => config.strings?.[key] || key;
  let active = null;
  let resizeTimer = null;

  function setupCanvas(canvas, height) {
    const rect = canvas.getBoundingClientRect();
    const width = Math.max(280, Math.floor(rect.width || canvas.parentElement?.clientWidth || 600));
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = Math.floor(width * dpr);
    canvas.height = Math.floor(height * dpr);
    canvas.style.height = `${height}px`;
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, width, height);
    return {ctx, width, height};
  }

  function colors() {
    const dark = window.matchMedia?.('(prefers-color-scheme: dark)').matches;
    return {
      text: dark ? '#dcdcde' : '#50575e',
      grid: dark ? 'rgba(255,255,255,.14)' : 'rgba(0,0,0,.10)'
    };
  }

  function niceMax(value) {
    if (!Number.isFinite(value) || value <= 0) return 1;
    const power = 10 ** Math.floor(Math.log10(value));
    const normalized = value / power;
    return (normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10) * power;
  }

  function drawLine(canvas, timeline) {
    const {ctx, width, height} = setupCanvas(canvas, 320);
    const {text, grid} = colors();
    const rows = Array.isArray(timeline?.rows) ? timeline.rows : [];
    const series = Array.isArray(timeline?.series) ? timeline.series : [];
    const margin = {top: 20, right: 20, bottom: 56, left: 52};
    const plotW = width - margin.left - margin.right;
    const plotH = height - margin.top - margin.bottom;
    ctx.font = '12px system-ui, sans-serif';
    ctx.fillStyle = text;
    if (!rows.length) {
      ctx.textAlign = 'center';
      ctx.fillText(t('chart.no_data'), width / 2, height / 2);
      return;
    }
    const maxValue = niceMax(Math.max(1, ...rows.flatMap(row => series.map(s => Number(row[s.key] || 0)))));
    ctx.strokeStyle = grid;
    ctx.lineWidth = 1;
    ctx.textAlign = 'right';
    ctx.textBaseline = 'middle';
    for (let i = 0; i <= 5; i += 1) {
      const y = margin.top + plotH * i / 5;
      ctx.beginPath(); ctx.moveTo(margin.left, y); ctx.lineTo(width - margin.right, y); ctx.stroke();
      ctx.fillStyle = text; ctx.fillText(String(Math.round(maxValue * (1 - i / 5))), margin.left - 8, y);
    }
    const xAt = index => rows.length === 1 ? margin.left + plotW / 2 : margin.left + plotW * index / (rows.length - 1);
    const yAt = value => margin.top + plotH - (Number(value || 0) / maxValue) * plotH;
    series.forEach((item, seriesIndex) => {
      ctx.strokeStyle = palette[seriesIndex % palette.length];
      ctx.fillStyle = palette[seriesIndex % palette.length];
      ctx.lineWidth = 2.4; ctx.lineJoin = 'round'; ctx.lineCap = 'round'; ctx.beginPath();
      rows.forEach((row, index) => index === 0 ? ctx.moveTo(xAt(index), yAt(row[item.key])) : ctx.lineTo(xAt(index), yAt(row[item.key])));
      ctx.stroke();
      if (rows.length <= 62) rows.forEach((row, index) => { ctx.beginPath(); ctx.arc(xAt(index), yAt(row[item.key]), 2.8, 0, Math.PI * 2); ctx.fill(); });
    });
    const maxLabels = Math.max(2, Math.floor(plotW / 72));
    const step = Math.max(1, Math.ceil(rows.length / maxLabels));
    ctx.fillStyle = text; ctx.textAlign = 'center'; ctx.textBaseline = 'top';
    rows.forEach((row, index) => {
      if (index % step !== 0 && index !== rows.length - 1) return;
      ctx.save(); ctx.translate(xAt(index), height - margin.bottom + 12); if (rows.length > 16) ctx.rotate(-Math.PI / 5); ctx.fillText(String(row.label || ''), 0, 0); ctx.restore();
    });
  }

  function drawDonut(canvas, chart) {
    const {ctx, width, height} = setupCanvas(canvas, 230);
    const {text} = colors();
    const rows = Array.isArray(chart?.rows) ? chart.rows.filter(row => Number(row.value) > 0) : [];
    const total = rows.reduce((sum, row) => sum + Number(row.value || 0), 0);
    const cx = width / 2, cy = height / 2;
    const radius = Math.min(width, height) * .38, inner = radius * .58;
    if (!total) { ctx.fillStyle = text; ctx.font = '12px system-ui,sans-serif'; ctx.textAlign = 'center'; ctx.fillText(t('common.no_data'), cx, cy); return; }
    let start = -Math.PI / 2;
    rows.forEach((row, index) => { const angle = Math.PI * 2 * Number(row.value) / total; ctx.beginPath(); ctx.moveTo(cx, cy); ctx.arc(cx, cy, radius, start, start + angle); ctx.closePath(); ctx.fillStyle = palette[index % palette.length]; ctx.fill(); start += angle; });
    ctx.globalCompositeOperation = 'destination-out'; ctx.beginPath(); ctx.arc(cx, cy, inner, 0, Math.PI * 2); ctx.fill(); ctx.globalCompositeOperation = 'source-over';
    ctx.fillStyle = text; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.font = '700 22px system-ui,sans-serif'; ctx.fillText(new Intl.NumberFormat(locale).format(total), cx, cy - 7); ctx.font = '12px system-ui,sans-serif'; ctx.fillText('PV', cx, cy + 17);
  }

  function draw(root, payload) {
    root.querySelectorAll('canvas[data-tya-line]').forEach(canvas => drawLine(canvas, payload?.timeline || {}));
    root.querySelectorAll('canvas[data-tya-donut]').forEach(canvas => drawDonut(canvas, payload?.breakdowns?.[canvas.dataset.tyaDonut] || {}));
  }

  window.TYCharts = {
    render(root = document, payload = {}) {
      active = {root, payload};
      requestAnimationFrame(() => draw(root, payload));
    },
    clear() { active = null; }
  };

  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => { if (active && document.contains(active.root)) draw(active.root, active.payload); }, 120);
  }, {passive: true});
})();

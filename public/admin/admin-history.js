(() => {
  'use strict';

  const validColumns = ['datetime', 'event', 'ip', 'location', 'organization', 'page', 'referrer', 'environment', 'details'];
  const pageConfig = window.TYA_ADMIN_CONFIG || {};
  const locale = pageConfig.locale || 'en-US';
  const t = (key, replacements = {}) => {
    let value = pageConfig.strings?.[key] || key;
    Object.entries(replacements).forEach(([name, replacement]) => { value = value.replaceAll(`{${name}}`, String(replacement)); });
    return value;
  };

  function escapeHtml(value) {
    return String(value).replace(/[&<>'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character]));
  }

  function init(root = document, config = {}) {
    const section = root.querySelector('#tya-history');
    if (!section || !config.endpoint) return {destroy(){}};
    const lifecycle = new AbortController();
    const signal = lifecycle.signal;
    const storageKey = config.storageKey || 'tenyenAnalytics.history.v1';
    const defaults = Object.assign({collapsed:false,density:'compact',perPage:25,actor:'human',event:'all',autoRefresh:0,wrap:false,stickyHeader:true,order:'desc',visibleColumns:['datetime','event','organization','page','referrer','environment','details']}, config.defaults || {});
    const form = section.querySelector('[data-history-form]');
    const body = section.querySelector('[data-history-body]');
    const settings = section.querySelector('[data-history-settings]');
    const tableHost = section.querySelector('[data-history-table]');
    const rangeTop = section.querySelector('[data-history-range-top]');
    const rangeBottom = section.querySelector('[data-history-range-bottom]');
    const status = section.querySelector('[data-history-status]');
    const toggleButton = section.querySelector('[data-history-toggle]');
    const settingsButton = section.querySelector('[data-history-settings-toggle]');
    const applyButton = section.querySelector('[data-settings-apply]');
    const resetSettingsButton = section.querySelector('[data-settings-reset]');
    const resetFiltersButton = section.querySelector('[data-filter-reset]');
    let currentPage = 1, loaded = false, requestController = null, debounceTimer = null, refreshTimer = null;

    function normalize(value) {
      const result = Object.assign({}, defaults, value && typeof value === 'object' ? value : {});
      result.collapsed = Boolean(result.collapsed); result.density = result.density === 'standard' ? 'standard' : 'compact';
      result.perPage = [25,50,100].includes(Number(result.perPage)) ? Number(result.perPage) : defaults.perPage;
      result.actor = ['all','human','bot'].includes(result.actor) ? result.actor : defaults.actor;
      result.event = ['all','pageview','engagement','external_click','download'].includes(result.event) ? result.event : defaults.event;
      result.autoRefresh = [0,30,60,300].includes(Number(result.autoRefresh)) ? Number(result.autoRefresh) : 0;
      result.wrap = Boolean(result.wrap); result.stickyHeader = Boolean(result.stickyHeader); result.order = result.order === 'asc' ? 'asc' : 'desc';
      const requested = Array.isArray(result.visibleColumns) ? result.visibleColumns : defaults.visibleColumns;
      result.visibleColumns = requested.filter((column,index) => validColumns.includes(column) && requested.indexOf(column) === index);
      if (!result.visibleColumns.includes('details')) result.visibleColumns.push('details');
      if (result.visibleColumns.length < 2) result.visibleColumns = defaults.visibleColumns.slice();
      return result;
    }
    function loadPrefs(){try{return normalize(JSON.parse(localStorage.getItem(storageKey)||'null'));}catch(_){return normalize(null);}}
    let prefs = loadPrefs();
    function savePrefs(){try{localStorage.setItem(storageKey,JSON.stringify(prefs));}catch(_){}}
    function setOptions(select,items,label){if(!select)return;const previous=select.value;select.textContent='';const all=document.createElement('option');all.value='';all.textContent=label;select.appendChild(all);(items||[]).forEach(item=>{const option=document.createElement('option');option.value=String(item.value??item);option.textContent=String(item.label??item.value??item);select.appendChild(option)});if([...select.options].some(option=>option.value===previous))select.value=previous;}
    function populate(){const options=config.options||{};setOptions(form.elements.country,options.countries,'All countries');setOptions(form.elements.browser,options.browsers,'All browsers');setOptions(form.elements.os,options.os,'All operating systems');setOptions(form.elements.device,options.devices,'All devices');}
    function syncForm(){form.elements.per_page.value=String(prefs.perPage);form.elements.actor.value=prefs.actor;form.elements.event.value=prefs.event;form.elements.order.value=prefs.order;}
    function syncSettings(){const density=settings?.querySelector(`[name="history_density"][value="${prefs.density}"]`);if(density)density.checked=true;const map={history_collapsed:'collapsed',history_wrap:'wrap',history_sticky:'stickyHeader'};Object.entries(map).forEach(([name,key])=>{const input=settings?.querySelector(`[name="${name}"]`);if(input)input.checked=Boolean(prefs[key]);});const auto=settings?.querySelector('[name="history_auto_refresh"]');if(auto)auto.value=String(prefs.autoRefresh);settings?.querySelectorAll('[name="history_columns[]"]').forEach(input=>input.checked=prefs.visibleColumns.includes(input.value));}
    function readSettings(){const density=settings?.querySelector('[name="history_density"]:checked');const columns=[...(settings?.querySelectorAll('[name="history_columns[]"]:checked')||[])].map(input=>input.value);prefs=normalize(Object.assign({},prefs,{density:density?.value||'compact',collapsed:Boolean(settings?.querySelector('[name="history_collapsed"]')?.checked),wrap:Boolean(settings?.querySelector('[name="history_wrap"]')?.checked),stickyHeader:Boolean(settings?.querySelector('[name="history_sticky"]')?.checked),autoRefresh:Number(settings?.querySelector('[name="history_auto_refresh"]')?.value||0),visibleColumns:columns,perPage:Number(form.elements.per_page.value),actor:form.elements.actor.value,event:form.elements.event.value,order:form.elements.order.value}));}
    function schedule(){if(refreshTimer)clearInterval(refreshTimer);refreshTimer=null;if(prefs.autoRefresh>0)refreshTimer=setInterval(()=>{if(!prefs.collapsed&&document.visibilityState==='visible')load(currentPage,true)},prefs.autoRefresh*1000);}
    function apply(){section.classList.toggle('tya-history--compact',prefs.density==='compact');section.classList.toggle('tya-history--standard',prefs.density==='standard');section.classList.toggle('tya-history--wrap',prefs.wrap);section.classList.toggle('tya-history--nowrap',!prefs.wrap);section.classList.toggle('tya-history--sticky',prefs.stickyHeader);body.hidden=prefs.collapsed;section.classList.toggle('is-collapsed',prefs.collapsed);toggleButton.setAttribute('aria-expanded',prefs.collapsed?'false':'true');toggleButton.textContent=prefs.collapsed?t('history.open'):t('history.close');validColumns.forEach(column=>section.classList.toggle(`tya-hide-${column}`,!prefs.visibleColumns.includes(column)));syncForm();syncSettings();schedule();}
    function loading(state,silent=false){section.classList.toggle('is-loading',state);if(state&&!silent)status.textContent=t('common.loading');}
    function params(page){const result=new URLSearchParams(new FormData(form));[...result.entries()].forEach(([key,value])=>{if(String(value)==='')result.delete(key)});result.set('page',String(page));result.set('per_page',String(prefs.perPage));result.set('actor',prefs.actor);result.set('event',prefs.event);result.set('order',prefs.order);return result;}
    function applyColumns(){validColumns.forEach(column=>section.querySelectorAll(`[data-col="${column}"]`).forEach(element=>element.hidden=!prefs.visibleColumns.includes(column)));}
    async function load(page=1,silent=false){if(prefs.collapsed)return;currentPage=Math.max(1,Number(page)||1);requestController?.abort();requestController=new AbortController();loading(true,silent);try{const headers={Accept:'application/json'};if(config.nonce)headers['X-WP-Nonce']=config.nonce;const response=await fetch(`${config.endpoint}${config.endpoint.includes('?')?'&':'?'}${params(currentPage)}`,{credentials:'same-origin',headers,signal:requestController.signal,cache:'no-store'});const payload=await response.json();if(response.status===401){location.reload();return;}if(!response.ok||payload.ok===false)throw new Error(payload.message||`HTTP ${response.status}`);tableHost.innerHTML=payload.table_html||`<p>${escapeHtml(t('common.no_data'))}</p>`;rangeTop.innerHTML=payload.range_html||'';rangeBottom.innerHTML=payload.range_html||'';const count=Number(payload.total||0).toLocaleString(locale);status.textContent=`${t('history.count',{count})}${payload.generated_at?` · ${t('history.updated',{time:payload.generated_at})}`:''}`;currentPage=Number(payload.page||currentPage);loaded=true;applyColumns();}catch(error){if(error.name==='AbortError')return;tableHost.innerHTML=`<div class="tya-history-error">${escapeHtml(t('history.load_failed',{message:error.message||String(error)}))}</div>`;status.textContent=t('common.failed_view');}finally{loading(false,true);}}

    populate(); apply();
    toggleButton.addEventListener('click',()=>{prefs.collapsed=!prefs.collapsed;savePrefs();apply();if(!prefs.collapsed&&!loaded)load(1)},{signal});
    settingsButton.addEventListener('click',()=>{settings.hidden=!settings.hidden;settingsButton.setAttribute('aria-expanded',settings.hidden?'false':'true')},{signal});
    applyButton.addEventListener('click',()=>{readSettings();savePrefs();apply();settings.hidden=true;settingsButton.setAttribute('aria-expanded','false');if(!prefs.collapsed)load(1)},{signal});
    resetSettingsButton.addEventListener('click',()=>{prefs=normalize(null);savePrefs();apply();if(!prefs.collapsed)load(1)},{signal});
    form.addEventListener('submit',event=>{event.preventDefault();prefs=normalize(Object.assign({},prefs,{perPage:Number(form.elements.per_page.value),actor:form.elements.actor.value,event:form.elements.event.value,order:form.elements.order.value}));savePrefs();apply();load(1)},{signal});
    form.addEventListener('input',event=>{if(event.target.name==='q'){clearTimeout(debounceTimer);debounceTimer=setTimeout(()=>load(1),320)}},{signal});
    form.addEventListener('change',event=>{if(event.target.name==='q')return;if(['per_page','actor','event','order'].includes(event.target.name)){prefs=normalize(Object.assign({},prefs,{perPage:Number(form.elements.per_page.value),actor:form.elements.actor.value,event:form.elements.event.value,order:form.elements.order.value}));savePrefs();}load(1)},{signal});
    resetFiltersButton.addEventListener('click',event=>{event.preventDefault();form.reset();syncForm();load(1)},{signal});
    section.addEventListener('click',event=>{const page=event.target.closest('[data-history-page]');if(page){event.preventDefault();load(Number(page.dataset.historyPage||1));return;}const button=event.target.closest('[data-history-detail]');if(button){const detail=button.closest('tr')?.nextElementSibling;if(detail?.classList.contains('tya-history-detail-row')){const open=detail.hidden;detail.hidden=!open;button.setAttribute('aria-expanded',open?'true':'false');button.textContent=open?t('common.close'):t('common.details');}}},{signal});
    document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible'&&prefs.autoRefresh>0&&!prefs.collapsed)load(currentPage,true)},{signal});
    if(!prefs.collapsed)load(1);
    return {destroy(){lifecycle.abort();requestController?.abort();clearTimeout(debounceTimer);if(refreshTimer)clearInterval(refreshTimer);}};
  }

  window.TYHistory = {init};
})();

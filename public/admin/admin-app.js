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
  let request = null, historyInstance = null, sessionsInstance = null, refreshTimer = null, metadataSearchTimer = null, currentPayload = null;

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
    loadSavedViewBar();
    renderMetadataManager();
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

  async function metadataRequest(action, {method='GET', data={}, query={}} = {}) {
    const url = new URL('api/metadata.php', location.href);
    url.searchParams.set('action', action);
    Object.entries(query).forEach(([key, value]) => { if (String(value) !== '') url.searchParams.set(key, String(value)); });
    const options = {method, credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json','X-CSRF-Token':config.csrf || ''}};
    if (method !== 'GET') {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify({action, ...data});
    }
    const response = await fetch(url, options);
    const payload = await response.json();
    if (response.status === 401) { location.reload(); throw new Error('Authentication required.'); }
    if (!response.ok || payload.ok === false) throw new Error(payload.message || t('metadata.failed'));
    return payload;
  }

  async function exclusionRequest(action, data = {}) {
    const url = new URL('api/exclusions.php', location.href);
    url.searchParams.set('action', action);
    const response = await fetch(url, {method:'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':config.csrf||''},body:JSON.stringify({action,...data})});
    const payload = await response.json();
    if (response.status === 401) { location.reload(); throw new Error('Authentication required.'); }
    if (!response.ok || payload.ok === false) throw new Error(payload.message || 'The exclusion operation failed.');
    return payload;
  }

  async function lifecycleRequest(action, data = {}) {
    const response=await fetch(new URL('api/lifecycle.php',location.href),{method:'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':config.csrf||''},body:JSON.stringify({action,...data})});
    const payload=await response.json();if(response.status===401){location.reload();throw new Error('Authentication required.');}if(!response.ok||payload.ok===false)throw new Error(payload.message||'The lifecycle operation failed.');return payload;
  }
  async function geoLite2Request(action,data={}){const response=await fetch(new URL('api/geolite2.php',location.href),{method:'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':config.csrf||''},body:JSON.stringify({action,...data})});const payload=await response.json();if(!response.ok||payload.ok===false)throw new Error(payload.message||`HTTP ${response.status}`);return payload;}

  function dialogShell(title) {
    const dialog = document.createElement('dialog');
    dialog.className = 'metadata-dialog';
    dialog.innerHTML = `<form method="dialog" class="metadata-dialog-card"><div class="journey-dialog-head"><h2>${escapeHtml(title)}</h2><button class="button secondary" value="cancel">${escapeHtml(t('common.close'))}</button></div><div data-dialog-content></div></form>`;
    document.body.append(dialog);
    dialog.addEventListener('close', () => { const opener = dialog._opener; dialog.remove(); opener?.focus?.(); });
    return dialog;
  }

  async function editAnnotation(button) {
    const type = button.dataset.entityType || '';
    const key = button.dataset.entityKey || '';
    const dialog = dialogShell(t('common.edit'));
    dialog._opener = button;
    const body = dialog.querySelector('[data-dialog-content]');
    dialog.showModal();
    try {
      const [annotationPayload, tagsPayload] = await Promise.all([
        metadataRequest('annotation', {query:{entity_type:type,entity_key:key}}),
        metadataRequest('tags')
      ]);
      const annotation = annotationPayload.annotation || {};
      const assigned = new Set((annotation.tags || []).map(tag => String(tag.tag_id)));
      body.innerHTML = `<p class="note">${escapeHtml(button.dataset.original || key)}</p><div class="field"><label>${escapeHtml(t('metadata.alias'))}<input name="alias" maxlength="120" value="${escapeHtml(annotation.alias || '')}"></label></div><div class="field"><label>${escapeHtml(t('metadata.note'))}<textarea name="note" maxlength="4000" rows="8">${escapeHtml(annotation.note || '')}</textarea></label></div><fieldset><legend>${escapeHtml(t('metadata.tags'))}</legend><div class="tag-options">${tagsPayload.items.map(tag => `<label><input type="checkbox" name="tag_ids" value="${Number(tag.tag_id)}"${assigned.has(String(tag.tag_id))?' checked':''}><span class="tag tag--${escapeHtml(tag.color)}">${escapeHtml(tag.name)}</span></label>`).join('')}</div></fieldset>${type==='organization'?`<label><input type="checkbox" name="watched"${annotation.watched?' checked':''}> ${escapeHtml(t('metadata.watched'))}</label>`:''}<div class="dialog-actions"><button class="button" type="submit">${escapeHtml(t('common.save'))}</button><button class="button secondary" type="button" data-dialog-cancel>${escapeHtml(t('common.cancel'))}</button></div><p aria-live="polite" data-dialog-status></p>`;
      const form = body.closest('form');
      body.querySelector('[data-dialog-cancel]').addEventListener('click', () => dialog.close());
      form.addEventListener('submit', async event => {
        event.preventDefault();
        const submit = body.querySelector('[type="submit"]'); submit.disabled = true;
        try {
          await metadataRequest('save_annotation', {method:'POST',data:{entity_type:type,entity_key:key,alias:body.querySelector('[name="alias"]').value,note:body.querySelector('[name="note"]').value,watched:body.querySelector('[name="watched"]')?.checked || false,tag_ids:[...body.querySelectorAll('[name="tag_ids"]:checked')].map(input => Number(input.value))}});
          status.textContent = t('metadata.saved'); dialog.close();
          load(new URL(location.href), {push:false});
        } catch (error) { body.querySelector('[data-dialog-status]').textContent = error.message; submit.disabled = false; }
      });
      body.querySelector('input')?.focus();
    } catch (error) { body.textContent = error.message; }
  }

  async function loadSavedViewBar() {
    const bar = content.querySelector('[data-saved-view-bar]');
    if (!bar) return;
    try {
      const payload = await metadataRequest('views', {query:{report:bar.dataset.report}});
      bar._views = payload.items;
      const select = bar.querySelector('[data-saved-view-select]');
      payload.items.forEach(view => {
        const option = document.createElement('option');
        option.value = view.saved_view_id;
        option.textContent = `${view.is_default ? '★ ' : ''}${view.name}`;
        select.append(option);
      });
      const defaultView = payload.items.find(view => view.is_default);
      const currentParams=new URL(location.href).searchParams;
      if (defaultView && [...currentParams.keys()].every(key=>key==='view') && !sessionStorage.getItem(`tya-default-${bar.dataset.report}`)) {
        sessionStorage.setItem(`tya-default-${bar.dataset.report}`,'1');
        const url=new URL(location.href);url.search='';url.searchParams.set('view',bar.dataset.report);
        Object.entries(defaultView.state||{}).forEach(([key,value])=>{if(Array.isArray(value))value.forEach(item=>url.searchParams.append(`${key}[]`,item));else url.searchParams.set(key,value);});
        load(url,{push:false});
      }
    } catch (error) { status.textContent = error.message; }
  }

  async function saveCurrentView(button) {
    const bar = button.closest('[data-saved-view-bar]');
    const dialog = dialogShell(t('saved_views.save_current')); dialog._opener = button;
    const body = dialog.querySelector('[data-dialog-content]');
    body.innerHTML = `<div class="field"><label>${escapeHtml(t('saved_views.name'))}<input name="name" maxlength="120" required></label></div><div class="field"><label>${escapeHtml(t('saved_views.description'))}<textarea name="description" maxlength="500"></textarea></label></div><label><input type="checkbox" name="pinned"> Pinned</label><label><input type="checkbox" name="is_default"> Default</label><div class="dialog-actions"><button class="button" type="submit">${escapeHtml(t('common.save'))}</button><button class="button secondary" type="button" data-dialog-cancel>${escapeHtml(t('common.cancel'))}</button></div><p aria-live="polite" data-dialog-status></p>`;
    dialog.showModal(); body.querySelector('[name="name"]').focus();
    body.querySelector('[data-dialog-cancel]').addEventListener('click',()=>dialog.close());
    body.closest('form').addEventListener('submit',async event=>{
      event.preventDefault();
      const state={};new URL(location.href).searchParams.forEach((value,key)=>{if(!['view','page','session','visitor'].includes(key)&&!key.endsWith('[]'))state[key]=value;});
      content.querySelectorAll('[data-view-filter],[data-sessions-filter],[data-history-form]').forEach(form=>new FormData(form).forEach((value,key)=>{if(String(value)!==''&&!key.endsWith('[]'))state[key]=String(value);}));
      const visible=[...content.querySelectorAll('[name="history_columns[]"]:checked')].map(input=>input.value);if(visible.length)state.visible_columns=visible;
      const submit=body.querySelector('[type="submit"]');submit.disabled=true;
      try{await metadataRequest('save_view',{method:'POST',data:{report:bar.dataset.report,name:body.querySelector('[name="name"]').value,description:body.querySelector('[name="description"]').value,pinned:body.querySelector('[name="pinned"]').checked,is_default:body.querySelector('[name="is_default"]').checked,state}});dialog.close();loadSavedViewBar();status.textContent=t('metadata.saved');}
      catch(error){body.querySelector('[data-dialog-status]').textContent=error.message;submit.disabled=false;}
    });
  }

  async function renderMetadataManager(tab='watched') {
    const manager=content.querySelector('[data-metadata-manager]');if(!manager)return;
    manager.dataset.activeTab=tab;
    manager.querySelectorAll('[data-metadata-tab]').forEach(button=>button.classList.toggle('secondary',button.dataset.metadataTab!==tab));
    manager.querySelector('[data-create-tag]').hidden=tab!=='tags';
    const results=manager.querySelector('[data-metadata-results]');const note=manager.querySelector('[data-metadata-status]');
    note.textContent=t('common.loading');
    try{
      if(tab==='tags'){
        const payload=await metadataRequest('tags',{query:{q:manager.querySelector('[data-metadata-search]').value}});
        results.innerHTML=`<div class="table-wrap"><table><thead><tr><th>${escapeHtml(t('metadata.tags'))}</th><th>Usage</th><th>${escapeHtml(t('common.edit'))}</th></tr></thead><tbody>${payload.items.map(tag=>`<tr><td><span class="tag tag--${escapeHtml(tag.color)}">${escapeHtml(tag.name)}</span></td><td>${Number(tag.usage_count)}</td><td><button class="button secondary" data-edit-tag data-tag="${escapeHtml(JSON.stringify(tag))}">${escapeHtml(t('common.edit'))}</button> <button class="button secondary" data-delete-tag="${Number(tag.tag_id)}">${escapeHtml(t('common.delete'))}</button></td></tr>`).join('')}</tbody></table></div>`;
      }else if(tab==='views'){
        const payload=await metadataRequest('views');
        results.innerHTML=`<div class="table-wrap"><table><thead><tr><th>${escapeHtml(t('saved_views.name'))}</th><th>Report</th><th>${escapeHtml(t('saved_views.description'))}</th><th>${escapeHtml(t('common.delete'))}</th></tr></thead><tbody>${payload.items.map(view=>`<tr><td>${view.pinned?'★ ':''}${escapeHtml(view.name)}${view.is_default?' (default)':''}</td><td>${escapeHtml(view.report)}</td><td>${escapeHtml(view.description)}</td><td><button class="button secondary" data-delete-view="${Number(view.saved_view_id)}">${escapeHtml(t('common.delete'))}</button></td></tr>`).join('')}</tbody></table></div>`;
      }else{
        const payload=await metadataRequest('annotations',{query:{q:manager.querySelector('[data-metadata-search]').value,tag_id:manager.querySelector('[data-metadata-tag]').value,watched:tab==='watched'?'1':''}});
        results.innerHTML=`<div class="table-wrap"><table><thead><tr><th>Type</th><th>${escapeHtml(t('metadata.alias'))}</th><th>Original key</th><th>${escapeHtml(t('metadata.tags'))}</th><th>${escapeHtml(t('common.edit'))}</th></tr></thead><tbody>${payload.items.map(item=>`<tr><td>${item.watched?'★ ':''}${escapeHtml(item.entity_type)}</td><td>${escapeHtml(item.alias||'—')}</td><td class="journey-long">${escapeHtml(item.entity_key)}</td><td>${(item.tags||[]).map(tag=>`<span class="tag tag--${escapeHtml(tag.color)}">${escapeHtml(tag.name)}</span>`).join(' ')}</td><td><button class="button secondary" data-edit-annotation data-entity-type="${escapeHtml(item.entity_type)}" data-entity-key="${escapeHtml(item.entity_key)}" data-original="${escapeHtml(item.entity_key)}">${escapeHtml(t('common.edit'))}</button></td></tr>`).join('')}</tbody></table></div>`;
      }
      note.textContent=t('common.ready');
    }catch(error){note.textContent=error.message;}
  }

  function editTag(button, tag={}) {
    const dialog=dialogShell(tag.tag_id?t('common.edit'):t('metadata.create_tag'));dialog._opener=button;
    const body=dialog.querySelector('[data-dialog-content]');
    const colors=['slate','blue','cyan','green','amber','orange','red','purple'];
    body.innerHTML=`<div class="field"><label>${escapeHtml(t('metadata.tags'))}<input name="name" maxlength="50" required value="${escapeHtml(tag.name||'')}"></label></div><div class="field"><label>Color<select name="color">${colors.map(color=>`<option value="${color}"${tag.color===color?' selected':''}>${color}</option>`).join('')}</select></label></div><div class="dialog-actions"><button class="button" type="submit">${escapeHtml(t('common.save'))}</button><button class="button secondary" type="button" data-dialog-cancel>${escapeHtml(t('common.cancel'))}</button></div><p aria-live="polite" data-dialog-status></p>`;
    dialog.showModal();body.querySelector('input').focus();body.querySelector('[data-dialog-cancel]').addEventListener('click',()=>dialog.close());
    body.closest('form').addEventListener('submit',async event=>{event.preventDefault();const submit=body.querySelector('[type="submit"]');submit.disabled=true;try{await metadataRequest('save_tag',{method:'POST',data:{tag_id:tag.tag_id||null,name:body.querySelector('[name="name"]').value,color:body.querySelector('[name="color"]').value}});dialog.close();renderMetadataManager('tags');}catch(error){body.querySelector('[data-dialog-status]').textContent=error.message;submit.disabled=false;}});
  }

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
    const annotationButton=event.target.closest('[data-edit-annotation]');
    if(annotationButton){editAnnotation(annotationButton);return;}
    const tab=event.target.closest('[data-metadata-tab]');
    if(tab){renderMetadataManager(tab.dataset.metadataTab);return;}
    const createTag=event.target.closest('[data-create-tag]');if(createTag){editTag(createTag);return;}
    const editTagButton=event.target.closest('[data-edit-tag]');if(editTagButton){editTag(editTagButton,JSON.parse(editTagButton.dataset.tag));return;}
    if(event.target.closest('[data-save-current-view]')){saveCurrentView(event.target.closest('[data-save-current-view]'));return;}
    if(event.target.closest('[data-load-saved-view]')){
      const bar=event.target.closest('[data-saved-view-bar]');const id=bar.querySelector('[data-saved-view-select]').value;
      const saved=(bar._views||[]).find(view=>String(view.saved_view_id)===id);if(saved){const url=new URL(location.href);url.search='';url.searchParams.set('view',bar.dataset.report);Object.entries(saved.state||{}).forEach(([key,value])=>{if(Array.isArray(value))value.forEach(item=>url.searchParams.append(`${key}[]`,item));else url.searchParams.set(key,value);});load(url);}return;
    }
    const deleteTag=event.target.closest('[data-delete-tag]');
    if(deleteTag&&confirm(t('common.confirm_delete'))){await metadataRequest('delete_tag',{method:'POST',data:{tag_id:Number(deleteTag.dataset.deleteTag)}});renderMetadataManager('tags');return;}
    const deleteView=event.target.closest('[data-delete-view]');
    if(deleteView&&confirm(t('common.confirm_delete'))){await metadataRequest('delete_view',{method:'POST',data:{saved_view_id:Number(deleteView.dataset.deleteView)}});renderMetadataManager('views');return;}
    const editExclusion=event.target.closest('[data-edit-exclusion]');
    if(editExclusion){const item=JSON.parse(editExclusion.dataset.rule);const form=content.querySelector('[data-exclusion-form]');if(form){Object.entries({rule_id:item.rule_id,rule_type:item.rule_type,rule_value:item.rule_value,scope:item.scope,note:item.note}).forEach(([key,value])=>{const field=form.elements.namedItem(key);if(field)field.value=value??'';});form.elements.namedItem('enabled').checked=Boolean(Number(item.enabled));form.scrollIntoView({behavior:'smooth',block:'start'});}return;}
    const deleteExclusion=event.target.closest('[data-delete-exclusion]');
    if(deleteExclusion&&confirm(t('common.confirm_delete'))){try{await exclusionRequest('delete',{rule_id:Number(deleteExclusion.dataset.deleteExclusion)});await load(new URL(location.href),{push:false});}catch(error){errorBox.textContent=error.message;errorBox.hidden=false;}return;}
    const lifecycleAction=event.target.closest('[data-lifecycle-action]');
    if(lifecycleAction){const result=lifecycleAction.closest('.panel')?.querySelector('[data-lifecycle-result]')||content.querySelector('[data-lifecycle-result]');if(lifecycleAction.dataset.lifecycleAction==='cleanup'&&!confirm(t('common.confirm_delete')))return;try{const payload=await lifecycleRequest(lifecycleAction.dataset.lifecycleAction);result.textContent=JSON.stringify(payload.preview||payload.cleanup||payload.aggregation,null,2);result.hidden=false;if(payload.cleanup||payload.aggregation)await load(new URL(location.href),{push:false});}catch(error){result.textContent=error.message;result.hidden=false;}return;}
    const geoAction=event.target.closest('[data-geolite2-action]');if(geoAction){const result=geoAction.closest('.panel').querySelector('[data-geolite2-result]');try{const payload=await geoLite2Request(geoAction.dataset.geolite2Action,geoAction.dataset.kind?{kind:geoAction.dataset.kind}:{});result.textContent=JSON.stringify(payload.update,null,2);result.hidden=false;await load(new URL(location.href),{push:false});}catch(error){result.textContent=error.message;result.hidden=false;}return;}
    const copy = event.target.closest('[data-copy-code]');
    if (copy) {
      const text = copy.parentElement?.querySelector('code')?.textContent || '';
      try { await navigator.clipboard.writeText(text); copy.textContent = 'Copied'; setTimeout(() => copy.textContent = 'Copy', 1400); }
      catch (_) { copy.textContent = 'Select and copy'; }
    }
  });

  content.addEventListener('submit', async event => {
    const exclusionForm=event.target.closest('[data-exclusion-form]');
    if(exclusionForm){event.preventDefault();const data=Object.fromEntries(new FormData(exclusionForm));data.enabled=exclusionForm.elements.namedItem('enabled').checked;const note=exclusionForm.querySelector('[data-exclusion-status]');try{await exclusionRequest('save',data);await load(new URL(location.href),{push:false});}catch(error){note.textContent=error.message;}return;}
    const lifecycleForm=event.target.closest('[data-lifecycle-form]');
    if(lifecycleForm){event.preventDefault();const statusNote=lifecycleForm.querySelector('[data-lifecycle-status]');try{await lifecycleRequest(lifecycleForm.dataset.action,Object.fromEntries(new FormData(lifecycleForm)));await load(new URL(location.href),{push:false});}catch(error){if(statusNote)statusNote.textContent=error.message;else errorBox.textContent=error.message;}return;}
    const geoForm=event.target.closest('[data-geolite2-form]');if(geoForm){event.preventDefault();const data=Object.fromEntries(new FormData(geoForm));data.enabled=geoForm.elements.namedItem('enabled').checked;const status=geoForm.querySelector('[data-geolite2-status]');try{await geoLite2Request('save',data);await load(new URL(location.href),{push:false});}catch(error){status.textContent=error.message;}return;}
    const diagnosticForm=event.target.closest('[data-exclusion-diagnostic]');
    if(diagnosticForm){event.preventDefault();const result=diagnosticForm.querySelector('[data-exclusion-result]');try{const payload=await exclusionRequest('diagnose',Object.fromEntries(new FormData(diagnosticForm)));result.textContent=JSON.stringify(payload.diagnostic,null,2);result.hidden=false;}catch(error){result.textContent=error.message;result.hidden=false;}return;}
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
  content.addEventListener('input',event=>{
    if(!event.target.matches('[data-metadata-search]'))return;
    if(metadataSearchTimer)clearTimeout(metadataSearchTimer);
    metadataSearchTimer=setTimeout(()=>renderMetadataManager(event.target.closest('[data-metadata-manager]')?.dataset.activeTab||'watched'),250);
  });
  content.addEventListener('change',event=>{if(event.target.matches('[data-metadata-tag]'))renderMetadataManager(event.target.closest('[data-metadata-manager]')?.dataset.activeTab||'watched');});

  window.addEventListener('popstate', () => load(new URL(location.href), {push:false}));
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && Number(currentPayload?.refresh_seconds || 0) > 0) load(new URL(location.href), {push:false, silent:true}); });

  const initial = initialState();
  currentPayload = initial; setActive(initial.view || 'dashboard'); startWidgets(initial);
  loadSavedViewBar(); renderMetadataManager();
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

(() => {
 window.DBoardProbe = {
  async open({panel,shell,request,el,isCurrent}) {
   shell('探针管理','统一查看服务器监控、管理探针接入和更新连接地址。');
   const status=el('output','dboard-marketing-status');status.setAttribute('aria-live','polite');panel.append(status);
   const tabs=el('div','dboard-probe-tabs');tabs.setAttribute('role','tablist');
   const body=el('section','dboard-probe-body');panel.append(tabs,body);
   let settings=null,active='monitor',poll=null;
   const button=(label,fn,cls='')=>{const b=el('button',cls,label);b.type='button';b.addEventListener('click',fn);return b;};
   const field=(label,type,value)=>{const wrap=el('label','dboard-marketing-field');wrap.append(el('span','',label));const input=el(type==='textarea'?'textarea':'input');if(type!=='textarea')input.type=type;input.value=value||'';wrap.append(input);return{wrap,input};};
   const feedback=fn=>async()=>{status.textContent='正在处理…';try{await fn();}catch(e){status.textContent=e.message;}};
   const renderMonitor=()=>{
    const card=el('div','dboard-probe-card');
    card.append(el('h2','','服务器监控'),el('p','',settings.enabled?'服务器负载、网络和在线状态由探针统一采集。':'先完成探针接入设置，即可进入监控后台。'));
    if(settings.enabled){
     const link=el('a','dboard-marketing-primary','进入监控后台 ↗');link.href=settings.dashboard_url;link.target='_blank';link.rel='noopener noreferrer';
     card.append(link,button('检查连接',feedback(async()=>{const s=await request('probe/status');status.textContent=s.connected?'探针与系统已连接':'探针可访问，系统连接尚未建立';})));
     card.append(el('p','dboard-probe-endpoint',settings.endpoint));
     card.append(el('p','dboard-probe-endpoint','监控后台在新窗口打开，可查看服务器负载、网络流量、告警和终端。'));
    }else card.append(button('配置探针',()=>render('settings'),'dboard-marketing-primary'));
    body.append(card);
   };
   const renderSettings=()=>{
    const form=el('form','dboard-marketing-form dboard-probe-card');
    const enabled=el('input');enabled.type='checkbox';enabled.checked=settings.enabled;
    const check=el('label','dboard-marketing-check');check.append(enabled,document.createTextNode('启用探针接入，新添加的服务器使用探针安装命令'));
    const endpoint=field('探针地址','url',settings.endpoint);endpoint.input.placeholder='https://monitor.example.com';endpoint.input.required=true;
    const key=field('对接密钥','password','');key.input.autocomplete='new-password';key.input.placeholder=settings.has_key?'已配置，留空保持原密钥':'填写探针服务的对接密钥';
    const version=field('整合 Agent 版本','text',settings.agent_version);version.input.required=true;
    form.append(check,endpoint.wrap,key.wrap,version.wrap);
    form.append(el('p','','已有服务器更换域名时，请使用“更新连接地址”。'));
    const save=el('button','dboard-marketing-primary','保存设置');save.type='submit';form.append(save);
    form.addEventListener('submit',async e=>{e.preventDefault();save.disabled=true;status.textContent='正在保存…';try{settings=await request('probe/save',{enabled:enabled.checked,endpoint:endpoint.input.value.trim(),control_key:key.input.value.trim()||null,agent_version:version.input.value.trim()});key.input.value='';status.textContent='设置已保存';}catch(e){status.textContent=e.message;}finally{save.disabled=false;}});
    const advanced=el('details','dboard-probe-advanced');advanced.append(el('summary','','系统连接配置'));
    advanced.append(el('p','','首次部署系统连接服务时使用。配置包含密钥，请妥善保存。'));
    advanced.append(button('下载连接配置',feedback(async()=>{const cfg=await request('probe/connector',{});const blob=new Blob([JSON.stringify(cfg,null,2)],{type:'application/json'});const u=URL.createObjectURL(blob);const a=el('a');a.href=u;a.download='probe-connector.json';a.click();setTimeout(()=>URL.revokeObjectURL(u),1000);status.textContent='连接配置已下载';})));
    form.append(advanced);body.append(form);
   };
   const renderMigration=async()=>{
    const form=el('form','dboard-probe-card dboard-marketing-form');body.append(form);
    form.append(el('h2','','更新探针连接地址'),el('p','','新域名和备用域名需先指向当前探针服务，并配置好 HTTPS。迁移完成前请保留旧地址。'));
    const endpoint=field('新的探针地址','url',settings.endpoint);endpoint.input.required=true;
    const backups=field('备用探针地址（每行一个，可留空）','textarea',(settings.backups||[]).join('\n'));backups.input.rows=2;
    form.append(endpoint.wrap,backups.wrap,el('h3','','选择服务器'));
    const list=el('div','dboard-probe-machines');form.append(list);
    const selected=new Set();let first=true;let busy=false;
    const refresh=async()=>{
     const rows=await request('probe/machines');if(!isCurrent()||active!=='migrate')return;
     list.replaceChildren();if(first){for(const m of rows)selected.add(m.id);first=false;}
     if(!rows.length)list.append(el('p','','暂未添加探针服务器'));
     for(const m of rows){
      const row=el('label','dboard-probe-machine');const cb=el('input');cb.type='checkbox';cb.checked=selected.has(m.id);cb.disabled=busy;cb.addEventListener('change',()=>cb.checked?selected.add(m.id):selected.delete(m.id));
      const name=el('span');name.append(el('strong','',m.name),el('small','',m.probe_endpoint||'等待首次连接'));
      const state=m.probe_migration?.state||'none';const label={none:'未迁移',pending:'等待连接 / 切换中',completed:'已切换',failed:'切换失败',fallback:'使用备用地址'}[state]||state;
      const badge=el('span','dboard-probe-state',label);badge.dataset.state=state;
      row.append(cb,name,badge);if(m.probe_migration?.message)row.title=m.probe_migration.message;list.append(row);
     }
    };
    await refresh();
    const submit=el('button','dboard-marketing-primary','检查并更新所选服务器');submit.type='submit';form.append(submit);
    form.addEventListener('submit',async e=>{e.preventDefault();if(!selected.size){status.textContent='请先选择服务器';return;}busy=true;submit.disabled=true;status.textContent='正在检查地址并下发更新…';try{
     await request('probe/migrate',{endpoint:endpoint.input.value.trim(),backups:backups.input.value.split(/\r?\n/).map(s=>s.trim()).filter(Boolean),ids:[...selected]});
     settings=await request('probe/fetch');status.textContent='更新任务已保存。在线服务器开始切换，离线服务器上线后自动处理。';await refresh();
    }catch(e){status.textContent=e.message;}finally{busy=false;submit.disabled=false;list.querySelectorAll("input").forEach(cb=>cb.disabled=false);}});
    poll=setInterval(()=>{if(!isCurrent()||active!=='migrate'){clearInterval(poll);return;}refresh().catch(()=>{});},3000);
   };
   async function render(key){
    if(poll){clearInterval(poll);poll=null;}active=key;body.replaceChildren();status.textContent='';
    for(const b of tabs.children){b.setAttribute('aria-selected',String(b.dataset.tab===key));}
    if(key==='monitor')renderMonitor();else if(key==='settings')renderSettings();else await renderMigration();
   }
   for(const [key,label] of [['monitor','监控后台'],['settings','接入设置'],['migrate','更新连接地址']]){
    const b=button(label,()=>render(key).catch(e=>{status.textContent=e.message;}));b.dataset.tab=key;b.setAttribute('role','tab');tabs.append(b);
   }
   try{settings=await request('probe/fetch');if(isCurrent())await render(settings.enabled?'monitor':'settings');}catch(e){status.textContent=e.message;}
  }
 };
})();

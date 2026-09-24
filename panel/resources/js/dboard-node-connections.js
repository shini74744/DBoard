(() => {
  const el=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=text;return n;};
  let current=null;
  const duration=value=>{
    const seconds=Math.floor(Number(value)||0);
    if(seconds<60)return seconds+'秒';
    const days=Math.floor(seconds/86400),hours=Math.floor(seconds%86400/3600),minutes=Math.floor(seconds%3600/60);
    return (days?days+'天 ':'')+(hours?hours+'小时 ':'')+(minutes?minutes+'分':'');
  };
  const date=value=>value?new Date(value*1000).toLocaleString():'—';
  function close(){
    if(!current)return;
    clearInterval(current.timer);current.controller?.abort();window.removeEventListener('keydown',current.onKey,true);current.overlay.remove();
    document.body.style.overflow=current.overflow;
    const trigger=current.trigger;current=null;trigger?.focus();
  }
  window.DBoardNodeConnections={open(node,kind='sources'){
    close();
    const state=current={node,kind,data:null,page:1,query:'',busy:false,controller:null,
      trigger:document.activeElement,overflow:document.body.style.overflow};
    const overlay=state.overlay=el('div','dboard-connections-overlay');
    const dialog=el('section','dboard-connections-dialog');dialog.setAttribute('role','dialog');dialog.setAttribute('aria-modal','true');
    dialog.setAttribute('aria-labelledby','dboard-connections-title');
    const header=el('header','dboard-connections-head');
    const title=el('h2','',node.name+' · 连接明细');title.id='dboard-connections-title';
    const closeButton=el('button','','关闭');closeButton.type='button';closeButton.onclick=close;header.append(title,closeButton);
    const meta=el('p','dboard-connections-meta','正在读取连接统计…');
    const tabs=el('div','dboard-connections-tabs');
    const names={sources:'来源 IP',tcp_rows:'TCP 连接',udp_rows:'UDP 会话'};
    for(const [key,name]of Object.entries(names)){
      const button=el('button','',name);button.type='button';button.dataset.kind=key;
      button.onclick=()=>{state.kind=key;state.page=1;state.query='';search.value='';draw();};tabs.append(button);
    }
    const tools=el('div','dboard-connections-tools');
    const search=el('input');search.type='search';search.placeholder='搜索 IP、运营商或连接目标';search.setAttribute('aria-label',search.placeholder);
    search.oninput=()=>{state.query=search.value.trim().toLowerCase();state.page=1;drawRows();};
    const refresh=el('button','','刷新');refresh.type='button';refresh.onclick=()=>load();tools.append(search,refresh);
    const status=el('p','dboard-connections-status');status.setAttribute('role','status');
    const body=el('div','dboard-connections-body');
    const footer=el('div','dboard-connections-footer');
    const hint=el('p','dboard-connections-note','次数为会话建立次数；时长为各连接累计时长，并发连接会分别累计。UDP 按会话统计，历史按分钟汇总，最多保留最近 24 小时。');
    dialog.append(header,meta,tabs,tools,status,body,footer,hint);overlay.append(dialog);document.body.append(overlay);document.body.style.overflow='hidden';
    overlay.onclick=e=>{if(e.target===overlay)close();};
    state.onKey=e=>{
      if(e.key==='Escape'){e.preventDefault();e.stopPropagation();close();return;}
      if(e.key==='Tab'){
        const focus=[...dialog.querySelectorAll('button:not(:disabled),input')];const first=focus[0],last=focus[focus.length-1];
        if(!dialog.contains(document.activeElement)){e.preventDefault();first.focus();}
        else if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}
        else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}
      }
    };
    window.addEventListener('keydown',state.onKey,true);
    function drawRows(){
      const scrollTop=body.scrollTop,scrollLeft=body.scrollLeft;body.replaceChildren();footer.replaceChildren();
      if(!state.data?.supported){body.append(el('p','dboard-connections-empty',state.data?.message||'尚无统计'));return;}
      const sources=state.kind==='sources';
      const rows=(state.data[state.kind]||[]).filter(r=>!state.query||[r.value,r.operator,r.asn].some(v=>String(v??'').toLowerCase().includes(state.query)));
      const pages=Math.max(1,Math.ceil(rows.length/50));state.page=Math.min(state.page,pages);
      if(!rows.length){body.append(el('p','dboard-connections-empty','该时间段内没有匹配的连接记录'));return;}
      const table=el('table');const head=el('thead');const tr=el('tr');
      for(const name of [sources?'来源 IP':'连接目标',...(sources?['运营商 / ASN']:[]),'当前连接','近24小时次数','累计时长'])tr.append(el('th','',name));
      head.append(tr);table.append(head);const tbody=el('tbody');
      for(const row of rows.slice((state.page-1)*50,state.page*50)){
        const tr=el('tr');const target=el('td','dboard-connections-address',row.value);tr.append(target);
        if(sources)tr.append(el('td','',row.operator+(row.asn?' · AS'+row.asn:'')));
        tr.append(el('td','',state.data.stale?'—':String(row.active)),el('td','',String(row.count)),el('td','',duration(row.seconds)));tbody.append(tr);
      }
      table.append(tbody);body.append(table);body.scrollTop=scrollTop;body.scrollLeft=scrollLeft;
      const prev=el('button','','上一页'),next=el('button','','下一页');prev.type=next.type='button';
      prev.disabled=state.page<=1;next.disabled=state.page>=pages;
      prev.onclick=()=>{state.page--;drawRows();};next.onclick=()=>{state.page++;drawRows();};
      footer.append(el('span','',rows.length+' 项 · '+state.page+' / '+pages+' 页'),prev,next);
    }
    function draw(){
      for(const b of tabs.children){b.classList.toggle('active',b.dataset.kind===state.kind);b.setAttribute('aria-pressed',String(b.dataset.kind===state.kind));}
      const d=state.data;
      meta.textContent=d?.supported?'统计范围：'+date(d.since)+' 至 '+date(d.updated_at)+' · 每 15 秒刷新':'等待节点上报；旧版节点需升级后才能采集。';
      status.textContent=d?.supported?[
        d.stale?'节点上报已过期，当前连接数暂不显示；以下为最后一次上报的历史记录。':'',
        d.truncated?'记录较多，明细已截取，次数和时长可能不完整；当前连接总数仍为完整统计。':''
      ].filter(Boolean).join(' '):'';
      drawRows();
    }
    async function load(){
      if(state.busy||current!==state)return;state.busy=true;refresh.disabled=true;state.controller=new AbortController();
      const timeout=setTimeout(()=>state.controller.abort(),12000);
      try{
        let token;try{token=JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN')||'{}').value;}catch{}
        const prefix=String(window.settings?.base_url||'/').replace(/\/?$/,'/')+'api/v2/'+String(window.settings?.secure_path||'').replace(/^\/+|\/+$/g,'')+'/';
        const response=await fetch(prefix+'server/manage/connections?id='+encodeURIComponent(node.id),{headers:{Authorization:token||''},signal:state.controller.signal});
        const result=await response.json();if(!response.ok||(result.code&&result.code!==0))throw new Error(result.message||'读取失败');
        if(current!==state)return;state.data=result.data;draw();
      }catch(error){if(current===state)status.textContent='读取失败，可点击刷新重试。'+(error.name==='AbortError'?'请求超时。':error.message);}
      finally{clearTimeout(timeout);state.busy=false;refresh.disabled=false;}
    }
    draw();closeButton.focus();void load();state.timer=setInterval(load,15000);
  }};
  window.addEventListener('hashchange',close);
})();

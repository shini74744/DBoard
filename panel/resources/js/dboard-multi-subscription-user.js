(() => {
  const GB=1073741824;
  const token=()=>{try{const v=JSON.parse(localStorage.getItem('VUE_NAIVE_ACCESS_TOKEN')||'{}');return v.expire==null||v.expire>Date.now()?v.value:null}catch{return null}};
  const el=(tag,text,cls)=>{const n=document.createElement(tag);if(text!=null)n.textContent=text;if(cls)n.className=cls;return n};
  const fmt=b=>(Number(b||0)/GB).toFixed(2)+' GB';
  const date=t=>t==null?'长期有效':new Date(Number(t)*1000).toLocaleString('zh-CN');
  let data,stamp=0,overlay;
  async function api(path,body){
    const response=await fetch('/api/v1/user/'+path,{method:body===undefined?'GET':'POST',headers:{Authorization:token()||'','Content-Type':'application/json'},body:body===undefined?undefined:JSON.stringify(body)});
    const value=await response.json();
    if(!response.ok||(value.code&&value.code!==0))throw Error(value.message||'读取失败');
    return value.data;
  }
  async function load(fresh){if(!fresh&&data&&Date.now()-stamp<15000)return data;data=await api('getSubscribe');stamp=Date.now();return data}
  const row=(name,value)=>{const n=el('div',null,'dboard-multi-row');n.append(el('span',name),el('strong',value));return n};
  function select(label,items,value,onChange){
    const n=el('label',null,'dboard-multi-field'),s=el('select');n.append(el('span',label));
    for(const [key,text] of items){const o=el('option',text);o.value=key;s.append(o)}
    s.value=value;s.addEventListener('change',()=>onChange(s.value));n.append(s);return n;
  }
  const close=()=>{overlay?.remove();overlay=null};
  function open(title){
    close();overlay=el('div',null,'dboard-multi-overlay');
    const panel=el('section',null,'dboard-multi-dialog');panel.setAttribute('role','dialog');panel.setAttribute('aria-modal','true');
    const head=el('header',null,'dboard-multi-head'),button=el('button','关闭');button.onclick=close;
    head.append(el('h2',title),button);panel.append(head);overlay.append(panel);document.body.append(overlay);
    overlay.addEventListener('click',e=>{if(e.target===overlay)close()});return panel;
  }
  async function packages(){
    const panel=open('我的套餐'),body=el('div','正在读取…');panel.append(body);
    try{
      const info=await load(true);if(!panel.isConnected)return;body.replaceChildren();
      const list=info.subscriptions||[],active=list.filter(x=>x.active);
      body.append(row('可用套餐',active.length+' 份'),row('合计剩余流量',fmt(active.reduce((n,x)=>n+Number(x.remaining||0),0))));
      const settings=el('section',null,'dboard-multi-settings');
      settings.append(select('订阅链接方式',[['merged','合并成一条链接'],['separate','每份套餐独立链接']],info.subscription_link_mode||'merged',v=>info.subscription_link_mode=v));
      settings.append(select('相同节点的显示',[['all','每份套餐各显示一条'],['first','只显示当前可用的第一条']],info.duplicate_node_mode||'all',v=>info.duplicate_node_mode=v));
      const save=el('button','保存链接设置','dboard-multi-primary');save.onclick=async()=>{save.disabled=true;try{await api('subscriptions/preferences',{subscription_link_mode:info.subscription_link_mode,duplicate_node_mode:info.duplicate_node_mode});stamp=0;save.textContent='已保存'}catch(e){save.textContent=e.message;save.disabled=false}};
      settings.append(save);body.append(settings);
      const merged=info.merged_summary||{};
      body.append(row('合并链接显示的总流量',fmt(merged.transfer_enable)),row('合并链接显示的到期时间',active.length?date(merged.expired_at):'暂无可用套餐'));
      const copyMerged=el('button','复制合并订阅链接');copyMerged.onclick=async()=>{await navigator.clipboard.writeText(info.subscribe_url);copyMerged.textContent='已复制'};body.append(copyMerged);
      body.append(el('p','合并链接只汇总当前可用套餐；客户端显示最晚到期时间。每份套餐到期后，其节点和剩余额度会自动从合并链接中移除。'));
      for(const item of list){if(!item.plan_id)continue;
        const card=el('article',null,'dboard-multi-package');card.append(el('h3',(item.display_name||item.plan_name||'套餐')+(item.primary?' · 原有套餐':'')));
        card.append(row('状态',item.active?'可用':'已到期或流量用尽'),row('到期时间',date(item.expired_at)),row('总流量',fmt(item.transfer_enable)),row('已用流量',fmt(Number(item.u)+Number(item.d))),row('剩余流量',fmt(item.remaining)));
        const copy=el('button','复制此套餐订阅链接');copy.onclick=async()=>{await navigator.clipboard.writeText(item.subscribe_url);copy.textContent='已复制'};card.append(copy);body.append(card);
      }
    }catch(e){body.textContent=e.message}
  }
  const choice={action:'auto',id:null,planId:null};
  let lastToken=null;
  window.DBoardMultiSubscription={orderChoice:planId=>({
    subscription_action:Number(planId)===choice.planId?choice.action:'auto',
    ...(Number(planId)===choice.planId&&choice.action==='renew'&&choice.id?{subscription_user_id:choice.id}:{})
  })};
  async function purchase(){
    const panel=open('购买方式'),body=el('div','正在读取…');panel.append(body);
    try{
      const info=await load();if(!panel.isConnected)return;
      const planId=Number(location.pathname.match(/\/plan\/(\d+)/)?.[1]);
      if(choice.planId!==planId){choice.action='auto';choice.id=null;choice.planId=planId}
      const matches=(info.subscriptions||[]).filter(x=>Number(x.plan_id)===planId);
      const options=[['auto','按原有方式购买或续费'],['add','新增一份独立套餐']];
      for(const x of matches)options.push(['renew:'+x.id,'续费 '+(x.display_name||x.plan_name||'套餐')+'（'+date(x.expired_at)+'）']);
      body.replaceChildren(el('p','同一套餐也能再买一份，流量和到期时间分别计算。'));
      body.append(select('本次购买',options,choice.action==='renew'?'renew:'+choice.id:choice.action,value=>{
        choice.action=value.startsWith('renew:')?'renew':value;
        choice.id=choice.action==='renew'?Number(value.slice(6)):null;
        document.querySelector('.dboard-multi-buy').textContent=options.find(x=>x[0]===value)?.[1]||'购买方式';
      }));
    }catch(e){body.textContent=e.message}
  }
  function mount(){
    if(!document.querySelector('#app')||!token())return;
    if(lastToken!==token()){lastToken=token();data=null;choice.action='auto';choice.id=null;choice.planId=null}
    const plan=/^\/plan\/\d+/.test(location.pathname),dashboard=location.pathname==='/dashboard';
    if(!plan)document.querySelector('.dboard-multi-buy')?.remove();
    if(!dashboard)document.querySelector('.dboard-multi-open')?.remove();
    if(dashboard&&(!data||Date.now()-stamp>15000))load().catch(()=>{});
    if(dashboard&&!document.querySelector('.dboard-multi-open')){
      const button=el('button','查看全部套餐与订阅链接','dboard-multi-open');button.onclick=packages;document.body.append(button);
    }
    if(plan&&!document.querySelector('.dboard-multi-buy')){
      const button=el('button','选择购买方式','dboard-multi-buy');button.onclick=purchase;document.body.append(button);
    }
  }
  document.addEventListener('click',e=>{
    const button=e.target.closest?.('button,a');
    if(location.pathname==='/dashboard'&&button?.textContent.trim()==='导入订阅'
      &&data?.subscription_link_mode==='separate'&&(data.subscriptions||[]).filter(x=>x.plan_id).length>1){
      e.preventDefault();e.stopPropagation();packages();
    }
  },true);
  document.addEventListener('keydown',e=>{if(e.key==='Escape')close()});
  setInterval(mount,1000);mount();
})();
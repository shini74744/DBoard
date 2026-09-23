(() => {
  const token=()=>{try{const v=JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN')||'{}');return v.expire==null||v.expire>Date.now()?v.value:null}catch{return null}};
  const periodNames={monthly:'月付',quarterly:'季付',half_yearly:'半年付',yearly:'年付',two_yearly:'两年付',three_yearly:'三年付',onetime:'一次性',month_price:'月付',quarter_price:'季付',half_year_price:'半年付',year_price:'年付',two_year_price:'两年付',three_year_price:'三年付',onetime_price:'一次性'};
  const base=()=>'/api/v2/'+String(window.settings?.secure_path||'').replace(/^\/+|\/+$/g,'')+'/';
  const el=(tag,text)=>{const n=document.createElement(tag);if(text!=null)n.textContent=text;return n};
  async function api(path,body){
    const response=await fetch(base()+path,{method:body===undefined?'GET':'POST',headers:{Authorization:token()||'','Content-Type':'application/json'},body:body===undefined?undefined:JSON.stringify(body)});
    const data=await response.json();if(!response.ok||(data.code&&data.code!==0))throw Error(data.message||'操作失败');return data.data;
  }
  let overlay;
  const close=()=>{overlay?.remove();overlay=null};
  function input(label,type='text'){
    const wrap=el('label'),caption=el('span',label),field=el('input');field.type=type;wrap.append(caption,field);return [wrap,field];
  }
  function expiryChoice(planName){
    const wrap=el('div');wrap.className='dboard-multi-expiry';
    const caption=el('span','有效期（单独选择）');
    const mode=el('select');mode.setAttribute('aria-label',planName+'的有效期');
    for(const [value,label] of [
      ['auto','按价格档位的周期'],
      ['7','7 天'],['30','30 天'],['90','90 天'],['180','180 天'],['365','365 天'],
      ['days','自定义天数'],['date','指定到期日期和时间']
    ]){const option=el('option',label);option.value=value;mode.append(option)}
    const days=el('input');days.type='number';days.min='1';days.max='3650';days.step='1';days.placeholder='输入有效天数（1–3650）';
    days.setAttribute('aria-label',planName+'的自定义有效天数');
    const date=el('input');date.type='datetime-local';date.setAttribute('aria-label',planName+'的到期日期和时间');
    const refresh=()=>{days.hidden=mode.value!=='days';date.hidden=mode.value!=='date'};
    mode.onchange=refresh;refresh();wrap.append(caption,mode,days,date);
    const values=()=>{
      if(mode.value==='auto')return {};
      if(mode.value==='date'){
        if(!date.value)throw Error('请设置 '+planName+' 的到期日期和时间');
        const stamp=Math.floor(new Date(date.value).getTime()/1000);
        if(!Number.isFinite(stamp)||stamp<=Date.now()/1000+60||stamp>Date.now()/1000+315360000)
          throw Error(planName+' 的到期时间须在 1 分钟后、10 年内');
        return {custom_expired_at:stamp};
      }
      const count=mode.value==='days'?Number(days.value):Number(mode.value);
      if(!Number.isInteger(count)||count<1||count>3650)
        throw Error('请设置 '+planName+' 的有效天数（1–3650）');
      return {custom_duration_days:count};
    };
    return {wrap,values};
  }
  async function open(){
    close();overlay=el('div');overlay.className='dboard-multi-admin-overlay';
    const panel=el('section');panel.className='dboard-multi-admin-dialog';panel.setAttribute('role','dialog');panel.setAttribute('aria-modal','true');panel.setAttribute('aria-label','管理用户套餐');
    const head=el('header'),dismiss=el('button','关闭');dismiss.type='button';dismiss.onclick=close;head.append(el('h2','管理用户套餐'),dismiss);panel.append(head);
    const form=el('form'),[emailField,email]=input('用户邮箱','email'),search=el('button','查询套餐'),status=el('p'),host=el('div');
    email.required=true;email.placeholder='输入已有用户的邮箱';search.type='submit';status.setAttribute('role','status');
    form.append(emailField,search,status);panel.append(form,host);overlay.append(panel);document.body.append(overlay);
    overlay.onclick=e=>{if(e.target===overlay)close()};
    form.onsubmit=async event=>{
      event.preventDefault();search.disabled=true;status.textContent='正在查询…';host.replaceChildren();delete host.dataset.subscriptionUserId;
      try{
        const query=new URLSearchParams({'filter[0][id]':'email','filter[0][value]':'eq:'+email.value.trim()});
        const matches=await api('user/fetch?'+query);
        const user=matches?.find(item=>String(item.email).toLowerCase()===email.value.trim().toLowerCase());
        if(!user)throw Error('没有找到该用户');
        host.className='dboard-multi-inline-host';
        await mountForUser(host,user,[],()=>window.location.reload(),()=>false);
        status.textContent='账号：'+user.email;
      }catch(error){status.textContent=error.message}finally{search.disabled=false}
    };
    email.focus();
  }
  const bytesText=value=>{const n=Math.max(0,Number(value)||0);return n>=1073741824?(n/1073741824).toLocaleString('zh-CN',{maximumFractionDigits:2})+' GB':n>=1048576?(n/1048576).toFixed(2)+' MB':n>=1024?(n/1024).toFixed(2)+' KB':n+' B'};
  const packageName=item=>(item.plan_name||'套餐')+' #'+item.id;
  const expiryText=value=>value==null?'长期有效':new Date(Number(value)*1000).toLocaleString('zh-CN',{hour12:false});
  function addMetric(parent,label,value){
    const cell=el('div');cell.append(el('span',label),el('strong',value));parent.append(cell);
  }
  async function showPackages(userId){
    close();overlay=el('div');overlay.className='dboard-multi-admin-overlay';
    const panel=el('section');panel.className='dboard-multi-admin-dialog';panel.setAttribute('role','dialog');panel.setAttribute('aria-modal','true');panel.setAttribute('aria-label','用户持有套餐');
    const head=el('header'),dismiss=el('button','关闭');dismiss.type='button';dismiss.onclick=close;
    head.append(el('h2','用户持有套餐'),dismiss);panel.append(head);
    const content=el('div','正在读取…');content.className='dboard-package-cards';panel.append(content);overlay.append(panel);document.body.append(overlay);
    overlay.onclick=e=>{if(e.target===overlay)close()};
    try{
      const current=await api('user/getUserInfoById?id='+encodeURIComponent(userId));
      if(!panel.isConnected)return;
      content.replaceChildren(el('p',current.email));
      const packages=(current.subscriptions||[]).filter(item=>item.plan_id);
      if(!packages.length)content.append(el('p','当前没有套餐'));
      for(const item of packages){
        const card=el('article');card.className='dboard-package-card';
        card.append(el('strong',packageName(item)));
        const metrics=el('div');metrics.className='dboard-package-metrics';
        addMetric(metrics,'已用流量',bytesText(Number(item.u)+Number(item.d)));
        addMetric(metrics,'总流量',bytesText(item.transfer_enable));
        addMetric(metrics,'剩余流量',bytesText(item.remaining));
        addMetric(metrics,'到期时间',expiryText(item.expired_at));
        addMetric(metrics,'限速',item.speed_limit?item.speed_limit+' Mbps':'不限速');
        addMetric(metrics,'设备限制',item.device_limit?item.device_limit+' 台':'不限制');
        card.append(metrics);content.append(card);
      }
    }catch(error){content.textContent=error.message}
    dismiss.focus();
  }
  async function mountSettings(host,user,onSaved){
    if(!host||!user||host.dataset.packageSettingsUserId===String(user.id))return;
    host.dataset.packageSettingsUserId=String(user.id);host.textContent='正在读取各套餐用量与限制…';
    try{
      const current=await api('user/getUserInfoById?id='+encodeURIComponent(user.id));
      if(!host.isConnected)return;
      host.replaceChildren(el('strong','各套餐用量与限制'));
      host.append(el('p','点击套餐名称展开设置；每份套餐独立保存。'));
      const packages=(current.subscriptions||[]).filter(item=>item.plan_id);
      if(!packages.length){host.append(el('p','当前没有套餐，请在下方勾选新增套餐。'));return}
      for(const item of packages){
        const card=el('details');card.className='dboard-package-card';card.open=packages.length===1;
        const summary=el('summary'),title=el('strong',packageName(item)),usage=el('span');
        const refreshSummary=()=>{usage.textContent='已用 '+bytesText(Number(item.u)+Number(item.d))+' / 总流量 '+bytesText(item.transfer_enable)};
        refreshSummary();summary.append(title,usage);card.append(summary);
        const fields=el('div');fields.className='dboard-package-fields';const controls=[];
        for(const [key,label,unit] of [['u','已用上行','GB'],['d','已用下行','GB'],['transfer_enable','总流量','GB'],['speed_limit','限速','Mbps'],['device_limit','设备限制','台']]){
          const wrap=el('label'),field=el('input');field.type='number';field.min='0';field.step=unit==='GB'?'any':'1';
          const traffic=unit==='GB';field.value=item[key]==null?'':traffic?String(Number(item[key])/1073741824):String(item[key]);
          field.placeholder=key==='speed_limit'?'留空不限速':key==='device_limit'?'留空不限制':'0';
          field.setAttribute('aria-label',packageName(item)+' '+label);
          wrap.append(el('span',label+'（'+unit+'）'),field);fields.append(wrap);
          controls.push({key,field,traffic,original:field.value});
        }
        const timeWrap=el('label'),timeField=el('input');timeField.type='datetime-local';timeField.step='1';
        const toLocal=value=>{if(value==null||!value)return '';const date=new Date(Number(value)*1000);return new Date(date.getTime()-date.getTimezoneOffset()*60000).toISOString().slice(0,19)};
        timeField.value=toLocal(item.expired_at);timeField.setAttribute('aria-label',packageName(item)+' 到期时间');
        let initialTime=timeField.value;
        const permanentWrap=el('label'),permanent=el('input');permanent.type='checkbox';permanent.checked=item.expired_at==null;
        let initialPermanent=permanent.checked;
        permanentWrap.className='dboard-package-permanent';permanentWrap.append(permanent,el('span','长期有效'));
        timeField.disabled=permanent.checked;permanent.onchange=()=>{timeField.disabled=permanent.checked};
        timeWrap.append(el('span','到期时间'),timeField);fields.append(timeWrap,permanentWrap);
        const save=el('button','保存此套餐设置');save.type='button';save.className='dboard-multi-inline-save';
        const status=el('p');status.setAttribute('role','status');
        save.onclick=async()=>{
          const changes={};
          try{
            for(const control of controls){
              if(control.field.value===control.original)continue;
              const raw=control.field.value;
              if(raw===''){if(control.traffic)throw Error('流量不能为空');changes[control.key]=null;continue}
              const number=Number(raw),value=control.traffic?Math.round(number*1073741824):number;
              if(!Number.isFinite(number)||number<0||!Number.isSafeInteger(value))throw Error('请填写有效的非负数，限速和设备数须为整数');
              changes[control.key]=value;
            }
            if(timeField.value!==initialTime||permanent.checked!==initialPermanent){
              const stamp=permanent.checked?null:Math.floor(new Date(timeField.value).getTime()/1000);
              if(stamp!==null&&(!Number.isSafeInteger(stamp)||stamp<0))throw Error('请选择有效的到期时间');
              changes.expired_at=stamp;
            }
            if(!Object.keys(changes).length){status.textContent='设置没有变化';return}
            save.disabled=true;status.textContent='正在保存…';
            await api('user/subscription/update',{user_id:current.id,subscription_user_id:item.id,...changes});
            Object.assign(item,changes);controls.forEach(control=>{control.original=control.field.value});
            initialTime=timeField.value;initialPermanent=permanent.checked;refreshSummary();
            status.textContent='已保存 '+packageName(item);onSaved?.();
          }catch(error){status.textContent=error.message}finally{save.disabled=false}
        };
        card.append(fields,save,status);host.append(card);
      }
    }catch(error){host.textContent='读取套餐失败：'+error.message}
  }
  async function mountForUser(host,user,plans,onSaved,isDirty){
    if(!host||host.dataset.subscriptionUserId===String(user.id))return;
    host.dataset.subscriptionUserId=String(user.id);
    host.textContent='正在读取套餐…';
    let current,availablePlans;
    try{
      [current,availablePlans]=await Promise.all([
        api('user/getUserInfoById?id='+encodeURIComponent(user.id)),
        api('plan/fetch')
      ]);
      if(!Array.isArray(availablePlans))throw Error('套餐列表格式有误');
    }catch(error){host.textContent='读取套餐失败：'+error.message;return}
    if(!host.isConnected)return;
    host.replaceChildren();
    const title=el('strong','已持有套餐（取消勾选可移除）');
    const currentList=el('div');currentList.className='dboard-multi-inline-list';
    const existing=[];
    for(const item of (current.subscriptions||[]).filter(item=>item.plan_id)){
      const label=el('label'),check=el('input');check.type='checkbox';check.checked=true;
      const text=el('span',(item.plan_name||'套餐')+' #'+item.id);
      label.append(check,text);currentList.append(label);existing.push({item,check});
    }
    if(!existing.length)currentList.append(el('p','当前没有套餐'));
    const newTitle=el('strong','新增套餐（勾选后保存）');
    const newList=el('div');newList.className='dboard-multi-inline-list';
    const available=[];
    for(const plan of availablePlans){
      const periods=Object.entries(plan.prices||{}).filter(([key,value])=>key!=='reset_price'&&key!=='reset_traffic'&&value!=null);
      if(!periods.length)continue;
      const row=el('div');row.className='dboard-multi-inline-choice';
      const label=el('label'),check=el('input');check.type='checkbox';
      label.append(check,el('span',plan.name));
      const period=el('select');period.setAttribute('aria-label',plan.name+'的订阅周期');
      for(const [key,value] of periods){const option=el('option',(periodNames[key]||key)+' · ¥'+value);option.value=key;period.append(option)}
      const priceLabel=el('span','价格档位');
      const expiry=expiryChoice(plan.name);
      priceLabel.className='dboard-multi-field-caption';
      const duplicate=existing.filter(entry=>Number(entry.item.plan_id)===Number(plan.id));
      const operation=el('div');operation.className='dboard-package-operation';
      const action=el('select'),target=el('select');
      action.setAttribute('aria-label',plan.name+'的开通方式');target.setAttribute('aria-label','选择要叠加时长的 '+plan.name);
      if(duplicate.length){
        operation.append(el('p','已持有此套餐，请选择新开一份，或给现有套餐叠加时长。'));
        for(const [value,text] of [['','请选择开通方式'],['add','新开独立套餐'],['extend','叠加现有套餐时长']]){
          const option=el('option',text);option.value=value;action.append(option);
        }
        for(const entry of duplicate){const option=el('option',packageName(entry.item)+' · '+expiryText(entry.item.expired_at));option.value=entry.item.id;option.disabled=entry.item.expired_at==null;target.append(option)}
        const note=el('p','叠加时长从现有到期时间起算，已过期则从现在起算；流量及限制设置保留。长期有效套餐无需叠加。');
        action.onchange=()=>{target.hidden=note.hidden=action.value!=='extend'};
        target.hidden=true;note.hidden=true;operation.append(action,target,note);
      }else{action.value='add'}
      period.hidden=priceLabel.hidden=expiry.wrap.hidden=operation.hidden=true;
      check.onchange=()=>{period.hidden=priceLabel.hidden=expiry.wrap.hidden=!check.checked;operation.hidden=!check.checked||!duplicate.length};
      row.append(label,operation,priceLabel,period,expiry.wrap);newList.append(row);available.push({plan,check,period,expiry,action,target,duplicate});
    }
    if(!available.length)newList.append(el('p','暂无设置价格的可开通套餐'));
    const hint=el('p','每个已持有套餐可单独取消；同款套餐可选择新开或叠加时长。有效期、流量与限制在对应套餐卡片内独立设置。');
    const status=el('p');status.setAttribute('role','status');
    const save=el('button','保存套餐选择');save.type='button';save.className='dboard-multi-inline-save';
    host.append(title,currentList,newTitle,newList,hint,save,status);
    save.onclick=async()=>{
      const additions=available.filter(row=>row.check.checked);
      const removals=existing.filter(row=>!row.check.checked);
      if(!additions.length&&!removals.length){status.textContent='套餐没有变化';return}
      if(isDirty?.()&&!window.confirm('编辑窗口里还有其他未保存的修改。继续会丢弃这些修改，确定吗？'))return;
      let prepared;
      try{prepared=additions.map(row=>{
        const action=row.duplicate.length?row.action.value:'add';
        if(!action)throw Error('请为 '+row.plan.name+' 选择新开套餐或叠加时长');
        const target=action==='extend'?Number(row.target.value):null;
        if(action==='extend'){
          const entry=existing.find(entry=>Number(entry.item.id)===target);
          if(!entry||entry.item.expired_at==null)throw Error('请选择可叠加时长的现有套餐');
          if(!entry.check.checked)throw Error('不能同时移除并叠加同一份套餐');
        }
        return {row,expiry:row.expiry.values(),action,target};
      })}
      catch(error){status.textContent=error.message;return}
      if(removals.length&&!window.confirm('确定移除 '+removals.map(row=>(row.item.plan_name||'套餐')+' #'+row.item.id).join('、')+'？移除后原订阅链接立即失效。'))return;
      save.disabled=true;let changed=false;
      try{
        for(const {row,expiry,action,target} of prepared){
          status.textContent=(action==='extend'?'正在叠加时长 ':'正在开通 ')+row.plan.name+'…';
          const tradeNo=await api('order/assign',{email:current.email,plan_id:Number(row.plan.id),period:row.period.value,total_amount:0,subscription_action:action,subscription_user_id:target,...expiry});
          changed=true;
          try{await api('order/paid',{trade_no:tradeNo})}
          catch(error){throw Error('订单 '+tradeNo+' 已创建但未开通，请先处理该订单：'+error.message)}
        }
        for(const row of removals){
          if(row.item.id===current.id&&additions.length){
            const fresh=await api('user/getUserInfoById?id='+encodeURIComponent(current.id));
            if(Number(fresh.plan_id)!==Number(row.item.plan_id))continue;
          }
          status.textContent='正在移除 '+(row.item.plan_name||'套餐')+' #'+row.item.id+'…';
          await api('user/subscription/remove',{user_id:current.id,subscription_user_id:row.item.id});
          changed=true;
        }
        status.textContent='已保存';onSaved?.();
      }catch(error){
        status.textContent=error.message;
        if(changed){window.alert('已完成部分操作，页面将刷新以免重复添加。'+error.message);onSaved?.()}
      }finally{save.disabled=false}
    };
  }
  window.DBoardMultiSubscriptionAdmin={mountForUser,mountSettings,showPackages};
  function mount(){
    const root=document.getElementById('root');if(!root||!token())return;
    const heading=[...root.querySelectorAll('h1,h2')].find(n=>n.textContent.trim()==='用户管理');
    if(!heading)return;
    if(root.querySelector('.dboard-multi-admin-open'))return;
    const button=el('button','管理用户套餐');button.type='button';button.className='dboard-multi-admin-open';button.onclick=open;
    heading.parentElement?.append(button);
  }
  document.addEventListener('keydown',e=>{if(e.key==='Escape')close()});
  const root=document.getElementById('root');if(root)new MutationObserver(()=>{if(!window.__dboardMultiAdminTimer)window.__dboardMultiAdminTimer=setTimeout(()=>{window.__dboardMultiAdminTimer=0;mount()},150)}).observe(root,{childList:true,subtree:true});
  mount();
})();
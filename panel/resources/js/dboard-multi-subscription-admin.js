(() => {
  const token=()=>{try{const v=JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN')||'{}');return v.expire==null||v.expire>Date.now()?v.value:null}catch{return null}};
  const periodNames={monthly:'月付',quarterly:'季付',half_yearly:'半年付',yearly:'年付',two_yearly:'两年付',three_yearly:'三年付',onetime:'一次性',month_price:'月付',quarter_price:'季付',half_year_price:'半年付',year_price:'年付',two_year_price:'两年付',three_year_price:'三年付',onetime_price:'一次性'};
  const base=()=>'/api/v2/'+String(window.settings?.secure_path||'').replace(/^\/+|\/+$/g,'')+'/';
  const el=(tag,text)=>{const n=document.createElement(tag);if(text!=null)n.textContent=text;return n};
  async function api(path,body){
    const response=await fetch(base()+path,{method:body===undefined?'GET':'POST',headers:{Authorization:token()||'','Content-Type':'application/json'},body:body===undefined?undefined:JSON.stringify(body)});
    const data=await response.json();if(!response.ok||(data.code&&data.code!==0))throw Error(data.message||'操作失败');return data.data;
  }
  const billingPeriods=['monthly','quarterly','half_yearly','yearly','two_yearly','three_yearly','onetime'];
  const money=cents=>(Number(cents)/100).toFixed(2);
  const toCents=value=>{
    const raw=String(value).trim();
    if(!/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(raw))throw Error('价格须为非负金额，最多两位小数');
    const [whole,decimal='']=raw.split('.');
    const cents=Number(whole)*100+Number(decimal.padEnd(2,'0'));
    if(!Number.isSafeInteger(cents)||cents>2147483647)throw Error('价格超出允许范围');
    return cents;
  };
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
        await mountForUser(host,user,[],()=>{},()=>false);
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
      const host=el('div');host.className='dboard-multi-inline-host';content.append(host);
      await mountForUser(host,current,[],()=>{},()=>false);
    }catch(error){content.textContent=error.message}
    dismiss.focus();
  }
  async function mountSettings(host,user,onSaved,currentData){
    if(!host||!user||host.dataset.packageSettingsUserId===String(user.id))return;
    host.dataset.packageSettingsUserId=String(user.id);host.textContent='正在读取各套餐用量与限制…';
    try{
      const current=currentData||await api('user/getUserInfoById?id='+encodeURIComponent(user.id));
      if(!host.isConnected)return;
      host.replaceChildren(el('strong','已持有套餐'));
      host.append(el('p','点击套餐查看详情，可修改、重置流量或取消订阅。'));
      const packages=(current.subscriptions||[]).filter(item=>item.plan_id);
      if(!packages.length){host.append(el('p','当前没有套餐，可在下方新增。'));return}
      for(const item of packages){
        const card=el('details');card.className='dboard-package-card';card.open=false;
        const summary=el('summary'),title=el('strong',packageName(item)),usage=el('span');
        const refreshSummary=()=>{usage.textContent='已用 '+bytesText(Number(item.u)+Number(item.d))+' / '+bytesText(item.transfer_enable)+' · '+expiryText(item.expired_at)+' · 连接 '+(item.connection_limit||'不限')+' · '+(periodNames[item.billing_period]||'周期未识别')+(item.billing_price==null?'':' ¥'+money(item.billing_price))};
        refreshSummary();summary.append(title,usage);card.append(summary);
        const fields=el('div');fields.className='dboard-package-fields';const controls=[];
        const billingWrap=el('label'),billingPeriod=el('select');
        billingPeriod.setAttribute('aria-label',packageName(item)+' 付费周期');
        const unknown=el('option','未识别，请选择周期');unknown.value='';unknown.disabled=true;billingPeriod.append(unknown);
        for(const key of billingPeriods){const option=el('option',periodNames[key]);option.value=key;billingPeriod.append(option)}
        billingPeriod.value=item.billing_period||'';
        billingWrap.append(el('span','付费周期'),billingPeriod);
        const priceWrap=el('label'),billingPrice=el('input'),standard=el('button','恢复标准价');
        billingPrice.type='number';billingPrice.min='0';billingPrice.max='21474836.47';billingPrice.step='0.01';
        billingPrice.setAttribute('aria-label',packageName(item)+' 周期价格');
        standard.type='button';standard.className='dboard-package-standard';
        const priceFor=key=>Object.hasOwn(item.billing_prices||{},key)?item.billing_prices[key]:(item.billing_plan_prices?.[key]==null?null:Math.round(Number(item.billing_plan_prices[key])*100));
        const updatePrice=()=>{const value=priceFor(billingPeriod.value);billingPrice.value=value==null?'':money(value);billingPrice.placeholder=value==null?'请填写价格':'留空使用标准价'};
        updatePrice();
        let originalPeriod=billingPeriod.value,originalPrice=billingPrice.value,restoreStandard=false;
        billingPeriod.onchange=()=>{restoreStandard=false;updatePrice()};
        billingPrice.oninput=()=>{restoreStandard=false};
        standard.onclick=()=>{billingPrice.value='';restoreStandard=true;billingPrice.placeholder='使用套餐标准价'};
        priceWrap.append(el('span','周期价格（元）'),billingPrice,standard);
        fields.append(billingWrap,priceWrap);
        const billingNote=el('p','仅此份套餐同周期续费沿用该价格，其他优惠仍按现有规则计算。修改不改变当前到期时间或历史订单。');
        billingNote.className='dboard-package-billing-note';fields.append(billingNote);
        for(const [key,label,unit] of [['u','已用上行','GB'],['d','已用下行','GB'],['transfer_enable','总流量','GB'],['speed_limit','限速','Mbps'],['device_limit','设备限制','台'],['connection_limit','连接数限制','个']]){
          const wrap=el('label'),field=el('input');field.type='number';field.min='0';field.step=unit==='GB'?'any':'1';
          const traffic=unit==='GB';field.value=item[key]==null?'':traffic?String(Number(item[key])/1073741824):String(item[key]);
          field.placeholder=key==='speed_limit'?'留空不限速':['device_limit','connection_limit'].includes(key)?'0 或留空不限':'0';
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
            if(billingPeriod.value!==originalPeriod||billingPrice.value!==originalPrice||restoreStandard){
              if(!billingPeriod.value)throw Error('请先选择付费周期');
              changes.billing_period=billingPeriod.value;
              changes.billing_price=billingPrice.value.trim()===''?null:toCents(billingPrice.value);
              if(changes.billing_price===null&&item.billing_plan_prices?.[billingPeriod.value]==null)throw Error('此周期没有标准价格，请填写价格');
            }
            for(const control of controls){
              if(control.field.value===control.original)continue;
              const raw=control.field.value;
              if(raw===''){if(control.traffic)throw Error('流量不能为空');changes[control.key]=null;continue}
              const number=Number(raw),value=control.traffic?Math.round(number*1073741824):number;
              if(!Number.isFinite(number)||number<0||!Number.isSafeInteger(value))throw Error('请填写有效的非负数，限速、设备数和连接数须为整数');
              if(control.key==='connection_limit'&&value>2147483647)throw Error('连接数超出允许范围');
              changes[control.key]=value;
            }
            if(timeField.value!==initialTime||permanent.checked!==initialPermanent){
              const stamp=permanent.checked?null:Math.floor(new Date(timeField.value).getTime()/1000);
              if(stamp!==null&&(!Number.isSafeInteger(stamp)||stamp<0))throw Error('请选择有效的到期时间');
              changes.expired_at=stamp;
            }
            if(!Object.keys(changes).length){status.textContent='设置没有变化';return}
            [save,reset,cancel].forEach(button=>button.disabled=true);status.textContent='正在保存…';
            await api('user/subscription/update',{user_id:current.id,subscription_user_id:item.id,...changes});
            Object.assign(item,changes);
            if(Object.hasOwn(changes,'billing_price')){
              item.billing_prices={...(item.billing_prices||{})};
              if(changes.billing_price==null)delete item.billing_prices[changes.billing_period];
              else item.billing_prices[changes.billing_period]=changes.billing_price;
              item.billing_price=priceFor(changes.billing_period);
              originalPeriod=billingPeriod.value;updatePrice();originalPrice=billingPrice.value;restoreStandard=false;
            }
            controls.forEach(control=>{control.original=control.field.value});
            initialTime=timeField.value;initialPermanent=permanent.checked;refreshSummary();
            status.textContent='已保存 '+packageName(item);onSaved?.('update');
          }catch(error){status.textContent=error.message}finally{[save,reset,cancel].forEach(button=>button.disabled=false)}
        };
        const reset=el('button','重置流量'),cancel=el('button','取消套餐订阅');
        reset.type=cancel.type='button';cancel.className='dboard-package-danger';
        const action=async(path,message)=>{
          if(!window.confirm(message))return;
          [save,reset,cancel].forEach(button=>button.disabled=true);status.textContent='正在处理…';
          try{
            await api(path,{user_id:current.id,subscription_user_id:item.id});
            if(path.endsWith('/remove')){card.remove();status.textContent='已取消订阅';onSaved?.('remove');}
            else{
              const fresh=await api('user/getUserInfoById?id='+encodeURIComponent(current.id));
              const updated=fresh.subscriptions?.find(entry=>Number(entry.id)===Number(item.id));
              if(!updated)throw Error('套餐已变更，请重新打开查看');
              Object.assign(item,updated);
              controls.forEach(control=>{control.field.value=item[control.key]==null?'':String(control.traffic?Number(item[control.key])/1073741824:item[control.key]);control.original=control.field.value});
              billingPeriod.value=item.billing_period||'';updatePrice();originalPeriod=billingPeriod.value;originalPrice=billingPrice.value;restoreStandard=false;
              refreshSummary();status.textContent='此套餐流量已重置';onSaved?.('reset');
            }
          }catch(error){status.textContent=error.message}
          finally{[save,reset,cancel].forEach(button=>button.disabled=false)}
        };
        reset.onclick=()=>action('user/subscription/reset-traffic','确定重置 '+packageName(item)+' 的流量？仅清零此套餐已用流量，并按套餐规则开始新周期；本周期临时流量奖励会到期。');
        cancel.onclick=()=>action('user/subscription/remove','确定取消 '+packageName(item)+'？该套餐订阅链接将立即失效，其他套餐不受影响。');
        const actions=el('div');actions.className='dboard-package-actions';actions.append(save,reset,cancel);
        card.append(fields,el('p','连接数按每个节点的 TCP 连接＋UDP 会话合计；0 或留空不限，需支持此功能的节点版本。'),actions,status);host.append(card);
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
    const currentList=el('div');currentList.className='dboard-owned-packages';
    const existing=(current.subscriptions||[]).filter(item=>item.plan_id).map(item=>({item,check:{checked:true}}));
    const refresh=async()=>{
      delete host.dataset.subscriptionUserId;
      await mountForUser(host,user,plans,onSaved,()=>false);
      onSaved?.();
    };
    host.append(currentList);
    await mountSettings(currentList,current,kind=>{if(kind==='remove')refresh();else onSaved?.()},current);
    const addSection=el('details');addSection.className='dboard-package-add';
    const newTitle=el('summary','＋ 新增套餐');
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
      const priceRow=el('div');priceRow.className='dboard-package-price-row';
      const customPrice=el('input');customPrice.type='number';customPrice.min='0';customPrice.max='21474836.47';customPrice.step='0.01';
      customPrice.placeholder='自定义价格（元）';customPrice.setAttribute('aria-label',plan.name+'的自定义价格');
      priceRow.append(period,customPrice);

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
      priceRow.hidden=priceLabel.hidden=expiry.wrap.hidden=operation.hidden=true;
      check.onchange=()=>{priceRow.hidden=priceLabel.hidden=expiry.wrap.hidden=!check.checked;operation.hidden=!check.checked||!duplicate.length};
      row.append(label,operation,priceLabel,priceRow,expiry.wrap);newList.append(row);available.push({plan,check,period,customPrice,expiry,action,target,duplicate});
    }
    if(!available.length)newList.append(el('p','暂无设置价格的可开通套餐'));
    const hint=el('p','自定义价格留空使用档位标准价，填写 0 为免费；自定义价格仅此份套餐同周期续费沿用。点击开通会按所选价格记录管理员已支付订单，不会自动扣款。');
    const status=el('p');status.setAttribute('role','status');
    const save=el('button','开通所选套餐');save.type='button';save.className='dboard-multi-inline-save';
    addSection.append(newTitle,newList,hint,save,status);host.append(addSection);
    save.onclick=async()=>{
      const additions=available.filter(row=>row.check.checked);
      const removals=existing.filter(row=>!row.check.checked);
      if(!additions.length&&!removals.length){status.textContent='套餐没有变化';return}

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
        const custom=row.customPrice.value.trim();
        const amount=toCents(custom===''?String(row.plan.prices[row.period.value]):custom);
        return {row,expiry:row.expiry.values(),action,target,amount,custom:custom!==''};
      })}
      catch(error){status.textContent=error.message;return}
      if(removals.length&&!window.confirm('确定移除 '+removals.map(row=>(row.item.plan_name||'套餐')+' #'+row.item.id).join('、')+'？移除后原订阅链接立即失效。'))return;
      save.disabled=true;let changed=false;
      try{
        for(const {row,expiry,action,target,amount,custom} of prepared){
          status.textContent=(action==='extend'?'正在叠加时长 ':'正在开通 ')+row.plan.name+'…';
          const tradeNo=await api('order/assign',{email:current.email,plan_id:Number(row.plan.id),period:row.period.value,total_amount:amount,...(custom?{renewal_price:amount}:{}),subscription_action:action,subscription_user_id:target,...expiry});
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
        status.textContent='已保存';await refresh();
      }catch(error){
        status.textContent=error.message;
        if(changed){window.alert('已完成部分操作，将重新读取套餐以免重复添加。'+error.message);await refresh()}
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
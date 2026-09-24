(function(){
 'use strict';
 window.DBoardFrontGateReact=function(H,Q,props){
  const {form,nodes=[],groups=[],machines=[]}=props;
  const [search,setSearch]=H.useState('');
  const id=Number(form.watch('id')||0),enabled=!!form.watch('front_gate_enabled');
  const nodeIds=(form.watch('front_gate_node_ids')||[]).map(Number),groupIds=(form.watch('front_gate_group_ids')||[]).map(Number);
  const current=nodes.find(n=>Number(n.id)===id),status=current?.front_gate_status||{};
  const candidates=nodes.filter(n=>Number(n.id)!==id&&!n.parent_id&&n.enabled!==false);
  const selected=new Set(nodeIds);
  const sameIds=(a,b)=>JSON.stringify([...a].map(Number).sort((a,b)=>a-b))===JSON.stringify([...(b||[])].map(Number).sort((a,b)=>a-b));
  const dirty=enabled!==!!current?.front_gate_enabled||!sameIds(nodeIds,current?.front_gate_node_ids)||!sameIds(groupIds,current?.front_gate_group_ids);
  const unavailableNodes=nodeIds.filter(id=>!candidates.some(n=>Number(n.id)===id));
  const unavailableGroups=groupIds.filter(id=>!groups.some(g=>Number(g.value)===id));
  const automatic=new Set(candidates.filter(n=>(n.group_ids||[]).some(g=>groupIds.includes(Number(g)))).map(n=>Number(n.id)));
  const effective=candidates.filter(n=>selected.has(Number(n.id))||automatic.has(Number(n.id)));
  const machine=id=>machines.find(m=>Number(m.id)===Number(id))?.name;
  const toggle=(field,list,value,checked)=>form.setValue(field,checked?[...new Set([...list,value])]:list.filter(v=>v!==value),{shouldDirty:true});
  const query=search.trim().toLowerCase();
  const visible=candidates.filter(n=>[n.name,n.id,machine(n.machine_id)].join(' ').toLowerCase().includes(query));
  const disabled=!id||(!status.capable&&!current?.front_gate_enabled);
  const row=(label,checked,onChange,key,detail)=>Q.jsxs('label',{className:'dboard-front-choice',children:[
   Q.jsx('input',{type:'checkbox',checked,onChange:e=>onChange(e.target.checked)}),
   Q.jsxs('span',{children:[Q.jsx('span',{children:label}),detail&&Q.jsx('small',{children:detail})]})
  ]},key);
  return Q.jsxs('section',{className:'dboard-front-gate','aria-label':'指定前置',children:[
   Q.jsxs('label',{className:'dboard-front-toggle',children:[
    Q.jsx('span',{children:'仅允许指定前置'}),
    Q.jsx('input',{type:'checkbox',role:'switch','aria-label':'仅允许指定前置',checked:enabled,disabled,onChange:e=>form.setValue('front_gate_enabled',e.target.checked,{shouldDirty:true})})
   ]}),
   Q.jsx('p',{className:'dboard-front-hint',children:!id?'先保存节点，再开启前置认证。':disabled?'请先升级落地节点程序，等待节点上报认证能力。':'由节点程序验证前置身份，用户订阅不包含认证凭据。'}),
   enabled&&Q.jsxs(Q.Fragment,{children:[
    Q.jsx('p',{className:'dboard-front-status',children:dirty?'有未保存的前置设置':status.applied?'已应用前置限制':'等待落地节点应用限制；以重新读取后的状态为准。'}),
    Q.jsx('strong',{children:'指定套餐权限组'}),
    Q.jsx('p',{className:'dboard-front-hint',children:'组内节点自动加入允许列表，增减成员自动同步。'}),
    Q.jsx('div',{className:'dboard-front-options',children:groups.map(g=>row(g.label,groupIds.includes(Number(g.value)),on=>toggle('front_gate_group_ids',groupIds,Number(g.value),on),'g'+g.value))}),
    ...unavailableGroups.map(id=>row('已移除的权限组 #'+id,true,on=>toggle('front_gate_group_ids',groupIds,id,on),'gone-g'+id,'请取消勾选后保存')),
    !groups.length&&Q.jsx('p',{className:'dboard-front-hint',children:'暂无套餐权限组'}),
    Q.jsx('strong',{children:'额外指定前置节点'}),
    Q.jsx('input',{type:'search',className:'dboard-front-search',placeholder:'搜索节点名、ID 或服务器','aria-label':'搜索允许的前置节点',value:search,onChange:e=>setSearch(e.target.value)}),
    Q.jsx('div',{className:'dboard-front-options',children:visible.map(n=>row(n.name,selected.has(Number(n.id)),on=>toggle('front_gate_node_ids',nodeIds,Number(n.id),on),'n'+n.id,
      '#'+n.id+' · '+(machine(n.machine_id)||'独立部署')+(automatic.has(Number(n.id))?' · 已由套餐权限组允许':'')+(!n.front_gate_status?.capable?' · 待升级':'')))}),
    ...unavailableNodes.map(id=>row((nodes.find(n=>Number(n.id)===id)?.name||'已移除的节点')+' #'+id,true,on=>toggle('front_gate_node_ids',nodeIds,id,on),'gone-n'+id,'该前置当前不可用，可取消勾选')),
    !visible.length&&Q.jsx('p',{className:'dboard-front-hint',children:'没有匹配的前置节点'}),
    Q.jsx('p',{className:'dboard-front-hint',children:'当前合并允许 '+effective.length+' 个前置。直接选择与套餐权限组合并生效，自动排除本节点；空组不会放行任何节点。'}),
    Q.jsx('p',{className:'dboard-front-hint',children:'开启后，此落地不能由用户客户端直接连接。前置的出站规则请选择此落地节点，系统自动配置认证并保留分流；修改授权会断开此落地的现有连接。'})
   ]})
  ]});
 };
})();

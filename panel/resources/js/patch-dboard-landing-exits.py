from pathlib import Path
p=Path(__file__).resolve().parents[2]/'public/assets/admin/assets/index-CEIYH7i8.js'
s=p.read_text()
def rep(a,b):
 global s
 if b in s:return
 assert s.count(a)==1,a[:100]
 s=s.replace(a,b,1)
rep('...(!o&&d?.admin_group_id?{admin_group_id:d.admin_group_id}:{})', '...(!o?{admin_scope:d?.admin_scope||"node",...(d?.admin_group_id?{admin_group_id:d.admin_group_id}:{})}:{})')
rep('children:!o&&d?.admin_group_name?"保存后自动归入管理分组："+d.admin_group_name:e("manage.description")', 'children:!o&&(d?.admin_group_name||d?.admin_scope==="node_landing")?"保存后自动归入："+(d?.admin_scope==="node_landing"?"落地分区 / ":"普通分区 / ")+(d?.admin_group_name||"未分组"):e("manage.description")')
rep("const openNodeExits=async()=>{\n    setNodeExitOpen(true);setNodeExitBusy(true);setNodeExitError('');setNodeExitSelected([]);", "const openNodeExits=async()=>{\n    if(nodeExitOpen){setNodeExitOpen(false);return;}\n    setNodeExitOpen(true);setNodeExitBusy(true);setNodeExitError('');")
rep('const[routeOpen,setRouteOpen]=H.useState(!1)', '''const[nodeExitGroup,setNodeExitGroup]=H.useState('all');
  const nodeExitGroupKey=node=>JSON.stringify([node.admin_scope||'node',node.admin_group||'']);
  const nodeExitGroupOptions=Array.from(new Map(nodeExitList.map(node=>[nodeExitGroupKey(node),{value:nodeExitGroupKey(node),label:((node.admin_scope||'node')==='node_landing'?'落地 / ':'普通 / ')+(node.admin_group||'未分组')}])).values()).sort((a,b)=>a.label.localeCompare(b.label,'zh-CN'));
  const formatExitBytes=value=>{const n=Math.max(0,Number(value)||0);if(!n)return '0 B';const i=Math.min(4,Math.floor(Math.log(n)/Math.log(1024)));return (n/1024**i).toFixed(i?2:0)+' '+['B','KB','MB','GB','TB'][i];};
  const[routeOpen,setRouteOpen]=H.useState(!1)''')
rep('const{data}=await aD();\n      const list=Array.isArray(data)?data:[];', "const data=await nodeExitRequest('fetch'+(Number(e.getValues('id'))?'?source_id='+Number(e.getValues('id')):''));\n      const list=Array.isArray(data)?data:[];")
rep('  const openAdvanced=(requested="cert")=>{', '''  H.useEffect(()=>{if(!r||o!=="outbounds")return;const timer=setInterval(()=>loadOutbounds(),5000);return()=>clearInterval(timer)},[r,o]);
  const openAdvanced=(requested="cert")=>{''')
rep('onClick:openNodeExits,children:"选择现有节点"', 'onClick:openNodeExits,"aria-expanded":nodeExitOpen,children:nodeExitOpen?"收起现有节点":"选择现有节点"')
needle='Q.jsx("input",{type:"search",value:nodeExitSearch'
rep(needle,'''Q.jsxs("label",{className:"text-sm",children:["按分组选出口",Q.jsxs("select",{"aria-label":"按分组选出口",value:nodeExitGroup,onChange:event=>setNodeExitGroup(event.target.value),style:{width:"100%",marginBottom:8,padding:"8px 10px",border:"1px solid hsl(var(--border))",borderRadius:6,background:"hsl(var(--background))",color:"inherit"},children:[Q.jsx("option",{value:"all",children:"全部分组"}),Q.jsx("option",{value:"scope:node",children:"普通分区 · 全部"}),Q.jsx("option",{value:"scope:node_landing",children:"落地分区 · 全部"}),...nodeExitGroupOptions.map(group=>Q.jsx("option",{value:group.value,children:group.label},group.value))]})]}),
                  '''+needle)
rep("nodeExitList.filter(node=>(node.name+' '+node.host+' '+node.id).toLowerCase().includes(nodeExitSearch.toLowerCase()))", "nodeExitList.filter(node=>(nodeExitGroup==='all'||nodeExitGroup==='scope:'+(node.admin_scope||'node')||nodeExitGroup===nodeExitGroupKey(node))&&(node.name+' '+node.host+' '+node.id).toLowerCase().includes(nodeExitSearch.toLowerCase()))")
rep('v.settings?.server&&Q.jsxs("div",{className:"mt-1 truncate font-mono text-[11px]",children:[v.settings.server,":",v.settings.server_port||""]})', '''v.settings?.server&&Q.jsxs("div",{className:"mt-1 truncate font-mono text-[11px]",children:[v.settings.server,":",v.settings.server_port||""]}),
                Q.jsxs("div",{"data-node-outbound-traffic":v.id,style:{marginTop:10,paddingTop:8,borderTop:"1px solid hsl(var(--border))",fontSize:12},children:[Q.jsx("div",{children:"本节点累计流量"}),v.node_traffic?Q.jsxs(Q.Fragment,{children:[Q.jsx("strong",{children:formatExitBytes(Number(v.node_traffic.upload)+Number(v.node_traffic.download))}),Q.jsx("div",{children:"上传 "+formatExitBytes(v.node_traffic.upload)+" · 下载 "+formatExitBytes(v.node_traffic.download)}),Q.jsx("small",{children:v.node_traffic.started_at?"自首次添加 "+new Date(v.node_traffic.started_at*1000).toLocaleString():"历史上报累计（首次添加时间未记录）"})]}):Q.jsx("span",{children:"保存节点后开始统计"})]})''')
rep('g.length>0&&Q.jsx("div",{className:"grid gap-2 sm:grid-cols-2 lg:grid-cols-3",children:selectedOutbounds.map', 'g.length>0&&Q.jsx("p",{className:"text-xs text-muted-foreground",children:"每 5 秒刷新本节点经各出口的累计上传与下载；移除后重新添加继续累计，不影响用户计费和出站管理的全局统计。"}),\n              g.length>0&&Q.jsx("div",{className:"grid gap-2 sm:grid-cols-2 lg:grid-cols-3",children:selectedOutbounds.map')
p.write_text(s)

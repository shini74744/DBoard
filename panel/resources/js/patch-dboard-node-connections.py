from pathlib import Path
p=Path(__file__).resolve().parents[2]/'public/assets/admin/assets/index-CEIYH7i8.js'
s=p.read_text()
column='''{id:"connection_stats",header:()=>"连接数",cell:({row:e})=>{const n=e.original.connection_stats;return Q.jsx("div",{className:"dboard-connection-counts","data-stale":!!n?.stale,children:[["sources","IP",n?.source_ips],["tcp_rows","TCP",n?.tcp],["udp_rows","UDP",n?.udp]].map(([kind,label,value])=>Q.jsxs("button",{type:"button",title:!n?"待升级：节点尚未上报连接明细":n.stale?"上报已过期，点击查看历史记录":"当前"+label+"数量，点击查看最近24小时明细",onClick:event=>{event.stopPropagation();window.DBoardNodeConnections?.open(e.original,kind)},children:[label," ",n&&!n.stale?value??0:"—"]},kind))})},size:190,enableSorting:!1,enableHiding:!0},'''
start=s.index('Z5t=');end=s.index('function Y5t',start)
part=s[start:end]
if 'id:"connection_stats"' not in part:
 anchor='{id:"actions",header:'
 assert part.count(anchor)==1
 part=part.replace(anchor,column+anchor,1)
 s=s[:start]+part+s[end:]
p.write_text(s)
print('Connection statistics column installed')

"""Keep admin group filtering inside the native React tables.

The DBoard repository currently ships the admin UI as a prebuilt bundle. Re-run
this script after replacing that bundle; it fails if upstream changes its anchors.
"""
from pathlib import Path

bundle = Path(__file__).resolve().parents[2] / 'public/assets/admin/assets/index-CEIYH7i8.js'
source = bundle.read_text()
hook = ('const[,dboardUpdateGroups]=H.useState(0);'
        'H.useEffect(()=>{const listener=()=>dboardUpdateGroups(value=>value+1);'
        'window.addEventListener("dboard-admin-group-change",listener);'
        'return()=>window.removeEventListener("dboard-admin-group-change",listener)},[]);')
changes = [
    ('function X5t(){const{t:e}=jy("server")',
     'function X5t(){' + hook + 'const{t:e}=jy("server")'),
    ('function F3t(){const{t:e}=jy("machine")',
     'function F3t(){' + hook + 'const{t:e}=jy("machine")'),
    ('NGt({data:_||[],columns:Z5t(',
     'NGt({data:window.DBoardAdminGroups?.filter("node",_||[])??(_||[]),columns:Z5t('),
    ('NGt({data:v,columns:h3t(f,e,b)',
     'NGt({data:window.DBoardAdminGroups?.filter("machine",v)??v,columns:h3t(f,e,b)'),
]
changes.extend([
    ('c(e??null),i(!0)},[]);return Q.jsx(d4t.Provider',
     'c({machine_id:e?.machine_id??null,enabled:e?.enabled??null,...(window.DBoardAdminGroups?.newNodeGroup?.()||{})}),i(!0)},[]);return Q.jsx(d4t.Provider'),
    ('qL({...s,type:l,transfer_enable:i})).data&&(D(),gE.success(e("form.success")),h())',
     'qL({...s,type:l,transfer_enable:i,...(!o?{admin_scope:d?.admin_scope||"node",...(d?.admin_group_id?{admin_group_id:d.admin_group_id}:{})}:{})})).data&&(D(),gE.success(e("form.success")),h(),window.DBoardAdminGroups?.refresh?.())'),
    ('Q.jsx(vtt,{className:"font-mono text-xs opacity-70",children:e("manage.description")})]}),Q.jsxs(zy,{...x',
     'Q.jsx(vtt,{className:"font-mono text-xs opacity-70",children:!o&&(d?.admin_group_name||d?.admin_scope==="node_landing")?"保存后自动归入："+(d?.admin_scope==="node_landing"?"落地分区 / ":"普通分区 / ")+(d?.admin_group_name||"未分组"):e("manage.description")})]}),Q.jsxs(zy,{...x'),
])
for old, new in changes:
    if source.count(new) == 1:
        continue
    if source.count(old) != 1:
        raise SystemExit(f'Expected one admin bundle anchor: {old[:60]}')
    source = source.replace(old, new, 1)
bundle.write_text(source)
print('Native admin table group filters applied:', bundle.name)

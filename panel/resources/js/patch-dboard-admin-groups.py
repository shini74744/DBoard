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
for old, new in changes:
    if source.count(new) == 1:
        continue
    if source.count(old) != 1:
        raise SystemExit(f'Expected one admin bundle anchor: {old[:60]}')
    source = source.replace(old, new, 1)
bundle.write_text(source)
print('Native admin table group filters applied:', bundle.name)

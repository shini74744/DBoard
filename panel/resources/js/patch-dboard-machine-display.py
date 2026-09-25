"""Apply display-only machine IDs to the shipped admin bundle; keep all identity values intact."""
from pathlib import Path
bundle=Path(__file__).resolve().parents[2]/'public/assets/admin/assets/index-CEIYH7i8.js'
s=bundle.read_text()
changes=[
 ('children:["SID:",e.id]','title:"内部 SID: "+e.id,children:["ID:",e.display_id??e.id]',3),
 ('children:["SID:",p.id]','title:"内部 SID: "+p.id,children:["ID:",p.display_id??p.id]',1),
 ('label:`${e.name} · SID:${e.id}`','label:`${e.name} · ID:${e.display_id??e.id}`',1),
 ('`sid:${i.id}`,String(i.id)','`sid:${i.id}`,`id:${i.display_id??i.id}`,String(i.display_id??i.id),String(i.id)',1),
]
for old,new,count in changes:
 if s.count(new)==count:continue
 assert s.count(old)==count,(old,s.count(old))
 s=s.replace(old,new)
bundle.write_text(s)
print('Machine display IDs applied to admin list, details and node selectors')

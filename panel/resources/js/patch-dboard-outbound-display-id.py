from pathlib import Path
p=Path(__file__).resolve().parents[2]/'public/assets/admin/assets/index-CEIYH7i8.js'
s=p.read_text()
a='s.map(n=>Q.jsxs("tr",{className:"border-b last:border-0",children:['
b='s.map(n=>Q.jsxs("tr",{"data-outbound-id":n.id,className:"border-b last:border-0",children:['
if a in s: s=s.replace(a,b,1)
a='variant:"outline",children:n.id})}),\n      Q.jsx("td",{className:"px-4 py-3 font-medium",children:n.name})'
b='variant:"outline",title:"原始 ID: "+n.id,children:n.display_id??n.id})}),\n      Q.jsx("td",{className:"px-4 py-3 font-medium",children:n.name})'
if a in s: s=s.replace(a,b,1)
assert b in s
p.write_text(s)

from pathlib import Path
asset = Path(__file__).resolve().parents[2] / 'public/assets/admin/assets/index-CEIYH7i8.js'
s = asset.read_text()
old = 'H3t=(e,t)=>[{accessorKey:"id",header:({column:e})=>Q.jsx(eQt,{column:e,title:t("columns.id")}),cell:({row:e})=>Q.jsx("div",{className:"flex items-center space-x-2",children:Q.jsx(nKt,{variant:"outline",children:e.getValue("id")})}),enableSorting:!0}'
new = 'H3t=(e,t)=>[{id:"id",accessorFn:e=>e.display_id??e.id,header:({column:e})=>Q.jsx(eQt,{column:e,title:t("columns.id")}),cell:({row:e})=>Q.jsx("div",{className:"flex items-center space-x-2",children:Q.jsx(nKt,{variant:"outline",title:"原始 ID: "+e.original.id,"data-group-original-id":e.original.id,children:e.getValue("id")})}),enableSorting:!0}'
if new not in s:
    assert s.count(old) == 1
    s = s.replace(old, new, 1)
asset.write_text(s)

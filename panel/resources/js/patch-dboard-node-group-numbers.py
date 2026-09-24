"""Preserve unique node identity while rendering persistent group-local numbers."""
from pathlib import Path
p=Path(__file__).resolve().parents[2]/'public/assets/admin/assets/index-CEIYH7i8.js'
s=p.read_text()
start=s.index('H5t=z.memo(');end=s.index(',z5',start)
cell=(Path(__file__).parent/'dboard-node-id-cell.js').read_text().strip()
s=s[:start]+cell+s[end:]
grouped='(window.DBoardAdminGroups?.selected.node||"all")!=="all"'
old='{accessorKey:"id",header:({column:e})=>Q.jsx(eQt,{column:e,title:t("columns.nodeId")}),cell:({row:e})=>Q.jsx(H5t,{node:e.original,t:t}),size:50,enableSorting:!0}'
new=('{id:"id",accessorFn:e=>'+grouped+'?e.admin_group_number:e.id,'
     'header:({column:e})=>Q.jsx(eQt,{column:e,title:'+grouped+'?"组内编号":t("columns.nodeId")}),'
     'cell:({row:e})=>Q.jsxs("div",{className:"flex flex-col gap-1",children:['
     'Q.jsx(H5t,{node:e.original,t:t,grouped:'+grouped+'}),'
     '!('+grouped+')&&Q.jsx("small",{className:"max-w-[170px] truncate text-xs text-muted-foreground",'
     'title:(e.original.admin_group||"未分组")+" · 组内编号 "+(e.original.admin_group_number??"—"),'
     'children:(e.original.admin_group||"未分组")+" · 组内编号 "+(e.original.admin_group_number??"—")})]}),'
     'size:'+grouped+'?90:185,enableSorting:!0}')
previous = new
new = new.replace('title:'+grouped+'?"组内编号":t("columns.nodeId")', 'title:t("columns.nodeId")')
new = new.replace('size:'+grouped+'?90:185', 'size:245')
new = new.replace('max-w-[170px]', 'max-w-[230px]')
new = new.replace('+" · 组内编号 "+(e.original.admin_group_number??"—")', '')
if previous in s:
 s=s.replace(previous,new,1)
expanded = new
new = new.replace('size:245', 'size:180').replace('max-w-[230px]', 'max-w-[165px]')
if expanded in s:
 s=s.replace(expanded,new,1)
if new not in s:
 if s.count(old)!=1:raise SystemExit('Unexpected node ID column anchor')
 s=s.replace(old,new,1)
p.write_text(s)
print('Group-local ID display applied')

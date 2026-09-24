from pathlib import Path
p=Path(__file__).resolve().parents[2]/'public/assets/admin/assets/index-CEIYH7i8.js'
s=p.read_text()
old='draggable:n,onDragStart:e=>i?.(e,t)'
new='draggable:!1,onPointerDown:n?e=>window.DBoardSortDrag?.nativeStart(e,t,{start:i,end:r,over:s,leave:o,drop:a}):void 0,onDragStart:e=>i?.(e,t)'
if new not in s:
 assert s.count(old)==1
 s=s.replace(old,new,1)
p.write_text(s)

"""Add a nullable display multiplier independently of actual billing rate."""
from pathlib import Path
asset=Path(__file__).resolve().parents[2]/'public/assets/admin/assets/index-CEIYH7i8.js'
s=asset.read_text()
def replace(old,new):
 global s
 if new in s:return
 assert s.count(old)==1,old[:100]
 s=s.replace(old,new,1)
replace('rate_time_enable:uy().default(!1),rate_time_ranges:', 'display_rate:my([dy(),cy()]).nullable().optional().refine(e=>e===null||e===undefined||e===""||Number.isFinite(Number(e))&&Number(e)>=0&&Number(e)<=1000000,{message:"展示费率需为 0 到 1000000 之间的数字"}),rate_time_enable:uy().default(!1),rate_time_ranges:')
replace('show:!1,name:"",rate:"1",rate_time_enable:', 'show:!1,name:"",rate:"1",display_rate:"",rate_time_enable:')
anchor='Q.jsx($y,{control:x.control,name:"rate_time_enable",render:'
field='''Q.jsx($y,{control:x.control,name:"display_rate",render:({field:t})=>Q.jsxs(Gy,{"data-dboard-display-rate":"",children:[Q.jsx(Zy,{className:"font-mono text-[12px] text-foreground/80",children:"展示费率"}),Q.jsx(Yy,{children:Q.jsxs("div",{className:"relative",children:[Q.jsx(u8e,{...t,value:t.value??"",type:"number",min:"0",max:"1000000",step:"0.0001",placeholder:"留空跟随实际倍率，例如 1",className:"h-9 pr-8 font-mono text-xs"}),Q.jsx("span",{className:"absolute right-2.5 top-1/2 -translate-y-1/2 font-mono text-[10px] text-muted-foreground",children:"x"})]})}),Q.jsx("p",{className:"font-mono text-[11px] text-muted-foreground",children:"仅用于用户端和订阅展示；留空跟随实际倍率，计费仍使用基础或动态倍率。"}),Q.jsx(Qy,{})]})}),'''
replace(anchor,field+anchor)
asset.write_text(s)
print('Display rate form patched')

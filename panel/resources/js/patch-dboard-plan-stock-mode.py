"""Persist per-plan stock display mode and preview both customer badge styles."""
from pathlib import Path
asset=Path(__file__).resolve().parents[2]/'public/assets/admin/assets/index-CEIYH7i8.js'
s=asset.read_text()
def replace(old,new):
 global s
 if new in s:return
 assert s.count(old)==1,old[:120]
 s=s.replace(old,new,1)
replace('capacity_limit:my([dy(),cy()]).nullable().optional(),device_limit:', 'capacity_limit:my([dy(),cy()]).nullable().optional(),capacity_display_mode:cy().default("status"),device_limit:')
replace('speed_limit:"",capacity_limit:"",device_limit:"",connection_limit:', 'speed_limit:"",capacity_limit:"",capacity_display_mode:"status",device_limit:"",connection_limit:')
component='''function DboardPlanStockMode({form,activeCount=0}){
 const value=form.watch("capacity_limit"),mode=form.watch("capacity_display_mode")||"status";
 const total=value===null||value===undefined||String(value).trim()===""?null:Math.max(0,Number(value)||0);
 const remaining=total===null?null:Math.max(0,total-Number(activeCount||0));
 const badge=(count,text)=>Q.jsx("span",{style:{display:"inline-block",borderRadius:12,padding:"3px 7px",fontSize:11,fontWeight:600,color:count===null||count>=5?"#15803d":count>0?"#a16207":"#dc2626",background:count===null||count>=5?"#dcfce7":count>0?"#fef3c7":"#fee2e2"},children:text});
 return Q.jsxs("fieldset",{"data-dboard-capacity-mode":"",style:{margin:0,padding:0,border:0,minWidth:0},children:[
  Q.jsx("legend",{style:{fontSize:12,fontWeight:500,marginBottom:6},children:"库存显示方式"}),
  Q.jsx("div",{style:{display:"grid",gridTemplateColumns:"repeat(2,minmax(0,1fr))",gap:6},children:[
   ["status","当前样式",total,total===null||total>=5?"库存充足":total>0?"库存紧张":"已售罄"],
   ["remaining","剩余数量",remaining,remaining===null?"名额不限":"剩余 "+remaining+" 份"]
  ].map(([key,label,count,text])=>Q.jsxs("label",{style:{cursor:"pointer",minWidth:0,border:"1px solid "+(mode===key?"#2563eb":"#dbe3ee"),background:mode===key?"rgba(37,99,235,.05)":"transparent",borderRadius:6,padding:7,display:"flex",flexDirection:"column",gap:6},children:[
   Q.jsxs("span",{style:{display:"flex",alignItems:"center",gap:4,fontSize:12},children:[Q.jsx("input",{type:"radio",name:"plan-capacity-display-mode",value:key,checked:mode===key,onChange:()=>form.setValue("capacity_display_mode",key,{shouldDirty:true,shouldTouch:true}),style:{margin:0,accentColor:"#2563eb"}}),label]}),
   Q.jsx("span",{"data-stock-mode-preview":key,children:badge(count,text)})
  ]},key))}),
  Q.jsx("p",{style:{fontSize:11,lineHeight:1.5,margin:"6px 0 0",color:"#64748b"},children:"预览：当前样式按总容量判断；剩余数量扣除有效订阅。仅影响商店展示。"})
 ]})
}
'''
replace('function l6t(){',component+'function l6t(){')
old='Q.jsx(TYt,{control:d.control,name:"capacity_limit",label:c("plan.form.capacity.label"),type:"number",min:0,unit:c("plan.form.capacity.unit"),placeholder:c("plan.form.capacity.placeholder")})'
replace(old,'Q.jsxs("div",{style:{display:"flex",flexDirection:"column",gap:10,minWidth:0},children:['+old+',Q.jsx(DboardPlanStockMode,{form:d,activeCount:n?.active_users_count||0})]})')
asset.write_text(s)
print('Plan stock mode patched')

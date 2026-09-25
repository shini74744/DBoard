"""Replace the plan-only Markdown widget with rich editing and server-backed preview."""
from pathlib import Path
root=Path(__file__).resolve().parents[2]
p=root/'public/assets/admin/assets/index-CEIYH7i8.js';s=p.read_text()
component=(root/'resources/js/dboard-plan-editor-component.js').read_text()
if 'function DboardPlanContentEditor(' in s:
 a=s.index('/* Inserted into the existing admin React bundle');b=s.index('function l6t(){',a);s=s[:a]+component+s[b:]
else:s=s.replace('function l6t(){',component+'function l6t(){',1)
start=s.index('Q.jsx($y,{control:d.control,name:"content",render:')
end=s.index(']})})})})}function c6t()',start)
s=s[:start]+'Q.jsx($y,{control:d.control,name:"content",render:({field:e})=>Q.jsxs(Gy,{children:[Q.jsx(DboardPlanContentEditor,{form:d,field:e,markdown:u}),Q.jsx(Qy,{})]})})'+s[end:]
p.write_text(s)
print('Plan description editor patched')

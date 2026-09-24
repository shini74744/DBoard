"""Keep the distributed admin bundle in sync (upstream admin source is unavailable)."""
from pathlib import Path
asset = Path(__file__).resolve().parents[2] / 'public/assets/admin/assets/index-CEIYH7i8.js'
s = asset.read_text()
def replace(old, new):
    global s
    if new in s: return
    assert s.count(old) == 1, old[:100]
    s = s.replace(old, new, 1)
replace('routeUsersD=(search,selected)=>IL(oD+"/server/route/users",{params:{search,selected:selected.join(",")}})',
        'routeUsersD=(search,selected,sourceId)=>IL(oD+"/server/route/users",{params:{search,selected:selected.join(","),source_id:sourceId||void 0}})')
replace('routeUsersD(routeUserSearch,routeDraft?.match?.user_ids||[])','routeUsersD(routeUserSearch,routeDraft?.match?.user_ids||[],Number(e.getValues("id")||0))')
replace('routeUsersD("",userIds)','routeUsersD("",userIds,Number(e.getValues("id")||0))')
replace('const routeUserOptions=routeUsers.map(user=>({label:user.email+" · #"+user.id,value:String(user.id)}));',
'''const routeUserOptions=routeUsers.map(user=>({label:user.email+" · #"+user.id+(user.available===false?"（已无此节点权限）":""),value:String(user.id),disable:user.available===false}));
  for(const id of routeDraft?.match?.user_ids||[])if(!routeUserOptions.some(user=>user.value===String(id)))routeUserOptions.push({label:"已选用户 #"+id,value:String(id),disable:true});''')
replace('placeholder:"选择用户"}),\n            Q.jsx("p",{className:"text-xs text-muted-foreground",children:"指定用户的新规则',
        'placeholder:"选择拥有此节点套餐的用户",scrollWithinDialog:!0}),\n            Q.jsx("p",{className:"text-xs text-muted-foreground",children:"仅显示当前有效套餐有权使用此节点的用户，多套餐合并显示。新节点请先保存。指定用户的新规则')
replace('hideClearAllButton:x=!1},w)=>{const C=H.useRef(null)', 'hideClearAllButton:x=!1,scrollWithinDialog:scrollWithinDialog=!1},w)=>{const C=H.useRef(null)')
replace('Q.jsx(wut,{className:"rounded-md border bg-popover text-popover-foreground shadow-md outline-none animate-in",children:N?',
        'Q.jsx(wut,{"data-dboard-route-user-list":scrollWithinDialog?"":void 0,"data-vaul-no-drag":scrollWithinDialog?"":void 0,onWheel:scrollWithinDialog?event=>event.stopPropagation():void 0,onTouchMove:scrollWithinDialog?event=>event.stopPropagation():void 0,style:scrollWithinDialog?{touchAction:"pan-y",WebkitOverflowScrolling:"touch",overscrollBehaviorY:"contain"}:void 0,className:"rounded-md border bg-popover text-popover-foreground shadow-md outline-none animate-in",children:N?')
replace('Q.jsx(ptt,{className:"w-[min(760px,calc(100vw-32px))] max-w-none gap-0 overflow-hidden p-0 sm:rounded-2xl",children:Q.jsxs("div",{className:"flex max-h-[92vh] flex-col",children:[',
        'Q.jsx(ptt,{"data-dboard-route-dialog":"","data-vaul-no-drag":"",drawerClassName:"overflow-hidden",className:"w-[min(760px,calc(100vw-32px))] max-w-none gap-0 overflow-hidden p-0 sm:rounded-2xl",children:Q.jsxs("div",{style:{maxHeight:"85dvh",minHeight:0,display:"flex",flexDirection:"column"},className:"flex min-h-0 flex-col",children:[')
replace('Q.jsx("div",{className:"min-h-0 flex-1 overflow-y-auto px-7 pb-6",children:routeDraft&&',
        'Q.jsx("div",{"data-dboard-route-scroll":"","data-vaul-no-drag":"",onWheel:event=>event.stopPropagation(),onTouchMove:event=>event.stopPropagation(),style:{touchAction:"pan-y",WebkitOverflowScrolling:"touch",overscrollBehaviorY:"contain"},className:"min-h-0 flex-1 overflow-y-auto px-7 pb-6",children:routeDraft&&')
asset.write_text(s)

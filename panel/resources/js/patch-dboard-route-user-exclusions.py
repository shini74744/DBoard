"""Add account exclusions to the distributed route editor; rerunnable."""
from pathlib import Path
asset = Path(__file__).resolve().parents[2] / 'public/assets/admin/assets/index-CEIYH7i8.js'
s = asset.read_text()
def replace(old, new):
    global s
    if new in s: return
    assert s.count(old) == 1, old[:120]
    s = s.replace(old, new, 1)
replace('out.match.user_ids=Array.from(new Set((out.match.user_ids||[]).map(Number).filter(v=>Number.isSafeInteger(v)&&v>0)));',
'''out.match.user_ids=Array.from(new Set((out.match.user_ids||[]).map(Number).filter(v=>Number.isSafeInteger(v)&&v>0)));
    out.match.excluded_user_ids=Array.from(new Set((out.match.excluded_user_ids||[]).map(Number).filter(v=>Number.isSafeInteger(v)&&v>0)));''')
replace('const selectedRouteUserIds=(routeDraft?.match?.user_ids||[]).join(",");',
'''const selectedRouteAccounts=Array.from(new Set([...(routeDraft?.match?.user_ids||[]),...(routeDraft?.match?.excluded_user_ids||[])]));
  const selectedRouteUserIds=selectedRouteAccounts.join(",");''')
replace('for(const id of routeDraft?.match?.user_ids||[])if(!routeUserOptions.some', 'for(const id of selectedRouteAccounts)if(!routeUserOptions.some')
replace('routeUsersD(routeUserSearch,routeDraft?.match?.user_ids||[],Number(e.getValues("id")||0))','routeUsersD(routeUserSearch,selectedRouteAccounts,Number(e.getValues("id")||0))')
replace('.flatMap(rule=>rule.match?.user_ids||[])', '.flatMap(rule=>[...(rule.match?.user_ids||[]),...(rule.match?.excluded_user_ids||[])])')
anchor='''            Q.jsx("p",{className:"text-xs text-muted-foreground",children:"仅显示当前有效套餐有权使用此节点的用户，多套餐合并显示。新节点请先保存。指定用户的新规则会排在通用规则前面。节点程序需升级到支持按用户分流的版本后才能保存。"})'''
replace(anchor,'''            Q.jsx("label",{className:"text-sm font-medium",children:"跳过用户（可多选）"}),
            Q.jsx(a4t,{options:routeUserOptions,value:routeUserOptions.filter(v=>(routeDraft.match?.excluded_user_ids||[]).map(String).includes(v.value)),onChange:values=>updateRouteDraft("match","excluded_user_ids",values.map(v=>Number(v.value))),placeholder:"选择不走本条规则的用户",scrollWithinDialog:!0}),
            Q.jsx("p",{className:"text-xs text-muted-foreground",children:"指定用户留空时，除跳过的用户外，其余用户均适用本规则。跳过优先于指定；被跳过的用户继续匹配后续规则，不代表断网或强制直连。同一账号的所有套餐一起生效。"}),
            Q.jsx("p",{className:"text-xs text-muted-foreground",children:"仅显示当前有效套餐有权使用此节点的用户，多套餐合并显示。上方搜索对两个选择框均生效。新节点请先保存。指定用户的新规则会排在通用规则前面；仅设置跳过用户时保持普通规则顺序。节点需支持按用户分流。"})''')
replace('children:(rule.match?.user_ids||[]).length?(rule.match.user_ids||[]).map(id=>routeUsers.find(u=>Number(u.id)===Number(id))?.email||"#"+id).join("\\n"):"全部用户"',
        'children:((rule.match?.user_ids||[]).length?(rule.match.user_ids||[]).map(id=>routeUsers.find(u=>Number(u.id)===Number(id))?.email||"#"+id).join("\\n"):"全部用户")+((rule.match?.excluded_user_ids||[]).length?"\\n跳过："+rule.match.excluded_user_ids.map(id=>routeUsers.find(u=>Number(u.id)===Number(id))?.email||"#"+id).join("、"):"")')
replace('const selectedRouteUserIds=selectedRouteAccounts.join(",");',
        '''const selectedRouteUserIds=selectedRouteAccounts.join(",");
  const summaryRouteAccounts=Array.from(new Set(m.flatMap(rule=>[...(rule.match?.user_ids||[]),...(rule.match?.excluded_user_ids||[])])));
  H.useEffect(()=>{
    if(!r||o!=="outbounds"||routeOpen||!summaryRouteAccounts.length)return;
    let active=true;
    routeUsersD("",summaryRouteAccounts,Number(e.getValues("id")||0)).then(({data})=>{if(active)setRouteUsers(Array.isArray(data)?data:[])}).catch(()=>{});
    return()=>{active=false}
  },[r,o,routeOpen,summaryRouteAccounts.join(",")]);''')
asset.write_text(s)
print('route user exclusions patched')

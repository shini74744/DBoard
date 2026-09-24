H5t=z.memo(({node:e,t:t})=>{
  const fields=[
    {kind:"group",shortLabel:"分组",label:"分组ID",value:e.admin_group_number,description:(e.admin_group||"未分组")+"的组内编号"},
    {kind:"custom",shortLabel:"自定",label:t("columns.customId"),value:e.code,description:t("columns.customId")},
    {kind:"original",shortLabel:"原始",label:t("columns.originalId"),value:e.id,description:"节点程序使用的唯一 ID"}
  ];
  return Q.jsx("div",{className:"dboard-node-id-boxes",children:fields.map(field=>{
    const present=field.value!==null&&field.value!==undefined&&String(field.value)!="";
    const value=present?String(field.value):"—";
    return Q.jsxs("button",{
      type:"button",className:"dboard-node-id-box",disabled:!present,"data-id-kind":field.kind,
      title:present?field.description+"："+value+"（点击复制）":"未设置"+field.label,
      "aria-label":present?"复制"+field.label+" "+value:field.label+"未设置",
      onClick:event=>{event.stopPropagation();if(present)IS(String(field.value)).then(()=>gE.success(t("common:copy.success")));},
      children:[Q.jsx("span",{className:"dboard-node-id-label",children:field.shortLabel}),
        Q.jsxs("span",{className:"dboard-node-id-content",children:[
          Q.jsx("span",{className:"dboard-node-id-value",children:value})
        ]})]
    },field.kind);
  })});
})

// Embedded in the admin bundle. Mobile forms fill the visual viewport without keyboard spacers.
const dboardMobileFormViewports=new WeakMap();
function DBoardResizeDrawerViewport(node,event){
  const viewport=window.visualViewport;
  if(viewport&&Math.abs(viewport.scale-1)>0.01)return;
  const height=Math.max(0,viewport?.height||window.innerHeight);
  const top=Math.max(0,viewport?.offsetTop||0);
  if(!node.hasAttribute("data-dboard-mobile-form")){
    node.style.height="auto";
    node.style.maxHeight=Math.floor(height*0.9)+"px";
    node.style.bottom=Math.max(0,window.innerHeight-height-top)+"px";
    return;
  }
  const active=document.activeElement;
  const typing=node.contains(active)&&active?.matches('textarea,input:not([type="checkbox"]):not([type="radio"]):not([type="button"]):not([type="submit"]),[contenteditable="true"]');
  let state=dboardMobileFormViewports.get(node);
  if(!state||Math.abs(state.width-window.innerWidth)>100){
    state={width:window.innerWidth,baseline:window.innerHeight,keyboard:false,editing:!!typing};
    dboardMobileFormViewports.set(node,state);
  }
  state.baseline=Math.max(state.baseline,window.innerHeight,height);
  const keyboard=state.baseline-height>150;
  if(event?.type==="focusin"&&typing)state.editing=true;
  if(!typing)state.editing=false;
  if(keyboard&&!state.keyboard&&typing)state.editing=true;
  if(state.keyboard&&!keyboard)state.editing=false;
  state.keyboard=keyboard;
  node.toggleAttribute("data-dboard-form-editing",state.editing);
  // Size the content itself; keyboard space must never become white form padding.
  const resized=state.height!==height;
  state.height=height;
  node.style.top=top+"px";
  node.style.height=height+"px";
  node.style.maxHeight="none";
  node.style.bottom="auto";
  node.style.paddingTop="0px";
  node.style.paddingBottom="0px";
  if(typing&&(resized||event?.type==="focusin")){
    const field=active.getBoundingClientRect(),area=node.getBoundingClientRect();
    const header=node.querySelector("[data-dboard-form-header]");
    const visibleTop=Math.max(area.top,header?.getBoundingClientRect().bottom||area.top);
    if(field.bottom>area.bottom-16)node.scrollTop+=field.bottom-area.bottom+16;
    else if(field.top<visibleTop+16)node.scrollTop-=visibleTop-field.top+16;
  }
}
function DBoardFinishFormInput(){
  return Q.jsx("button",{type:"button",className:"dboard-finish-form-input",
    onClick:e=>{
      const node=e.currentTarget.closest("[data-dboard-mobile-form]");
      document.activeElement?.blur();
      if(node){const state=dboardMobileFormViewports.get(node);if(state)state.editing=false;DBoardResizeDrawerViewport(node);}
    },children:Q.jsx("span",{children:"完成输入"})});
}

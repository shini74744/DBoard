(() => {
 let drag=null,frame=0;
 function stop(cancel=false){
  if(!drag)return;const d=drag;drag=null;cancelAnimationFrame(frame);
  d.row.classList.remove('dragging','dboard-dragging','opacity-50');
  d.target?.classList.remove('dboard-drop-target');
  if(d.list.hasPointerCapture?.(d.id))d.list.releasePointerCapture(d.id);
  if(d.native){
   if(!cancel&&d.target&&d.target!==d.row)d.handlers.drop?.(eventFor(d,d.target),[...d.list.children].indexOf(d.target));
   d.handlers.end?.(eventFor(d,d.row));
  }else if(!cancel&&d.moved)d.onEnd?.();
 }
 function eventFor(d,row){return {currentTarget:row,target:row,dataTransfer:d.transfer,preventDefault(){},stopPropagation(){}};}
 function scrollers(x,y,fallback){
  let n=document.elementFromPoint(x,y)||fallback;const all=[];
  while(n){if(n.scrollHeight>n.clientHeight+1&&/(auto|scroll)/.test(getComputedStyle(n).overflowY))all.push(n);n=n.parentElement;}
  for(n=fallback;n;n=n.parentElement){if(n.scrollHeight>n.clientHeight+1&&/(auto|scroll)/.test(getComputedStyle(n).overflowY)&&!all.includes(n))all.push(n);}
  const page=document.scrollingElement;if(page&&!all.includes(page))all.push(page);return all;
 }
 function scrollBy(d,delta){
  for(const n of scrollers(d.x,d.y,d.list)){
   const old=n.scrollTop;n.scrollTop+=delta;
   if(n.scrollTop!==old)return true;
  }return false;
 }
 function locate(){
  const d=drag;if(!d||!d.list.isConnected)return;
  const target=document.elementFromPoint(d.x,d.y)?.closest(d.selector);
  if(!target||target.parentElement!==d.list||target===d.row)return;
  if(d.native){d.target?.classList.remove('dboard-drop-target');d.target=target;target.classList.add('dboard-drop-target');return;}
  const before=[...d.list.children].indexOf(d.row)>[...d.list.children].indexOf(target);
  d.list.insertBefore(d.row,before?target:target.nextSibling);d.moved=true;d.onMove?.();
 }
 function tick(){
  if(!drag)return;
  if(!drag.list.isConnected){stop(true);return;}
  if(performance.now()-drag.wheelAt>400){
   for(const n of scrollers(drag.x,drag.y,drag.list)){
    const r=n===document.scrollingElement?{top:0,bottom:innerHeight}:n.getBoundingClientRect();
    const top=Math.max(0,r.top),bottom=Math.min(innerHeight,r.bottom);
    const delta=drag.y<top+28?-8:drag.y>bottom-28?8:0;
    if(delta&&scrollBy(drag,delta))break;
   }
  }
  locate();frame=requestAnimationFrame(tick);
 }
 function begin(e,row,list,options){
  if(drag||e.button!==0)return;
  e.preventDefault();drag={...options,row,list,id:e.pointerId,x:e.clientX,y:e.clientY,wheelAt:0,moved:false};
  list.setPointerCapture(e.pointerId);row.classList.add('dragging');frame=requestAnimationFrame(tick);
 }
 window.DBoardSortDrag={
  bind(list,{rowSelector='li',handleSelector='.dboard-machine-sort-handle',disabled=()=>false,onMove,onEnd}={}){
   list.addEventListener('pointerdown',e=>{
    const handle=e.target.closest(handleSelector),row=handle?.closest(rowSelector);
    if(!row||row.parentElement!==list||disabled())return;
    handle.draggable=false;begin(e,row,list,{selector:rowSelector,onMove,onEnd,native:false});
   });
   list.addEventListener('dragstart',e=>{if(e.target.closest(handleSelector))e.preventDefault();});
  },
  nativeStart(e,index,handlers){
   if(e.target.closest('button,input,select,textarea,a,[contenteditable=true]'))return;
   const row=e.currentTarget,list=row.parentElement,transfer=new DataTransfer();
   begin(e,row,list,{selector:'tr',native:true,handlers,transfer});
   if(drag)handlers.start?.(eventFor(drag,row),index);
  },
  cancel(){stop(true);}
 };
 document.addEventListener('pointermove',e=>{if(drag&&drag.id===e.pointerId){drag.x=e.clientX;drag.y=e.clientY;locate();}},true);
 document.addEventListener('pointerup',e=>{if(drag&&drag.id===e.pointerId)stop();},true);
 document.addEventListener('pointercancel',()=>stop(true),true);
 document.addEventListener('wheel',e=>{
  if(!drag)return;
  e.preventDefault();e.stopImmediatePropagation();drag.wheelAt=performance.now();
  const scale=e.deltaMode===1?16:e.deltaMode===2?innerHeight:1;
  scrollBy(drag,e.deltaY*scale);locate();
 },{capture:true,passive:false});
 window.addEventListener('blur',()=>stop(true));
 window.addEventListener('hashchange',()=>stop(true));
})();

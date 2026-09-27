// Embedded in the admin bundle. Keep responsive forms inside the visual viewport.
function DBoardResizeDrawerViewport(node){
  const viewport=window.visualViewport;
  // Pinch zoom must remain pannable instead of fighting the browser's viewport.
  if(viewport&&Math.abs(viewport.scale-1)>0.01)return;
  const height=Math.max(0,viewport?.height||window.innerHeight);
  const top=Math.max(0,viewport?.offsetTop||0);
  node.style.height="auto";
  node.style.maxHeight=Math.floor(height*0.9)+"px";
  node.style.bottom=Math.max(0,window.innerHeight-height-top)+"px";
}

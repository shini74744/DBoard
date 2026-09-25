/* Inserted into the existing admin React bundle by patch-dboard-plan-editor.py. */
function DboardPlanContentEditor({form,field,markdown}) {
 const el=H.createElement,editor=H.useRef(null),selection=H.useRef(null),last=H.useRef(null);
 const [source,setSource]=H.useState(false),[preview,setPreview]=H.useState(false),[previewHtml,setPreviewHtml]=H.useState(''),[previewError,setPreviewError]=H.useState(''),[busy,setBusy]=H.useState(false),[confirmTemplate,setConfirmTemplate]=H.useState(false);
 const clean=html=>window.DOMPurify.sanitize(html,{FORBID_TAGS:['style','iframe','form','input','button']});
 const rendered=value=>clean(markdown.render(value||''));
 const values=form.watch();
 const payload=JSON.stringify({content:field.value||'',prices:values.prices||{},transfer_enable:values.transfer_enable||0,speed_limit:values.speed_limit||null,device_limit:values.device_limit||null,connection_limit:values.connection_limit||null,reset_traffic_method:values.reset_traffic_method??null});
 H.useEffect(()=>{
  if(!source&&editor.current&&last.current!==field.value){editor.current.innerHTML=rendered(field.value);last.current=field.value;selection.current=null;}
 },[field.value,source]);
 H.useEffect(()=>{
  if(!preview)return;
  let active=true;setBusy(true);setPreviewError('');
  const timer=setTimeout(()=>RL(ID+'/plan/preview',JSON.parse(payload)).then(({data})=>{if(active){setPreviewHtml(rendered(data.content));setBusy(false);}}).catch(()=>{if(active){setPreviewError('预览加载失败，请关闭预览后重试。');setBusy(false);}}),250);
  return()=>{active=false;clearTimeout(timer);};
 },[preview,payload]);
 const saveSelection=()=>{const s=window.getSelection();if(s&&s.rangeCount&&editor.current?.contains(s.getRangeAt(0).commonAncestorContainer))selection.current=s.getRangeAt(0).cloneRange();};
 const restoreSelection=()=>{editor.current?.focus();const s=window.getSelection();if(selection.current&&editor.current?.contains(selection.current.commonAncestorContainer)){s.removeAllRanges();s.addRange(selection.current);}};
 const publish=()=>{if(!editor.current)return;const html=clean(editor.current.innerHTML);last.current=html;field.onChange(html);saveSelection();};
 const command=(name,value)=>{restoreSelection();document.execCommand('styleWithCSS',false,true);document.execCommand(name,false,value);publish();};
 const changeSize=value=>{restoreSelection();document.execCommand('styleWithCSS',false,false);document.execCommand('fontSize',false,'7');editor.current.querySelectorAll('font[size="7"]').forEach(n=>{const span=document.createElement('span');span.style.fontSize=value+'px';span.innerHTML=n.innerHTML;n.replaceWith(span);});document.execCommand('styleWithCSS',false,true);publish();};
 const template='<h2 style="color:#2563eb;font-size:16px">套餐详情</h2><ul><li>流量：{{transfer}} GB</li><li>速度限制：{{speed_text}}</li><li>同时在线设备：{{devices_text}}</li><li data-plan-price="onetime">流量包：{{onetime_price}} 元（一次性，无时间限制）</li><li data-plan-price="reset_traffic">重置包：{{reset_price}} 元（重置套餐流量，可多次购买）</li></ul><h2 style="color:#2563eb;font-size:16px">服务说明</h2><ol><li>流量重置：{{reset_method}}</li><li>支持多平台使用</li><li>7×24小时技术支持</li></ol>';
 const applyTemplate=()=>{last.current=null;field.onChange(template);setSource(false);setConfirmTemplate(false);};
 const button=(text,title,action,props={})=>el('button',{type:'button',title,'aria-label':title,onMouseDown:e=>e.preventDefault(),onClick:action,...props},text);
 const toolbar=el('div',{className:'dboard-plan-toolbar',role:'toolbar','aria-label':'说明文字格式'},
  el('label',null,'字号',el('select',{'aria-label':'字号',defaultValue:'14',onPointerDown:saveSelection,onChange:e=>changeSize(e.target.value)},...[12,14,16,18,20,24,28].map(n=>el('option',{key:n,value:n},n+' px')))),
  el('label',null,'文字颜色',el('input',{type:'color',defaultValue:'#2563eb','aria-label':'文字颜色',onPointerDown:saveSelection,onChange:e=>command('foreColor',e.target.value)})),
  el('label',null,'背景色',el('input',{type:'color',defaultValue:'#fef08a','aria-label':'文字背景色',onPointerDown:saveSelection,onChange:e=>command('hiliteColor',e.target.value)})),
  button('B','加粗',()=>command('bold'),{style:{fontWeight:700}}),button('I','斜体',()=>command('italic'),{style:{fontStyle:'italic'}}),button('U','下划线',()=>command('underline'),{style:{textDecoration:'underline'}}),
  button('标题','设置标题',()=>command('formatBlock','h2')),button('正文','设置正文',()=>command('formatBlock','p')),
  button('• 列表','无序列表',()=>command('insertUnorderedList')),button('1. 列表','有序列表',()=>command('insertOrderedList')),
  button('链接','插入链接',()=>{saveSelection();const url=window.prompt('输入链接（https://…）');if(url&&/^(https?:\/\/|mailto:)/i.test(url))command('createLink',url);}),
  button('清除格式','清除文字格式',()=>command('removeFormat')),button('↶','撤销',()=>command('undo')),button('↷','重做',()=>command('redo')));
 return el('section',{className:'dboard-plan-editor','data-dboard-plan-editor':''},
  el('div',{className:'dboard-plan-editor-heading'},el('label',null,'套餐说明'),el('div',{className:'dboard-plan-editor-actions'},
   button('使用模板','使用模板',()=>field.value?.trim()?setConfirmTemplate(true):applyTemplate()),
   button(source?'可视化编辑':'源码','切换编辑模式',()=>{last.current=null;setSource(!source);}),
   button(preview?'隐藏预览':'显示预览',preview?'隐藏预览':'显示预览',()=>setPreview(!preview)))),
  confirmTemplate&&el('div',{className:'dboard-plan-template-confirm',role:'status'},'使用模板将替换当前说明。',button('替换说明','替换说明',applyTemplate),button('取消','取消替换模板',()=>setConfirmTemplate(false))),
  el('div',{className:'dboard-plan-editor-frame'},!source&&toolbar,
   source?el('textarea',{className:'dboard-plan-source','aria-label':'套餐说明源码',value:field.value||'',onChange:e=>{last.current=null;field.onChange(e.target.value);},onBlur:field.onBlur,spellCheck:false}):
   el('div',{ref:editor,className:'dboard-plan-rich',contentEditable:true,suppressContentEditableWarning:true,role:'textbox','aria-label':'套餐说明可视化编辑','aria-multiline':true,onInput:publish,onMouseUp:saveSelection,onKeyUp:saveSelection,onBlur:()=>{saveSelection();field.onBlur();},onPaste:e=>{e.preventDefault();const html=e.clipboardData.getData('text/html');if(html)command('insertHTML',clean(html));else command('insertText',e.clipboardData.getData('text/plain'));}})),
  el('p',{className:'dboard-plan-editor-help'},'选中文字后设置颜色、字号等样式。支持原有 Markdown / HTML；模板变量会随套餐设置自动更新。'),
  el('p',{className:'dboard-plan-editor-help'},'流量包、重置包未填写价格时，预览和用户端自动隐藏对应项；0 元仍显示。'),
  preview&&el('section',{className:'dboard-plan-preview','aria-label':'用户端效果预览'},el('div',{className:'dboard-plan-preview-title'},'用户端效果预览',busy&&el('span',{role:'status'},'更新中…')),previewError?el('p',{role:'alert'},previewError):el('div',{className:'dboard-plan-preview-content',dangerouslySetInnerHTML:{__html:previewHtml}}))
 );
}

// Embedded in the compiled admin bundle; H/Q are its React/runtime bindings.
function DBoardEmailAutofill({form,open,disabled}){
  const [raw,setRaw]=H.useState(""),[touched,setTouched]=H.useState(false);
  const id=H.useId(),hintId=id+"-hint";
  H.useEffect(()=>{if(open){setRaw("");setTouched(false)}},[open]);
  const parse=value=>{
    const email=String(value).trim().replace(/^mailto:/i,"");
    const match=/^([^\s@<>]+)@([^\s@<>]+\.[^\s@<>]+)$/.exec(email);
    return match?{email,prefix:match[1],suffix:match[2]}:null;
  };
  const parsed=parse(raw),prefix=form.watch("email_prefix")||"",suffix=form.watch("email_suffix")||"";
  const matched=parsed&&parsed.prefix===prefix&&parsed.suffix===suffix&&!form.watch("generate_count");
  const invalid=touched&&raw.trim()&&!parsed;
  const hint=invalid?"请输入有效的完整邮箱，例如 name@example.com":matched?"已自动填入下方邮箱":parsed?"下方邮箱已手动修改，提交以下方内容为准":"输入或粘贴完整邮箱，自动拆分账号和域名。";
  const update=value=>{
    setRaw(value);setTouched(false);
    const result=parse(value);if(!result)return;
    const options={shouldDirty:true,shouldValidate:true};
    form.setValue("generate_count",undefined,options);
    form.setValue("download_csv",false,options);
    form.setValue("email_prefix",result.prefix,options);
    form.setValue("email_suffix",result.suffix,options);
  };
  return Q.jsxs("div",{className:"space-y-2 rounded-lg border border-input bg-muted/20 p-3","data-dboard-email-autofill":"",children:[
    Q.jsx("label",{htmlFor:id,className:"text-xs font-medium text-muted-foreground",children:"完整邮箱（自动识别）"}),
    Q.jsx("input",{id,type:"text",inputMode:"email",autoComplete:"off",autoCapitalize:"none",autoCorrect:"off",spellCheck:false,value:raw,disabled,
      placeholder:"例如 name@example.com","aria-describedby":hintId,"aria-invalid":Boolean(invalid),
      className:"h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring",
      onChange:event=>update(event.target.value),onBlur:()=>setTouched(true)}),
    Q.jsx("p",{id:hintId,role:"status",className:"text-xs leading-relaxed "+(invalid?"text-destructive":matched?"text-emerald-600":"text-muted-foreground"),children:hint})
  ]});
}

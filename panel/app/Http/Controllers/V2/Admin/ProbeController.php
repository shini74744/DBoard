<?php
namespace App\Http\Controllers\V2\Admin;
use App\Http\Controllers\Controller;
use App\Models\ProbeSetting;
use App\Services\ProbeService;
use Illuminate\Http\Request;
class ProbeController extends Controller {
 public function fetch(){
  $s=ProbeService::settings();return $this->success(['enabled'=>(bool)$s?->enabled,'endpoint'=>$s?->endpoint??'','backups'=>$s?->backups??[],'agent_version'=>$s?->agent_version??'v0.2.0','has_key'=>(bool)$s,'dashboard_url'=>$s?$s->endpoint.'/dashboard':'']);
 }
 public function save(Request $request){
  $d=$request->validate(['enabled'=>'required|boolean','endpoint'=>'required|url|max:255','control_key'=>'nullable|string|min:32|max:256','agent_version'=>['required','regex:/^v[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/']]);
  $u=parse_url($d['endpoint']);
  if (($u['scheme']??'')!=='https' || isset($u['user'])||isset($u['pass'])||isset($u['query'])||isset($u['fragment'])||!in_array($u['path']??'',['','/'],true)) return $this->fail([422,'探针地址请填写 HTTPS 域名，不要填写路径']);
  $s=ProbeService::settings()??new ProbeSetting;
  if (!$s->exists && empty($d['control_key'])) return $this->fail([422,'首次配置需要填写探针对接密钥']);
  if ($s->exists && $s->endpoint!==rtrim($d['endpoint'],'/') && \App\Models\ServerMachine::whereNotNull('probe_uuid')->exists()) return $this->fail([422,'已有探针服务器，请使用更新连接地址执行迁移']);
  abort_if(!$d['enabled'] && \App\Models\ServerMachine::whereNotNull('probe_endpoint')->where('is_active',true)->exists(),422,'仍有服务器使用探针，请先停用这些服务器');
  $s->fill(['enabled'=>$d['enabled'],'endpoint'=>rtrim($d['endpoint'],'/'),'agent_version'=>$d['agent_version']]);
  if (!empty($d['control_key'])) $s->control_key=$d['control_key'];
  if (!$s->exists){$s->id=1;$s->connector_key=bin2hex(random_bytes(32));}
  $s->save();return $this->fetch();
 }
 public function status(){return $this->success(ProbeService::api('status'));}
 public function connector(){
  $s=ProbeService::settings();abort_unless($s,422,'请先保存探针设置');
  return $this->success(['Endpoint'=>$s->endpoint,'Backups'=>$s->backups??[],'Key'=>$s->control_key,'Panel'=>'http://127.0.0.1:7001','PanelKey'=>$s->connector_key,'WebSocket'=>'ws://127.0.0.1:8076/ws']);
 }
 public function machines(){return $this->success(\App\Models\ServerMachine::whereNotNull('probe_uuid')->get(['id','name','is_active','last_seen_at','probe_endpoint','probe_migration']));}
 public function migrate(Request $r){
  $d=$r->validate(['endpoint'=>'required|url|max:255','ids'=>'required|array|min:1','ids.*'=>'integer|distinct','backups'=>'nullable|array|max:8','backups.*'=>'url|max:255']);
  $s=ProbeService::settings();abort_unless($s&&$s->enabled,422);
  $endpoint=rtrim($d['endpoint'],'/');$backups=array_values(array_unique(array_merge($d['backups']??[],[$s->endpoint])));
  $backups=array_values(array_filter(array_map(fn($v)=>rtrim($v,'/'),$backups),fn($v)=>$v!==$endpoint));
  abort_if(count($backups)>8,422,'包含原地址在内最多保留 8 个备用地址');
  foreach(array_merge([$endpoint],$backups) as $url){$u=parse_url($url);abort_unless(($u['scheme']??'')==='https'&&!isset($u['user'])&&!isset($u['pass'])&&!isset($u['query'])&&!isset($u['fragment'])&&in_array($u['path']??'',['','/'],true),422,'请填写 HTTPS 域名');}
  $gatewayId=hash('sha256',$s->control_key);
  // The previously configured origin may already be offline. Keep it as a fallback,
  // but authenticate the new origin independently using the gateway identity.
  foreach(array_unique(array_merge([$endpoint],array_filter($backups,fn($v)=>$v!==$s->endpoint))) as $url){
   $reply=\Illuminate\Support\Facades\Http::withToken($s->control_key)->withoutRedirecting()->connectTimeout(5)->timeout(10)->get(rtrim($url,'/').'/bridge/v1/control/status');
   abort_unless($reply->successful()&&hash_equals($gatewayId,(string)$reply->json('gateway_id')),422,'新地址和备用地址必须能访问同一探针服务');
  }
  $machines=\App\Models\ServerMachine::whereNotNull('probe_uuid')->whereIn('id',$d['ids'])->get();abort_unless($machines->count()===count($d['ids']),422);
  \Illuminate\Support\Facades\DB::transaction(function()use($machines,$endpoint,$backups,$s){
   foreach($machines as $m){$m->update(['probe_migration'=>['request_id'=>bin2hex(random_bytes(12)),'endpoint'=>$endpoint,'backups'=>$backups,'state'=>'pending','created_at'=>time()]]);}
   $s->endpoint=$endpoint;$s->backups=$backups;$s->save();
  });
  foreach($machines as $m){\App\Services\NodeSyncService::pushMachine($m->id,'probe.endpoint',$m->probe_migration);}
  return $this->machines();
 }
}

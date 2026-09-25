<?php
namespace App\Http\Controllers\V2\Server;
use App\Http\Controllers\Controller;
use App\Http\Controllers\V1\Server\UniProxyController;
use App\Http\Middleware\ServerV2;
use App\Models\ServerMachine;
use App\Services\ProbeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class ProbeController extends Controller {
 private function machine(Request $r): ServerMachine {
  $s=ProbeService::settings();
  abort_unless($s && $s->enabled && hash_equals($s->connector_key,(string)$r->bearerToken()),403);
  // Check the socket address, never an X-Forwarded-For header.
  abort_unless(in_array($r->server('REMOTE_ADDR'),['127.0.0.1','::1'],true),403);
  return ServerMachine::where('probe_uuid',$r->input('device'))->where('is_active',true)->firstOrFail();
 }
 public function endpoint(Request $r){
  $s=ProbeService::settings();abort_unless($s && $s->enabled && hash_equals($s->connector_key,(string)$r->bearerToken()) && in_array($r->server('REMOTE_ADDR'),['127.0.0.1','::1'],true),403);
  return response()->json(['endpoint'=>$s->endpoint,'backups'=>$s->backups??[]]);
 }
 public function credential(Request $r){
  $m=$this->machine($r);return response()->json(['machine_id'=>$m->id,'token'=>$m->token]);
 }
 public function forward(Request $r){
  $m=$this->machine($r);
  $d=$r->validate(['path'=>'required|string','method'=>'required|in:GET,POST','query'=>'nullable|array','headers'=>'nullable|array','body'=>'nullable|string']);
  $routes=[
   '/api/v2/server/handshake'=>[ServerController::class,'handshake','POST'],
   '/api/v2/server/config'=>[UniProxyController::class,'config','GET'],
   '/api/v2/server/user'=>[UniProxyController::class,'user','GET'],
   '/api/v2/server/report'=>[ServerController::class,'report','POST'],
   '/api/v2/server/machine/nodes'=>[MachineController::class,'nodes','POST'],
   '/api/v2/server/machine/status'=>[MachineController::class,'status','POST'],
  ];
  abort_unless(isset($routes[$d['path']]) && $routes[$d['path']][2]===$d['method'],403);
  $raw=base64_decode($d['body']??'',true);abort_if($raw===false||strlen($raw)>16*1024*1024,422);
  $body=$raw===''?[]:json_decode($raw,true,512,JSON_THROW_ON_ERROR);
  abort_unless(is_array($body),422);
  $q=[];foreach($d['query']??[] as $k=>$v){if(is_array($v))$q[$k]=(string)($v[0]??'');}
  $data=array_merge($q,$body,['machine_id'=>$m->id,'token'=>$m->token]);
  $sub=Request::create($d['path'],$d['method'],$data,[],[],['REMOTE_ADDR'=>'127.0.0.1','HTTP_HOST'=>'localhost','HTTP_ACCEPT'=>'application/json']);
  $sub->attributes->set('probe_sync',true);
  if (!empty($d['headers']['If-None-Match'])) $sub->headers->set('If-None-Match',$d['headers']['If-None-Match']);
  foreach(['X-DBoard-User-Routes','X-DBoard-Front-Gate'] as $capability){if(($d['headers'][$capability]??'')==='1')$sub->headers->set($capability,'1');}
  [$class,$method]=$routes[$d['path']];
  $invoke=fn()=>str_contains($d['path'],'/machine/')?app($class)->$method($sub):(new ServerV2)->handle($sub,fn($req)=>app($class)->$method($req));
  $previous=app('request');app()->instance('request',$sub);
  try {
   if ($d['path']==='/api/v2/server/report' && !empty($body['traffic'])) {
    $batch=$d['headers']['X-Probe-Request-ID']??'';
    abort_unless(preg_match('/^[a-f0-9]{48}$/',$batch),422,'Missing report identity');
    $hash=hash('sha256',$d['path'].'|'.($data['node_id']??'').'|'.$raw);
    $response=DB::transaction(function()use($m,$batch,$hash,$invoke){
     $inserted=DB::table('dboard_probe_receipts')->insertOrIgnore(['machine_id'=>$m->id,'batch_id'=>$batch,'body_hash'=>$hash,'created_at'=>now()]);
     if(!$inserted){$old=DB::table('dboard_probe_receipts')->where('machine_id',$m->id)->where('batch_id',$batch)->first();abort_unless($old&&hash_equals($old->body_hash,$hash),409);return response()->json(['data'=>true]);}
     $resp=$invoke();abort_if($resp->getStatusCode()>=400,503);return $resp;
    },3);
   } else {$response=$invoke();}
  } finally {app()->instance('request',$previous);}
  return response()->json(['status'=>$response->getStatusCode(),'headers'=>['Content-Type'=>$response->headers->get('Content-Type'),'ETag'=>$response->headers->get('ETag')],'body'=>base64_encode($response->getContent())]);
 }
}

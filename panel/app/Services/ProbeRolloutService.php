<?php
namespace App\Services;
use App\Models\ServerMachine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Durable, explicitly queued migrations. Offline machines wait for a fresh heartbeat. */
class ProbeRolloutService
{
 public const ACTIVE = ['queued','upgrading','installing'];
 public static function queue(ServerMachine $m): void
 {
  if ($m->probe_endpoint) return;
  $job=$m->probe_install??[];
  if (in_array($job['state']??'',self::ACTIVE,true)) return;
  $m->update(['probe_install'=>['state'=>'queued','created_at'=>time(),'updated_at'=>time()]]);
 }
 public static function poll(ServerMachine $machine): void
 {
  Cache::lock('probe_rollout:'.$machine->id,30)->block(2,function()use($machine){
   $m=$machine->fresh();$job=$m->probe_install??[];
   if (!$m->is_active || !in_array($job['state']??'',self::ACTIVE,true)) return;
   if (($job['state']??'')==='installing') {
    if (time()-($job['updated_at']??0)>1500) {
     $job['state']='failed';$job['message']='安装超时，请检查原节点和探针服务后重试';$m->update(['probe_install'=>$job]);
    }
    return;
   }
   if ((int)$m->last_seen_at<time()-90) return;
   $s=ProbeService::settings();
   if (!$s?->enabled || !$s->agent_version) return;
   $version=(string)Cache::get('dboard_machine_version:'.$m->id,'');
   $capable=(bool)Cache::get('dboard_machine_probe_install:'.$m->id,false);
   if (!$capable) {
    if (!Cache::get('dboard_machine_upgrade_capable:'.$m->id) || MachineUpgradeService::busy(MachineUpgradeService::state($m->id))) return;
    if ($version==='' || version_compare(ltrim($version,'v'),'0.2.0','>=')) {
     $job['state']='failed';$job['message']='当前程序不支持探针接管';$m->update(['probe_install'=>$job]);return;
    }
    if (($job['state']??'')==='upgrading') {
     $job['state']='failed';$job['message']='过渡版本升级未完成，请查看升级结果';$m->update(['probe_install'=>$job]);return;
    }
    $id=bin2hex(random_bytes(12));
    MachineUpgradeService::start($m->id,['request_id'=>$id,'state'=>'queued','target_version'=>$s->agent_version,'from_version'=>$version,'protocol'=>2,'created_at'=>time(),'updated_at'=>time()]);
    $job['state']='upgrading';$job['updated_at']=time();$m->update(['probe_install'=>$job]);
    NodeSyncService::pushMachine($m->id,'node.upgrade',['request_id'=>$id,'version'=>$s->agent_version]);
    return;
   }
   if (MachineUpgradeService::busy(MachineUpgradeService::state($m->id))) return;
   if (!$m->probe_uuid) $m->update(['probe_uuid'=>(string)Str::uuid()]);
   $code=bin2hex(random_bytes(24));ProbeService::sync($m,$code);
   $id=bin2hex(random_bytes(12));
   $job=array_merge($job,['state'=>'installing','request_id'=>$id,'endpoint'=>$s->endpoint,'version'=>$s->agent_version,'updated_at'=>time()]);
   // Credentials are sent once over the authenticated channel, never stored in the job.
   $m->update(['probe_install'=>$job]);
   MachineUpgradeService::start($m->id,['request_id'=>$id,'state'=>'queued','target_version'=>$s->agent_version,'from_version'=>$version,'protocol'=>2,'created_at'=>time(),'updated_at'=>time()]);
   if (!NodeSyncService::pushMachine($m->id,'node.probe.install',['request_id'=>$id,'version'=>$s->agent_version,'endpoint'=>$s->endpoint,'uuid'=>$m->probe_uuid,'enrollment'=>$code])) {
    $job['state']='failed';$job['message']='接管指令发送失败';$m->update(['probe_install'=>$job]);
   }
  });
 }
 public static function result(int $id,array $result,?string $endpoint): void
 {
  $m=ServerMachine::find($id);$job=$m?->probe_install??[];
  if (($job['state']??'')!=='installing' || !hash_equals((string)($job['request_id']??''),(string)($result['request_id']??''))) return;
  $state=$result['state']??'';
  if ($state==='success') {
   if (!$endpoint || $endpoint!==($job['endpoint']??'')) return;
   $job['state']='completed';$job['updated_at']=time();$job['message']='探针监控与节点通道均通过健康检查';
   $m->update(['probe_endpoint'=>$endpoint,'probe_install'=>$job]);
  } elseif (in_array($state,['failed','rolled_back'],true)) {
   $job['state']='failed';$job['updated_at']=time();$job['message']=mb_substr((string)($result['message']??''),0,400);
   $m->update(['probe_install'=>$job]);
  }
 }
}

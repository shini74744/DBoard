<?php
namespace App\Console\Commands;
use App\Models\ServerMachine;
use App\Services\ProbeRolloutService;
use Illuminate\Console\Command;
class ProbeRollout extends Command
{
 protected $signature='probe:rollout {ids?* : Explicitly queue these machine IDs} {--status : Read status without dispatching}';
 protected $description='Migrate explicitly queued machines to the integrated probe, preserving stock Nezha';
 public function handle(): int
 {
  $ids=array_values(array_unique(array_map('intval',$this->argument('ids'))));
  if (!$this->option('status')) {
   foreach(ServerMachine::whereIn('id',$ids)->get() as $m) ProbeRolloutService::queue($m);
   foreach(ServerMachine::whereNotNull('probe_install')->get() as $m) {
    try { ProbeRolloutService::poll($m); }
    catch(\Throwable $e) { $this->warn('Machine '.$m->id.': rollout deferred; check probe connectivity'); }
   }
  }
  $rows=ServerMachine::orderBy('id')->get()->map(fn($m)=>[
   'id'=>$m->id,'name'=>$m->name,'recent'=>$m->last_seen_at>time()-90,
   'version'=>\Illuminate\Support\Facades\Cache::get('dboard_machine_version:'.$m->id),
   'endpoint'=>$m->probe_endpoint,'state'=>$m->probe_install['state']??null,
  ])->all();
  $this->line(json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  return self::SUCCESS;
 }
}

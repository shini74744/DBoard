<?php
namespace Tests\Unit\Services;
use App\Services\MachineUpgradeService as Upgrade;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
class MachineUpgradeTest extends TestCase {
 protected function setUp():void{parent::setUp();Cache::setDefaultDriver('array');Cache::flush();}
 private function task(string $state='queued',int $protocol=1,int $age=0):void{
  Cache::put('dboard_machine_upgrade:7',['request_id'=>'0123456789abcdef','state'=>$state,'target_version'=>'v0.1.8','from_version'=>'v0.1.7','protocol'=>$protocol,'created_at'=>time()-$age,'updated_at'=>time()-$age,'message'=>'old error'],86400);
  Cache::put('dboard_machine_version:7','v0.1.7',86400);
 }
 private function reportResult(string $state,string $message='',int $timestamp=0):void{Upgrade::result(7,['request_id'=>'0123456789abcdef','state'=>$state,'message'=>$message,'updated_at'=>$timestamp]);}
 private function connected(string $v):void{Cache::put('dboard_machine_version:7',$v,86400);Upgrade::version(7,$v);}
 public function test_interrupted_legacy_launcher_is_confirmed_by_actual_version():void{
  $this->task();$this->reportResult('accepted');$this->reportResult('failed','launch upgrade: signal: terminated');
  $this->assertSame('verifying',Upgrade::state(7)['state']);$this->connected('v0.1.8');
  $this->assertSame('success',Upgrade::state(7)['state']);$this->reportResult('failed','delayed failure');$this->assertSame('success',Upgrade::state(7)['state']);
 }
 public function test_existing_false_failure_is_repaired_on_reconnect():void{
  $this->task('failed');$this->connected('v0.1.8');$s=Upgrade::state(7);$this->assertSame('success',$s['state']);$this->assertStringNotContainsString('old error',$s['message']);
 }
 public function test_slow_download_does_not_fail_at_ten_minutes():void{
  $this->task('downloading',2,901);$this->assertSame('downloading',Upgrade::state(7)['state']);
  $this->task('downloading',2,3001);$s=Upgrade::state(7);$this->assertSame('unknown',$s['state']);$this->assertTrue(Upgrade::busy($s));
  $this->task('unknown',2,3301);$this->assertFalse(Upgrade::busy(Upgrade::state(7)));
 }
 public function test_new_protocol_waits_for_health_result_and_recovers_on_rollback():void{
  $this->task('downloading',2);$this->connected('v0.1.8');$this->assertSame('verifying',Upgrade::state(7)['state']);
  $this->reportResult('rolling_back','health failed',100);$this->connected('v0.1.7');$this->reportResult('rolled_back','old version restored',101);
  $this->reportResult('downloading','stale progress',99);$this->assertSame('rolled_back',Upgrade::state(7)['state']);
 }
 public function test_success_requires_running_target_and_ignores_unrelated_result():void{
  $this->task('accepted',2);$this->reportResult('success');$this->assertSame('verifying',Upgrade::state(7)['state']);
  $this->connected('v0.1.8');$this->reportResult('success');$this->assertSame('success',Upgrade::state(7)['state']);
  $this->task('queued',2);Upgrade::result(7,['request_id'=>'ffffffffffffffff','state'=>'failed']);$this->assertSame('queued',Upgrade::state(7)['state']);
 }
 public function test_late_accepted_ack_cannot_override_download_progress():void{
  $this->task('downloading',2);$this->reportResult('accepted');$this->assertSame('downloading',Upgrade::state(7)['state']);
 }
}

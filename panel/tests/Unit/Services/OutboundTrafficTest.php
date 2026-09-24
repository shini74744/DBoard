<?php
namespace Tests\Unit\Services;
use App\Models\{Server,ServerOutbound,User};
use App\Services\{OutboundTrafficService,ServerService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class OutboundTrafficTest extends TestCase {
 use RefreshDatabase;
 private function outbound(): ServerOutbound {
  return ServerOutbound::create(['name'=>'出口','tag'=>'exit','protocol'=>'socks','enabled'=>true,'settings'=>['server'=>'127.0.0.1','server_port'=>1080]]);
 }
 private function node(array $ids): Server {
  return Server::create(['name'=>'入口','type'=>'vless','host'=>'127.0.0.1','port'=>'45110','server_port'=>45110,'enabled'=>true,'show'=>false,'group_ids'=>[],'outbound_ids'=>$ids,'rate'=>2,'protocol_settings'=>['network'=>'tcp','tls'=>0]]);
 }
 private function report(Server $node,int $id,array $bytes,string $session='a1111111-1111-4111-8111-111111111111'):void {
  OutboundTrafficService::record($node,['session'=>$session,'traffic'=>[$id=>$bytes]]);
 }
 public function test_cumulative_reports_are_retry_safe_and_merge_across_nodes_and_restarts():void {
  $out=$this->outbound();$a=$this->node([$out->id]);$b=$this->node([$out->id]);
  $this->report($a,$out->id,[100,200]);$this->report($a,$out->id,[100,200]);
  $this->report($a,$out->id,[50,100]); // delayed older snapshot
  $this->assertSame(100,$out->fresh()->traffic_upload);
  $this->report($a,$out->id,[150,250]);$this->report($b,$out->id,[10,20]);
  $this->report($a,$out->id,[5,7],'b2222222-2222-4222-8222-222222222222');
  $this->assertSame(165,$out->fresh()->traffic_upload);$this->assertSame(277,$out->fresh()->traffic_download);
  $this->assertSame(3,DB::table('v2_outbound_traffic_cursor')->count());
  $this->assertNotNull($out->fresh()->traffic_updated_at);
  $before=$out->toNodeConfig();$out->update(['name'=>'改名','tag'=>'new label']);
  $this->report($a,$out->id,[155,260]);$this->assertSame(170,$out->fresh()->traffic_upload);
  $this->assertSame($out->id,$before['id']);$this->assertArrayNotHasKey('traffic_upload',$before);
 }
 public function test_rejects_other_nodes_negative_and_malformed_reports_and_accepts_zero():void {
  $out=$this->outbound();$a=$this->node([$out->id]);$other=$this->node([]);
  $this->report($other,$out->id,[500,500]);$this->report($a,$out->id,[-1,100]);$this->report($a,$out->id,['100',100]);
  $this->report($a,$out->id,[1,2],'bad');
  $this->assertSame(0,DB::table('v2_outbound_traffic_cursor')->count());
  $this->report($a,$out->id,[0,0]);$this->assertNotNull($out->fresh()->traffic_updated_at);
  $a->outbound_ids=[];$this->report($a,$out->id,[10,20]); // draining configured generation
  $this->assertSame(10,$out->fresh()->traffic_upload);
  $this->report($a,$out->id,[500,500],'b2222222-2222-4222-8222-222222222222');
  $this->assertSame(10,$out->fresh()->traffic_upload);
 }
 public function test_metrics_ingestion_and_admin_list_expose_totals_without_charging_users():void {
  $out=$this->outbound();$node=$this->node([$out->id]);
  $user=User::create(['email'=>'outbound-stat@example.invalid','password'=>'unused','uuid'=>'fixture','token'=>'fixture','is_admin'=>true,'u'=>11,'d'=>12]);
  ServerService::updateMetrics($node,['outbound_traffic'=>['session'=>'a1111111-1111-4111-8111-111111111111','traffic'=>[$out->id=>[1024,2048]]]]);
  $this->assertSame(11,$user->fresh()->u);$this->assertSame(12,$user->fresh()->d);
  Sanctum::actingAs($user);
  $data=$this->getJson('/api/v2/00000000/server/route/fetch')->assertOk()->json('data.0');
  $this->assertSame(1024,$data['traffic_upload']);$this->assertSame(2048,$data['traffic_download']);
  $this->assertNotNull($data['traffic_started_at']);
  $out->delete();$this->assertSame(0,DB::table('v2_outbound_traffic_cursor')->count());
 }
}

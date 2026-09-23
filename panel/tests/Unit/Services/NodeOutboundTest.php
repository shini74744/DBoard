<?php
namespace Tests\Unit\Services;
use App\Models\{Server,ServerOutbound,User};
use App\Services\{NodeOutboundService,ServerService,UserService};
use App\Jobs\{TrafficFetchJob,StatUserJob,StatServerJob};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class NodeOutboundTest extends TestCase {
 use RefreshDatabase;
 private function node(string $name,int $port): Server {
  return Server::create(['name'=>$name,'type'=>'vless','host'=>'127.0.0.1','port'=>(string)$port,'server_port'=>$port,'enabled'=>true,'show'=>false,'group_ids'=>[],'rate'=>1,'protocol_settings'=>['network'=>'tcp','tls'=>0]]);
 }
 public function test_reference_uses_internal_identity_without_creating_a_customer_and_tracks_endpoint(): void {
  $target=$this->node('出口',41001);$source=$this->node('入口',41002);
  $target->update(['protocol_settings'=>['network'=>'tcp','tls'=>0,'encryption'=>['enabled'=>true,'encryption'=>'none','decryption'=>'none']]]);
  $count=User::count();$out=NodeOutboundService::reference($target);
  $this->assertSame($out->id,NodeOutboundService::reference($target)->id);
  $this->assertCount(0,ServerService::getAvailableUsers($target));
  $source->update(['outbound_ids'=>[$out->id]]);
  $users=ServerService::getAvailableUsers($target);$this->assertCount(1,$users);
  $this->assertSame(-$target->id,$users[0]->id);
  $this->assertSame($users[0]->uuid,$out->toNodeConfig()['settings']['uuid']);
  $this->assertSame($count,User::count());
  $this->assertArrayNotHasKey('uuid',$out->settings);
  $target->update(['host'=>'new.example.invalid','port'=>'42001']);
  $this->assertSame('new.example.invalid',$out->toNodeConfig()['settings']['server']);
  $this->assertSame(42001,$out->toNodeConfig()['settings']['server_port']);
  $target->update(['enabled'=>false]);
  $this->assertSame('node-unavailable.invalid',$out->toNodeConfig()['settings']['server']);
  $this->assertCount(0,ServerService::getAvailableUsers($target));
 }
 public function test_self_and_cyclic_references_are_rejected(): void {
  $a=$this->node('A',43001);$b=$this->node('B',43002);
  $oa=NodeOutboundService::reference($a);$ob=NodeOutboundService::reference($b);
  try{NodeOutboundService::validateSelection($a->id,[$oa->id]);$this->fail('self accepted');}catch(\App\Exceptions\ApiException $e){$this->assertStringContainsString('自身',$e->getMessage());}
  $a->update(['outbound_ids'=>[$ob->id]]);
  $this->expectException(\App\Exceptions\ApiException::class);
  NodeOutboundService::validateSelection($b->id,[$oa->id]);
 }
 public function test_admin_selects_nodes_without_a_user_or_plan(): void {
  $admin=User::create(['email'=>'node-exit@example.invalid','password'=>'unused','uuid'=>'fixture','token'=>'fixture','is_admin'=>true]);Sanctum::actingAs($admin);
  $a=$this->node('入口',44001);$b=$this->node('出口',44002);$api='/api/v2/00000000/server/route/';
  $list=$this->getJson($api.'nodes?source_id='.$a->id)->assertOk()->json('data');
  $this->assertNotEmpty($list[0]['unavailable_reason']);$this->assertNull($list[1]['unavailable_reason']);
  $out=$this->postJson($api.'from-node',['node_id'=>$b->id,'source_id'=>$a->id])->assertOk()->json('data');
  $this->assertSame($b->id,$out['target_server_id']);
  $this->postJson('/api/v2/00000000/server/manage/save',array_merge($a->toArray(),['id'=>$a->id,'outbound_ids'=>[$out['id']]]))->assertOk();
  $this->assertSame([$out['id']],$a->fresh()->outbound_ids);
  $this->assertCount(1,ServerService::getAvailableUsers($b));
  $this->postJson('/api/v2/00000000/server/manage/save',array_merge($a->toArray(),['id'=>$a->id,'outbound_ids'=>[]]))->assertOk();
  $this->assertCount(0,ServerService::getAvailableUsers($b));
  $this->assertStringNotContainsString(NodeOutboundService::uuid($b),json_encode($out));
  $this->assertNotEquals(200,$this->postJson($api.'from-node',['node_id'=>$a->id,'source_id'=>$a->id])->status());
 }
 public function test_relay_traffic_counts_on_node_without_charging_customers_twice(): void {
  $node=$this->node('出口',45001);Queue::fake();
  (new UserService)->trafficFetch($node,'vless',[-$node->id=>[100,200]]);
  Queue::assertPushed(StatServerJob::class);Queue::assertNotPushed(TrafficFetchJob::class);Queue::assertNotPushed(StatUserJob::class);
 }
}

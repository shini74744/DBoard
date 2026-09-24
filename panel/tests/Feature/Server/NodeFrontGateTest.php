<?php
namespace Tests\Feature\Server;
use App\Models\{Server,ServerGroup,ServerOutbound,User};
use App\Services\{NodeFrontGateService as Gate,NodeOutboundService,ServerService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache,DB,Queue};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class NodeFrontGateTest extends TestCase {
 use RefreshDatabase;
 public function createApplication() {
  $app=parent::createApplication();$app->detectEnvironment(fn()=>'testing');
  $app['config']->set('app.env','testing');$app['config']->set('app.key','base64:'.base64_encode(str_repeat('T',32)));$app['config']->set('database.default','sqlite');
  $app['config']->set('database.connections.sqlite',['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);
  DB::purge('sqlite');Cache::setDefaultDriver('array');return $app;
 }
 protected function setUp():void {
  parent::setUp();Queue::fake();
  Sanctum::actingAs(User::create(['email'=>'front@example.invalid','password'=>'unused','uuid'=>'test','token'=>'test','is_admin'=>true]));
 }
 private function node($name,$groups=[],$extra=[]) {
  return Server::create(array_merge(['type'=>'vless','name'=>$name,'host'=>'node.example.invalid','port'=>443,'server_port'=>443,'group_ids'=>$groups,'show'=>false,'enabled'=>true,'rate'=>1,'protocol_settings'=>['network'=>'tcp','tls'=>0]],$extra));
 }
 private function payload($node,$extra) {
  return array_merge($node->makeVisible(Gate::FIELDS)->only(['id','name','type','host','port','server_port','rate','group_ids','protocol_settings','front_gate_enabled','front_gate_node_ids','front_gate_group_ids']),$extra);
 }
 public function test_multiple_nodes_and_permission_groups_form_dynamic_union() {
  $g=new ServerGroup();$g->name='套餐权限组';$g->save();
  $a=$this->node('A');$c=$this->node('C',[$g->id]);$d=$this->node('D');
  $b=$this->node('B',[$g->id],['front_gate_enabled'=>true,'front_gate_node_ids'=>[$a->id,$c->id],'front_gate_group_ids'=>[$g->id]]);
  $this->assertSame([$a->id,$c->id],Gate::allowed($b)->pluck('id')->all());
  $d->update(['group_ids'=>[(string)$g->id],'parent_id'=>0]);$c->update(['group_ids'=>[]]);
  $this->assertSame([$a->id,$c->id,$d->id],Gate::allowed($b)->pluck('id')->all());
  $c->update(['enabled'=>false]);$this->assertSame([$a->id,$d->id],Gate::allowed($b)->pluck('id')->all());
  $d->delete();$this->assertSame([$a->id],Gate::allowed($b)->pluck('id')->all());
 }
 public function test_private_material_is_targeted_and_identity_is_stable_and_encrypted() {
  $a=$this->node('A');$b=$this->node('B',[],['front_gate_enabled'=>true,'front_gate_node_ids'=>[$a->id]]);
  $x=$this->node('outsider');$out=NodeOutboundService::reference($b);
  $identity=Gate::identity($a);$this->assertSame($identity,Gate::identity($a));
  $stored=DB::table('dboard_node_identity')->where('node_id',$a->id)->value('encrypted_key');
  $this->assertStringNotContainsString('PRIVATE KEY',$stored);
  $in=ServerService::buildNodeConfig($b);
  $this->assertSame('dboard-front-only',$in['protocol']);$this->assertCount(1,$in['front_gate']['trusted_clients']);
  $this->assertSame($identity['certificate'],$in['front_gate']['trusted_clients'][0]);
  $this->assertNotSame($identity['private_key'],$in['front_gate']['private_key']);
  $allowed=$out->toNodeConfig($a);$denied=$out->toNodeConfig($x);
  $this->assertSame('vless',$allowed['protocol']);$this->assertSame($identity['private_key'],$allowed['settings']['client_key_pem']);
  $this->assertSame('node-not-authorized.invalid',$denied['settings']['server']);
  $this->assertStringNotContainsString('PRIVATE KEY',json_encode($denied));
  $this->assertStringNotContainsString('PRIVATE KEY',$b->toJson());
  $this->assertArrayNotHasKey('front_gate_enabled',$b->toArray());
  $this->assertStringNotContainsString('PRIVATE KEY',$this->getJson('/api/v2/00000000/server/manage/getNodes')->assertOk()->getContent());
 }
 public function test_legacy_enable_is_rejected_and_applied_state_requires_matching_ack() {
  $a=$this->node('A');$b=$this->node('B');
  $body=$this->payload($b,['front_gate_enabled'=>true,'front_gate_node_ids'=>[$a->id]]);
  $this->postJson('/api/v2/00000000/server/manage/save',$body)->assertStatus(422);
  $this->assertFalse($b->fresh()->front_gate_enabled);
  Cache::put('dboard_front_gate_capable:'.$b->id,true,600);
  $this->postJson('/api/v2/00000000/server/manage/save',$body)->assertOk();$b=$b->fresh();
  $in=ServerService::buildNodeConfig($b);
  $this->assertFalse(Gate::summary($b)['applied']);
  Cache::put('dboard_front_gate_applied:'.$b->id,$in['front_gate']['revision']);
  $this->assertTrue(Gate::summary($b)['applied']);
  $body['front_gate_node_ids']=[$b->id];
  $this->postJson('/api/v2/00000000/server/manage/save',$body)->assertStatus(422);
  $b->update(['front_gate_node_ids'=>[]]);
  $empty=Gate::inbound($b);$this->assertSame([],$empty['trusted_clients']);
  $this->assertNotSame($in['front_gate']['revision'],$empty['revision']);
  $this->assertFalse(Gate::summary($b)['applied']);
 }
 public function test_split_rules_and_chained_outbound_ids_tags_are_preserved() {
  $a=$this->node('A');$b=$this->node('B',[],['front_gate_enabled'=>true,'front_gate_node_ids'=>[$a->id]]);
  $hop=ServerOutbound::create(['name'=>'prior hop','tag'=>'hop-c','protocol'=>'socks','settings'=>['server'=>'hop.example.invalid','server_port'=>1080],'enabled'=>true]);
  $out=NodeOutboundService::reference($b);$out->update(['proxy_tag'=>$hop->tag]);
  $rules=[['name'=>'selected','match'=>['domains'=>['selected.example']],'action'=>['type'=>'route','target'=>$out->tag]],['name'=>'fallback','match'=>[],'action'=>['type'=>'direct']]];
  $a->update(['outbound_ids'=>[$out->id],'custom_route_rules'=>$rules]);
  $config=ServerService::buildNodeConfig($a);
  $this->assertSame($rules,$config['custom_route_rules']);
  $this->assertSame([$out->id,$hop->id],array_column($config['custom_outbounds'],'id'));
  $this->assertSame($out->tag,$config['custom_outbounds'][0]['tag']);
  $this->assertSame('hop-c',$config['custom_outbounds'][0]['proxy_tag']);
  $this->assertSame(1,$config['custom_outbounds'][0]['settings']['front_gate_version']);
  $b->update(['front_gate_enabled'=>false]);
  $normal=ServerService::buildNodeConfig($a)['custom_outbounds'][0];
  $this->assertArrayNotHasKey('client_key_pem',$normal['settings']);
  $this->assertSame('hop-c',$normal['proxy_tag']);
 }
}

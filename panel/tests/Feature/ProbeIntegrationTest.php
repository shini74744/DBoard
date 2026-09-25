<?php
namespace Tests\Feature;
use App\Models\{ProbeSetting,ServerMachine,Server,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Cache,Http,Redis};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class ProbeIntegrationTest extends TestCase {
 use RefreshDatabase;
 public function createApplication(){
  $app=parent::createApplication();$app->detectEnvironment(fn()=>'testing');
  $app['config']->set('app.env','testing');$app['config']->set('app.key','base64:'.base64_encode(str_repeat('k',32)));
  $app['config']->set('database.default','sqlite');$app['config']->set('database.connections.sqlite',['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);DB::purge('sqlite');Cache::setDefaultDriver('array');return $app;
 }
 private function settings(){return ProbeSetting::create(['id'=>1,'enabled'=>true,'endpoint'=>'https://probe.example.test','control_key'=>str_repeat('a',48),'connector_key'=>str_repeat('b',48),'agent_version'=>'v0.2.0']);}
 private function admin(){Sanctum::actingAs(User::create(['email'=>'admin@example.test','password'=>'unused','uuid'=>'fixture','token'=>'fixture','is_admin'=>true]));}
 public function test_server_creation_provisions_probe_and_installation_contains_only_probe_address(){
  $this->settings();$this->admin();Http::fake(['probe.example.test/*'=>Http::response(['server_id'=>7])]);
  $uri=collect(app('router')->getRoutes())->first(fn($route)=>str_ends_with($route->uri(),'/server/machine/save'))->uri();$prefix='/'.substr($uri,0,-strlen('/server/machine/save'));
  $r=$this->postJson($prefix.'/server/machine/save',['name'=>'Probe machine'])->assertOk();
  $m=ServerMachine::first();$this->assertNotNull($m->probe_uuid);$cmd=$r->json('data.install_command');
  $this->assertStringContainsString('https://probe.example.test/bridge/v1/install.sh',$cmd);
  $this->assertStringNotContainsString('--panel',$cmd);$this->assertStringNotContainsString('--machine-id',$cmd);$this->assertStringNotContainsString($m->token,$cmd);
  $this->assertEquals(7,$m->probe_server_id);
 }
 public function test_connector_credentials_require_key_and_real_loopback_connection(){
  $s=$this->settings();$m=ServerMachine::create(['name'=>'p','token'=>'private-token','probe_uuid'=>'c9a2215c-a675-448e-b6ac-c96dd420a79b','is_active'=>true]);
  $this->postJson('/api/v2/probe/credential',['device'=>$m->probe_uuid])->assertForbidden();
  $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.1'])->withHeader('Authorization','Bearer '.$s->connector_key)->postJson('/api/v2/probe/credential',['device'=>$m->probe_uuid])->assertForbidden();
  $this->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])->postJson('/api/v2/probe/credential',['device'=>$m->probe_uuid])->assertOk()->assertJsonPath('machine_id',$m->id);
  $m->update(['is_active'=>false]);$this->postJson('/api/v2/probe/credential',['device'=>$m->probe_uuid])->assertNotFound();
 }
 public function test_traffic_replay_is_counted_once_and_cross_machine_nodes_are_rejected(){
  $s=$this->settings();$m=ServerMachine::create(['name'=>'p','token'=>'private-token','probe_uuid'=>'c9a2215c-a675-448e-b6ac-c96dd420a79b','is_active'=>true]);
  $user=User::create(['email'=>'u@example.test','password'=>'unused','uuid'=>'u','token'=>'u','u'=>0,'d'=>0]);
  $node=Server::create(['name'=>'node','type'=>'vless','host'=>'example.test','port'=>'443','server_port'=>443,'rate'=>1.05,'group_ids'=>['1'],'show'=>true,'enabled'=>true,'machine_id'=>$m->id,'protocol_settings'=>['network'=>'tcp','tls'=>0]]);
  Redis::shouldReceive('sadd')->andReturn(1);
  $outbound=\App\Models\ServerOutbound::create(['name'=>'third party','tag'=>'probe-exit','enabled'=>true,'protocol'=>'socks','settings'=>['server'=>'127.0.0.1','server_port'=>1080]]);
  $node->update(['outbound_ids'=>[$outbound->id]]);
  $body=json_encode(['traffic'=>[$user->id=>[100,200]],'metrics'=>['outbound_traffic'=>['session'=>'b2158d7c-404e-4126-b130-c4fa0b3c8aaf','traffic'=>[$outbound->id=>[300,600]]]]]);
  $frame=['device'=>$m->probe_uuid,'method'=>'POST','path'=>'/api/v2/server/report','query'=>['node_id'=>[(string)$node->id]],'body'=>base64_encode($body),'headers'=>['X-Probe-Request-ID'=>str_repeat('c',48)]];
  $this->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])->withHeader('Authorization','Bearer '.$s->connector_key);
  $this->postJson('/api/v2/probe/dispatch',$frame)->assertOk()->assertJsonPath('status',200);
  $this->postJson('/api/v2/probe/dispatch',$frame)->assertOk()->assertJsonPath('status',200);
  $m->update(['probe_endpoint'=>'https://probe.example.test']);
  $this->postJson('/api/v2/server/machine/nodes',['machine_id'=>$m->id,'token'=>$m->token])->assertForbidden();
  $configFrame=['device'=>$m->probe_uuid,'method'=>'GET','path'=>'/api/v2/server/config','query'=>['node_id'=>[(string)$node->id]],'headers'=>['X-DBoard-User-Routes'=>'1','X-DBoard-Front-Gate'=>'1']];
  $this->postJson('/api/v2/probe/dispatch',$configFrame)->assertOk()->assertJsonPath('status',200);
  $this->assertTrue((bool)Cache::get('dboard_user_routes_capable:'.$node->id));
  $this->assertTrue((bool)Cache::get('dboard_front_gate_capable:'.$node->id));
  $this->assertEquals(105,$user->fresh()->u);$this->assertEquals(210,$user->fresh()->d);$this->assertEquals(100,$node->fresh()->u);
  $this->assertEquals(300,$outbound->fresh()->traffic_upload);$this->assertEquals(600,$outbound->fresh()->traffic_download);
  $frame['body']=base64_encode(json_encode(['traffic'=>[$user->id=>[900,200]]]));
  $this->postJson('/api/v2/probe/dispatch',$frame)->assertStatus(409);
  $frame['headers']['X-Probe-Request-ID']=str_repeat('d',48);$frame['query']['node_id']=['99999'];
  $this->postJson('/api/v2/probe/dispatch',$frame)->assertStatus(400);
  $this->assertEquals(105,$user->fresh()->u);
 }

 private function prefix(){return '/'.substr(collect(app('router')->getRoutes())->first(fn($x)=>str_ends_with($x->uri(),'/probe/migrate'))->uri(),0,-strlen('/probe/migrate'));}
 public function test_endpoint_migration_is_persisted_for_offline_nodes_and_bad_target_is_rejected(){
  $settings=$this->settings();$this->admin();
  $m=ServerMachine::create(['name'=>'offline probe','token'=>'internal','probe_uuid'=>'c9a2215c-a675-448e-b6ac-c96dd420a79b','is_active'=>true]);
  Http::fake(['probe.example.test/*'=>Http::response(['gateway_id'=>hash('sha256',$settings->control_key),'connected'=>true]),'new.example.test/*'=>Http::response(['gateway_id'=>hash('sha256',$settings->control_key),'connected'=>true]),'other.example.test/*'=>Http::response(['gateway_id'=>'other'])]);
  Redis::shouldReceive('publish')->andReturn(0);
  $this->postJson($this->prefix().'/probe/migrate',['endpoint'=>'https://other.example.test','ids'=>[$m->id]])->assertStatus(422);
  $this->assertNull($m->fresh()->probe_migration);
  $this->postJson($this->prefix().'/probe/migrate',['endpoint'=>'https://new.example.test','ids'=>[$m->id]])->assertOk();
  $this->assertSame('pending',$m->fresh()->probe_migration['state']);
  $this->assertSame(['https://probe.example.test'],$m->fresh()->probe_migration['backups']);
  $this->assertSame('https://new.example.test',$settings->fresh()->endpoint);
  $this->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])->withHeader('Authorization','Bearer '.$settings->connector_key)->postJson('/api/v2/probe/endpoint')->assertOk()->assertJsonPath('endpoint','https://new.example.test');
 }
 public function test_probe_upgrade_uses_integrated_release_without_github(){
  $this->settings();$this->admin();
  $m=ServerMachine::create(['name'=>'p','token'=>'internal','probe_endpoint'=>'https://probe.example.test','probe_uuid'=>'c9a2215c-a675-448e-b6ac-c96dd420a79b','is_active'=>true]);
  Cache::put('dboard_machine_version:'.$m->id,'v0.1.0',600);
  Cache::put('dboard_machine_upgrade_capable:'.$m->id,true,600);
  Cache::put('dboard_machine_upgrade_protocol:'.$m->id,2,600);
  Http::preventStrayRequests();Redis::shouldReceive('publish')->andReturn(1);
  $this->getJson($this->prefix().'/server/machine/latestRelease')->assertOk()->assertJsonPath('data.probe_version','v0.2.0');
  $this->postJson($this->prefix().'/server/machine/upgrade',['ids'=>[$m->id]])->assertOk()->assertJsonPath('data.target_version','v0.2.0');
  Http::assertNothingSent();
 }

 public function test_rollout_waits_for_offline_machine_and_only_locks_direct_access_after_verified_success(){
  $this->settings();Http::preventStrayRequests();
  $m=ServerMachine::create(['name'=>'offline','token'=>'internal','is_active'=>true,'last_seen_at'=>time()-500]);
  \App\Services\ProbeRolloutService::queue($m); \App\Services\ProbeRolloutService::poll($m);
  $this->assertSame('queued',$m->fresh()->probe_install['state']);$this->assertNull($m->fresh()->probe_endpoint);
  Http::assertNothingSent();
  $job=['state'=>'installing','request_id'=>str_repeat('a',24),'endpoint'=>'https://probe.example.test','updated_at'=>time()];
  $m->update(['probe_install'=>$job]);
  $result=['request_id'=>$job['request_id'],'state'=>'success'];
  \App\Services\ProbeRolloutService::result($m->id,$result,null);
  $this->assertNull($m->fresh()->probe_endpoint);
  \App\Services\ProbeRolloutService::result($m->id,$result,'https://other.example.test');
  $this->assertSame('installing',$m->fresh()->probe_install['state']);
  \App\Services\ProbeRolloutService::result($m->id,$result,'https://probe.example.test');
  $this->assertSame('completed',$m->fresh()->probe_install['state']);
  $this->assertSame('https://probe.example.test',$m->fresh()->probe_endpoint);
 }
 public function test_failed_install_keeps_legacy_access_available(){
  $m=ServerMachine::create(['name'=>'rollback','token'=>'internal','is_active'=>true,'probe_install'=>['state'=>'installing','request_id'=>str_repeat('b',24)]]);
  \App\Services\ProbeRolloutService::result($m->id,['request_id'=>str_repeat('c',24),'state'=>'failed'],null);
  $this->assertSame('installing',$m->fresh()->probe_install['state']);
  \App\Services\ProbeRolloutService::result($m->id,['request_id'=>str_repeat('b',24),'state'=>'failed'],null);
  $this->assertSame('failed',$m->fresh()->probe_install['state']);$this->assertNull($m->fresh()->probe_endpoint);
 }

 public function test_transition_upgrade_preserves_legacy_acknowledgement_protocol(){
  $this->settings();Http::preventStrayRequests();Redis::shouldReceive('publish')->andReturn(1);
  $m=ServerMachine::create(['name'=>'old','token'=>'internal','is_active'=>true,'last_seen_at'=>time()]);
  Cache::put('dboard_machine_version:'.$m->id,'v0.1.5',600);
  Cache::put('dboard_machine_upgrade_capable:'.$m->id,true,600);
  Cache::put('dboard_machine_upgrade_protocol:'.$m->id,1,600);
  \App\Services\ProbeRolloutService::queue($m);\App\Services\ProbeRolloutService::poll($m);
  $this->assertSame('upgrading',$m->fresh()->probe_install['state']);
  $this->assertSame(1,\App\Services\MachineUpgradeService::state($m->id)['protocol']);
  Http::assertNothingSent();
 }

 public function test_machine_deletion_removes_probe_and_detaches_nodes(){
  $this->settings();$this->admin();
  $m=ServerMachine::create(['name'=>'111','token'=>'internal','probe_uuid'=>'c9a2215c-a675-448e-b6ac-c96dd420a79b','is_active'=>true]);
  $node=Server::create(['name'=>'node','type'=>'vless','host'=>'example.test','port'=>'443','server_port'=>443,'rate'=>1,'group_ids'=>['1'],'machine_id'=>$m->id]);
  Http::fake(['probe.example.test/bridge/v1/control/device/delete'=>Http::response(['deleted'=>true])]);
  Redis::shouldReceive('publish')->andReturn(1);
  $this->postJson($this->prefix().'/server/machine/drop',['id'=>$m->id])->assertOk();
  $this->assertNull($m->fresh());$this->assertNull($node->fresh()->machine_id);
  Http::assertSent(fn($r)=>$r->url()==='https://probe.example.test/bridge/v1/control/device/delete' && $r['uuid']===$m->probe_uuid);
  Http::assertSentCount(1);
 }
 public function test_failed_probe_deletion_keeps_machine_and_node_bindings_for_retry(){
  $this->settings();$this->admin();
  $m=ServerMachine::create(['name'=>'111','token'=>'internal','probe_uuid'=>'c9a2215c-a675-448e-b6ac-c96dd420a79b','is_active'=>true]);
  $node=Server::create(['name'=>'node','type'=>'vless','host'=>'example.test','port'=>'443','server_port'=>443,'rate'=>1,'group_ids'=>['1'],'machine_id'=>$m->id]);
  Http::fake(['probe.example.test/*'=>Http::sequence()->push([],503)->push(['deleted'=>true])]);
  Redis::shouldReceive('publish')->once()->andReturn(1);
  $this->postJson($this->prefix().'/server/machine/drop',['id'=>$m->id])->assertStatus(422);
  $this->assertNotNull($m->fresh());$this->assertTrue((bool)$m->fresh()->is_active);$this->assertSame($m->id,$node->fresh()->machine_id);
  $this->postJson($this->prefix().'/server/machine/drop',['id'=>$m->id])->assertOk();
  $this->assertNull($m->fresh());$this->assertNull($node->fresh()->machine_id);
 }

 public function test_unconfirmed_probe_deletion_keeps_machine(){
  $this->settings();$this->admin();
  $m=ServerMachine::create(['name'=>'111','token'=>'internal','probe_uuid'=>'c9a2215c-a675-448e-b6ac-c96dd420a79b','is_active'=>true]);
  Http::fake(['probe.example.test/*'=>Http::response([])]);
  $this->postJson($this->prefix().'/server/machine/drop',['id'=>$m->id])->assertStatus(422);
  $this->assertNotNull($m->fresh());
 }
}

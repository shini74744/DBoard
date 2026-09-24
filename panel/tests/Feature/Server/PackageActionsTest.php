<?php
namespace Tests\Feature\Server;
use App\Models\{User,Plan};
use App\Services\{MultiSubscriptionService,ServerService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache,DB,Queue};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class PackageActionsTest extends TestCase {
 use RefreshDatabase;
 public function createApplication() {
  $app=parent::createApplication();$app->detectEnvironment(fn()=>'testing');
  $app['config']->set('app.env','testing');$app['config']->set('database.default','sqlite');
  $app['config']->set('database.connections.sqlite',['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);
  DB::purge('sqlite');Cache::setDefaultDriver('array');return $app;
 }
 private function fixture() {
  Queue::fake();
  $plan=Plan::create(['name'=>'Fixture','group_id'=>1,'transfer_enable'=>100,'connection_limit'=>9,'prices'=>['monthly'=>10],'reset_traffic_method'=>1]);
  $owner=User::create(['email'=>'owner@example.invalid','password'=>'unused','uuid'=>'owner','token'=>'owner','is_admin'=>true,'plan_id'=>$plan->id,'group_id'=>1,'u'=>11,'d'=>22,'transfer_enable'=>107374182400,'expired_at'=>time()+86400,'connection_limit'=>9]);
  $child=MultiSubscriptionService::createPackage($owner);
  $child->update(['plan_id'=>$plan->id,'group_id'=>1,'u'=>33,'d'=>44,'transfer_enable'=>107374182400,'expired_at'=>time()+86400,'connection_limit'=>4]);
  MultiSubscriptionService::primaryPackageToken($owner);
  Sanctum::actingAs($owner);return [$plan,$owner,$child];
 }
 public function test_targeted_edit_reset_remove_preserves_other_package() {
  [$plan,$owner,$child]=$this->fixture();$before=$owner->fresh()->getAttributes();
  $body=['user_id'=>$owner->id,'subscription_user_id'=>$child->id];
  $this->postJson('/api/v2/00000000/user/subscription/update',$body+['connection_limit'=>2])->assertOk();
  $this->assertSame(2,$child->fresh()->connection_limit);
  $this->getJson('/api/v2/00000000/user/getUserInfoById?id='.$owner->id)->assertOk()->assertJsonPath('data.subscriptions.1.connection_limit',2);
  $this->postJson('/api/v2/00000000/user/subscription/reset-traffic',$body)->assertOk();
  $fresh=$child->fresh();$this->assertSame(0,$fresh->u);$this->assertSame(0,$fresh->d);$this->assertSame(2,$fresh->connection_limit);
  $this->assertSame($before,$owner->fresh()->getAttributes());
  $this->postJson('/api/v2/00000000/user/subscription/remove',$body)->assertOk();
  $this->assertNull($child->fresh()->plan_id);$this->assertSame($before,$owner->fresh()->getAttributes());
  $this->postJson('/api/v2/00000000/user/subscription/reset-traffic',$body)->assertStatus(422);
 }
 public function test_reset_ownership_and_connection_validation() {
  [$plan,$owner,$child]=$this->fixture();
  $other=User::create(['email'=>'other@example.invalid','password'=>'unused','uuid'=>'other','token'=>'other','plan_id'=>$plan->id,'u'=>55]);
  $this->postJson('/api/v2/00000000/user/subscription/reset-traffic',['user_id'=>$owner->id,'subscription_user_id'=>$other->id])->assertStatus(422);
  $this->assertSame(55,$other->fresh()->u);
  foreach([-1,1.5,2147483648] as $bad) $this->postJson('/api/v2/00000000/user/subscription/update',['user_id'=>$owner->id,'subscription_user_id'=>$child->id,'connection_limit'=>$bad])->assertStatus(422);
  Sanctum::actingAs($other);
  $this->postJson('/api/v2/00000000/user/subscription/reset-traffic',['user_id'=>$owner->id,'subscription_user_id'=>$child->id])->assertStatus(403);
 }
 public function test_plan_force_update_and_unlimited_propagate() {
  [$plan,$owner,$child]=$this->fixture();
  $payload=['id'=>$plan->id,'name'=>'Fixture','group_id'=>1,'transfer_enable'=>100,'speed_limit'=>null,'device_limit'=>null,'prices'=>['monthly'=>10],'force_update'=>true,'connection_limit'=>12];
  $this->postJson('/api/v2/00000000/plan/save',$payload)->assertOk();
  $this->assertSame(12,$plan->fresh()->connection_limit);$this->assertSame(12,$child->fresh()->connection_limit);
  $payload['connection_limit']=null;
  $this->postJson('/api/v2/00000000/plan/save',$payload)->assertOk();
  $this->assertNull($owner->fresh()->connection_limit);$this->assertNull($child->fresh()->connection_limit);
 }
}

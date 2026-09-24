<?php
namespace Tests\Feature\Server;
use App\Models\{User,Plan,Order,SubscriptionActivity};
use App\Services\{MultiSubscriptionService,OrderService,SubscriptionActivityService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache,DB,Queue};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionActivityTest extends TestCase {
 use RefreshDatabase;
 public function createApplication() {
  $app=parent::createApplication();$app->detectEnvironment(fn()=>'testing');
  $app['config']->set('app.env','testing');$app['config']->set('database.default','sqlite');
  $app['config']->set('database.connections.sqlite',['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);
  DB::purge('sqlite');Cache::setDefaultDriver('array');return $app;
 }
 private function fixture() {
  Queue::fake();
  $plan=Plan::create(['name'=>'A套餐','group_id'=>1,'transfer_enable'=>100,'prices'=>['monthly'=>40,'quarterly'=>110],'show'=>true,'sell'=>true,'renew'=>true,'capacity_limit'=>null,'reset_traffic_method'=>1]);
  $owner=User::create(['email'=>'activity@example.invalid','password'=>'unused','uuid'=>'owner','token'=>'owner','is_admin'=>true,'plan_id'=>$plan->id,'group_id'=>1,'u'=>1073741824,'d'=>2147483648,'transfer_enable'=>107374182400,'expired_at'=>time()+86400,'balance'=>0]);
  $child=MultiSubscriptionService::createPackage($owner);
  $child->update(['plan_id'=>$plan->id,'group_id'=>1,'u'=>1073741824,'d'=>2147483648,'transfer_enable'=>107374182400,'expired_at'=>time()+86400,'billing_period'=>'monthly']);
  Sanctum::actingAs($owner);return [$plan,$owner,$child,['user_id'=>$owner->id,'subscription_user_id'=>$child->id]];
 }
 public function test_admin_creation_has_one_record_without_changing_financial_type() {
  [$plan,$owner]=$this->fixture();
  $trade=$this->postJson('/api/v2/00000000/order/assign',['email'=>$owner->email,'plan_id'=>$plan->id,'period'=>'monthly','total_amount'=>3275,'renewal_price'=>3275,'subscription_action'=>'add'])->assertOk()->json('data');
  $order=Order::where('trade_no',$trade)->sole();
  $this->assertTrue($order->is_admin_created);$this->assertSame($owner->id,$order->admin_actor_id);
  $order->update(['status'=>Order::STATUS_PROCESSING]);(new OrderService($order))->open();(new OrderService($order->fresh()))->open();
  $this->assertSame(1,SubscriptionActivity::count());
  $this->assertSame(Order::TYPE_ADDITIONAL,$order->fresh()->type);
  $admin=$this->getJson('/api/v2/00000000/order/fetch?'.http_build_query(['filter'=>[['id'=>'type','value'=>[6]]]]))->assertOk()->json('data');
  $this->assertCount(1,$admin);$this->assertSame(6,$admin[0]['type']);$this->assertSame('open',$admin[0]['activity_action']);
  $user=$this->getJson('/api/v1/user/order/fetch')->assertOk()->json('data');
  $this->assertCount(1,$user);$this->assertStringContainsString('开通套餐',$user[0]['activity_summary']);
  $this->assertArrayNotHasKey('admin_actor_id',$user[0]);$this->assertSame(3275,$user[0]['total_amount']);
 }
 public function test_customer_changes_are_allowlisted_in_response_not_only_hidden_by_ui() {
  [$plan,$owner,$child,$body]=$this->fixture();
  $this->postJson('/api/v2/00000000/user/subscription/update',$body+['connection_limit'=>987654,'device_limit'=>789,'speed_limit'=>456,'billing_period'=>'quarterly','billing_price'=>8765,'transfer_enable'=>200])->assertOk();
  $activity=SubscriptionActivity::sole();$this->assertStringContainsString('987654',$activity->summary);
  $admin=$this->postJson('/api/v2/00000000/order/detail',['id'=>-$activity->id])->assertOk()->json('data');
  $this->assertSame('ACT-'.$activity->id,$admin['trade_no']);$this->assertStringContainsString('连接数限制',$admin['activity_summary']);
  $response=$this->getJson('/api/v1/user/order/fetch')->assertOk();$rows=$response->json('data');
  $this->assertCount(1,$rows);
  $fields=array_column($rows[0]['activity_changes'],'field');
  $this->assertContains('billing_period',$fields);$this->assertContains('billing_prices',$fields);$this->assertContains('transfer_enable',$fields);
  $this->assertStringContainsString('季付',$rows[0]['activity_summary']);$this->assertStringContainsString('87.65',$rows[0]['activity_summary']);
  foreach(['connection_limit','device_limit','speed_limit','连接数','倍率','987654'] as $secret)$this->assertStringNotContainsString($secret,json_encode($rows,JSON_UNESCAPED_UNICODE));
  $this->postJson('/api/v2/00000000/user/subscription/update',$body+['connection_limit'=>654321])->assertOk();
  $this->assertSame(2,SubscriptionActivity::count());$this->assertCount(1,$this->getJson('/api/v1/user/order/fetch')->assertOk()->json('data'));
  $this->assertSame(0,Order::count());
  $internal=SubscriptionActivityService::decorate(SubscriptionActivityService::feed()->find(-SubscriptionActivity::max('id')));
  $this->assertNull(SubscriptionActivityService::forCustomer($internal));
  $this->assertStringNotContainsString('654321',$internal->activity_summary);
 }
 public function test_reset_cancel_history_is_owned_and_survives_package_removal() {
  [$plan,$owner,$child,$body]=$this->fixture();
  $this->postJson('/api/v2/00000000/user/subscription/reset-traffic',$body)->assertOk();
  $this->postJson('/api/v2/00000000/user/subscription/remove',$body)->assertOk();
  $this->assertNull($child->fresh()->plan_id);$this->assertSame(2,SubscriptionActivity::count());$this->assertSame(0,Order::count());
  $rows=$this->getJson('/api/v1/user/order/fetch')->assertOk()->json('data');$this->assertCount(2,$rows);
  $cancel=collect($rows)->firstWhere('activity_action','cancel');$this->assertSame('A套餐',$cancel['plan']['name']);
  $this->assertStringContainsString('取消套餐订阅',$cancel['activity_summary']);
  $this->postJson('/api/v1/user/order/cancel',['trade_no'=>$cancel['trade_no']])->assertStatus(400);
  $other=User::create(['email'=>'other@example.invalid','password'=>'unused','uuid'=>'other','token'=>'other']);
  Sanctum::actingAs($other);
  $this->assertSame([],$this->getJson('/api/v1/user/order/fetch')->assertOk()->json('data'));
 }
 public function test_noop_and_invalid_mutations_do_not_create_activity() {
  [$plan,$owner,$child,$body]=$this->fixture();
  $this->postJson('/api/v2/00000000/user/subscription/update',$body+['billing_period'=>'monthly'])->assertOk();
  $this->assertSame(0,SubscriptionActivity::count());
  $this->postJson('/api/v2/00000000/user/subscription/update',$body+['connection_limit'=>-1])->assertStatus(422);
  $this->assertSame(0,SubscriptionActivity::count());
 }
 public function test_main_user_editor_records_changes_and_customer_summary_is_filtered() {
  [$plan,$owner,$child]=$this->fixture();$child->update(['plan_id'=>null]);
  $this->postJson('/api/v2/00000000/user/update',['id'=>$owner->id,'transfer_enable'=>214748364800,'connection_limit'=>123456])->assertOk();
  $this->assertSame(1,SubscriptionActivity::count());
  $rows=$this->getJson('/api/v1/user/order/fetch')->assertOk()->json('data');
  $this->assertCount(1,$rows);$this->assertStringContainsString('200 GB',$rows[0]['activity_summary']);
  $this->assertStringNotContainsString('123456',$rows[0]['activity_summary']);
  $this->postJson('/api/v2/00000000/user/update',['id'=>$owner->id,'connection_limit'=>234567])->assertOk();
  $this->assertSame(2,SubscriptionActivity::count());
  $this->assertCount(1,$this->getJson('/api/v1/user/order/fetch')->assertOk()->json('data'));
 }

}

<?php
namespace Tests\Feature\Server;
use App\Models\{User,Plan,Order};
use App\Services\{MultiSubscriptionService,PackageBillingService,OrderService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache,DB,Queue};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PackageBillingTest extends TestCase {
 use RefreshDatabase;
 public function createApplication() {
  $app=parent::createApplication();$app->detectEnvironment(fn()=>'testing');
  $app['config']->set('app.env','testing');$app['config']->set('database.default','sqlite');
  $app['config']->set('database.connections.sqlite',['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);
  DB::purge('sqlite');Cache::setDefaultDriver('array');return $app;
 }
 private function fixture() {
  Queue::fake();
  $plan=Plan::create(['name'=>'Billing fixture','group_id'=>1,'transfer_enable'=>100,'prices'=>['monthly'=>40,'quarterly'=>110,'yearly'=>400,'reset_traffic'=>5],'show'=>true,'sell'=>true,'renew'=>true,'capacity_limit'=>null,'reset_traffic_method'=>1]);
  $owner=User::create(['email'=>'billing@example.invalid','password'=>'unused','uuid'=>'owner','token'=>'owner','is_admin'=>true,'plan_id'=>$plan->id,'group_id'=>1,'u'=>1,'d'=>2,'transfer_enable'=>107374182400,'expired_at'=>time()+86400,'balance'=>0]);
  $child=MultiSubscriptionService::createPackage($owner);
  $child->update(['plan_id'=>$plan->id,'group_id'=>1,'u'=>3,'d'=>4,'transfer_enable'=>107374182400,'expired_at'=>time()+86400]);
  Sanctum::actingAs($owner);return [$plan,$owner,$child];
 }
 private function order($owner,$target,$plan,$period,$amount=0) {
  return Order::create(['user_id'=>$owner->id,'subscription_user_id'=>$target->id,'subscription_action'=>'add','plan_id'=>$plan->id,'period'=>$period,'total_amount'=>$amount,'status'=>Order::STATUS_COMPLETED,'type'=>Order::TYPE_ADDITIONAL,'trade_no'=>bin2hex(random_bytes(12))]);
 }
 public function test_period_uses_only_target_history_and_never_guesses_from_expiry() {
  [$plan,$owner,$child]=$this->fixture();
  $this->assertNull(PackageBillingService::info($child)['billing_period']);
  $rootOrder=$this->order($owner,$owner,$plan,'monthly',4000);
  $this->order($owner,$child,$plan,'quarterly',0);
  $this->order($owner,$child,$plan,'reset_traffic',500);
  $this->assertSame('monthly',PackageBillingService::info($owner)['billing_period']);
  $info=PackageBillingService::info($child);
  $this->assertSame('quarterly',$info['billing_period']);$this->assertSame(11000,$info['billing_price']);
  $rootOrder->update(['subscription_user_id'=>null,'subscription_action'=>'auto']);
  $this->assertSame('monthly',PackageBillingService::info($owner)['billing_period']);
 }
 public function test_custom_cycle_price_is_targeted_and_real_renewal_matches_quote() {
  [$plan,$owner,$child]=$this->fixture();$rootBefore=$owner->fresh()->getAttributes();$expiry=$child->expired_at;
  $payload=['user_id'=>$owner->id,'subscription_user_id'=>$child->id,'billing_period'=>'quarterly','billing_price'=>8755];
  $this->postJson('/api/v2/00000000/user/subscription/update',$payload)->assertOk();
  $this->assertSame($expiry,$child->fresh()->expired_at);
  $this->assertSame($rootBefore,$owner->fresh()->getAttributes());
  $this->assertSame(110,$plan->fresh()->prices['quarterly']);
  $this->getJson('/api/v1/user/plan/fetch?id='.$plan->id.'&subscription_user_id='.$child->id)
   ->assertOk()->assertJsonPath('data.quarter_price',8755)->assertJsonPath('data.billing_period','quarter_price');
  $order=OrderService::createFromRequest($owner,$plan,'quarter_price',null,'renew',$child->id);
  $this->assertSame(8755,$order->total_amount);
  $order->update(['status'=>Order::STATUS_PROCESSING]);(new OrderService($order))->open();
  $this->assertSame('quarterly',$child->fresh()->billing_period);
  $this->assertSame(8755,$child->fresh()->billing_prices['quarterly']);
  $new=OrderService::createFromRequest($owner,$plan,'quarterly',null,'add');
  $this->assertSame(11000,$new->total_amount);
 }
 public function test_zero_price_restore_standard_and_invalid_prices() {
  [$plan,$owner,$child]=$this->fixture();$body=['user_id'=>$owner->id,'subscription_user_id'=>$child->id,'billing_period'=>'monthly'];
  $this->postJson('/api/v2/00000000/user/subscription/update',$body+['billing_price'=>0])->assertOk();
  $this->assertSame(0,PackageBillingService::info($child->fresh())['billing_price']);
  $order=OrderService::createFromRequest($owner,$plan,'monthly',null,'renew',$child->id);
  $this->assertSame(0,$order->total_amount);$order->update(['status'=>Order::STATUS_CANCELLED]);
  $this->postJson('/api/v2/00000000/user/subscription/update',$body+['billing_price'=>null])->assertOk();
  $this->assertSame(4000,PackageBillingService::info($child->fresh())['billing_price']);
  $plan->update(['prices'=>['monthly'=>45]]);
  $this->assertSame(4500,PackageBillingService::info($child->fresh())['billing_price']);
  foreach([-1,1.2,2147483648] as $bad)
   $this->postJson('/api/v2/00000000/user/subscription/update',$body+['billing_price'=>$bad])->assertStatus(422);
  $this->postJson('/api/v2/00000000/user/subscription/update',['user_id'=>$owner->id,'subscription_user_id'=>$child->id,'billing_period'=>'reset_traffic','billing_price'=>1])->assertStatus(422);
 }
 public function test_admin_custom_price_snapshot_is_applied_only_after_open() {
  [$plan,$owner,$child]=$this->fixture();
  $trade=$this->postJson('/api/v2/00000000/order/assign',['email'=>$owner->email,'plan_id'=>$plan->id,'period'=>'monthly','total_amount'=>3275,'renewal_price'=>3275,'subscription_action'=>'add'])->assertOk()->json('data');
  $order=Order::where('trade_no',$trade)->sole();$this->assertSame(3275,$order->renewal_price);
  $this->assertNull($child->fresh()->billing_prices);
  $order->update(['status'=>Order::STATUS_PROCESSING]);(new OrderService($order))->open();
  $target=User::findOrFail($order->fresh()->subscription_user_id);
  $this->assertNotSame($child->id,$target->id);
  $this->assertSame('monthly',$target->billing_period);$this->assertSame(3275,$target->billing_prices['monthly']);
  $this->assertNull($owner->fresh()->billing_prices);$this->assertNull($child->fresh()->billing_prices);
 }
 public function test_other_accounts_cannot_read_or_change_package_prices() {
  [$plan,$owner,$child]=$this->fixture();
  $other=User::create(['email'=>'foreign@example.invalid','password'=>'unused','uuid'=>'other','token'=>'other']);
  Sanctum::actingAs($other);
  $this->getJson('/api/v1/user/plan/fetch?id='.$plan->id.'&subscription_user_id='.$child->id)->assertStatus(422);
  $this->postJson('/api/v2/00000000/user/subscription/update',['user_id'=>$owner->id,'subscription_user_id'=>$child->id,'billing_period'=>'monthly','billing_price'=>0])->assertStatus(403);
 }
}

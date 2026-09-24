<?php
namespace Tests\Feature;

use App\Models\{Server, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Queue};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseSourceTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app->detectEnvironment(fn () => 'testing');
        $app['config']->set('app.env', 'testing');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        Cache::setDefaultDriver('array');
        return $app;
    }

    private function fixture(): array {
        Queue::fake();
        $plan=\App\Models\Plan::create(['capacity_limit'=>null,'name'=>'套餐 A','group_id'=>7,'transfer_enable'=>400,'prices'=>['monthly'=>40], 'show'=>true,'sell'=>true,'renew'=>true]);
        $root=User::create(['email'=>'buyer@example.invalid','password'=>'unused','uuid'=>'buyer','token'=>'buyer','plan_id'=>$plan->id,'group_id'=>7,'transfer_enable'=>400*1073741824,'expired_at'=>time()+86400,'billing_prices'=>['monthly'=>1500],'billing_period'=>'monthly']);
        $child=\App\Services\MultiSubscriptionService::createPackage($root);
        $child->update(['plan_id'=>$plan->id,'group_id'=>7,'transfer_enable'=>400*1073741824,'expired_at'=>time()+2*86400,'billing_prices'=>['monthly'=>2200],'billing_period'=>'monthly']);
        Sanctum::actingAs($root);
        return [$plan,$root,$child];
    }
    public function test_quotes_and_orders_use_the_same_entry_price_and_persist_it(): void {
        [$plan,$root,$child]=$this->fixture();
        foreach ([['shop','add',null,4000],['shop','renew',$root->id,4000],['shop','renew',$child->id,4000],['package','renew',$root->id,1500],['package','renew',$child->id,2200]] as [$source,$action,$target,$expected]) {
            $payload=['plan_id'=>$plan->id,'period'=>'month_price','subscription_action'=>$action,'purchase_source'=>$source];
            if ($target) $payload['subscription_user_id']=$target;
            $quote=$this->getJson('/api/v1/user/plan/fetch?'.http_build_query(['id'=>$plan->id]+$payload))->assertOk()->json('data');
            $this->assertEquals($expected,$quote['month_price']);
            // A client-supplied amount must not control the order price.
            $trade=$this->postJson('/api/v1/user/order/save',$payload+['total_amount'=>1])->assertOk()->json('data');
            $order=\App\Models\Order::where('trade_no',$trade)->sole();
            $this->assertEquals($expected,$order->total_amount);
            $this->assertSame($expected,$order->quoted_price);
            if ($target) $this->assertSame(User::find($target)->expired_at,$order->subscription_expired_at_before);
            $this->assertSame($source,$order->purchase_source);
            $detail=$this->getJson('/api/v1/user/order/detail?trade_no='.$trade)->assertOk()->json('data');
            $this->assertSame($source,$detail['purchase_source']);
            $this->assertSame($expected,$detail['quoted_price']);
            $order->update(['status'=>2]);
        }
        $this->getJson('/api/v1/user/order/fetch')->assertOk()->assertJsonCount(5,'data');
    }
    public function test_shop_renewal_extends_only_selected_package_and_preserves_admin_price(): void {
        [$plan,$root,$child]=$this->fixture();$expiry=$root->expired_at;$childExpiry=$child->expired_at;
        $order=\App\Services\OrderService::createFromRequest($root,$plan,'monthly',null,'renew',$child->id,'shop');
        $order->update(['status'=>1]);(new \App\Services\OrderService($order))->open();
        $this->assertSame($expiry,$root->fresh()->expired_at);
        $this->assertGreaterThan($childExpiry,$child->fresh()->expired_at);
        $this->assertSame(['monthly'=>2200],$child->fresh()->billing_prices);
        $this->assertSame(4000,$order->fresh()->quoted_price);
        $next=\App\Services\OrderService::createFromRequest($root->fresh(),$plan,'monthly',null,'renew',$child->id,'package');
        $this->assertEquals(2200,$next->total_amount);
    }
    public function test_new_same_plan_keeps_existing_packages_and_uses_catalog_price(): void {
        [$plan,$root,$child]=$this->fixture();$expiry=$root->expired_at;$childExpiry=$child->expired_at;
        $order=\App\Services\OrderService::createFromRequest($root,$plan,'monthly',null,'add',null,'shop');
        $order->update(['status'=>1]);(new \App\Services\OrderService($order))->open();
        $this->assertSame($expiry,$root->fresh()->expired_at);$this->assertSame($childExpiry,$child->fresh()->expired_at);
        $this->assertSame(2,User::where('parent_id',$root->id)->count());
        $new=User::findOrFail($order->fresh()->subscription_user_id);$this->assertNull($new->billing_prices);
        $this->assertSame(4000,$order->fresh()->quoted_price);
    }
    public function test_foreign_or_missing_targets_and_invalid_sources_are_rejected(): void {
        [$plan,$root,$child]=$this->fixture();
        $foreign=User::create(['email'=>'other@example.invalid','password'=>'unused','uuid'=>'other','token'=>'other','plan_id'=>$plan->id]);
        $base=['plan_id'=>$plan->id,'period'=>'month_price','subscription_action'=>'renew','purchase_source'=>'shop'];
        $this->postJson('/api/v1/user/order/save',$base+['subscription_user_id'=>$foreign->id])->assertStatus(400);
        $this->postJson('/api/v1/user/order/save',$base)->assertStatus(400);
        $this->getJson('/api/v1/user/plan/fetch?'.http_build_query(['id'=>$plan->id]+$base+['subscription_user_id'=>$foreign->id]))->assertStatus(422);
        $this->postJson('/api/v1/user/order/save',array_replace($base,['purchase_source'=>'invalid','subscription_user_id'=>$child->id]))->assertStatus(422);
        $this->assertSame(0,\App\Models\Order::count());
    }
}

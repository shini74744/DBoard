<?php
namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Http\Resources\PlanResource;
use App\Jobs\OrderHandleJob;
use App\Models\{Order, Plan, User};
use App\Services\{OrderService, PlanService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Bus, Cache, DB, Queue};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderStockReservationTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app->detectEnvironment(fn () => 'testing');
        $app['config']->set('app.env', 'testing');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>'', 'foreign_key_constraints'=>true,
        ]);
        DB::purge('sqlite');
        Cache::setDefaultDriver('array');
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Bus::fake([OrderHandleJob::class]);
    }

    private function plan(array $extra=[]): Plan
    {
        return Plan::create($extra + [
            'name'=>'Reservation', 'group_id'=>7, 'transfer_enable'=>100,
            'prices'=>['monthly'=>40,'reset_traffic'=>5,'onetime'=>50],
            'show'=>true,'sell'=>true,'renew'=>true,'capacity_limit'=>1,
        ]);
    }

    private function buyer(array $extra=[]): User
    {
        $key=bin2hex(random_bytes(8));
        return User::create($extra+[
            'email'=>$key.'@example.invalid','password'=>'unused','uuid'=>$key,'token'=>$key,
            'expired_at'=>0,'transfer_enable'=>0,'balance'=>0,
        ]);
    }

    private function add(User $buyer, Plan $plan): Order
    {
        return OrderService::createFromRequest($buyer,$plan,'monthly',null,'add',null,'shop')->fresh();
    }

    private function expire(Order $order): Order
    {
        $order->update(['created_at'=>time()-Order::PAYMENT_TIMEOUT_SECONDS-1]);
        return $order->fresh();
    }

    public function test_pending_order_holds_last_slot_and_rejects_another_buyer(): void
    {
        $plan=$this->plan(); $first=$this->buyer(); $second=$this->buyer();
        $order=$this->add($first,$plan);
        $this->assertSame(1,PlanService::reservedCapacity($plan));
        $this->assertSame(0,PlanService::remainingCapacity($plan));
        $this->assertSame(0,$plan->users()->count());
        $resource=(new PlanResource($plan))->toArray(request());
        $this->assertSame(0,$resource['capacity_remaining']);
        Sanctum::actingAs($second);
        $this->postJson('/api/v1/user/order/save',[
            'plan_id'=>$plan->id,'period'=>'month_price','subscription_action'=>'add','purchase_source'=>'shop',
        ])->assertStatus(400);
        $this->assertSame(1,Order::count());
        $this->assertTrue((new OrderService($order))->cancel());
        $this->assertSame(1,PlanService::remainingCapacity($plan));
        $this->assertFalse((new OrderService($order))->cancel());
        $this->assertSame(1,PlanService::remainingCapacity($plan));
        $this->add($second,$plan);
        $this->assertSame(0,PlanService::remainingCapacity($plan));
    }

    public function test_timeout_releases_stock_before_worker_and_refunds_balance_once(): void
    {
        $plan=$this->plan();$buyer=$this->buyer(['balance'=>1000]);
        $order=$this->add($buyer,$plan);
        $this->assertEquals(1000,$order->balance_amount);
        $this->assertEquals(0,$buyer->fresh()->balance);
        $this->assertFalse((new OrderService($order))->cancel(onlyExpired:true));
        $order=$this->expire($order);
        $this->assertSame(1,PlanService::remainingCapacity($plan));
        $next=$this->add($this->buyer(),$plan);
        (new OrderHandleJob($order->trade_no))->handle();
        (new OrderHandleJob($order->trade_no))->handle();
        $this->assertEquals(Order::STATUS_CANCELLED,$order->fresh()->status);
        $this->assertEquals(1000,$buyer->fresh()->balance);
        $this->assertSame(0,PlanService::remainingCapacity($plan));
        $this->assertFalse((new OrderService($order))->paid('late-payment'));
        $this->assertEquals(Order::STATUS_PENDING,$next->fresh()->status);
    }

    public function test_paid_processing_and_activation_use_one_slot_and_are_idempotent(): void
    {
        $plan=$this->plan();$buyer=$this->buyer();$order=$this->add($buyer,$plan);
        $this->assertTrue((new OrderService($order))->paid('paid-stock'));
        $this->assertEquals(Order::STATUS_PROCESSING,$order->fresh()->status);
        $this->assertSame(0,PlanService::remainingCapacity($plan));
        $this->expire($order);
        $this->assertSame(1,PlanService::reservedCapacity($plan));
        $this->assertFalse((new OrderService($order))->cancel());
        (new OrderHandleJob($order->trade_no))->handle();
        $this->assertEquals(Order::STATUS_COMPLETED,$order->fresh()->status);
        $this->assertSame(0,PlanService::reservedCapacity($plan));
        $this->assertSame(0,PlanService::remainingCapacity($plan));
        $this->assertSame(1,$plan->users()->count());
        $expiry=$buyer->fresh()->expired_at;
        $this->assertTrue((new OrderService($order))->paid('duplicate-payment'));
        (new OrderHandleJob($order->trade_no))->handle();
        $this->assertSame($expiry,$buyer->fresh()->expired_at);
        $this->assertSame(1,$plan->users()->count());
    }

    public function test_active_renewal_and_reset_do_not_take_extra_stock(): void
    {
        $plan=$this->plan();
        $buyer=$this->buyer(['plan_id'=>$plan->id,'group_id'=>7,'transfer_enable'=>1000,'expired_at'=>time()+3600]);
        $renew=OrderService::createFromRequest($buyer,$plan,'monthly',null,'renew',$buyer->id,'shop')->fresh();
        $this->assertSame(0,PlanService::reservedCapacity($plan));
        $this->assertSame(0,PlanService::remainingCapacity($plan));
        // The same slot stays occupied if the package expires while payment is pending.
        $buyer->update(['expired_at'=>time()-1]);
        $this->assertSame(1,PlanService::reservedCapacity($plan));
        $this->assertSame(0,PlanService::remainingCapacity($plan));
        (new OrderService($renew))->cancel();
        $this->assertSame(1,PlanService::remainingCapacity($plan));
        $buyer->update(['expired_at'=>time()+3600]);
        $reset=OrderService::createFromRequest($buyer,$plan,'reset_traffic',null,'renew',$buyer->id,'package')->fresh();
        $this->assertSame(0,PlanService::reservedCapacity($plan));
        (new OrderService($reset))->cancel();
        $this->assertSame(0,PlanService::remainingCapacity($plan));
    }

    public function test_expired_renewal_reserves_stock_and_cannot_bypass_sold_out(): void
    {
        $plan=$this->plan();$buyer=$this->buyer(['plan_id'=>$plan->id,'expired_at'=>time()-1]);
        $renew=OrderService::createFromRequest($buyer,$plan,'monthly',null,'renew',$buyer->id,'package')->fresh();
        $this->assertSame(1,PlanService::reservedCapacity($plan));
        (new OrderService($renew))->cancel();
        $this->add($this->buyer(),$plan);
        $this->expectException(ApiException::class);
        OrderService::createFromRequest($buyer,$plan,'monthly',null,'renew',$buyer->id,'shop');
    }

    public function test_expired_checkout_and_late_callback_cannot_steal_reallocated_slot(): void
    {
        $plan=$this->plan();$buyer=$this->buyer();$old=$this->expire($this->add($buyer,$plan));
        $this->add($this->buyer(),$plan);
        $this->assertFalse((new OrderService($old))->paid('late-notification'));
        $this->assertSame(0,$plan->users()->count());
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/user/order/checkout',['trade_no'=>$old->trade_no])->assertStatus(400);
        $this->assertEquals(Order::STATUS_CANCELLED,$old->fresh()->status);
        $this->assertSame(0,PlanService::remainingCapacity($plan));
    }

    public function test_cancelled_and_completed_history_never_reserve_stock(): void
    {
        $plan=$this->plan(['capacity_limit'=>3]);$buyer=$this->buyer();
        foreach ([Order::STATUS_CANCELLED,Order::STATUS_COMPLETED,Order::STATUS_DISCOUNTED] as $status) {
            $order=$this->add($buyer,$plan);$order->update(['status'=>$status]);
        }
        $this->assertSame(0,PlanService::reservedCapacity($plan));
        $this->assertSame(3,PlanService::remainingCapacity($plan));
        $unlimited=$this->plan(['capacity_limit'=>null]);
        $this->add($buyer,$unlimited);
        $this->assertNull(PlanService::remainingCapacity($unlimited));
    }

    public function test_multiple_new_packages_each_reserve_one_and_preserve_existing_package(): void
    {
        $plan=$this->plan(['capacity_limit'=>3]);
        $buyer=$this->buyer(['plan_id'=>$plan->id,'group_id'=>7,'transfer_enable'=>1000,'expired_at'=>time()+3600]);
        $first=$this->add($buyer,$plan);$second=$this->add($buyer,$plan);
        $this->assertSame(2,PlanService::reservedCapacity($plan));
        $this->assertSame(0,PlanService::remainingCapacity($plan));
        $expiry=$buyer->expired_at;
        (new OrderService($first))->paid('first');
        (new OrderHandleJob($first->trade_no))->handle();
        $this->assertSame(1,PlanService::reservedCapacity($plan));
        $this->assertSame(0,PlanService::remainingCapacity($plan));
        $this->assertSame($expiry,$buyer->fresh()->expired_at);
        (new OrderService($second))->cancel();
        $this->assertSame(1,PlanService::remainingCapacity($plan));
        $this->assertSame(2,$plan->users()->count());
    }
}

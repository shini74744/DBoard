<?php
namespace Tests\Feature;

use App\Models\{Server, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Queue};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanStockModeTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Sanctum::actingAs(User::create([
            'email' => 'node-group@example.invalid', 'password' => 'unused',
            'uuid' => 'fixture', 'token' => 'fixture', 'is_admin' => true,
        ]));
    }


    private function plan(array $extra=[]): \App\Models\Plan
    {
        return \App\Models\Plan::create($extra+['name'=>'Stock','group_id'=>7,'transfer_enable'=>400,'prices'=>['monthly'=>40],
            'show'=>true,'sell'=>true,'renew'=>true,'capacity_limit'=>5,'device_limit'=>3]);
    }
    private function subscriber($plan, string $name, array $extra=[]): User
    {
        return User::create($extra+['email'=>$name.'@example.invalid','password'=>'unused','uuid'=>$name,'token'=>$name,
            'plan_id'=>$plan->id,'group_id'=>7,'transfer_enable'=>1000,'expired_at'=>null]);
    }
    public function test_default_mode_and_remaining_count_follow_purchase_capacity(): void
    {
        $plan=$this->plan()->fresh();$this->assertSame('status',$plan->capacity_display_mode);
        $root=$this->subscriber($plan,'root');$this->subscriber($plan,'package',['parent_id'=>$root->id]);
        $this->subscriber($plan,'timed',['expired_at'=>time()+3600]);
        $this->subscriber($plan,'expired',['expired_at'=>time()-100]);
        $other=$this->plan();$this->subscriber($other,'other');
        $resource=(new \App\Http\Resources\PlanResource($plan))->toArray(request());
        $this->assertSame(5,$resource['capacity_limit']);$this->assertSame(2,$resource['capacity_remaining']);
        $this->assertSame('status',$resource['capacity_display_mode']);
        $this->assertTrue((new \App\Services\PlanService($plan))->hasCapacity($plan));
        $plan->update(['capacity_display_mode'=>'remaining']);
        $resource=(new \App\Http\Resources\PlanResource($plan->fresh()))->toArray(request());
        $this->assertSame('remaining',$resource['capacity_display_mode']);$this->assertSame(2,$resource['capacity_remaining']);
        $rows=$this->getJson('/api/v1/user/plan/fetch')->assertOk()->json('data');
        $row=collect($rows)->firstWhere('id',$plan->id);$this->assertSame(2,$row['capacity_remaining']);
        $this->assertSame('remaining',$row['capacity_display_mode']);
    }
    public function test_unlimited_full_and_over_capacity_do_not_change_sale_rules(): void
    {
        $unlimited=$this->plan(['capacity_limit'=>null,'capacity_display_mode'=>'remaining']);
        $this->assertNull(\App\Services\PlanService::remainingCapacity($unlimited));
        $plan=$this->plan(['capacity_limit'=>1,'capacity_display_mode'=>'remaining']);
        $root=$this->subscriber($plan,'a');$this->subscriber($plan,'b');
        foreach(['remaining','status'] as $mode){
            $plan->update(['capacity_display_mode'=>$mode]);
            $this->assertSame(0,\App\Services\PlanService::remainingCapacity($plan));
            $service=new \App\Services\PlanService($plan);
            $this->assertFalse($service->hasCapacity($plan));
            $this->assertFalse($service->getAvailablePlans()->contains('id',$plan->id));
            $this->assertTrue($service->isPlanAvailableForUser($plan,$root));
        }
    }
    public function test_admin_can_save_and_reload_modes_and_invalid_mode_is_rejected(): void
    {
        $plan=$this->plan();$payload=['id'=>$plan->id,'name'=>'Stock','transfer_enable'=>400,'prices'=>['monthly'=>40],'capacity_limit'=>5];
        foreach(['remaining','status'] as $mode){
            $this->postJson('/api/v2/00000000/plan/save',$payload+['capacity_display_mode'=>$mode])->assertOk();
            $this->assertSame($mode,$plan->fresh()->capacity_display_mode);
            $rows=$this->getJson('/api/v2/00000000/plan/fetch')->assertOk()->json('data');
            $this->assertSame($mode,collect($rows)->firstWhere('id',$plan->id)['capacity_display_mode']);
        }
        $this->postJson('/api/v2/00000000/plan/save',$payload+['capacity_display_mode'=>'invalid'])->assertStatus(422);
        $this->assertSame('status',$plan->fresh()->capacity_display_mode);
        $plan->update(['capacity_display_mode'=>'remaining']);
        $this->postJson('/api/v2/00000000/plan/save',$payload)->assertOk();
        $this->assertSame('remaining',$plan->fresh()->capacity_display_mode);
    }
}

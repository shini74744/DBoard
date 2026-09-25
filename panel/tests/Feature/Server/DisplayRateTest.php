<?php
namespace Tests\Feature\Server;

use App\Models\{Server, User};
use App\Services\{ServerService, UserService};
use App\Jobs\TrafficFetchJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Queue, Redis};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DisplayRateTest extends TestCase
{
    use RefreshDatabase;
    public function createApplication()
    {
        $app = parent::createApplication();
        $app->detectEnvironment(fn () => 'testing');
        $app['config']->set('app.env', 'testing');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', ['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);
        DB::purge('sqlite');
        Cache::setDefaultDriver('array');
        return $app;
    }
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Sanctum::actingAs(User::create(['email'=>'display-admin@example.invalid','password'=>'unused','uuid'=>'fixture','token'=>'fixture','is_admin'=>true]));
    }
    private function payload(): array
    {
        return ['name'=>'Display fixture','type'=>'vless','host'=>'example.invalid','port'=>'443','server_port'=>443,'rate'=>1.05,'group_ids'=>['9'],'show'=>true,'protocol_settings'=>['network'=>'tcp','tls'=>0]];
    }
    public function test_admin_can_save_read_preserve_and_clear_display_rate(): void
    {
        $url='/api/v2/00000000/server/manage/save';
        $this->postJson($url,$this->payload()+['display_rate'=>1])->assertOk();
        $node=Server::where('name','Display fixture')->firstOrFail();
        $this->assertSame(1.0,$node->display_rate);
        $this->assertEquals(1.05,$node->rate);
        $this->getJson('/api/v2/00000000/server/manage/getNodes')->assertOk()->assertJsonFragment(['display_rate'=>1]);
        $this->postJson($url,$this->payload()+['id'=>$node->id])->assertOk();
        $this->assertSame(1.0,$node->fresh()->display_rate);
        $this->postJson($url,$this->payload()+['id'=>$node->id,'display_rate'=>''])->assertOk();
        $this->assertNull($node->fresh()->display_rate);
        $this->assertSame(1.05,$node->fresh()->getDisplayRate());
        $this->postJson($url,$this->payload()+['id'=>$node->id,'display_rate'=>0])->assertOk();
        $this->assertSame(0.0,$node->fresh()->getDisplayRate());
        foreach([-1,1000001,'invalid',1.12345] as $invalid) {
            $this->assertNotEquals(200,$this->postJson($url,$this->payload()+['id'=>$node->id,'display_rate'=>$invalid])->status());
        }
        $this->assertSame(0.0,$node->fresh()->display_rate);
    }
    public function test_presentation_override_does_not_change_billing_and_null_follows_dynamic_rate(): void
    {
        $user=User::create(['email'=>'display-customer@example.invalid','password'=>'unused','uuid'=>'customer','token'=>'customer','group_id'=>9,'transfer_enable'=>1073741824,'expired_at'=>time()+86400,'u'=>0,'d'=>0]);
        $node=Server::create($this->payload()+['display_rate'=>1]);
        $this->assertEquals(1,ServerService::getAvailableServers($user)[0]['rate']);
        $this->assertEquals(1.05,$node->fresh()->rate);
        Redis::shouldReceive('sadd')->twice()->with('traffic:pending_check',$user->id)->andReturn(1);
        app(UserService::class)->trafficFetch($node->fresh(),'vless',[$user->id=>[10000,20000]]);
        Queue::assertPushed(TrafficFetchJob::class,function($job){$job->handle();return true;});
        $this->assertEquals(10500,$user->fresh()->u);
        $this->assertEquals(21000,$user->fresh()->d);
        Queue::fake();
        $node->update(['rate_time_enable'=>true,'rate_time_ranges'=>[['start'=>'00:00','end'=>'23:59','rate'=>2.5]]]);
        $this->assertEquals(1,ServerService::getAvailableServers($user)[0]['rate']);
        $this->assertSame(2.5,$node->fresh()->getCurrentRate());
        app(UserService::class)->trafficFetch($node->fresh(),'vless',[$user->id=>[10000,20000]]);
        Queue::assertPushed(TrafficFetchJob::class,function($job){$job->handle();return true;});
        $this->assertEquals(35500,$user->fresh()->u);
        $this->assertEquals(71000,$user->fresh()->d);
        $node->update(['display_rate'=>null]);
        $this->assertEquals(2.5,ServerService::getAvailableServers($user)[0]['rate']);
        $this->assertEquals(1.05,$node->fresh()->rate);
    }
    public function test_customer_api_and_cache_refresh_use_the_display_rate(): void
    {
        $user=User::create(['email'=>'display-api@example.invalid','password'=>'unused','uuid'=>'api-user','token'=>'api-user','subscription_link_mode'=>'single','group_id'=>9,'transfer_enable'=>1073741824,'expired_at'=>time()+86400]);
        $node=Server::create($this->payload()+['display_rate'=>1]);
        Sanctum::actingAs($user);
        $first=$this->getJson('/api/v1/user/server/fetch')->assertOk()->assertJsonPath('data.0.rate',1);
        $etag=$first->headers->get('ETag');
        $node->update(['display_rate'=>2]);
        $second=$this->withHeader('If-None-Match',$etag)->getJson('/api/v1/user/server/fetch')->assertOk()->assertJsonPath('data.0.rate',2);
        $this->assertNotSame($etag,$second->headers->get('ETag'));
        $plan=\App\Models\Plan::create(['name'=>'Display Plan','group_id'=>9,'transfer_enable'=>100,'prices'=>['monthly'=>10],'show'=>true,'sell'=>true,'renew'=>true]);
        $user->update(['plan_id'=>$plan->id,'subscription_link_mode'=>'merged']);
        $this->assertEquals(2,\App\Services\MultiSubscriptionService::mergedServers($user->fresh())[0]['rate']);
        $this->withHeader('If-None-Match','')->getJson('/api/v1/user/server/fetch')->assertOk()->assertJsonPath('data.0.rate',2);

        $this->assertEquals(1.05,$node->fresh()->rate);
    }

}

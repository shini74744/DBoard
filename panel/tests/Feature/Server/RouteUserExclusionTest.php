<?php
namespace Tests\Feature\Server;

use App\Models\{Server, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Queue};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RouteUserExclusionTest extends TestCase
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


    private function identity(string $name, ?int $parent = null): User
    {
        return User::create(['email'=>$name.'@example.invalid','password'=>'unused','uuid'=>$name,'token'=>$name,
            'group_id'=>7,'parent_id'=>$parent,'transfer_enable'=>1000,'expired_at'=>null,'u'=>0,'d'=>0,'banned'=>false]);
    }

    private function rule(array $include=[], array $exclude=[]): array
    {
        return ['match'=>['user_ids'=>$include,'excluded_user_ids'=>$exclude,'domain_suffixes'=>['example.com']],
            'action'=>['type'=>'direct']];
    }

    public function test_all_except_account_excludes_all_its_packages_and_preserves_later_rules(): void
    {
        $skip=$this->identity('skip'); $child=$this->identity('skip-package',$skip->id);
        $keep=$this->identity('keep'); $keepChild=$this->identity('keep-package',$keep->id);
        $later=['match'=>[], 'action'=>['type'=>'block']];
        $rules=\App\Services\ServerService::expandAccountRouteRules([$this->rule([],[$skip->id]),$later]);
        $ids=$rules[0]['match']['user_ids'];
        $this->assertNotContains($skip->id,$ids); $this->assertNotContains($child->id,$ids);
        $this->assertContains($keep->id,$ids); $this->assertContains($keepChild->id,$ids);
        $this->assertSame(['example.com'],$rules[0]['match']['domain_suffixes']);
        $this->assertArrayNotHasKey('excluded_user_ids',$rules[0]['match']);
        $this->assertSame($later,$rules[1]);
        $new=$this->identity('future-package',$keep->id);
        $newSkip=$this->identity('future-skip-package',$skip->id);
        $refreshed=\App\Services\ServerService::expandAccountRouteRules([$this->rule([],[$skip->id])]);
        $this->assertContains($new->id,$refreshed[0]['match']['user_ids']);
        $this->assertNotContains($newSkip->id,$refreshed[0]['match']['user_ids']);
    }

    public function test_exclusion_wins_and_empty_result_never_becomes_wildcard(): void
    {
        $a=$this->identity('a');$child=$this->identity('a-package',$a->id);$b=$this->identity('b');
        $rules=\App\Services\ServerService::expandAccountRouteRules([$this->rule([$a->id,$b->id],[$a->id])]);
        $this->assertSame([$b->id],$rules[0]['match']['user_ids']);
        $this->assertSame([],\App\Services\ServerService::expandAccountRouteRules([$this->rule([$a->id],[$a->id])]));
        $this->assertSame([],\App\Services\ServerService::expandAccountRouteRules([$this->rule([],User::pluck('id')->all())]));
        $this->assertSame([],\App\Services\ServerService::compatibleRouteRules([$this->rule([],[$a->id])],false));
    }

    public function test_large_complement_is_chunked_without_losing_matchers_or_order(): void
    {
        $skip=$this->identity('skip');
        User::withoutEvents(function () {for($i=0;$i<105;$i++) $this->identity('bulk'.$i);});
        $rule=$this->rule([],[$skip->id]);$rule['match']['networks']=['tcp'];
        $rules=\App\Services\ServerService::expandAccountRouteRules([$rule]);
        $this->assertCount(2,$rules);
        $ids=[];foreach($rules as $row){$this->assertLessThanOrEqual(100,count($row['match']['user_ids']));$this->assertSame(['tcp'],$row['match']['networks']);$ids=array_merge($ids,$row['match']['user_ids']);}
        $this->assertEqualsCanonicalizing(User::where('id','!=',$skip->id)->pluck('id')->all(),$ids);
    }

    private function node(): Server
    {
        return Server::create(['name'=>'route','type'=>'vless','host'=>'example.invalid','port'=>'443','server_port'=>443,
            'rate'=>1,'group_ids'=>['7'],'show'=>false,'protocol_settings'=>['network'=>'tcp','tls'=>0]]);
    }

    public function test_admin_save_round_trip_and_old_node_protection(): void
    {
        $node=$this->node();$skip=$this->identity('skip');
        $payload=['id'=>$node->id,'name'=>'route','type'=>'vless','host'=>'example.invalid','port'=>'443','server_port'=>443,
            'rate'=>1,'group_ids'=>['7'],'show'=>false,'protocol_settings'=>['network'=>'tcp','tls'=>0],
            'custom_route_rules'=>[$this->rule([],[$skip->id])]];
        $url='/api/v2/00000000/server/manage/save';
        $this->assertNotSame(200,$this->postJson($url,$payload)->status());
        Cache::put('dboard_user_routes_capable:'.$node->id,true,600);
        $this->postJson($url,$payload)->assertOk();
        $this->assertSame([$skip->id],$node->fresh()->custom_route_rules[0]['match']['excluded_user_ids']);
        $fetched=$this->getJson('/api/v2/00000000/server/manage/getNodes')->assertOk()->json('data');
        $this->assertSame([$skip->id],$fetched[0]['custom_route_rules'][0]['match']['excluded_user_ids']);
        $payload['custom_route_rules'][0]['match']['excluded_user_ids']=[999999];
        $this->assertNotSame(200,$this->postJson($url,$payload)->status());
    }

    public function test_new_identity_refreshes_exclusion_config_before_credentials(): void
    {
        $node=$this->node();$skip=$this->identity('skip');$new=$this->identity('new');
        $node->update(['custom_route_rules'=>[$this->rule([],[$skip->id])]]);
        Cache::put('dboard_user_routes_capable:'.$node->id,true,600);
        Cache::put('node_ws_alive:'.$node->id,true,600);
        $events=[];
        \Illuminate\Support\Facades\Redis::shouldReceive('publish')->andReturnUsing(function($channel,$message)use(&$events){$events[]=json_decode($message,true);return 1;});
        (new \App\Jobs\NodeUserSyncJob($new->id,'created'))->handle();
        $this->assertSame(['sync.config','sync.user.delta'],array_column($events,'event'));
        $ids=$events[0]['data']['config']['custom_route_rules'][0]['match']['user_ids'];
        $this->assertContains($new->id,$ids);$this->assertNotContains($skip->id,$ids);
        $events=[];
        (new \App\Jobs\NodeUserSyncJob($new->id,'updated'))->handle();
        $this->assertSame(['sync.user.delta'],array_column($events,'event'));
    }
}

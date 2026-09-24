<?php
namespace Tests\Feature\Server;

use App\Models\{Server, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Queue};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroupDisplayIdTest extends TestCase
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


    private function group(string $name): \App\Models\ServerGroup {
        $group=new \App\Models\ServerGroup();$group->name=$name;$group->save();return $group;
    }
    public function test_renumber_persists_without_changing_any_bindings(): void {
        $a=$this->group('A');$b=$this->group('B');$c=$this->group('C');
        $account=User::create(['email'=>'bound@example.invalid','password'=>'unused','uuid'=>'bound','token'=>'bound','group_id'=>$a->id]);
        $node=Server::create(['name'=>'bound','type'=>'vless','host'=>'example.invalid','port'=>'443','server_port'=>443,'rate'=>1,'group_ids'=>[(string)$a->id,(string)$b->id],'protocol_settings'=>[]]);
        $plan=new \App\Models\Plan();$plan->name='bound';$plan->group_id=$a->id;$plan->save();
        $this->postJson('/api/v2/00000000/server/group/sort',['ids'=>[$c->id,$a->id,$b->id],'renumber'=>true])->assertOk();
        $this->assertSame(1,$c->fresh()->display_id);$this->assertSame(2,$a->fresh()->display_id);$this->assertSame(3,$b->fresh()->display_id);
        $this->assertEquals($a->id,$account->fresh()->group_id);$this->assertEquals($a->id,$plan->fresh()->group_id);
        $this->assertEquals([(string)$a->id,(string)$b->id],$node->fresh()->group_ids);
        $this->assertSame('A',$a->fresh()->name);
        $rows=$this->getJson('/api/v2/00000000/server/group/fetch')->assertOk()->json('data');
        $this->assertSame([$c->id,$a->id,$b->id],array_column($rows,'id'));$this->assertSame([1,2,3],array_column($rows,'display_id'));
        $this->postJson('/api/v2/00000000/server/group/sort',['ids'=>[$a->id,$b->id,$c->id],'renumber'=>false])->assertOk();
        $this->assertSame(2,$a->fresh()->display_id);$this->assertSame(1,$c->fresh()->display_id);
        $next=$this->group('Next');$this->assertGreaterThan(3,$next->display_id);
    }
    public function test_stale_duplicate_and_invalid_requests_cannot_partially_renumber(): void {
        $a=$this->group('A');$b=$this->group('B');$url='/api/v2/00000000/server/group/sort';
        foreach([['ids'=>[$a->id]],['ids'=>[$a->id,$a->id]],['ids'=>[$a->id,$b->id],'renumber'=>'wrong']] as $data){
            $response=$this->postJson($url,$data+['renumber'=>true]);$this->assertNotEquals(200,$response->status());
            $this->assertSame(1,$a->fresh()->display_id);$this->assertSame(2,$b->fresh()->display_id);
        }
        $user=User::create(['email'=>'ordinary@example.invalid','password'=>'unused','uuid'=>'ordinary','token'=>'ordinary','is_admin'=>false]);
        Sanctum::actingAs($user);$this->postJson($url,['ids'=>[$b->id,$a->id],'renumber'=>true])->assertForbidden();
    }
}

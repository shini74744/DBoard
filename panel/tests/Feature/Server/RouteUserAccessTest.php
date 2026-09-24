<?php
namespace Tests\Feature\Server;

use App\Models\{Server, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Queue};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RouteUserAccessTest extends TestCase
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


    public function test_picker_uses_effective_node_access_and_collapses_package_users(): void
    {
        $node=Server::create(['name'=>'route','type'=>'vless','host'=>'example.invalid','port'=>'443','server_port'=>443,'rate'=>1,'group_ids'=>[7],'show'=>false,'protocol_settings'=>[]]);
        $make=fn($email,$extra=[])=>User::create($extra+['email'=>$email,'password'=>'unused','uuid'=>$email,'token'=>$email,'group_id'=>7,'transfer_enable'=>1000,'expired_at'=>null,'u'=>0,'d'=>0,'banned'=>false]);
        $a=$make('direct@example.invalid');
        $parent=$make('multi@example.invalid',['group_id'=>9,'transfer_enable'=>0]);
        $make('child1@example.invalid',['parent_id'=>$parent->id]);
        $make('child2@example.invalid',['parent_id'=>$parent->id]);
        $outside=$make('outside@example.invalid',['group_id'=>9]);
        $make('expired@example.invalid',['expired_at'=>time()-10]);
        $make('exhausted@example.invalid',['u'=>1000]);
        $make('banned@example.invalid',['banned'=>true]);
        $bannedParent=$make('banned-parent@example.invalid',['group_id'=>9,'banned'=>true]);
        $make('banned-child@example.invalid',['parent_id'=>$bannedParent->id]);
        $url='/api/v2/00000000/server/route/users?source_id='.$node->id;
        $rows=$this->getJson($url)->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$a->id,$parent->id],array_column($rows,'id'));
        $this->assertSame([true,true],array_column($rows,'available'));
        $this->getJson($url.'&search=multi')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$parent->id);
        $this->getJson($url.'&search='.$a->id)->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$a->id);
        $this->getJson($url.'&search=outside')->assertOk()->assertJsonCount(0,'data');
        $old=$this->getJson($url.'&selected='.$outside->id)->assertOk()->json('data');
        $this->assertFalse(collect($old)->firstWhere('id',$outside->id)['available']);
        $this->assertCount(3,$old);
        $this->getJson('/api/v2/00000000/server/route/users')->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/v2/00000000/server/route/users?source_id=999999')->assertStatus(422);
        Sanctum::actingAs($a);
        $this->getJson($url)->assertForbidden();
    }
}

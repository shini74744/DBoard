<?php
namespace Tests\Feature\Server;

use App\Models\{Server, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Queue};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LandingAndExitTest extends TestCase
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



    private function node(string $name,array $extra=[]): Server {
        return Server::create($extra+['name'=>$name,'type'=>'vless','host'=>'example.invalid','port'=>'443','server_port'=>443,'rate'=>1,'group_ids'=>[],'protocol_settings'=>['network'=>'tcp','tls'=>0]]);
    }
    private function group(string $kind,string $name): int {
        return $this->postJson('/api/v2/00000000/server/admin-group/save',['kind'=>$kind,'name'=>$name])->assertOk()->json('data.id');
    }
    public function test_landing_groups_are_isolated_and_creation_inherits_scope(): void {
        $ordinary=$this->group('node','同名');$landing=$this->group('node_landing','同名');
        $a=$this->node('ordinary',['admin_group'=>'同名']);
        $b=$this->node('landing',['admin_scope'=>'node_landing','admin_group'=>'同名']);
        $this->assertSame(1,$a->admin_group_number);$this->assertSame(1,$b->admin_group_number);
        $this->assertArrayNotHasKey('admin_scope',$b->toArray());
        $this->getJson('/api/v2/00000000/server/admin-group/fetch?kind=node_landing')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$landing);
        $payload=['name'=>'new landing','type'=>'vless','host'=>'landing.invalid','port'=>'444','server_port'=>444,'rate'=>1,'group_ids'=>[],'protocol_settings'=>['network'=>'tcp','tls'=>0],'admin_scope'=>'node_landing','admin_group_id'=>$landing];
        $this->postJson('/api/v2/00000000/server/manage/save',$payload)->assertOk();
        $created=Server::where('name','new landing')->firstOrFail();$this->assertSame('node_landing',$created->admin_scope);$this->assertSame('同名',$created->admin_group);$this->assertSame(2,$created->admin_group_number);
        $this->postJson('/api/v2/00000000/server/admin-group/save',['id'=>$landing,'kind'=>'node_landing','name'=>'落地新组'])->assertOk();
        $this->assertSame('同名',$a->fresh()->admin_group);$this->assertSame('落地新组',$b->fresh()->admin_group);
        $this->postJson('/api/v2/00000000/server/admin-group/syncMembers',['id'=>$landing,'item_ids'=>[$a->id,$b->id,$created->id]])->assertOk();
        $this->assertSame('node_landing',$a->fresh()->admin_scope);$this->assertSame(3,$a->fresh()->admin_group_number);
        $this->postJson('/api/v2/00000000/server/admin-group/drop',['id'=>$ordinary])->assertOk();$this->assertSame('落地新组',$a->fresh()->admin_group);
        $this->getJson('/api/v2/00000000/server/route/nodes')->assertOk()->assertJsonFragment(['id'=>$created->id,'admin_scope'=>'node_landing','admin_group'=>'落地新组']);
    }
    public function test_both_third_party_and_node_exits_have_independent_retry_safe_totals(): void {
        $target=$this->node('landing');
        $third=\App\Models\ServerOutbound::create(['name'=>'third','tag'=>'third','protocol'=>'socks','settings'=>['server'=>'third.invalid','server_port'=>1080],'enabled'=>true]);
        $ref=\App\Services\NodeOutboundService::reference($target);
        $a=$this->node('A',['outbound_ids'=>[$third->id,$ref->id]]);$b=$this->node('B',['outbound_ids'=>[$third->id,$ref->id]]);
        foreach([$third,$ref] as $exit){
            $report=fn($node,$bytes,$session='a1111111-1111-4111-8111-111111111111')=>\App\Services\OutboundTrafficService::record($node,['session'=>$session,'traffic'=>[$exit->id=>$bytes]]);
            $report($a,[100,200]);$report($a,[100,200]);$report($a,[50,80]);$report($b,[10,20]);
            $report($a,[5,7],'b2222222-2222-4222-8222-222222222222');
            $data=\App\Services\NodeOutboundTrafficService::forNode($a->id)[$exit->id];
            $this->assertSame(105,$data['upload']);$this->assertSame(207,$data['download']);$this->assertNotNull($data['started_at']);
            $this->assertSame(115,$exit->fresh()->traffic_upload);$this->assertSame(227,$exit->fresh()->traffic_download);
            $a->update(['outbound_ids'=>[]]);$a->update(['outbound_ids'=>[$third->id,$ref->id]]);
            $this->assertSame($data,\App\Services\NodeOutboundTrafficService::forNode($a->id)[$exit->id]);
        }
        $rows=$this->getJson('/api/v2/00000000/server/route/fetch?source_id='.$a->id)->assertOk()->json('data');
        $this->assertCount(2,$rows);foreach($rows as $row)$this->assertSame(105,$row['node_traffic']['upload']);
        $global=$this->getJson('/api/v2/00000000/server/route/fetch')->assertOk()->json('data');$this->assertArrayNotHasKey('node_traffic',$global[0]);
        // Rebuild the new table from existing session cursors without inventing an attachment date.
        \Illuminate\Support\Facades\Schema::drop('dboard_node_outbound_traffic');
        (require database_path('migrations/2026_09_24_000020_add_node_outbound_totals.php'))->up();
        $history=\App\Services\NodeOutboundTrafficService::forNode($a->id)[$third->id];$this->assertSame(105,$history['upload']);$this->assertNull($history['started_at']);
    }
}

<?php
namespace Tests\Feature\Server;

use App\Models\{Server, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Queue};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NodeConnectionStatsTest extends TestCase
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


    private function node(): Server
    {
        return Server::create(['name'=>'stats','type'=>'vless','host'=>'example.invalid','port'=>'443','server_port'=>443,'rate'=>1,'group_ids'=>[],'show'=>false,'protocol_settings'=>[]]);
    }
    private function snapshot(): array
    {
        return ['version'=>1,'since'=>time()-120,'source_ips'=>1,'tcp'=>2,'udp'=>1,
        'sources'=>[['value'=>'1.1.1.1','active'=>3,'count'=>12,'seconds'=>150]],
        'tcp_rows'=>[['value'=>'example.com:443','active'=>2,'count'=>9,'seconds'=>100]],
        'udp_rows'=>[['value'=>'[2001:db8::1]:53','active'=>1,'count'=>3,'seconds'=>50]]];
    }
    public function test_details_are_admin_only_and_list_has_no_addresses(): void
    {
        $node=$this->node();
        \App\Services\ServerService::updateMetrics($node,['connection_stats'=>$this->snapshot()]);
        $this->getJson('/api/v2/00000000/server/manage/connections?id='.$node->id)
            ->assertOk()->assertJsonPath('data.tcp',2)->assertJsonPath('data.sources.0.value','1.1.1.1');
        $summary=\App\Services\NodeConnectionService::summary($node);
        $this->assertSame(1,$summary['source_ips']);
        $this->assertArrayNotHasKey('sources',$summary);
        $this->assertFalse($summary['stale']);
        Sanctum::actingAs(User::create(['email'=>'regular@example.invalid','password'=>'unused','uuid'=>'regular','token'=>'regular','is_admin'=>false]));
        $this->getJson('/api/v2/00000000/server/manage/connections?id='.$node->id)->assertForbidden();
    }
    public function test_missing_stale_and_bounded_snapshots_are_explicit(): void
    {
        $node=$this->node();
        $this->assertNull(\App\Services\NodeConnectionService::summary($node));
        $this->assertFalse(\App\Services\NodeConnectionService::details($node)['supported']);
        $snapshot=$this->snapshot();
        $snapshot['sources']=array_fill(0,400,$snapshot['sources'][0]);
        \App\Services\NodeConnectionService::record($node,$snapshot);
        $data=Cache::get('dboard_node_connections:'.$node->id);
        $this->assertCount(300,$data['sources']);
        $this->assertTrue($data['truncated']);
        $data['updated_at']=time()-10000;
        Cache::put('dboard_node_connections:'.$node->id,$data,90000);
        $this->assertTrue(\App\Services\NodeConnectionService::summary($node)['stale']);
    }
    public function test_offline_operator_lookup_and_invalid_source(): void
    {
        $file=tempnam(sys_get_temp_dir(),'asn-test');
        $db=new \PDO('sqlite:'.$file);
        $db->exec("CREATE TABLE ranges(version INTEGER,start TEXT,end TEXT,asn INTEGER,name TEXT,PRIMARY KEY(version,start))");
        $db->exec("INSERT INTO ranges VALUES(4,'01010100','010101ff',13335,'CLOUDFLARENET')");
        config(['dboard.asn_database'=>$file]);
        $lookup=\App\Services\NodeConnectionService::operator('1.1.1.1');
        unlink($file);
        $this->assertSame(13335,$lookup['asn']);
        $this->assertStringContainsString('CLOUDFLARE',strtoupper($lookup['operator']));
        $this->assertSame('内网 / 保留地址',\App\Services\NodeConnectionService::operator('127.0.0.1')['operator']);
        $node=$this->node();$snapshot=$this->snapshot();
        $snapshot['sources'][0]['value']='https://example.invalid';
        \App\Services\NodeConnectionService::record($node,$snapshot);
        $this->assertSame([],\App\Services\NodeConnectionService::details($node)['sources']);
    }

    public function test_user_scope_uses_current_node_permissions_and_package_ownership(): void
    {
        $node=$this->node();$node->group_ids=[7];$node->save();
        $make=fn($email,$extra=[])=>User::create($extra+['email'=>$email,'password'=>'unused','uuid'=>$email,'token'=>$email,'group_id'=>7,'transfer_enable'=>1000,'expired_at'=>null,'u'=>0,'d'=>0,'banned'=>false]);
        $a=$make('a@example.invalid');
        $child=$make('package@example.invalid',['parent_id'=>$a->id]);
        $b=$make('b@example.invalid');
        $expired=$make('expired@example.invalid',['expired_at'=>time()-10]);
        $other=$make('other@example.invalid',['group_id'=>9]);
        $snapshot=$this->snapshot();$snapshot['version']=2;$snapshot['user_since']=time()-60;
        $snapshot['sources']=[];$snapshot['tcp_rows']=[];$snapshot['udp_rows']=[];
        foreach([$a->id,$child->id,$b->id,0] as $id){
            $row=['value'=>'1.1.1.1','user_id'=>$id,'active'=>1,'count'=>2,'seconds'=>10];
            $snapshot['sources'][]=$row;$row['value']='shared.example:443';$snapshot['tcp_rows'][]=$row;
        }
        \App\Services\NodeConnectionService::record($node,$snapshot);
        $url='/api/v2/00000000/server/manage/connections?id='.$node->id;
        $all=$this->getJson($url)->assertOk()->json('data');
        $this->assertSame(8,$all['tcp_rows'][0]['count']);
        $this->assertEqualsCanonicalizing([$a->id,$b->id],array_column($all['users'],'id'));
        $scope=$this->getJson($url.'&user_id='.$a->id)->assertOk()->json('data');
        $this->assertSame(2,$scope['tcp']);$this->assertSame(1,$scope['source_ips']);
        $this->assertSame(4,$scope['tcp_rows'][0]['count']);
        $this->assertGreaterThanOrEqual(time()-61,$scope['since']);
        foreach([$child->id,$expired->id,$other->id]as$id)$this->getJson($url.'&user_id='.$id)->assertStatus(422);
        $a->banned=true;$a->save();
        $this->getJson($url.'&user_id='.$a->id)->assertStatus(422);
    }
    public function test_legacy_reports_never_leak_all_users_into_a_selected_scope(): void
    {
        $node=$this->node();$node->group_ids=[7];$node->save();
        $a=User::create(['email'=>'legacy@example.invalid','password'=>'unused','uuid'=>'legacy','token'=>'legacy','group_id'=>7,'transfer_enable'=>1000,'expired_at'=>null,'u'=>0,'d'=>0,'banned'=>false]);
        \App\Services\NodeConnectionService::record($node,$this->snapshot());
        $data=\App\Services\NodeConnectionService::details($node,$a->id);
        $this->assertFalse($data['supported']);$this->assertSame([],$data['sources']);$this->assertSame(0,$data['tcp']);
    }
    public function test_outbound_display_renumber_is_persistent_and_preserves_identity(): void
    {
        $a=\App\Models\ServerOutbound::create(['name'=>'a','tag'=>'a','protocol'=>'socks','settings'=>['server'=>'127.0.0.1','server_port'=>1000],'enabled'=>true,'sort'=>1,'traffic_upload'=>123]);
        $b=\App\Models\ServerOutbound::create(['name'=>'b','tag'=>'b','protocol'=>'socks','settings'=>['server'=>'127.0.0.1','server_port'=>1001],'enabled'=>true,'sort'=>2]);
        $node=$this->node();$node->outbound_ids=[$a->id];$node->save();
        $url='/api/v2/00000000/server/route/sort';
        $this->postJson($url,['ids'=>[$b->id,$a->id],'renumber'=>true])->assertOk();
        $this->assertSame(1,$b->fresh()->display_id);$this->assertSame(2,$a->fresh()->display_id);
        $this->assertSame(123,$a->fresh()->traffic_upload);$this->assertSame([$a->id],$node->fresh()->outbound_ids);
        $this->assertSame($a->id,$a->fresh()->toNodeConfig()['id']);
        $this->postJson($url,['ids'=>[$a->id,$b->id]])->assertOk();
        $this->assertSame(2,$a->fresh()->display_id);
        $this->postJson($url,['ids'=>[$a->id],'renumber'=>true])->assertStatus(400);
        $this->assertSame(2,$a->fresh()->display_id);
        $c=\App\Models\ServerOutbound::create(['name'=>'c','tag'=>'c','protocol'=>'socks','settings'=>[]]);
        $this->assertGreaterThan(2,$c->display_id);
        $this->postJson($url,['ids'=>[$c->id,$b->id,$a->id],'renumber'=>true])->assertOk();
        $this->assertSame(1,$c->fresh()->display_id);$this->assertSame(3,$a->fresh()->display_id);
    }
}

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
}

<?php
namespace Tests\Feature\Server;

use App\Models\{Server, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Queue};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NodeAdminGroupCreationTest extends TestCase
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

    private function payload(string $name): array
    {
        return ['name' => $name, 'type' => 'vless', 'host' => 'node.example.invalid',
            'port' => '443', 'server_port' => 443, 'rate' => 1, 'group_ids' => [9],
            'show' => false, 'protocol_settings' => ['network' => 'tcp', 'tls' => 0]];
    }

    private function group(string $kind, string $name): int
    {
        return DB::table('dboard_admin_groups')->insertGetId([
            'kind' => $kind, 'name' => $name, 'created_at' => time(), 'updated_at' => time(),
        ]);
    }

    public function test_new_node_uses_selected_group_and_current_name_without_changing_permission_groups(): void
    {
        $id = $this->group('node', '香港分组');
        DB::table('dboard_admin_groups')->where('id', $id)->update(['name' => '香港新名称']);
        $this->postJson('/api/v2/00000000/server/manage/save', $this->payload('已分组节点') + ['admin_group_id' => $id])->assertOk();
        $node = Server::where('name', '已分组节点')->firstOrFail();
        $this->assertSame('香港新名称', $node->admin_group);
        $this->assertSame([9], $node->group_ids);
        $this->assertArrayNotHasKey('admin_group_id', $node->getAttributes());
    }

    public function test_ungrouped_creation_and_existing_node_edit_do_not_inherit_current_filter(): void
    {
        $this->postJson('/api/v2/00000000/server/manage/save', $this->payload('未分组节点'))->assertOk();
        $node = Server::where('name', '未分组节点')->firstOrFail();
        $this->assertNull($node->admin_group);
        $node->update(['admin_group' => '原分组']);
        $another = $this->group('node', '其他分组');
        $this->postJson('/api/v2/00000000/server/manage/save', $this->payload('修改名称') + ['id' => $node->id, 'admin_group_id' => $another])->assertOk();
        $this->assertSame('原分组', $node->fresh()->admin_group);
    }

    public function test_deleted_and_machine_groups_cannot_receive_new_nodes(): void
    {
        $id = $this->group('machine', '服务器分组');
        foreach ([$id, $id + 999] as $invalid) {
            $this->assertNotEquals(200, $this->postJson('/api/v2/00000000/server/manage/save', $this->payload('无效分组节点') + ['admin_group_id' => $invalid])->status());
        }
        $this->assertSame(0, Server::where('name', '无效分组节点')->count());
    }
    public function test_numbers_append_when_moving_and_keep_internal_ids(): void
    {
        $a = $this->group('node', 'A'); $b = $this->group('node', 'B');
        foreach ([['A1', $a], ['A2', $a], ['B1', $b], ['B2', $b]] as [$name, $group]) {
            $this->postJson('/api/v2/00000000/server/manage/save', $this->payload($name) + ['admin_group_id' => $group])->assertOk();
        }
        $nodes = Server::orderBy('id')->get()->keyBy('name');
        $this->assertSame([1, 2, 1, 2], $nodes->pluck('admin_group_number')->all());
        $movedId = $nodes['A1']->id;
        $members = [$nodes['B1']->id, $nodes['B2']->id, $movedId];
        $this->postJson('/api/v2/00000000/server/admin-group/syncMembers', ['id' => $b, 'item_ids' => $members])->assertOk();
        $this->assertSame(3, Server::findOrFail($movedId)->admin_group_number);
        $this->assertSame('B', Server::findOrFail($movedId)->admin_group);
        $this->assertSame(2, $nodes['A2']->fresh()->admin_group_number);
        $this->postJson('/api/v2/00000000/server/admin-group/syncMembers', ['id' => $b, 'item_ids' => $members])->assertOk();
        $this->assertSame(3, Server::findOrFail($movedId)->admin_group_number);
        $this->postJson('/api/v2/00000000/server/manage/setGroup', ['id' => $movedId, 'admin_group' => 'A'])->assertOk();
        $this->assertSame(3, Server::findOrFail($movedId)->admin_group_number);
        $this->postJson('/api/v2/00000000/server/manage/setGroup', ['id' => $movedId, 'admin_group' => 'B'])->assertOk();
        $this->assertSame(4, Server::findOrFail($movedId)->admin_group_number);
        $this->assertSame($movedId, Server::findOrFail($movedId)->id);
    }

    public function test_rename_copy_removal_and_recreation_preserve_group_sequences(): void
    {
        $id = $this->group('node', '原组');
        $this->postJson('/api/v2/00000000/server/manage/save', $this->payload('原节点') + ['admin_group_id' => $id])->assertOk();
        $node = Server::where('name', '原节点')->firstOrFail();
        $this->postJson('/api/v2/00000000/server/manage/copy', ['id' => $node->id])->assertOk();
        $this->assertSame([1, 2], Server::orderBy('id')->pluck('admin_group_number')->all());
        $this->postJson('/api/v2/00000000/server/admin-group/save', ['id' => $id, 'kind' => 'node', 'name' => '新组'])->assertOk();
        $this->assertSame(1, $node->fresh()->admin_group_number);
        $this->postJson('/api/v2/00000000/server/manage/save', $this->payload('第三节点') + ['admin_group_id' => $id])->assertOk();
        $this->assertSame(3, Server::where('name', '第三节点')->firstOrFail()->admin_group_number);
        $ids = Server::orderBy('id')->pluck('id')->all();
        $this->postJson('/api/v2/00000000/server/admin-group/drop', ['id' => $id])->assertOk();
        $this->assertSame($ids, Server::orderBy('id')->pluck('id')->all());
        $this->assertSame(0, Server::whereNotNull('admin_group')->count());
        $this->assertSame([1, 2, 3], Server::orderBy('id')->pluck('admin_group_number')->all());
        $newId = $this->group('node', '新组');
        $this->postJson('/api/v2/00000000/server/manage/save', $this->payload('重新建组节点') + ['admin_group_id' => $newId])->assertOk();
        $this->assertSame(1, Server::where('name', '重新建组节点')->firstOrFail()->admin_group_number);
    }

    public function test_migration_backfills_each_group_without_changing_node_identity(): void
    {
        foreach (['A', 'B', 'A', null] as $index => $name) {
            Server::create($this->payload('历史节点' . $index) + ['admin_group' => $name]);
        }
        $before = Server::orderBy('id')->get(['id', 'name', 'admin_group', 'group_ids'])->map->getAttributes()->all();
        $migration = require database_path('migrations/2026_09_24_000012_add_node_admin_group_numbers.php');
        $migration->down(); $migration->up();
        $this->assertSame([1, 1, 2, 1], Server::orderBy('id')->pluck('admin_group_number')->all());
        $this->assertSame($before, Server::orderBy('id')->get(['id', 'name', 'admin_group', 'group_ids'])->map->getAttributes()->all());
        $list = $this->getJson('/api/v2/00000000/server/manage/getNodes')->assertOk()->json('data');
        $this->assertArrayHasKey('admin_group_number', $list[0]);
        $this->assertArrayNotHasKey('admin_group_number', Server::first()->toArray());
    }

}

<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\NodeAdminGroupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminGroupController extends Controller
{
    private const TABLES = [
        'machine' => 'v2_server_machine',
        'node' => 'v2_server',
        'node_landing' => 'v2_server',
    ];

    public function fetch(Request $request)
    {
        $params = $request->validate(['kind' => 'required|in:machine,node,node_landing']);
        return $this->success(DB::table('dboard_admin_groups')
            ->where('kind', $params['kind'])->orderBy('name')->get(['id', 'kind', 'name']));
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|exists:dboard_admin_groups,id',
            'kind' => 'required|in:machine,node,node_landing',
            'name' => 'required|string|max:64',
        ]);
        $name = trim($params['name']);
        if ($name === '' || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            return $this->fail([422, '分组名称不能为空或包含控制字符']);
        }
        $id = $params['id'] ?? null;
        $kind = $params['kind'];
        $group = $id ? DB::table('dboard_admin_groups')->where('id', $id)->first() : null;
        if ($group && $group->kind !== $kind) {
            return $this->fail([422, '分组类型不匹配']);
        }
        $duplicate = DB::table('dboard_admin_groups')->where('kind', $kind)->where('name', $name);
        if ($id) {
            $duplicate->where('id', '<>', $id);
        }
        if ($duplicate->exists()) {
            return $this->fail([422, '分组名称已存在']);
        }

        if ($group) {
            DB::transaction(function () use ($group, $name, $kind) {
                $group = $this->lockedGroup((int) $group->id);
                DB::table('dboard_admin_groups')->where('id', $group->id)
                    ->update(['name' => $name, 'updated_at' => time()]);
                if ($kind !== 'machine') NodeAdminGroupService::rename($group->name, $name, $kind);
                DB::table(self::TABLES[$kind])->when($kind !== 'machine', fn($q) => $q->where('admin_scope', $kind))->where('admin_group', $group->name)
                    ->update(['admin_group' => $name]);
            });
            return $this->success(['id' => $group->id, 'kind' => $kind, 'name' => $name]);
        }

        $newId = DB::table('dboard_admin_groups')->insertGetId([
            'kind' => $kind,
            'name' => $name,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        return $this->success(['id' => $newId, 'kind' => $kind, 'name' => $name]);
    }

    public function syncMembers(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:dboard_admin_groups,id',
            'item_ids' => 'present|array|max:10000',
            'item_ids.*' => 'required|integer|distinct',
        ]);
        $group = DB::table('dboard_admin_groups')->where('id', $params['id'])->first();
        $table = self::TABLES[$group->kind];
        $ids = array_map('intval', $params['item_ids']);
        if ($ids && DB::table($table)->whereIn('id', $ids)->count() !== count($ids)) {
            return $this->fail([422, '选择的服务器或节点不存在']);
        }
        DB::transaction(function () use ($table, $group, $ids) {
            $group = $this->lockedGroup((int) $group->id);
            $remove = DB::table($table)->when($group->kind !== 'machine', fn($q) => $q->where('admin_scope', $group->kind))->where('admin_group', $group->name);
            if ($ids) {
                $remove->whereNotIn('id', $ids);
            }
            if ($group->kind !== 'machine') {
                NodeAdminGroupService::move($remove->pluck('id')->all(), null, $group->kind);
                NodeAdminGroupService::move($ids, $group->name, $group->kind);
            } else {
                $remove->update(['admin_group' => null]);
                if ($ids) DB::table($table)->whereIn('id', $ids)->update(['admin_group' => $group->name]);
            }
        });
        return $this->success(true);
    }

    public function drop(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|exists:dboard_admin_groups,id']);
        $group = DB::table('dboard_admin_groups')->where('id', $params['id'])->first();
        DB::transaction(function () use ($group) {
            $group = $this->lockedGroup((int) $group->id);
            $members = DB::table(self::TABLES[$group->kind])->when($group->kind !== 'machine', fn($q) => $q->where('admin_scope', $group->kind))->where('admin_group', $group->name);
            if ($group->kind !== 'machine') {
                NodeAdminGroupService::move($members->pluck('id')->all(), null, $group->kind);
                DB::table('dboard_node_scope_sequences')->where('scope', $group->kind)->where('group_name', $group->name)->delete();
            } else $members->update(['admin_group' => null]);
            DB::table('dboard_admin_groups')->where('id', $group->id)->delete();
        });
        return $this->success(true);
    }
    private function lockedGroup(int $id): object
    {
        $group = DB::table('dboard_admin_groups')->where('id', $id)->lockForUpdate()->first();
        if (!$group) {
            throw \Illuminate\Validation\ValidationException::withMessages(['id' => '分组已删除，请刷新后重试']);
        }
        return $group;
    }

}

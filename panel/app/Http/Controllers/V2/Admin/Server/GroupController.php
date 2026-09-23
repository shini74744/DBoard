<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GroupController extends Controller
{
    public function fetch(Request $request): JsonResponse
    {
        $serverGroups = ServerGroup::query()
            ->orderBy('sort')->orderByDesc('id')
            ->withCount('users')
            ->get();

        // 只在需要时手动加载server_count
        $serverGroups->each(function ($group) {
            $group->setAttribute('server_count', $group->server_count);
        });

        return $this->success($serverGroups);
    }

    public function sort(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|integer|distinct|min:1',
        ]);
        $ids = array_map('intval', $data['ids']);
        DB::transaction(function () use ($ids) {
            $current = ServerGroup::query()->lockForUpdate()->pluck('id')->map(fn($id) => (int) $id)->all();
            sort($current);
            $incoming = $ids;
            sort($incoming);
            if ($current !== $incoming) {
                throw new ApiException('权限组列表已更新，请刷新后重新排序');
            }
            foreach ($ids as $position => $id) {
                ServerGroup::whereKey($id)->update(['sort' => $position + 1]);
            }
        });
        return $this->success(true);
    }

    public function save(Request $request)
    {
        if (empty($request->input('name'))) {
            return $this->fail([422, '组名不能为空']);
        }

        if ($request->input('id')) {
            $serverGroup = ServerGroup::find($request->input('id'));
        } else {
            $serverGroup = new ServerGroup();
            $serverGroup->sort = ((int) ServerGroup::max('sort')) + 1;
        }

        $serverGroup->name = $request->input('name');
        return $this->success($serverGroup->save());
    }

    public function drop(Request $request)
    {
        $groupId = $request->input('id');

        $serverGroup = ServerGroup::find($groupId);
        if (!$serverGroup) {
            return $this->fail([400202, '组不存在']);
        }
        if (Server::whereJsonContains('group_ids', $groupId)->exists()) {
            return $this->fail([400, '该组已被节点所使用，无法删除']);
        }

        if (Plan::where('group_id', $groupId)->exists()) {
            return $this->fail([400, '该组已被订阅所使用，无法删除']);
        }
        if (User::where('group_id', $groupId)->exists()) {
            return $this->fail([400, '该组已被用户所使用，无法删除']);
        }
        return $this->success($serverGroup->delete());
    }
}

<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\NodeSyncService;
use App\Services\MachineUpgradeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MachineController extends Controller
{
    /**
     * 获取机器列表（附带关联节点数）
     */
    public function fetch(Request $request)
    {
        $machines = ServerMachine::withCount('servers')
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(function (ServerMachine $machine) {
                return [
                    'id' => $machine->id,
                    'probe_uuid' => $machine->probe_uuid,
                    'probe_server_id' => $machine->probe_server_id,
                    'access_mode' => $machine->probe_uuid ? 'probe' : 'direct',
                    'sort' => $machine->sort,
                    'name' => $machine->name,
                    'admin_group' => $machine->admin_group,
                    'notes' => $machine->notes,
                    'is_active' => $machine->is_active,
                    'last_seen_at' => $machine->last_seen_at,
                    'load_status' => $machine->load_status,
                    'node_version' => Cache::get('dboard_machine_version:' . $machine->id),
                    'upgrade_capable' => (bool) Cache::get('dboard_machine_upgrade_capable:' . $machine->id),
                    'upgrade_status' => $this->upgradeState($machine->id),
                    'servers_count' => $machine->servers_count,
                    'created_at' => $machine->created_at,
                    'updated_at' => $machine->updated_at,
                ];
            });

        return $this->success($machines);
    }

    private function upgradeState(int $machineId): ?array
    {
        $status = MachineUpgradeService::state($machineId);
        if (!$status) return null;
        unset($status['request_id']);
        return $status;
    }

    public function upgradeStatus(Request $request)
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids', ''))));
        $ids = array_values(array_unique($ids));
        if (!$ids || count($ids) > 25) {
            return $this->fail([422, '请选择 1 至 25 台服务器']);
        }
        $result = [];
        foreach ($ids as $id) {
            $result[$id] = [
                'version' => Cache::get('dboard_machine_version:' . $id),
                'capable' => (bool) Cache::get('dboard_machine_upgrade_capable:' . $id),
                'status' => $this->upgradeState($id),
            ];
        }
        return $this->success($result);
    }

    private function latestNodeRelease(): string
    {
        return Cache::remember('dboard_latest_node_release', 300, function () {
            $response = Http::withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'DBoard'])
                ->connectTimeout(5)->timeout(15)->retry(2, 1000)->get('https://api.github.com/repos/shini74744/DBoard/releases/latest');
            if (!$response->successful()) {
                throw new \RuntimeException('GitHub Release 查询失败');
            }
            $tag = (string) $response->json('tag_name');
            if (!preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/', $tag)) {
                throw new \RuntimeException('GitHub Release 版本无效');
            }
            return $tag;
        });
    }

    public function latestRelease()
    {
        try {
            $hasLegacy=ServerMachine::whereNull('probe_uuid')->exists();
            $version = $hasLegacy?$this->latestNodeRelease():(\App\Services\ProbeService::settings()?->agent_version??'');
            $probeVersion=\App\Services\ProbeService::settings()?->agent_version??'';
            $upgradeable = [];
            foreach (ServerMachine::query()->get(['id', 'is_active','probe_uuid']) as $machine) {
                $target=$machine->probe_uuid?$probeVersion:$version;
                $current = (string) Cache::get('dboard_machine_version:' . $machine->id, '');
                if ($machine->is_active && Cache::get('dboard_machine_upgrade_capable:' . $machine->id)
                    && !MachineUpgradeService::busy(MachineUpgradeService::state($machine->id))
                    && $current !== '' && $target!=='' && version_compare(ltrim($current, 'v'), ltrim($target, 'v'), '<')) {
                    $upgradeable[] = $machine->id;
                }
            }
            return $this->success(['version' => $version,'probe_version'=>$probeVersion, 'upgradeable_machine_ids' => $upgradeable]);
        } catch (\Throwable $e) {
            return $this->fail([502, '暂时无法读取 GitHub 最新版本：' . $e->getMessage()]);
        }
    }

    public function upgrade(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array|min:1|max:25',
            'ids.*' => 'required|integer|distinct|min:1',
        ]);
        $machines = ServerMachine::whereIn('id', $params['ids'])->get()->keyBy('id');
        try {
            $legacyVersion = $machines->contains(fn($m)=>!$m->probe_uuid)?$this->latestNodeRelease():'';
            $probeVersion=\App\Services\ProbeService::settings()?->agent_version??'';
            $version=$probeVersion?:$legacyVersion;
        } catch (\Throwable $e) {
            return $this->fail([502, '暂时无法读取 GitHub 最新版本：' . $e->getMessage()]);
        }
        $results = [];
        foreach ($params['ids'] as $id) {
            $machine = $machines->get($id);
            $version=$machine?->probe_uuid?$probeVersion:$legacyVersion;
            $current = (string) Cache::get('dboard_machine_version:' . $id, '');
            if (!$machine) {
                $results[$id] = ['state' => 'failed', 'message' => '服务器不存在'];
                continue;
            }
            if (!$machine->is_active || !Cache::get('dboard_machine_upgrade_capable:' . $id)) {
                $results[$id] = ['state' => 'failed', 'message' => '服务器离线或节点程序不支持后台升级，请先手动升级一次'];
                continue;
            }
            if ($version === '') { $results[$id]=['state'=>'failed','message'=>'尚未配置整合 Agent 版本'];continue; }
            if ($current === '' || version_compare(ltrim($current, 'v'), ltrim($version, 'v'), '>=')) {
                $results[$id] = ['state' => 'skipped', 'message' => $current === '' ? '节点版本未知' : '已是最新版本'];
                continue;
            }
            $dispatchLock=Cache::lock('dboard_machine_upgrade_dispatch:'.$id,20);
            if(!$dispatchLock->get()){$results[$id]=['state'=>'skipped','message'=>'正在下发升级任务，请稍候'];continue;}
            try {
            $previous = $this->upgradeState((int) $id);
            if (MachineUpgradeService::busy($previous)) {
                $results[$id] = ['state' => 'skipped', 'message' => '已有升级任务正在执行'];
                continue;
            }
            $requestId = bin2hex(random_bytes(8));
            $status = [
                'request_id' => $requestId, 'state' => 'queued', 'target_version' => $version,
                'protocol'=>(int)Cache::get('dboard_machine_upgrade_protocol:'.$id,1),
                'from_version' => $current, 'message' => '', 'created_at' => time(), 'updated_at' => time(),
            ];
            MachineUpgradeService::start((int) $id, $status);
            if (!NodeSyncService::pushMachine((int) $id, 'node.upgrade', [
                'request_id' => $requestId, 'version' => $version,
            ])) {
                $status['state'] = 'failed';
                $status['message'] = '升级指令发送失败，请检查面板消息服务';
                MachineUpgradeService::result((int) $id, $status);
            }
            unset($status['request_id']);
            $results[$id] = $status;
            } finally { $dispatchLock->release(); }
        }
        return $this->success(['target_version' => $version, 'machines' => $results]);
    }

    public function sort(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|integer|distinct|min:1',
        ]);
        $ids = array_map('intval', $data['ids']);
        DB::transaction(function () use ($ids) {
            $current = ServerMachine::query()->lockForUpdate()->pluck('id')->map(fn($id) => (int) $id)->all();
            sort($current);
            $incoming = $ids;
            sort($incoming);
            if ($current !== $incoming) {
                throw new ApiException('服务器列表已更新，请刷新后重新排序');
            }
            foreach ($ids as $position => $id) {
                ServerMachine::whereKey($id)->update(['sort' => $position + 1]);
            }
        });
        return $this->success(true);
    }

    /**
     * 创建 / 更新机器
     */
    public function save(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_server_machine,id',
            'name' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        if (!empty($params['id'])) {
            $machine = ServerMachine::find($params['id']);
            $update = ['name' => $params['name']];
            if (array_key_exists('notes', $params)) {
                $update['notes'] = $params['notes'];
            }
            if (array_key_exists('is_active', $params)) {
                $update['is_active'] = $params['is_active'];
            }
            if ($machine->probe_uuid) {
                $machine->fill($update);
                \App\Services\ProbeService::sync($machine);
            }
            $machine->update($update);
            return $this->success(true);
        }

        $machine = ServerMachine::create([
            'probe_uuid' => \App\Services\ProbeService::enabled() ? (string)\Illuminate\Support\Str::uuid() : null,
            'sort' => ((int) ServerMachine::max('sort')) + 1,
            'name' => $params['name'],
            'notes' => $params['notes'] ?? null,
            'is_active' => $params['is_active'] ?? true,
            'token' => ServerMachine::generateToken(),
        ]);

        return $this->success([
            'id' => $machine->id,
            'token' => $machine->token,
            'install_command' => $this->buildInstallCommand($request, $machine),
        ]);
    }

    /**
     * 后台列表分组，仅影响管理界面的筛选。
     */
    public function setGroup(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
            'admin_group' => 'present|nullable|string|max:64',
        ]);

        $group = trim((string) ($params['admin_group'] ?? ''));
        if ($group !== '' && preg_match('/[\x00-\x1F\x7F]/u', $group)) {
            return $this->fail([422, '分组名称不能包含控制字符']);
        }

        if ($group !== '') {
            DB::table('dboard_admin_groups')->insertOrIgnore([
                'kind' => 'machine', 'name' => $group,
                'created_at' => time(), 'updated_at' => time(),
            ]);
        }
        ServerMachine::whereKey($params['id'])->update(['admin_group' => $group ?: null]);
        return $this->success(true);
    }

    /**
     * 重置机器 Token
     */
    public function resetToken(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);
        $token = ServerMachine::generateToken();
        $machine->update(['token' => $token]);

        return $this->success(['token' => $token]);
    }

    /**
     * 获取机器 Token（仅展示一次，用于首次配置）
     */
    public function getToken(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);

        return $this->success(['token' => $machine->token]);
    }

    /**
     * 获取机器模式一键安装命令
     */
    public function installCommand(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);

        return $this->success([
            'command' => $this->buildInstallCommand($request, $machine),
        ]);
    }

    /**
     * 删除机器（自动解除关联节点）
     */
    public function drop(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);
        $machineId = $machine->id;
        if ($machine->probe_uuid) { $machine->is_active=false; \App\Services\ProbeService::sync($machine); }

        // Detach nodes first (sets machine_id = null), then delete and notify
        Server::where('machine_id', $machineId)->update(['machine_id' => null]);
        $machine->delete();

        // Notify with empty node list so WS process cleans up registry
        NodeSyncService::notifyMachineNodesChanged($machineId);

        return $this->success(true);
    }

    /**
     * 获取机器下的节点列表
     */
    public function nodes(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $nodes = Server::where('machine_id', $params['machine_id'])
            ->orderBy('sort')
            ->get(['id', 'name', 'type', 'host', 'port', 'show', 'enabled', 'sort']);

        return $this->success($nodes);
    }

    /**
     * 获取机器负载历史
     */
    public function history(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
            'limit' => 'nullable|integer|min:10|max:1440',
            'range_hours' => 'nullable|integer|min:1|max:24',
        ]);

        $query = ServerMachineLoadHistory::query()
            ->where('machine_id', $params['machine_id']);

        if (!empty($params['range_hours'])) {
            $query->where('recorded_at', '>=', now()->subHours((int) $params['range_hours'])->timestamp);
        }

        $limit = (int) ($params['limit'] ?? 60);

        $history = $query
            ->orderByDesc('recorded_at')
            ->limit($limit)
            ->get([
                'cpu',
                'mem_total',
                'mem_used',
                'disk_total',
                'disk_used',
                'net_in_speed',
                'net_out_speed',
                'recorded_at',
            ])
            ->reverse()
            ->values();

        return $this->success($history);
    }

    private function buildInstallCommand(Request $request, ServerMachine $machine): string
    {
        if ($machine->probe_uuid || \App\Services\ProbeService::enabled()) { return \App\Services\ProbeService::installCommand($machine); }
        $panelUrl = rtrim((string) (admin_setting('app_url') ?: $request->getSchemeAndHttpHost()), '/');
        $installerUrl = 'https://raw.githubusercontent.com/shini74744/DBoard/main/node/install.sh';

        return sprintf(
            'curl -fsSL %s | sudo bash -s -- --mode machine --panel %s --token %s --machine-id %d',
            $installerUrl,
            escapeshellarg($panelUrl),
            escapeshellarg($machine->token),
            $machine->id
        );
    }
}

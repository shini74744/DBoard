<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerRoute;
use App\Models\ServerOutbound;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

class ServerService
{

    /**
     * 获取所有服务器列表
     * @return Collection
     */
    public static function getAllServers(): Collection
    {
        $query = Server::orderBy('sort', 'ASC');

        return $query->get()->append([
            'last_check_at',
            'last_push_at',
            'online',
            'is_online',
            'available_status',
            'cache_key',
            'load_status',
            'metrics',
            'online_conn'
        ]);
    }

    /**
     * 获取机器下所有已启用节点
     */
    public static function getMachineNodes(ServerMachine $machine): Collection
    {
        return Server::where('machine_id', $machine->id)
            ->where('enabled', true)
            ->orderBy('sort', 'ASC')
            ->get();
    }

    /**
     * 获取指定用户可用的服务器列表
     * @param User $user
     * @return array
     */
    public static function getAvailableServers(User $user): array
    {
        $servers = Server::whereJsonContains('group_ids', (string) $user->group_id)
            ->where('show', true)
            ->where(function ($query) {
                $query->whereNull('transfer_enable')
                    ->orWhere('transfer_enable', 0)
                    ->orWhereRaw('u + d < transfer_enable');
            })
            ->orderBy('sort', 'ASC')
            ->get()
            ->append(['last_check_at', 'last_push_at', 'online', 'is_online', 'available_status', 'cache_key', 'server_key']);

        $servers = collect($servers)->map(function ($server) use ($user) {
            // 判断动态端口
            if (str_contains($server->port, '-')) {
                $port = $server->port;
                $server->port = (int) Helper::randomPort($port);
                $server->ports = $port;
            } else {
                $server->port = (int) $server->port;
            }
            $server->password = $server->generateServerPassword($user);
            $server->rate = $server->getCurrentRate();
            return $server;
        })->toArray();

        return $servers;
    }

    /**
     * 根据权限组获取可用的用户列表
     * @param Server $node
     * @return Collection
     */
    public static function getAvailableUsers(Server $node)
    {
        $groupIds = $node->group_ids ?? [];
        if (empty($groupIds)) {
            return NodeOutboundService::users($node);
        }
        $users = User::toBase()
            ->whereIn('group_id', $groupIds)
            ->whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', NULL);
            })
            ->where('banned', 0)
            ->whereRaw('(parent_id IS NULL OR NOT EXISTS (SELECT 1 FROM v2_user account WHERE account.id = v2_user.parent_id AND account.banned = 1))')
            ->select([
                'id',
                'uuid',
                'speed_limit',
                'device_limit',
                'connection_limit'
            ])
            ->get();
        return collect(HookManager::filter('server.users.get', $users, $node))->concat(NodeOutboundService::users($node))->values();
    }

    // 获取路由规则
    public static function getRoutes(array $routeIds)
    {
        return ServerRoute::select(['id', 'match', 'action', 'action_value'])->whereIn('id', $routeIds)->get();
    }

    public static function getOutbounds(array $outboundIds, ?Server $source=null): array
    {
        if (empty($outboundIds)) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $outboundIds)));
        $outbounds = ServerOutbound::where('enabled',true)->get()->keyBy('id');
        $byTag=$outbounds->keyBy('tag');$result=[];$seen=[];
        $append=function($outbound)use(&$append,&$result,&$seen,$byTag,$source){
            if (!$outbound || isset($seen[$outbound->id]))return;
            $seen[$outbound->id]=true;
            $result[]=$outbound->toNodeConfig($source);
            if ($outbound->proxy_tag)$append($byTag->get($outbound->proxy_tag));
        };
        foreach($ids as $id)$append($outbounds->get($id));

        return $result;
    }

    /**
     * 处理节点流量数据汇报
     */
    public static function processTraffic(Server $node, array $traffic): void
    {
        $data = array_filter($traffic, fn($item) =>
            is_array($item) && count($item) === 2
            && is_numeric($item[0]) && is_numeric($item[1])
        );

        if (empty($data)) {
            return;
        }

        $nodeType = strtoupper($node->type);
        $nodeId = $node->id;

        Cache::put(CacheKey::get("SERVER_{$nodeType}_ONLINE_USER", $nodeId), count(array_filter(array_keys($data), fn($id) => (int)$id > 0)), 3600);
        Cache::put(CacheKey::get("SERVER_{$nodeType}_LAST_PUSH_AT", $nodeId), time(), 3600);

        (new UserService())->trafficFetch($node, $node->type, $data);
    }

    /**
     * 处理节点在线设备汇报
     */
    public static function processAlive(int $nodeId, array $alive): void
    {
        $service = app(DeviceStateService::class);
        foreach ($alive as $uid => $ips) {
            if ((int)$uid <= 0) continue;
            $service->setDevices((int) $uid, $nodeId, (array) $ips);
        }
    }

    /**
     * 处理节点连接数汇报
     */
    public static function processOnline(Server $node, array $online): void
    {
        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);
        $nodeType = $node->type;
        $nodeId = $node->id;

        foreach ($online as $uid => $conn) {
            if ((int)$uid <= 0) continue;
            $cacheKey = CacheKey::get("USER_ONLINE_CONN_{$nodeType}_{$nodeId}", $uid);
            Cache::put($cacheKey, (int) $conn, $cacheTime);
        }
    }

    /**
     * 处理节点负载状态汇报
     */
    public static function processStatus(Server $node, array $status): void
    {
        $nodeType = strtoupper($node->type);
        $nodeId = $node->id;

        $statusData = [
            'cpu' => (float) ($status['cpu'] ?? 0),
            'mem' => [
                'total' => (int) ($status['mem']['total'] ?? 0),
                'used' => (int) ($status['mem']['used'] ?? 0),
            ],
            'swap' => [
                'total' => (int) ($status['swap']['total'] ?? 0),
                'used' => (int) ($status['swap']['used'] ?? 0),
            ],
            'disk' => [
                'total' => (int) ($status['disk']['total'] ?? 0),
                'used' => (int) ($status['disk']['used'] ?? 0),
            ],
            'updated_at' => now()->timestamp,
            'kernel_status' => $status['kernel_status'] ?? null,
        ];

        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);
        cache([
            CacheKey::get("SERVER_{$nodeType}_LOAD_STATUS", $nodeId) => $statusData,
            CacheKey::get("SERVER_{$nodeType}_LAST_LOAD_AT", $nodeId) => now()->timestamp,
        ], $cacheTime);
    }

    /**
     * 标记节点心跳
     */
    public static function touchNode(Server $node): void
    {
        Cache::put(
            CacheKey::get('SERVER_' . strtoupper($node->type) . '_LAST_CHECK_AT', $node->id),
            time(),
            3600
        );
    }

    /**
     * Update node metrics and load status
     */
    public static function updateMetrics(Server $node, array $metrics): void
    {
        if (($metrics['front_gate_version']??0)===1) Cache::put('dboard_front_gate_capable:'.$node->id,true,600);
        else Cache::forget('dboard_front_gate_capable:'.$node->id);
        Cache::put('dboard_front_gate_applied:'.$node->id,(string)($metrics['front_gate_revision']??''),30);
        if (isset($metrics['connection_stats'])) {
            try { NodeConnectionService::record($node, $metrics['connection_stats']); }
            catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Connection snapshot not saved', ['node_id'=>$node->id]);
            }
        }
        if (isset($metrics['outbound_traffic'])) {
            try {
                OutboundTrafficService::record($node, $metrics['outbound_traffic']);
            } catch (\Throwable $e) {
                // The next cumulative snapshot recovers these bytes. Do not fail
                // the customer traffic report, which is processed as deltas.
                \Illuminate\Support\Facades\Log::warning('Outbound traffic snapshot not saved', ['node_id'=>$node->id,'error'=>$e->getMessage()]);
            }
        }
        $nodeType = strtoupper($node->type);
        $nodeId = $node->id;
        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);

        $metricsData = [
            'uptime' => (int) ($metrics['uptime'] ?? 0),
            'goroutines' => (int) ($metrics['goroutines'] ?? 0),
            'active_connections' => (int) ($metrics['active_connections'] ?? 0),
            'total_connections' => (int) ($metrics['total_connections'] ?? 0),
            'total_users' => (int) ($metrics['total_users'] ?? 0),
            'active_users' => (int) ($metrics['active_users'] ?? 0),
            'inbound_speed' => (int) ($metrics['inbound_speed'] ?? 0),
            'outbound_speed' => (int) ($metrics['outbound_speed'] ?? 0),
            'cpu_per_core' => $metrics['cpu_per_core'] ?? [],
            'load' => $metrics['load'] ?? [],
            'speed_limiter' => $metrics['speed_limiter'] ?? [],
            'gc' => $metrics['gc'] ?? [],
            'api' => $metrics['api'] ?? [],
            'ws' => $metrics['ws'] ?? [],
            'limits' => $metrics['limits'] ?? [],
            'updated_at' => now()->timestamp,
            'kernel_status' => (bool) ($metrics['kernel_status'] ?? false),
        ];

        Cache::put(
            CacheKey::get('SERVER_' . $nodeType . '_METRICS', $nodeId),
            $metricsData,
            $cacheTime
        );
    }

    /** Expand account-specific rules to each package's node identity. */
    public static function expandAccountRouteRules(array $rules): array
    {
        $accountIds = collect($rules)->flatMap(fn ($rule) => data_get($rule, 'match.user_ids', []))
            ->map(fn ($id) => (int) $id)->filter()->unique()->all();
        if (!$accountIds) return $rules;
        $children = User::whereIn('parent_id', $accountIds)->get(['id', 'parent_id'])->groupBy('parent_id');
        $expanded = [];
        foreach ($rules as $rule) {
            $ids = array_values(array_unique(array_map('intval', data_get($rule, 'match.user_ids', []))));
            if (!$ids) { $expanded[] = $rule; continue; }
            $originalIds = $ids;
            foreach ($originalIds as $accountId) {
                foreach ($children->get($accountId, collect()) as $child) $ids[] = (int) $child->id;
            }
            $ids = array_values(array_unique($ids));
            foreach (array_chunk($ids, 100) as $chunk) {
                $copy = $rule;
                data_set($copy, 'match.user_ids', $chunk);
                $expanded[] = $copy;
            }
        }
        return $expanded;
    }

    public static function compatibleRouteRules(array $rules, bool $userRoutesCapable): array
    {
        if ($userRoutesCapable) {
            return $rules;
        }
        return array_values(array_filter($rules, fn ($rule) => empty(data_get($rule, 'match.user_ids'))));
    }

    public static function buildNodeConfig(Server $node): array
    {
        $nodeType = $node->type;
        $protocolSettings = $node->protocol_settings;
        $serverPort = $node->server_port;
        $host = $node->host;

        $baseConfig = [
            'protocol' => $nodeType,
            'listen_ip' => '0.0.0.0',
            'server_port' => (int) $serverPort,
            'network' => data_get($protocolSettings, 'network'),
            'networkSettings' => data_get($protocolSettings, 'network_settings') ?: null,
        ];

        $response = match ($nodeType) {
            'shadowsocks' => [
                ...$baseConfig,
                'cipher' => $protocolSettings['cipher'],
                'plugin' => $protocolSettings['plugin'],
                'plugin_opts' => $protocolSettings['plugin_opts'],
                'server_key' => match ($protocolSettings['cipher']) {
                        '2022-blake3-aes-128-gcm' => Helper::getServerKey($node->created_at, 16),
                        '2022-blake3-aes-256-gcm' => Helper::getServerKey($node->created_at, 32),
                        default => null,
                    },
            ],
            'vmess' => [
                ...$baseConfig,
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => $protocolSettings['tls_settings'],
                'multiplex' => data_get($protocolSettings, 'multiplex'),
            ],
            'trojan' => [
                ...$baseConfig,
                'host' => $host,
                'server_name' => data_get($protocolSettings, 'tls_settings.server_name'),
                'multiplex' => data_get($protocolSettings, 'multiplex'),
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => match ((int) $protocolSettings['tls']) {
                        2 => $protocolSettings['reality_settings'],
                        default => $protocolSettings['tls_settings'],
                    },
            ],
            'vless' => [
                ...$baseConfig,
                'tls' => (int) $protocolSettings['tls'],
                'flow' => $protocolSettings['flow'],
                'decryption' => match (data_get($protocolSettings, 'encryption.enabled')) {
                    true => data_get($protocolSettings, 'encryption.decryption'),
                    default => null,
                },
                'tls_settings' => match ((int) $protocolSettings['tls']) {
                        2 => $protocolSettings['reality_settings'],
                        default => $protocolSettings['tls_settings'],
                    },
                'multiplex' => data_get($protocolSettings, 'multiplex'),
            ],
            'hysteria' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'version' => (int) $protocolSettings['version'],
                'host' => $host,
                'server_name' => $protocolSettings['tls']['server_name'],
                'tls_settings' => $protocolSettings['tls'],
                'up_mbps' => (int) $protocolSettings['bandwidth']['up'],
                'down_mbps' => (int) $protocolSettings['bandwidth']['down'],
                ...match ((int) $protocolSettings['version']) {
                        1 => ['obfs' => $protocolSettings['obfs']['password'] ?? null],
                        2 => [
                            'obfs' => $protocolSettings['obfs']['open'] ? $protocolSettings['obfs']['type'] : null,
                            'obfs-password' => $protocolSettings['obfs']['password'] ?? null,
                        ],
                        default => [],
                    },
            ],
            'tuic' => [
                ...$baseConfig,
                'version' => (int) $protocolSettings['version'],
                'server_port' => (int) $serverPort,
                'server_name' => $protocolSettings['tls']['server_name'],
                'congestion_control' => $protocolSettings['congestion_control'],
                'tls_settings' => $protocolSettings['tls'],
                'auth_timeout' => '3s',
                'zero_rtt_handshake' => false,
                'heartbeat' => '3s',
            ],
            'anytls' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'server_name' => $protocolSettings['tls']['server_name'],
                'tls_settings' => $protocolSettings['tls'],
                'padding_scheme' => $protocolSettings['padding_scheme'],
            ],
            'socks' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'tls' => (int) data_get($protocolSettings, 'tls', 0),
                'tls_settings' => data_get($protocolSettings, 'tls_settings'),
            ],
            'naive' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => $protocolSettings['tls_settings'],
            ],
            'http' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => $protocolSettings['tls_settings'],
            ],
            'mieru' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'transport' => data_get($protocolSettings, 'transport', 'TCP'),
                'traffic_pattern' => $protocolSettings['traffic_pattern'],
            ],
            default => [],
        };

        if (!empty($node['route_ids'])) {
            $response['routes'] = self::getRoutes($node['route_ids']);
        }

        $managedOutbounds = self::getOutbounds($node['outbound_ids'] ?? [],$node);
        $legacyOutbounds = is_array($node['custom_outbounds'] ?? null) ? $node['custom_outbounds'] : [];
        if (!empty($managedOutbounds) || !empty($legacyOutbounds)) {
            $outboundsByTag = [];
            foreach ($legacyOutbounds as $outbound) {
                if (is_array($outbound) && !empty($outbound['tag'])) {
                    $outboundsByTag[(string) $outbound['tag']] = $outbound;
                }
            }
            foreach ($managedOutbounds as $outbound) {
                if (!empty($outbound['tag'])) {
                    $outboundsByTag[(string) $outbound['tag']] = $outbound;
                }
            }
            $response['custom_outbounds'] = array_values($outboundsByTag);
        }

        if (!empty($node['custom_route_rules'])) {
            $rules = self::compatibleRouteRules(
                $node['custom_route_rules'],
                (bool) Cache::get('dboard_user_routes_capable:' . $node->id)
            );
            if ($rules) {
                $response['custom_route_rules'] = self::expandAccountRouteRules($rules);
            }
        }

        if (!empty($node['custom_balancers'])) {
            $response['custom_balancers'] = $node['custom_balancers'];
        }

        if (!empty($node['custom_routes'])) {
            $response['custom_routes'] = $node['custom_routes'];
        }

        if (!empty($node['cert_config'])) {
            $certConfig = $node['cert_config'];
            // Normalize: accept both "mode" and "cert_mode" from the database
            if (isset($certConfig['mode']) && !isset($certConfig['cert_mode'])) {
                $certConfig['cert_mode'] = $certConfig['mode'];
                unset($certConfig['mode']);
            }
            if (data_get($certConfig, 'cert_mode') !== 'none') {
                $response['cert_config'] = $certConfig;
            }
        }

        if ($node->front_gate_enabled) {
            $response['front_gate']=NodeFrontGateService::inbound($node);
            $response['protocol']='dboard-front-only';
            unset($response['cert_config'],$response['auto_tls']);
        }
        return $response;
    }

    /**
     * 根据协议类型和标识获取服务器
     * @param int $serverId
     * @param string $serverType
     * @return Server|null
     */
    public static function getServer($serverId, ?string $serverType = null): Server | null
    {
        return Server::query()
            ->when($serverType, function ($query) use ($serverType) {
                $query->where('type', Server::normalizeType($serverType));
            })
            ->where(function ($query) use ($serverId) {
                $query->where('code', $serverId)
                    ->orWhere('id', $serverId);
            })
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$serverId])
            ->first();
    }
}

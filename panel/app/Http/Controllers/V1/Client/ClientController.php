<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Protocols\General;
use App\Services\Plugin\HookManager;
use App\Services\MultiSubscriptionService;
use App\Services\ServerService;
use App\Services\TelegramUserAlertService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    /**
     * Protocol prefix mapping for server names
     */
    private const PROTOCOL_PREFIXES = [
        'hysteria' => [
            1 => '[Hy]',
            2 => '[Hy2]'
        ],
        'vless' => '[vless]',
        'shadowsocks' => '[ss]',
        'vmess' => '[vmess]',
        'trojan' => '[trojan]',
        'tuic' => '[tuic]',
        'socks' => '[socks]',
        'anytls' => '[anytls]'
    ];


    public function subscribe(Request $request)
    {
        HookManager::call('client.subscribe.before');
        $request->validate([
            'types' => ['nullable', 'string'],
            'filter' => ['nullable', 'string'],
            'flag' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $userService = new UserService();

        $account = MultiSubscriptionService::account($user);
        if ($account->banned || ($user->parent_id && !$userService->isAvailable($user))) {
            HookManager::call('client.subscribe.unavailable');
            return response('', 403, ['Content-Type' => 'text/plain']);
        }
        $combination = $request->attributes->get('subscription_combination');
        if ($combination) {
            $ids = $combination->package_ids;
            if (MultiSubscriptionService::activePackages($account, $ids)->isEmpty()) {
                return response('', 403, ['Content-Type' => 'text/plain']);
            }
            $servers = MultiSubscriptionService::mergedServers($account, $ids, $combination->duplicate_node_mode);
            $infoPackages = MultiSubscriptionService::packages($account)->filter(fn ($item) => $item->plan_id && in_array($item->id, $ids, true));
            $response = $this->doSubscribe($request, MultiSubscriptionService::mergedHeaderUser($account, $ids), $servers, $infoPackages);
        } elseif (!$user->parent_id && !$request->attributes->get('primary_package_subscription') && $account->subscription_link_mode === 'merged') {
            if (MultiSubscriptionService::activePackages($account)->isEmpty()) {
                return response('', 403, ['Content-Type' => 'text/plain']);
            }
            $servers = MultiSubscriptionService::mergedServers($account);
            $response = $this->doSubscribe($request, MultiSubscriptionService::mergedHeaderUser($account), $servers, MultiSubscriptionService::packages($account)->whereNotNull('plan_id'));
        } else {
            if (!$userService->isAvailable($user)) {
                return response('', 403, ['Content-Type' => 'text/plain']);
            }
            $response = $this->doSubscribe($request, $user);
        }
        if ($response->getStatusCode() < 400) {
            TelegramUserAlertService::recordSubscriptionAccess($user, (string) $request->ip());
        }
        return $response;
    }

    public function doSubscribe(Request $request, $user, $servers = null, $infoPackages = null)
    {
        if ($servers === null) {
            $servers = ServerService::getAvailableServers($user);
            $servers = HookManager::filter('client.subscribe.servers', $servers, $user, $request);
        }

        $clientInfo = $this->getClientInfo($request);

        $requestedTypes = $this->parseRequestedTypes($request->input('types'));
        $filterKeywords = $this->parseFilterKeywords($request->input('filter'));

        $protocolClassName = app('protocols.manager')->matchProtocolClassName($clientInfo['flag'])
            ?? General::class;

        $serversFiltered = $this->filterServers(
            servers: $servers,
            allowedTypes: $requestedTypes,
            filterKeywords: $filterKeywords
        );

        $this->setSubscribeInfoToServers($serversFiltered, $infoPackages ?? collect([$user]), count($servers) - count($serversFiltered));
        $serversFiltered = $this->addPrefixToServerName($serversFiltered);

        // Instantiate the protocol class with filtered servers and client info
        $protocolInstance = app()->make($protocolClassName, [
            'user' => $user,
            'servers' => $serversFiltered,
            'clientName' => $clientInfo['name'] ?? null,
            'clientVersion' => $clientInfo['version'] ?? null,
            'userAgent' => $clientInfo['flag'] ?? null
        ]);

        return $protocolInstance->handle();
    }

    /**
     * Parses the input string for requested server types.
     */
    private function parseRequestedTypes(?string $typeInputString): array
    {
        if (blank($typeInputString) || $typeInputString === 'all') {
            return Server::VALID_TYPES;
        }

        $requested = collect(preg_split('/[|,｜]+/', $typeInputString))
            ->map(fn($type) => trim($type))
            ->filter() // Remove empty strings that might result from multiple delimiters
            ->all();

        return array_values(array_intersect($requested, Server::VALID_TYPES));
    }

    /**
     * Parses the input string for filter keywords.
     */
    private function parseFilterKeywords(?string $filterInputString): ?array
    {
        if (blank($filterInputString) || mb_strlen($filterInputString) > 20) {
            return null;
        }

        return collect(preg_split('/[|,｜]+/', $filterInputString))
            ->map(fn($keyword) => trim($keyword))
            ->filter() // Remove empty strings
            ->all();
    }

    /**
     * Filters servers based on allowed types and keywords.
     */
    private function filterServers(array $servers, array $allowedTypes, ?array $filterKeywords): array
    {
        return collect($servers)->filter(function ($server) use ($allowedTypes, $filterKeywords) {
            // Condition 1: Server type must be in the list of allowed types
            if ($allowedTypes && !in_array($server['type'], $allowedTypes)) {
                return false; // Filter out (don't keep)
            }

            // Condition 2: If filterKeywords are provided, at least one keyword must match
            if (!empty($filterKeywords)) { // Check if $filterKeywords is not empty
                $keywordMatch = collect($filterKeywords)->contains(function ($keyword) use ($server) {
                    return stripos($server['name'], $keyword) !== false
                        || in_array($keyword, $server['tags'] ?? []);
                });
                if (!$keywordMatch) {
                    return false; // Filter out if no keywords match
                }
            }
            // Keep the server if its type is allowed AND (no filter keywords OR at least one keyword matched)
            return true;
        })->values()->all();
    }

    private function getClientInfo(Request $request): array
    {
        $flag = strtolower($request->input('flag') ?? $request->header('User-Agent', ''));

        $clientName = null;
        $clientVersion = null;

        if (preg_match('/([a-zA-Z0-9\-_]+)[\/\s]+(v?[0-9]+(?:\.[0-9]+){0,2})/', $flag, $matches)) {
            $potentialName = strtolower($matches[1]);
            $clientVersion = preg_replace('/^v/', '', $matches[2]);

            if (in_array($potentialName, app('protocols.flags'))) {
                $clientName = $potentialName;
            }
        }

        if (!$clientName) {
            $flags = collect(app('protocols.flags'))->sortByDesc(fn($f) => strlen($f))->values()->all();
            foreach ($flags as $name) {
                if (stripos($flag, $name) !== false) {
                    $clientName = $name;
                    if (!$clientVersion) {
                        $pattern = '/' . preg_quote($name, '/') . '[\/\s]+(v?[0-9]+(?:\.[0-9]+){0,2})/i';
                        if (preg_match($pattern, $flag, $vMatches)) {
                            $clientVersion = preg_replace('/^v/', '', $vMatches[1]);
                        }
                    }
                    break;
                }
            }
        }

        if (!$clientVersion) {
            if (preg_match('/\/v?(\d+(?:\.\d+){0,2})/', $flag, $matches)) {
                $clientVersion = $matches[1];
            }
        }

        return [
            'flag' => $flag,
            'name' => $clientName,
            'version' => $clientVersion
        ];
    }

    /** Information entries use an inert address and credentials, never a real node. */
    private function informationServer(array $template, string $name): array
    {
        return [
            'id' => 0, 'type' => $template['type'], 'name' => $name,
            'host' => 'subscription-info.invalid', 'port' => 1,
            'password' => '00000000-0000-0000-0000-000000000000',
            'rate' => 1, 'tags' => [], 'is_subscription_info' => true,
            'protocol_settings' => [
                'network' => 'tcp', 'tls' => 0, 'flow' => '',
                'cipher' => 'aes-128-gcm',
                'version' => $template['protocol_settings']['version'] ?? 2,
                'up_mbps' => 1, 'down_mbps' => 1,
            ],
        ];
    }

    private function setSubscribeInfoToServers(&$servers, $packages, $rejectServerCount = 0)
    {
        if (!isset($servers[0])) return;
        $template = $servers[0];
        $information = [];
        if ($rejectServerCount > 0) {
            $information[] = $this->informationServer($template, "过滤掉{$rejectServerCount}条线路（仅提示）");
        }
        if ((int) admin_setting('show_info_to_server_enable', 0)) {
            $userService = new UserService();
            $first = $packages->first();
            $displayNames = $first ? MultiSubscriptionService::displayNames(MultiSubscriptionService::account($first)) : [];
            foreach ($packages as $package) {
                $prefix = ($displayNames[$package->id] ?? $package->plan?->name ?? '套餐') . ' · ';
                $remaining = Helper::trafficConvert(max(0, $package->transfer_enable - $package->u - $package->d));
                $expiry = $package->expired_at ? date('Y-m-d', $package->expired_at) : __('长期有效');
                $reset = $userService->getResetDay($package);
                $resetText = $reset === null ? '不重置' : ($reset === 0 ? '今日重置' : "{$reset} 天");
                foreach (["剩余流量：{$remaining}", "距离下次重置：{$resetText}", "套餐到期：{$expiry}"] as $message) {
                    $information[] = $this->informationServer($template, $prefix . $message);
                }
            }
        }
        $servers = array_merge($information, $servers);
    }

    private function addPrefixToServerName(array $servers): array
    {
        if (!admin_setting('show_protocol_to_server_enable', false)) {
            return $servers;
        }
        return collect($servers)
            ->map(function (array $server): array {
                if (empty($server['is_subscription_info'])) $server['name'] = $this->getPrefixedServerName($server);
                return $server;
            })
            ->all();
    }

    private function getPrefixedServerName(array $server): string
    {
        $type = $server['type'] ?? '';
        if (!isset(self::PROTOCOL_PREFIXES[$type])) {
            return $server['name'] ?? '';
        }
        $prefix = is_array(self::PROTOCOL_PREFIXES[$type])
            ? self::PROTOCOL_PREFIXES[$type][$server['protocol_settings']['version'] ?? 1] ?? ''
            : self::PROTOCOL_PREFIXES[$type];
        return $prefix . ($server['name'] ?? '');
    }
}

<?php

namespace Plugin\SubscriptionGuard;

use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->listen('client.subscribe.before', [$this, 'onSubscribeBefore'], 5);
    }

    public function schedule(\Illuminate\Console\Scheduling\Schedule $schedule): void
    {
        $retentionDays = (int) $this->getConfig('log_retention_days', 30);
        if ($retentionDays > 0) {
            $schedule->call(function () use ($retentionDays) {
                $this->cleanupOldLogs($retentionDays);
            })->daily()->at('03:00');
        }
    }

    /**
     * 订阅拉取前钩子
     */
    public function onSubscribeBefore(): void
    {
        $request = request();
        $user = $request->user();
        if (!$user) {
            return;
        }

        // 排除管理员
        if ($this->getConfig('exclude_admin', true) && ($user->is_admin || $user->is_staff)) {
            return;
        }

        $ip = $this->resolveClientIp($request);
        $userAgent = $request->header('User-Agent', '');
        $token = $user->token;

        // IP白名单检查
        if ($this->isWhitelisted($ip)) {
            return;
        }

        // 频率限制：同一用户+IP在N秒内不重复记录
        $rateLimitSeconds = (int) $this->getConfig('rate_limit_seconds', 60);
        if ($rateLimitSeconds > 0) {
            $cacheKey = "sub_guard:rate:{$user->id}:{$ip}";
            if (Cache::has($cacheKey)) {
                return;
            }
            Cache::put($cacheKey, 1, $rateLimitSeconds);
        }

        // 获取IP归属地
        $region = $this->getIpRegion($ip);

        // 记录拉取日志
        DB::table('v2_subscribe_log')->insert([
            'user_id'    => $user->id,
            'ip'         => $ip,
            'ip_region'  => $region,
            'user_agent' => Str::limit($userAgent, 500),
            'token'      => hash('sha256', (string) $token),
            'created_at' => now(),
        ]);

        // 检测滥用
        $this->checkAbuse($user, $ip);
    }

    /**
     * 解析真实客户端IP
     *
     * 兼容常见CDN/反向代理场景：
     * - Cloudflare: CF-Connecting-IP
     * - Akamai/Cloudflare Enterprise: True-Client-IP
     * - 通用反代: X-Forwarded-For / X-Real-IP / Forwarded
     *
     * 安全策略：
     * 1. 优先使用 Laravel / OpenResty 已校验并还原的 request()->ip()
     * 2. 只有直接连接来源命中 trusted_proxy_ips 时，才读取 CDN/通用代理头
     */
    protected function resolveClientIp(Request $request): string
    {
        $requestIp = $this->normalizeIp((string) $request->ip());
        $remoteAddr = $this->normalizeIp((string) $request->server('REMOTE_ADDR', ''));

        // Prefer the IP already resolved by Laravel TrustProxies / OpenResty.
        // This prevents a client supplied CF-Connecting-IP/XFF header from
        // overriding the verified request IP.
        if ($requestIp !== '' && $requestIp !== $remoteAddr) {
            return $requestIp;
        }

        // Only an explicitly trusted directly-connected proxy may supply headers.
        if ($remoteAddr !== '' && $this->isTrustedProxy($remoteAddr)) {
            if ($this->getConfig('trust_cdn_headers', true)) {
                foreach ($this->getCdnHeaderNames() as $header) {
                    $ip = $this->extractClientIpFromHeader($request->header($header), $header);
                    if ($ip !== null) {
                        return $ip;
                    }
                }
            }

            foreach ($this->getGenericProxyHeaderNames() as $header) {
                $ip = $this->extractClientIpFromHeader($request->header($header), $header);
                if ($ip !== null) {
                    return $ip;
                }
            }
        }

        return $requestIp !== '' ? $requestIp : ($remoteAddr !== '' ? $remoteAddr : '0.0.0.0');
    }

    /**
     * CDN 专用真实IP头（仅受信代理来源下使用）
     */
    protected function getCdnHeaderNames(): array
    {
        $configured = trim((string) $this->getConfig(
            'cdn_real_ip_headers',
            'CF-Connecting-IP,True-Client-IP,Fastly-Client-IP,X-Azure-ClientIP'
        ));

        return $this->splitHeaderList($configured);
    }

    /**
     * 通用反代真实IP头（仅 trusted_proxy_ips 命中时读取）
     */
    protected function getGenericProxyHeaderNames(): array
    {
        $configured = trim((string) $this->getConfig(
            'proxy_real_ip_headers',
            'X-Forwarded-For,X-Real-IP,Forwarded'
        ));

        return $this->splitHeaderList($configured);
    }

    protected function splitHeaderList(string $headers): array
    {
        $items = array_filter(array_map('trim', explode(',', $headers)));
        return array_values(array_unique($items));
    }

    /**
     * 当前请求是否来自可信代理
     */
    protected function isTrustedProxy(string $ip): bool
    {
        $proxyList = trim((string) $this->getConfig('trusted_proxy_ips', ''));
        if ($proxyList === '') {
            return false;
        }

        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $proxyList)));
        foreach ($lines as $line) {
            if ($line === $ip) {
                return true;
            }

            if (str_contains($line, '/') && $this->ipInCidr($ip, $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 从指定请求头中提取客户端IP
     */
    protected function extractClientIpFromHeader(?string $value, ?string $headerName = null): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        // RFC 7239 Forwarded: for=1.2.3.4;proto=https;by=...
        if ($headerName !== null && strtolower($headerName) === 'forwarded') {
            if (preg_match('/for=(?:"?\[?)([^;,"]+)(?:\]?"?)/i', $value, $matches)) {
                $candidate = $this->normalizeIp($matches[1]);
                return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : null;
            }
            return null;
        }

        // X-Forwarded-For / 其他单IP头
        $parts = array_map('trim', explode(',', $value));
        foreach ($parts as $part) {
            if ($part === '' || strtolower($part) === 'unknown') {
                continue;
            }

            $candidate = $this->normalizeIp($part);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 归一化IP格式
     */
    protected function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return '';
        }

        // [2001:db8::1]:443
        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $ip, $matches)) {
            $ip = $matches[1];
        }

        // 兼容 IPv4:port
        if (preg_match('/^(\d+\.\d+\.\d+\.\d+):\d+$/', $ip, $matches)) {
            $ip = $matches[1];
        }

        // 兼容 IPv4-mapped IPv6
        if (stripos($ip, '::ffff:') === 0) {
            $mapped = substr($ip, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip = $mapped;
            }
        }

        return trim($ip, '"\' ');
    }

    /**
     * 检测是否滥用
     */
    protected function checkAbuse(User $user, string $currentIp): void
    {
        $maxIps = (int) $this->getConfig('max_unique_ips', 5);
        $windowHours = (int) $this->getConfig('time_window_hours', 24);
        $actionMode = $this->getConfig('action_mode', 'auto_ban');

        $since = now()->subHours($windowHours);

        // 获取时间窗口内的所有独立IP
        $ips = DB::table('v2_subscribe_log')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $since)
            ->distinct()
            ->pluck('ip')
            ->toArray();

        // 先排除白名单，再做子网合并，避免一个白名单地址代表整个子网。
        $ips = array_values(array_filter($ips, fn($ip) => !$this->isWhitelisted($ip)));

        // 子网合并：IPv4 按 /24，IPv6 按 /64。
        if ($this->getConfig('enable_subnet_grouping', false)) {
            $ips = $this->groupBySubnet($ips);
        }

        $uniqueCount = count($ips);

        // max_unique_ips 表示“允许的最大数量”，只有超过才处罚。
        if ($uniqueCount <= $maxIps) {
            return;
        }

        // 防止对同一用户重复触发（1小时内不重复处罚）
        $abuseKey = "sub_guard:abused:{$user->id}";
        if (Cache::has($abuseKey)) {
            return;
        }
        Cache::put($abuseKey, 1, 3600);

        // 获取IP详细信息
        $ipDetails = DB::table('v2_subscribe_log')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $since)
            ->select('ip', 'ip_region', DB::raw('COUNT(*) as pull_count'), DB::raw('MAX(created_at) as last_pull'))
            ->groupBy('ip', 'ip_region')
            ->orderByDesc('pull_count')
            ->get()
            ->toArray();

        $details = json_encode($ipDetails, JSON_UNESCAPED_UNICODE);

        // 记录滥用事件
        DB::table('v2_subscribe_abuse')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'unique_ip_count' => $uniqueCount,
                'ip_list'         => json_encode($ips),
                'action_taken'    => $actionMode,
                'details'         => $details,
                'created_at'      => now(),
            ]
        );

        // 执行处罚
        switch ($actionMode) {
            case 'auto_ban':
                $this->banAndResetUser($user, $uniqueCount, $ips);
                break;
            case 'reset_only':
                $this->resetSubscription($user, $uniqueCount, $ips);
                break;
            case 'alert_only':
                $this->alertAdmin($user, $uniqueCount, $ips);
                break;
        }
    }

    /**
     * 封禁用户并重置订阅
     */
    protected function banAndResetUser(User $user, int $ipCount, array $ips): void
    {
        $banReason = $this->getConfig('ban_reason', '订阅共享滥用 - 检测到多IP拉取');
        $oldToken = $user->token;

        $user->banned = true;
        $user->token = Str::random(32);
        $user->remarks = ($user->remarks ? $user->remarks . "\n" : '')
            . "[" . now()->format('Y-m-d H:i') . "] {$banReason} (IP数: {$ipCount})";
        $user->save();

        Log::warning("[SubscriptionGuard] 用户被封禁", [
            'user_id'   => $user->id,
            'email'     => $user->email,
            'ip_count'  => $ipCount,
            'ips'       => $ips,
            'old_token_sha256' => hash('sha256', (string) $oldToken),
        ]);

        $this->notifyAdmin($user, $ipCount, $ips, '已封禁+重置订阅');

        // 中断当前请求
        $this->intercept(response('', 403, ['Content-Type' => 'text/plain']));
    }

    /**
     * 仅重置订阅不封禁
     */
    protected function resetSubscription(User $user, int $ipCount, array $ips): void
    {
        $user->token = Str::random(32);
        $user->remarks = ($user->remarks ? $user->remarks . "\n" : '')
            . "[" . now()->format('Y-m-d H:i') . "] 订阅已重置 - 多IP拉取检测 (IP数: {$ipCount})";
        $user->save();

        Log::warning("[SubscriptionGuard] 用户订阅已重置", [
            'user_id'  => $user->id,
            'email'    => $user->email,
            'ip_count' => $ipCount,
            'ips'      => $ips,
        ]);

        $this->notifyAdmin($user, $ipCount, $ips, '已重置订阅');

        $this->intercept(response('', 403, ['Content-Type' => 'text/plain']));
    }

    /**
     * 仅通知管理员
     */
    protected function alertAdmin(User $user, int $ipCount, array $ips): void
    {
        Log::warning("[SubscriptionGuard] 检测到可疑共享", [
            'user_id'  => $user->id,
            'email'    => $user->email,
            'ip_count' => $ipCount,
            'ips'      => $ips,
        ]);

        $this->notifyAdmin($user, $ipCount, $ips, '仅告警(未处罚)');
    }

    /**
     * 通过Telegram通知管理员
     */
    protected function notifyAdmin(User $user, int $ipCount, array $ips, string $action): void
    {
        if (!$this->getConfig('enable_telegram_notify', true)) {
            return;
        }

        try {
            $windowHours = (int) $this->getConfig('time_window_hours', 24);

            // 获取IP详情
            $since = now()->subHours($windowHours);
            $ipLogs = DB::table('v2_subscribe_log')
                ->where('user_id', $user->id)
                ->where('created_at', '>=', $since)
                ->select('ip', 'ip_region', DB::raw('COUNT(*) as cnt'))
                ->groupBy('ip', 'ip_region')
                ->orderByDesc('cnt')
                ->limit(10)
                ->get();

            $ipList = '';
            foreach ($ipLogs as $log) {
                $ipList .= "  `{$log->ip}` ({$log->ip_region}) x{$log->cnt}\n";
            }

            $message = "🚨 *订阅共享告警*\n"
                . "━━━━━━━━━━━━━━━━━━━━\n"
                . "📧 用户: `{$user->email}` (#{$user->id})\n"
                . "📊 独立IP数: *{$ipCount}*\n"
                . "⏰ 检测窗口: {$windowHours}小时\n"
                . "⚡ 处理: *{$action}*\n"
                . "━━━━━━━━━━━━━━━━━━━━\n"
                . "📍 IP列表:\n{$ipList}";

            $telegramService = new TelegramService();
            $telegramService->sendMessageWithAdmin($message, true);
        } catch (\Exception $e) {
            Log::error("[SubscriptionGuard] Telegram通知失败: " . $e->getMessage());
        }
    }

    /**
     * 获取IP归属地
     */
    protected function getIpRegion(string $ip): string
    {
        try {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return 'IPv6';
            }
            return (new \Ip2Region())->simple($ip) ?: '未知';
        } catch (\Exception $e) {
            return '未知';
        }
    }

    /**
     * IP白名单检查
     */
    protected function isWhitelisted(string $ip): bool
    {
        $whitelist = $this->getConfig('ip_whitelist', '');
        if (empty($whitelist)) {
            return false;
        }

        $lines = array_filter(array_map('trim', explode("\n", $whitelist)));
        foreach ($lines as $line) {
            if ($line === $ip) {
                return true;
            }
            // CIDR 匹配
            if (str_contains($line, '/') && $this->ipInCidr($ip, $line)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 检查IP是否在CIDR范围内
     */
    protected function ipInCidr(string $ip, string $cidr): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $parts = explode('/', $cidr);
        if (count($parts) !== 2) {
            return false;
        }

        $subnet = ip2long($parts[0]);
        $mask = -1 << (32 - (int) $parts[1]);
        $ipLong = ip2long($ip);

        return ($ipLong & $mask) === ($subnet & $mask);
    }

    /**
     * 子网合并：IPv4 按 /24、IPv6 按 /64 归组
     */
    protected function groupBySubnet(array $ips): array
    {
        $groups = [];

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $parts = explode('.', $ip);
                $key = 'v4:' . $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
                $groups[$key] ??= $ip;
                continue;
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $packed = @inet_pton($ip);
                if ($packed !== false && strlen($packed) === 16) {
                    // First 64 bits identify the IPv6 /64 network.
                    $key = 'v6:' . bin2hex(substr($packed, 0, 8));
                    $groups[$key] ??= $ip;
                    continue;
                }
            }

            $groups['other:' . $ip] ??= $ip;
        }

        return array_values($groups);
    }

    /**
     * 清理过期日志
     */
    protected function cleanupOldLogs(int $retentionDays): void
    {
        $before = now()->subDays($retentionDays);
        $deleted = DB::table('v2_subscribe_log')
            ->where('created_at', '<', $before)
            ->delete();

        if ($deleted > 0) {
            Log::info("[SubscriptionGuard] 清理了 {$deleted} 条过期日志");
        }
    }
}

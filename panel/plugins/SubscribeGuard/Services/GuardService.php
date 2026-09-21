<?php

namespace Plugin\SubscribeGuard\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class GuardService
{
    private array $config;
    private string $logFile;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->logFile = storage_path('app/subscribe-guard/blocked.jsonl');
    }

    public function inspect(Request $request): array
    {
        $ip = $this->getRealIp($request);
        $userAgent = trim((string) $request->header('User-Agent', ''));

        if ($this->ipInList($ip, $this->arrayConfig('ip_whitelist'))) {
            return $this->allow('ip_whitelist', $ip, $userAgent);
        }

        if ($userAgent === '') {
            return $this->boolConfig('allow_unknown_user_agent', false)
                ? $this->allow('empty_user_agent_allowed', $ip, $userAgent)
                : $this->block('empty_user_agent', $ip, $userAgent);
        }

        $allowedKeyword = $this->matchKeyword($userAgent, $this->arrayConfig('allowed_user_agents'));
        if ($allowedKeyword !== null) {
            return $this->allow('allowed_user_agent:' . $allowedKeyword, $ip, $userAgent);
        }

        $blockedKeyword = $this->matchKeyword($userAgent, $this->arrayConfig('blocked_user_agents'));
        if ($blockedKeyword !== null) {
            return $this->block('blocked_user_agent:' . $blockedKeyword, $ip, $userAgent);
        }

        return $this->boolConfig('allow_unknown_user_agent', false)
            ? $this->allow('unknown_user_agent_allowed', $ip, $userAgent)
            : $this->block('unknown_user_agent', $ip, $userAgent);
    }

    public function recordBlockedRequest(Request $request, array $result): void
    {
        $ipInfo = $this->resolveRealIp($request);
        $subscriptionUser = $this->resolveSubscriptionUser($request);

        $entry = [
            'time' => now()->toDateTimeString(),
            'ip' => $result['ip'] ?? $ipInfo['ip'],
            'ip_source' => $ipInfo['source'],
            'remote_ip' => $ipInfo['direct_ip'],
            'request_ip' => $ipInfo['request_ip'],
            'user_id' => $subscriptionUser['id'],
            'user_email' => $subscriptionUser['email'],
            'ua' => $result['user_agent'] ?? (string) $request->header('User-Agent', ''),
            'reason' => $result['reason'] ?? 'unknown',
            'path' => '/' . ltrim($request->path(), '/'),
            'method' => $request->method(),
            'query' => $this->safeQuery($request),
        ];

        if ($this->isDuplicateLog($entry)) {
            return;
        }

        Log::warning('[SubscribeGuard] blocked subscription request', $entry);
        $this->appendLog($entry);
    }

    public function getRecentLogs(int $limit = 100): array
    {
        $limit = max(1, min($limit, 1000));

        if (!File::exists($this->logFile)) {
            return [];
        }

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -$limit);
        $logs = [];

        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $logs[] = $decoded;
            }
        }

        return $logs;
    }

    public function clearLogs(): void
    {
        if (File::exists($this->logFile)) {
            File::delete($this->logFile);
        }
    }

    public function getRealIp(Request $request): string
    {
        return $this->resolveRealIp($request)['ip'];
    }

    public function resolveRealIp(Request $request): array
    {
        $requestIp = $this->normalizeIp((string) $request->ip()) ?? '';
        $directIp = $this->normalizeIp((string) ($request->server('REMOTE_ADDR') ?: '')) ?? '';

        // DBoard / Laravel TrustProxies is the primary source of truth.
        // When it has already resolved a proxy chain, never re-read client supplied
        // forwarding headers inside the plugin.
        if ($requestIp !== '' && $requestIp !== $directIp) {
            return [
                'ip' => $requestIp,
                'source' => 'request_ip',
                'direct_ip' => $directIp,
                'request_ip' => $requestIp,
            ];
        }

        // Header fallback is allowed only when the directly connected peer is
        // explicitly trusted. Wildcard trust is intentionally not the default.
        $trustedProxies = $this->arrayConfig('trusted_proxies', ['127.0.0.1', '::1']);
        $trusted = $directIp !== '' && $this->ipInList($directIp, $trustedProxies);

        if ($trusted) {
            foreach ($this->getIpHeaders() as $header) {
                $value = trim((string) $request->header($header, ''));
                if ($value === '') {
                    continue;
                }

                $ip = $this->extractIpFromHeader($header, $value, $trustedProxies);
                if ($ip === null) {
                    continue;
                }

                if ($this->isGenericIpHeader($header) && in_array($ip, array_filter([$directIp, $requestIp]), true)) {
                    continue;
                }

                return [
                    'ip' => $ip,
                    'source' => $header,
                    'direct_ip' => $directIp,
                    'request_ip' => $requestIp,
                ];
            }
        }

        $fallback = $requestIp !== '' ? $requestIp : $directIp;

        return [
            'ip' => $fallback !== '' ? $fallback : '0.0.0.0',
            'source' => $trusted ? 'request_ip_no_header' : 'request_ip_untrusted_proxy',
            'direct_ip' => $directIp,
            'request_ip' => $requestIp,
        ];
    }

    private function allow(string $reason, string $ip, string $userAgent): array
    {
        return [
            'allowed' => true,
            'reason' => $reason,
            'ip' => $ip,
            'user_agent' => $userAgent,
        ];
    }

    private function block(string $reason, string $ip, string $userAgent): array
    {
        return [
            'allowed' => false,
            'reason' => $reason,
            'ip' => $ip,
            'user_agent' => $userAgent,
        ];
    }

    private function matchKeyword(string $value, array $keywords): ?string
    {
        $value = strtolower($value);

        foreach ($keywords as $keyword) {
            $keyword = strtolower(trim((string) $keyword));
            if ($keyword !== '' && str_contains($value, $keyword)) {
                return $keyword;
            }
        }

        return null;
    }

    private function extractIpFromHeader(string $header, string $value, array $trustedProxies = ['127.0.0.1', '::1']): ?string
    {
        $ips = [];

        if (strtolower($header) === 'forwarded') {
            if (preg_match_all('/for="?\[?([^;,\]"]+)\]?"?/i', $value, $matches)) {
                foreach ($matches[1] as $match) {
                    $ip = $this->normalizeIp($match);
                    if ($ip !== null) {
                        $ips[] = $ip;
                    }
                }
            }
        } else {
            foreach (explode(',', $value) as $part) {
                $ip = $this->normalizeIp(trim($part));
                if ($ip !== null) {
                    $ips[] = $ip;
                }
            }
        }

        if (empty($ips)) {
            return null;
        }

        foreach ($ips as $ip) {
            if (!$this->ipInList($ip, $trustedProxies)) {
                return $ip;
            }
        }

        return $ips[0];
    }

    private function getIpHeaders(): array
    {
        $configured = $this->arrayConfig('ip_headers');

        $defaults = [
            'CF-Connecting-IP',
            'True-Client-IP',
            'X-Azure-ClientIP',
            'X-Azure-SocketIP',
            'Fly-Client-IP',
            'Fastly-Client-IP',
            'X-Vercel-Forwarded-For',
            'X-Original-Forwarded-For',
            'X-Forwarded-For',
            'Forwarded',
            'X-Real-IP',
            'X-Client-IP',
            'X-Cluster-Client-IP',
        ];

        $headers = array_merge($defaults, $configured);
        $headers = array_filter(array_map(fn($header) => trim((string) $header), $headers));

        return array_values(array_unique($headers));
    }

    private function isGenericIpHeader(string $header): bool
    {
        return in_array(strtolower($header), [
            'x-real-ip',
            'x-client-ip',
            'x-cluster-client-ip',
        ], true);
    }

    private function normalizeIp(string $value): ?string
    {
        $value = trim($value, ' "[]');

        if ($value === '') {
            return null;
        }

        if (str_contains($value, ':') && substr_count($value, ':') === 1 && str_contains($value, '.')) {
            $value = explode(':', $value, 2)[0];
        }

        return filter_var($value, FILTER_VALIDATE_IP) ? $value : null;
    }

    private function ipInList(string $ip, array $rules): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach ($rules as $rule) {
            $rule = trim((string) $rule);
            if ($rule === '') {
                continue;
            }

            if ($rule === '*') {
                return true;
            }

            if ($this->ipMatchesRule($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    private function ipMatchesRule(string $ip, string $rule): bool
    {
        if (!str_contains($rule, '/')) {
            return $ip === $rule;
        }

        [$network, $prefix] = explode('/', $rule, 2);
        $network = trim($network);
        $prefix = (int) $prefix;

        $ipBin = @inet_pton($ip);
        $networkBin = @inet_pton($network);

        if ($ipBin === false || $networkBin === false || strlen($ipBin) !== strlen($networkBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($networkBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($networkBin[$fullBytes]) & $mask);
    }

    private function isDuplicateLog(array $entry): bool
    {
        $ttl = max(0, (int) ($this->config['log_dedup_ttl'] ?? 300));
        if ($ttl <= 0) {
            return false;
        }

        $fingerprint = sha1(implode('|', [
            $entry['ip'] ?? '',
            $entry['ua'] ?? '',
            $entry['reason'] ?? '',
            $entry['path'] ?? '',
        ]));

        $key = 'subscribe_guard:blocked_log:' . $fingerprint;
        if (Cache::has($key)) {
            return true;
        }

        Cache::put($key, true, now()->addSeconds($ttl));

        return false;
    }

    private function appendLog(array $entry): void
    {
        File::ensureDirectoryExists(dirname($this->logFile));
        File::append($this->logFile, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        $this->truncateLogs();
    }

    private function truncateLogs(): void
    {
        $max = max(100, (int) ($this->config['max_log_entries'] ?? 1000));

        if (!File::exists($this->logFile)) {
            return;
        }

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if (count($lines) <= $max) {
            return;
        }

        $lines = array_slice($lines, -$max);
        File::put($this->logFile, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    private function resolveSubscriptionUser(Request $request): array
    {
        $token = $this->extractSubscriptionToken($request);

        if ($token === null) {
            return [
                'id' => null,
                'email' => null,
            ];
        }

        $user = User::query()
            ->select(['id', 'email'])
            ->where('token', $token)
            ->first();

        return [
            'id' => $user?->id,
            'email' => $user?->email,
        ];
    }

    private function extractSubscriptionToken(Request $request): ?string
    {
        $token = $request->route('token') ?: $request->query('token');

        if (is_string($token) && trim($token) !== '') {
            return trim($token);
        }

        $segments = array_values(array_filter(explode('/', trim($request->path(), '/'))));
        $subscribePath = trim((string) admin_setting('subscribe_path', 's'), '/');

        if ($subscribePath !== '') {
            foreach ($segments as $index => $segment) {
                if ($segment === $subscribePath && isset($segments[$index + 1])) {
                    return trim($segments[$index + 1]);
                }
            }
        }

        $lastSegment = end($segments);
        return is_string($lastSegment) && trim($lastSegment) !== '' ? trim($lastSegment) : null;
    }

    private function safeQuery(Request $request): array
    {
        $query = $request->query();

        foreach (['token', 'access_token', 'key', 'secret'] as $key) {
            if (isset($query[$key])) {
                $query[$key] = '***';
            }
        }

        return $query;
    }

    private function arrayConfig(string $key, array $default = []): array
    {
        $value = $this->config[$key] ?? $default;

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            return array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: []));
        }

        return $default;
    }

    private function boolConfig(string $key, bool $default = false): bool
    {
        $value = $this->config[$key] ?? $default;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
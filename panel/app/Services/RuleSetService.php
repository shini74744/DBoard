<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RuleSetService
{
    private const CATALOG_URL = 'https://api.github.com/repos/blackmatrix7/ios_rule_script/contents/rule/Clash?ref=master';
    private const RAW_BASE = 'https://raw.githubusercontent.com/blackmatrix7/ios_rule_script/master/rule/Clash/';
    private const CATALOG_CACHE_KEY = 'xboard:blackmatrix:clash:catalog';
    private const CATALOG_TTL = 21600;
    private const MAX_RULE_BYTES = 8388608;

    public function apps(bool $force = false): array
    {
        if ($force) {
            Cache::forget(self::CATALOG_CACHE_KEY);
        }

        return Cache::remember(self::CATALOG_CACHE_KEY, self::CATALOG_TTL, function () {
            $response = Http::withHeaders([
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Xboard-Custom',
            ])->timeout(20)->get(self::CATALOG_URL);

            if (!$response->successful()) {
                throw new ApiException('获取 blackmatrix7 应用目录失败：HTTP ' . $response->status());
            }

            $items = $response->json();
            if (!is_array($items)) {
                throw new ApiException('blackmatrix7 应用目录格式无效');
            }

            $apps = [];
            foreach ($items as $item) {
                if (($item['type'] ?? '') !== 'dir') {
                    continue;
                }
                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '' || !preg_match("/^[A-Za-z0-9_.@+'-]+$/", $name)) {
                    continue;
                }
                $apps[] = [
                    'name' => $name,
                    'path' => $name . '/' . $name . '.list',
                    'category' => $this->category($name),
                ];
            }

            usort($apps, fn($a, $b) => strcasecmp($a['name'], $b['name']));
            if (!$apps) {
                throw new ApiException('blackmatrix7 应用目录为空');
            }

            return $apps;
        });
    }

    public function resolve(string $path): array
    {
        $path = trim($path);
        if (!preg_match("/^[A-Za-z0-9_.@+'-]+\/[A-Za-z0-9_.@+'-]+\.list$/", $path)) {
            throw new ApiException('第三方规则路径无效');
        }

        [$dir, $file] = explode('/', $path, 2);
        $url = self::RAW_BASE . rawurlencode($dir) . '/' . rawurlencode($file);
        $data = $this->fetchFixedUrl($url);
        $result = $this->parseList($dir, $path, $data);

        if (($result['imported'] ?? 0) === 0) {
            throw new ApiException('该规则文件没有可转换的路由条目');
        }

        return $result;
    }

    public function resolveUrl(string $url): array
    {
        $url = trim($url);
        $data = $this->fetchPublicUrl($url);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $name = pathinfo(basename($path), PATHINFO_FILENAME) ?: 'CustomList';

        $result = $this->parseList($name, $url, $data);
        if (($result['imported'] ?? 0) === 0) {
            throw new ApiException('该自定义 List 没有可转换的路由条目');
        }

        return $result;
    }

    public function resolveMany(array $paths): array
    {
        $paths = array_values(array_unique(array_filter(array_map(
            fn($value) => trim((string) $value),
            $paths
        ))));

        if (!$paths) {
            throw new ApiException('至少选择一个应用规则');
        }
        if (count($paths) > 40) {
            throw new ApiException('一次最多合并 40 个应用规则');
        }

        $domains = [];
        $ips = [];
        $domainSeen = [];
        $ipSeen = [];
        $failed = [];
        $typeCounts = [];
        $skipped = 0;
        $files = 0;

        foreach ($paths as $path) {
            try {
                $result = $this->resolve($path);
                $files++;
                $skipped += (int) ($result['skipped'] ?? 0);

                foreach (($result['typeCounts'] ?? []) as $kind => $count) {
                    $typeCounts[$kind] = ($typeCounts[$kind] ?? 0) + (int) $count;
                }

                foreach (($result['domains'] ?? []) as $value) {
                    if (!isset($domainSeen[$value])) {
                        $domainSeen[$value] = true;
                        $domains[] = $value;
                    }
                }
                foreach (($result['ips'] ?? []) as $value) {
                    if (!isset($ipSeen[$value])) {
                        $ipSeen[$value] = true;
                        $ips[] = $value;
                    }
                }
            } catch (\Throwable) {
                $failed[] = $path;
            }
        }

        sort($failed);
        if (!$domains && !$ips) {
            throw new ApiException('所选应用没有可导入的路由条目');
        }

        return [
            'domains' => $domains,
            'ips' => $ips,
            'imported' => count($domains) + count($ips),
            'skipped' => $skipped,
            'files' => $files,
            'failed' => $failed,
            'typeCounts' => $typeCounts,
        ];
    }

    private function parseList(string $name, string $path, string $data): array
    {
        $domains = [];
        $ips = [];
        $domainSeen = [];
        $ipSeen = [];
        $typeCounts = [];
        $skippedTypes = [];
        $imported = 0;
        $skipped = 0;
        $updated = null;

        $text = str_replace(["\r\n", "\r"], "\n", $data);
        foreach (explode("\n", $text) as $raw) {
            $line = trim(ltrim($raw, "\xEF\xBB\xBF"));
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '#')) {
                if (str_starts_with($line, '# UPDATED:')) {
                    $updated = trim(substr($line, strlen('# UPDATED:')));
                }
                continue;
            }

            $parts = array_map('trim', explode(',', $line));
            if (count($parts) < 2 || $parts[1] === '') {
                $skipped++;
                $skippedTypes['INVALID'] = ($skippedTypes['INVALID'] ?? 0) + 1;
                continue;
            }

            $kind = strtoupper($parts[0]);
            $value = $parts[1];
            $typeCounts[$kind] = ($typeCounts[$kind] ?? 0) + 1;
            $converted = null;
            $isDomain = false;

            switch ($kind) {
                case 'DOMAIN':
                    $converted = 'full:' . $value;
                    $isDomain = true;
                    break;
                case 'DOMAIN-SUFFIX':
                    $converted = 'domain:' . $value;
                    $isDomain = true;
                    break;
                case 'DOMAIN-KEYWORD':
                    $converted = 'keyword:' . $value;
                    $isDomain = true;
                    break;
                case 'DOMAIN-REGEX':
                    $converted = 'regexp:' . $value;
                    $isDomain = true;
                    break;
                case 'GEOSITE':
                    $converted = 'geosite:' . $value;
                    $isDomain = true;
                    break;
                case 'IP-CIDR':
                case 'IP-CIDR6':
                    $converted = $value;
                    break;
                case 'GEOIP':
                    $converted = 'geoip:' . strtolower($value);
                    break;
                default:
                    $skipped++;
                    $skippedTypes[$kind] = ($skippedTypes[$kind] ?? 0) + 1;
                    continue 2;
            }

            if ($isDomain) {
                if (!isset($domainSeen[$converted])) {
                    $domainSeen[$converted] = true;
                    $domains[] = $converted;
                    $imported++;
                }
            } else {
                if (!isset($ipSeen[$converted])) {
                    $ipSeen[$converted] = true;
                    $ips[] = $converted;
                    $imported++;
                }
            }
        }

        return [
            'name' => $name,
            'path' => $path,
            'domains' => $domains,
            'ips' => $ips,
            'imported' => $imported,
            'skipped' => $skipped,
            'typeCounts' => $typeCounts,
            'skippedTypes' => $skippedTypes,
            'updated' => $updated,
        ];
    }

    private function fetchFixedUrl(string $url): string
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Xboard-Custom',
            'Accept' => 'text/plain,*/*;q=0.8',
        ])->timeout(25)->get($url);

        if (!$response->successful()) {
            throw new ApiException('下载规则失败：HTTP ' . $response->status());
        }

        $body = $response->body();
        if (strlen($body) > self::MAX_RULE_BYTES) {
            throw new ApiException('远端规则内容超过安全限制');
        }

        return $body;
    }

    private function fetchPublicUrl(string $url, int $redirects = 0): string
    {
        if ($redirects > 5) {
            throw new ApiException('自定义 List 重定向次数过多');
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new ApiException('自定义 List 地址格式无效');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new ApiException('自定义 List 仅支持公开 http/https 地址');
        }

        if (strcasecmp($host, 'localhost') === 0 || str_ends_with(strtolower($host), '.localhost')) {
            throw new ApiException('自定义 List 不允许访问 localhost/内网地址');
        }

        $ips = $this->resolvePublicIps($host);
        if (!$ips) {
            throw new ApiException('自定义 List 域名没有解析到公网地址');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $headers = [];
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'Xboard-Custom',
            CURLOPT_HTTPHEADER => ['Accept: text/plain,*/*;q=0.8'],
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ips[0]],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $len = strlen($line);
                $line = trim($line);
                if ($line !== '' && str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return $len;
            },
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new ApiException('下载自定义 List 失败：' . $error);
        }

        if ($status >= 300 && $status < 400 && !empty($headers['location'])) {
            $next = $this->resolveRedirectUrl($url, $headers['location']);
            return $this->fetchPublicUrl($next, $redirects + 1);
        }

        if ($status !== 200) {
            throw new ApiException('远端规则返回 HTTP ' . $status);
        }
        if (strlen($body) > self::MAX_RULE_BYTES) {
            throw new ApiException('远端规则内容超过安全限制');
        }

        return $body;
    }

    private function resolvePublicIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host) ? [$host] : [];
        }

        $ips = [];
        foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip && $this->isPublicIp($ip)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    private function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            $start = ip2long('100.64.0.0');
            $end = ip2long('100.127.255.255');
            if ($long !== false && $long >= $start && $long <= $end) {
                return false;
            }
        }

        return true;
    }

    private function resolveRedirectUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        if (!is_array($parts)) {
            throw new ApiException('重定向地址无效');
        }

        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $port . $location;
        }

        $path = $parts['path'] ?? '/';
        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
        return $scheme . '://' . $host . $port . ($dir ? $dir . '/' : '/') . $location;
    }

    private function category(string $name): string
    {
        $sets = [
            'ai' => ['Anthropic', 'BardAI', 'Civitai', 'Claude', 'Copilot', 'Gemini', 'OpenAI'],
            'social' => ['Discord', 'Facebook', 'Instagram', 'Line', 'LinkedIn', 'Pinterest', 'Reddit', 'Telegram', 'TelegramNL', 'TelegramSG', 'TelegramUS', 'Threads', 'Twitter', 'WeChat', 'Whatsapp'],
            'adult' => ['EHGallery', 'Japonx'],
            'music' => ['Spotify', 'AppleMusic', 'YouTubeMusic', 'Tidal', 'Pandora', 'SoundCloud', 'Deezer', 'KKBOX', 'JOOX'],
            'game' => ['Steam', 'SteamCN', 'Epic', 'EpicGames', 'PlayStation', 'Nintendo', 'Xbox', 'Blizzard', 'EA', 'Ubisoft', 'Riot'],
            'video' => ['Abema', 'AbemaTV', 'AmazonPrimeVideo', 'Bahamut', 'BiliBili', 'BiliBiliIntl', 'Dailymotion', 'Disney', 'HBO', 'HBOAsia', 'HBOHK', 'HBOUSA', 'Hulu', 'HuluJP', 'HuluUSA', 'iQIYI', 'iQIYIIntl', 'Netflix', 'Niconico', 'PrimeVideo', 'TikTok', 'Twitch', 'Vimeo', 'YouTube'],
        ];

        foreach ($sets as $category => $items) {
            if (in_array($name, $items, true)) {
                return $category;
            }
        }

        return 'other';
    }
}

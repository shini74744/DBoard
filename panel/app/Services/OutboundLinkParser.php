<?php

namespace App\Services;

use App\Exceptions\ApiException;

class OutboundLinkParser
{
    public function parse(string $link): array
    {
        $link = trim($link);
        if ($link === '') {
            throw new ApiException('节点链接不能为空');
        }

        $scheme = strtolower((string) parse_url($link, PHP_URL_SCHEME));

        return match ($scheme) {
            'vmess' => $this->parseVmess($link),
            'vless' => $this->parseUriProxy($link, 'vless'),
            'trojan' => $this->parseUriProxy($link, 'trojan'),
            'ss' => $this->parseShadowsocks($link),
            default => throw new ApiException("暂不支持的出站协议: {$scheme}"),
        };
    }

    private function parseVmess(string $link): array
    {
        $payload = substr($link, strlen('vmess://'));
        $json = $this->decodeBase64($payload);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new ApiException('VMess 链接解析失败');
        }

        $server = trim((string) ($data['add'] ?? ''));
        $port = (int) ($data['port'] ?? 0);
        $uuid = trim((string) ($data['id'] ?? ''));

        $this->assertEndpoint($server, $port);
        if ($uuid === '') {
            throw new ApiException('VMess UUID 不能为空');
        }

        $tls = strtolower((string) ($data['tls'] ?? ''));
        $settings = $this->clean([
            'server' => $server,
            'server_port' => $port,
            'uuid' => $uuid,
            'alter_id' => (int) ($data['aid'] ?? 0),
            'security' => (string) ($data['scy'] ?? $data['security'] ?? 'auto'),
            'network' => (string) ($data['net'] ?? 'tcp'),
            'tls_mode' => in_array($tls, ['tls', 'reality'], true) ? $tls : 'none',
            'server_name' => (string) ($data['sni'] ?? ''),
            'host' => (string) ($data['host'] ?? ''),
            'path' => (string) ($data['path'] ?? ''),
            'service_name' => (string) ($data['serviceName'] ?? ''),
            'fingerprint' => (string) ($data['fp'] ?? ''),
            'alpn' => $this->splitList($data['alpn'] ?? null),
        ]);

        $name = trim((string) ($data['ps'] ?? '')) ?: $this->defaultName('vmess', $server, $port);

        return $this->result('vmess', $name, $settings, $link);
    }

    private function parseUriProxy(string $link, string $protocol): array
    {
        $parts = parse_url($link);
        if (!is_array($parts)) {
            throw new ApiException(strtoupper($protocol) . ' 链接解析失败');
        }

        $server = trim((string) ($parts['host'] ?? ''));
        $port = (int) ($parts['port'] ?? 0);
        $credential = rawurldecode((string) ($parts['user'] ?? ''));

        $this->assertEndpoint($server, $port);
        if ($credential === '') {
            throw new ApiException(strtoupper($protocol) . ' 认证信息不能为空');
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $security = strtolower((string) ($query['security'] ?? ($protocol === 'trojan' ? 'tls' : 'none')));
        $network = strtolower((string) ($query['type'] ?? 'tcp'));

        $settings = [
            'server' => $server,
            'server_port' => $port,
            'network' => $network,
            'tls_mode' => in_array($security, ['tls', 'reality'], true) ? $security : 'none',
            'server_name' => rawurldecode((string) ($query['sni'] ?? $query['serverName'] ?? '')),
            'host' => rawurldecode((string) ($query['host'] ?? '')),
            'path' => rawurldecode((string) ($query['path'] ?? '')),
            'service_name' => rawurldecode((string) ($query['serviceName'] ?? $query['service_name'] ?? '')),
            'fingerprint' => (string) ($query['fp'] ?? ''),
            'alpn' => $this->splitList($query['alpn'] ?? null),
            'flow' => (string) ($query['flow'] ?? ''),
            'public_key' => (string) ($query['pbk'] ?? $query['publicKey'] ?? ''),
            'short_id' => (string) ($query['sid'] ?? $query['shortId'] ?? ''),
            'spider_x' => rawurldecode((string) ($query['spx'] ?? '')),
        ];

        if ($protocol === 'vless') {
            $settings['uuid'] = $credential;
            $settings['encryption'] = (string) ($query['encryption'] ?? 'none');
        } else {
            $settings['password'] = $credential;
        }

        $settings = $this->clean($settings);
        $name = rawurldecode((string) ($parts['fragment'] ?? ''))
            ?: $this->defaultName($protocol, $server, $port);

        return $this->result($protocol, $name, $settings, $link);
    }

    private function parseShadowsocks(string $link): array
    {
        $body = substr($link, strlen('ss://'));
        $fragment = '';
        if (str_contains($body, '#')) {
            [$body, $fragment] = explode('#', $body, 2);
        }

        $queryString = '';
        if (str_contains($body, '?')) {
            [$body, $queryString] = explode('?', $body, 2);
        }

        $decoded = $body;
        if (!str_contains($decoded, '@')) {
            $decoded = $this->decodeBase64($decoded);
        }

        if (!str_contains($decoded, '@')) {
            throw new ApiException('Shadowsocks 链接缺少服务器信息');
        }

        [$auth, $endpoint] = explode('@', $decoded, 2);
        if (!str_contains($auth, ':')) {
            $auth = $this->decodeBase64($auth);
        }

        if (!str_contains($auth, ':')) {
            throw new ApiException('Shadowsocks 认证信息格式错误');
        }

        [$method, $password] = explode(':', $auth, 2);
        [$server, $port] = $this->parseHostPort($endpoint);
        $this->assertEndpoint($server, $port);

        parse_str($queryString, $query);
        $plugin = rawurldecode((string) ($query['plugin'] ?? ''));
        $pluginName = '';
        $pluginOpts = '';
        if ($plugin !== '') {
            $segments = explode(';', $plugin, 2);
            $pluginName = $segments[0];
            $pluginOpts = $segments[1] ?? '';
        }

        $settings = $this->clean([
            'server' => $server,
            'server_port' => $port,
            'method' => rawurldecode($method),
            'password' => rawurldecode($password),
            'plugin' => $pluginName,
            'plugin_opts' => $pluginOpts,
        ]);

        $name = rawurldecode($fragment) ?: $this->defaultName('ss', $server, $port);

        return $this->result('shadowsocks', $name, $settings, $link);
    }

    private function result(string $protocol, string $name, array $settings, string $rawLink): array
    {
        return [
            'name' => $name,
            'tag' => 'out-' . substr(sha1($rawLink), 0, 10),
            'protocol' => $protocol,
            'settings' => $settings,
            'raw_link' => $rawLink,
        ];
    }

    private function defaultName(string $protocol, string $server, int $port): string
    {
        return strtoupper($protocol) . ' ' . $server . ':' . $port;
    }

    private function assertEndpoint(string $server, int $port): void
    {
        if ($server === '') {
            throw new ApiException('服务器地址不能为空');
        }
        if ($port < 1 || $port > 65535) {
            throw new ApiException('服务器端口必须在 1-65535 之间');
        }
    }

    private function parseHostPort(string $endpoint): array
    {
        $endpoint = trim($endpoint);
        if (preg_match('/^\[([^]]+)]:(\d+)$/', $endpoint, $m)) {
            return [$m[1], (int) $m[2]];
        }
        if (!preg_match('/^(.+):(\d+)$/', $endpoint, $m)) {
            throw new ApiException('服务器地址或端口格式错误');
        }
        return [$m[1], (int) $m[2]];
    }

    private function decodeBase64(string $value): string
    {
        $value = trim(rawurldecode($value));
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new ApiException('Base64 数据解析失败');
        }
        return $decoded;
    }

    private function splitList(mixed $value): ?array
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $items = preg_split('/[,|]/', $value) ?: [];
        } else {
            return null;
        }

        $items = array_values(array_filter(array_map(
            static fn($item) => trim((string) $item),
            $items
        )));

        return $items ?: null;
    }

    private function clean(array $settings): array
    {
        return array_filter(
            $settings,
            static fn($value) => $value !== null && $value !== '' && $value !== []
        );
    }
}

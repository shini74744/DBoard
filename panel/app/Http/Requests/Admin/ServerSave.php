<?php


namespace App\Http\Requests\Admin;

use App\Models\Server;
use App\Models\ServerOutbound;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Http\FormRequest;

class ServerSave extends FormRequest
{
    private const UTLS_RULES = [
        'utls.enabled' => 'nullable|boolean',
        'utls.fingerprint' => 'nullable|string',
    ];

    private const MULTIPLEX_RULES = [
        'multiplex.enabled' => 'nullable|boolean',
        'multiplex.protocol' => 'nullable|string',
        'multiplex.max_connections' => 'nullable|integer',
        'multiplex.min_streams' => 'nullable|integer',
        'multiplex.max_streams' => 'nullable|integer',
        'multiplex.padding' => 'nullable|boolean',
        'multiplex.brutal.enabled' => 'nullable|boolean',
        'multiplex.brutal.up_mbps' => 'nullable|integer',
        'multiplex.brutal.down_mbps' => 'nullable|integer',
    ];

    private const ECH_RULES = [
        'enabled' => 'nullable|boolean',
        'config' => 'nullable|string',
        'query_server_name' => 'nullable|string',
        'key' => 'nullable|string',
    ];

    private const REALITY_RULES = [
        'reality_settings.allow_insecure' => 'nullable|boolean',
        'reality_settings.server_name' => 'nullable|string',
        'reality_settings.server_port' => 'nullable|integer',
        'reality_settings.public_key' => 'nullable|string',
        'reality_settings.private_key' => 'nullable|string',
        'reality_settings.short_id' => 'nullable|string',
    ];

    private const PROTOCOL_RULES = [
        'shadowsocks' => [
            'cipher' => 'required|string',
            'obfs' => 'nullable|string',
            'obfs_settings.path' => 'nullable|string',
            'obfs_settings.host' => 'nullable|string',
            'plugin' => 'nullable|string',
            'plugin_opts' => 'nullable|string',
        ],
        'vmess' => [
            'tls' => 'required|integer',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'rules' => 'nullable|array',
        ],
        'trojan' => [
            'tls' => 'nullable|integer',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'server_name' => 'nullable|string',
            'allow_insecure' => 'nullable|boolean',
        ],
        'hysteria' => [
            'version' => 'required|integer',
            'alpn' => 'nullable|string',
            'obfs.open' => 'nullable|boolean',
            'obfs.type' => 'string|nullable',
            'obfs.password' => 'string|nullable',
            'bandwidth.up' => 'nullable|integer',
            'bandwidth.down' => 'nullable|integer',
            'hop_interval' => 'integer|nullable',
        ],
        'vless' => [
            'tls' => 'required|integer',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'flow' => 'nullable|string',
            'encryption' => 'nullable|array',
            'encryption.enabled' => 'nullable|boolean',
            'encryption.encryption' => 'nullable|string',
            'encryption.decryption' => 'nullable|string',
        ],
        'socks' => [
            'tls' => 'nullable|integer',
        ],
        'naive' => [
            'tls' => 'required|integer',
        ],
        'http' => [
            'tls' => 'required|integer',
        ],
        'tuic' => [
            'version' => 'nullable|integer',
            'congestion_control' => 'nullable|string',
            'alpn' => 'nullable|array',
            'udp_relay_mode' => 'nullable|string',
        ],
        'mieru' => [
            'transport' => 'required|string|in:TCP,UDP',
            'traffic_pattern' => 'string',
        ],
        'anytls' => [
            'tls' => 'nullable|array',
            'alpn' => 'nullable|string',
            'padding_scheme' => 'nullable|array',
        ],
    ];

    private function getBaseRules(): array
    {
        return [
            'type' => 'required|in:' . implode(',', Server::VALID_TYPES),
            'spectific_key' => 'nullable|string',
            'code' => 'nullable|string',
            'show' => '',
            'name' => 'required|string',
            'group_ids' => 'nullable|array',
            'route_ids' => 'nullable|array',
            'outbound_ids' => 'nullable|array',
            'outbound_ids.*' => 'integer|exists:v2_server_outbound,id',
            'parent_id' => 'nullable|integer',
            'machine_id' => 'nullable|integer',
            'enabled' => 'nullable|boolean',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'tags' => 'nullable|array',
            'excludes' => 'nullable|array',
            'ips' => 'nullable|array',
            'rate' => 'required|numeric',
            'rate_time_enable' => 'nullable|boolean',
            'rate_time_ranges' => 'nullable|array',
            'custom_outbounds' => 'nullable|array',
            'custom_routes' => 'nullable|array',
            'custom_route_rules' => 'nullable|array',
            'custom_route_rules.*.name' => 'nullable|string|max:255',
            'custom_route_rules.*.disabled' => 'nullable|boolean',
            'custom_route_rules.*.ip_domain_relation' => 'nullable|in:or,and',
            'custom_route_rules.*.match' => 'nullable|array',
            'custom_route_rules.*.match.user_ids' => 'nullable|array|max:100',
            'custom_route_rules.*.match.user_ids.*' => 'required|integer|min:1|distinct|exists:v2_user,id',
            'custom_route_rules.*.match.domains' => 'nullable|array',
            'custom_route_rules.*.match.domain_suffixes' => 'nullable|array',
            'custom_route_rules.*.match.ip_cidrs' => 'nullable|array',
            'custom_route_rules.*.match.ports' => 'nullable|array',
            'custom_route_rules.*.match.ports.*' => 'string|max:32',
            'custom_route_rules.*.match.networks' => 'nullable|array',
            'custom_route_rules.*.match.protocols' => 'nullable|array',
            'custom_route_rules.*.match.protocols.*' => 'string|in:http,tls,bittorrent,quic',
            'custom_route_rules.*.match.source_cidrs' => 'nullable|array',
            'custom_route_rules.*.match.source_ports' => 'nullable|array',
            'custom_route_rules.*.action' => 'required_with:custom_route_rules|array',
            'custom_route_rules.*.action.type' => 'required_with:custom_route_rules|in:direct,block,route,balancer',
            'custom_route_rules.*.action.target' => 'nullable|string|max:128',
            'custom_balancers' => 'nullable|array',
            'custom_balancers.*.tag' => ['required_with:custom_balancers', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
            'custom_balancers.*.strategy' => 'required_with:custom_balancers|in:random,roundRobin,leastLoad,leastPing,round_robin,least_load,latency',
            'custom_balancers.*.selector' => 'required_with:custom_balancers|array|min:1|max:256',
            'custom_balancers.*.selector.*' => 'required|string|max:128|distinct:strict',
            'custom_balancers.*.fallback_tag' => 'nullable|string|max:128',
            'custom_balancers.*.probe_url' => 'nullable|url:http,https|max:2048',
            'custom_balancers.*.probe_interval_seconds' => 'nullable|integer|between:5,3600',
            'custom_balancers.*.probe_timeout_seconds' => 'nullable|integer|between:1,30',
            'cert_config' => 'nullable|array',
            'rate_time_ranges.*.start' => 'required_with:rate_time_ranges|string|date_format:H:i',
            'rate_time_ranges.*.end' => 'required_with:rate_time_ranges|string|date_format:H:i',
            'rate_time_ranges.*.rate' => 'required_with:rate_time_ranges|numeric|min:0',
            'protocol_settings' => 'array',
            'transfer_enable' => 'nullable|integer|min:0',
        ];
    }

    private function getProtocolRules(string $type): array
    {
        $rules = self::PROTOCOL_RULES[$type] ?? [];

        return match ($type) {
            'vmess' => array_merge(
                $rules,
                $this->buildTlsSettingsRules(),
                self::MULTIPLEX_RULES,
                self::UTLS_RULES,
            ),
            'trojan' => array_merge(
                $rules,
                $this->buildTlsSettingsRules(includeRoot: true),
                self::REALITY_RULES,
                self::MULTIPLEX_RULES,
                self::UTLS_RULES,
            ),
            'hysteria' => array_merge(
                $rules,
                $this->buildTlsObjectRules(),
            ),
            'tuic' => array_merge(
                $rules,
                $this->buildTlsObjectRules(),
            ),
            'mieru' => array_merge(
                $rules,
                self::MULTIPLEX_RULES,
            ),
            'vless' => array_merge(
                $rules,
                $this->buildTlsSettingsRules(),
                self::REALITY_RULES,
                self::MULTIPLEX_RULES,
                self::UTLS_RULES,
            ),
            'socks', 'naive', 'http' => array_merge(
                $rules,
                $this->buildTlsSettingsRules(includeRoot: $type !== 'socks'),
            ),
            'anytls' => array_merge(
                $rules,
                $this->buildTlsObjectRules(includeRoot: true),
            ),
            default => $rules,
        };
    }

    private function buildTlsSettingsRules(bool $includeRoot = false): array
    {
        return array_merge(
            $includeRoot ? ['tls_settings' => 'nullable|array'] : [],
            [
                'tls_settings.server_name' => 'nullable|string',
                'tls_settings.allow_insecure' => 'nullable|boolean',
                'tls_settings.ech' => 'nullable|array',
            ],
            $this->prefixRules('tls_settings.ech.', self::ECH_RULES),
        );
    }

    private function buildTlsObjectRules(bool $includeRoot = false): array
    {
        return array_merge(
            $includeRoot ? ['tls' => 'nullable|array'] : [],
            [
                'tls.server_name' => 'nullable|string',
                'tls.allow_insecure' => 'nullable|boolean',
                'tls.ech' => 'nullable|array',
            ],
            $this->prefixRules('tls.ech.', self::ECH_RULES),
        );
    }

    private function prefixRules(string $prefix, array $rules): array
    {
        $result = [];
        foreach ($rules as $field => $rule) {
            $result[$prefix . $field] = $rule;
        }
        return $result;
    }

    public function rules(): array
    {
        $type = $this->input('type');
        $rules = $this->getBaseRules();
        $protocolRules = $this->getProtocolRules((string) $type);

        foreach ($protocolRules as $field => $rule) {
            $rules['protocol_settings.' . $field] = $rule;
        }

        return $rules;
    }

    // Resolve dependencies server-side too. A stale UI must not store a group
    // whose members/fallback are missing from the node's actual outbounds.
    protected function prepareForValidation(): void
    {
        $balancers = $this->input('custom_balancers');
        $ids = $this->input('outbound_ids', []);
        if (!is_array($balancers) || !$balancers || !is_array($ids)) {
            return;
        }
        $tags = [];
        foreach ($balancers as $balancer) {
            if (!is_array($balancer)) { continue; }
            foreach (is_array($balancer['selector'] ?? null) ? $balancer['selector'] : [] as $tag) {
                if (is_string($tag) && trim($tag) !== '') { $tags[] = trim($tag); }
            }
            if (is_string($balancer['fallback_tag'] ?? null) && trim($balancer['fallback_tag']) !== '') {
                $tags[] = trim($balancer['fallback_tag']);
            }
        }
        $requiredIds = ServerOutbound::query()->where('enabled', true)
            ->whereIn('tag', array_values(array_unique($tags)))->pluck('id')->all();
        $this->merge(['outbound_ids' => array_values(array_unique(array_merge($ids, $requiredIds)))]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $outboundIds = array_values(array_unique(array_map(
                'intval',
                (array) $this->input('outbound_ids', [])
            )));

            if (!$validator->errors()->any()) {
                try {
                    \App\Services\NodeOutboundService::validateSelection((int)$this->input('id'), $outboundIds, $this->all());
                } catch (\App\Exceptions\ApiException $error) {
                    $validator->errors()->add('outbound_ids', $error->getMessage());
                }
            }

            $availableTags = ServerOutbound::query()
                ->whereIn('id', $outboundIds)
                ->where('enabled', true)
                ->pluck('tag')
                ->map(fn($tag) => trim((string) $tag))
                ->filter()
                ->values()
                ->all();

            $availableTagSet = array_fill_keys($availableTags, true);
            $balancerTags = [];

            foreach ((array) $this->input('custom_balancers', []) as $index => $balancer) {
                $tag = trim((string) data_get($balancer, 'tag', ''));
                if ($tag === '') {
                    continue;
                }

                if (isset($availableTagSet[$tag]) || in_array($tag, ['direct', 'block'], true)) {
                    $validator->errors()->add(
                        "custom_balancers.{$index}.tag",
                        '负载均衡 Tag 不能与出站 Tag 或系统保留 Tag 重复'
                    );
                }

                if (isset($balancerTags[$tag])) {
                    $validator->errors()->add(
                        "custom_balancers.{$index}.tag",
                        '负载均衡 Tag 不能重复'
                    );
                }
                $balancerTags[$tag] = true;

                $selectors = array_values(array_unique(array_filter(array_map(
                    fn($value) => trim((string) $value),
                    (array) data_get($balancer, 'selector', [])
                ))));

                foreach ($selectors as $selector) {
                    if (!isset($availableTagSet[$selector])) {
                        $validator->errors()->add(
                            "custom_balancers.{$index}.selector",
                            "负载均衡成员 {$selector} 不属于当前节点已选择的出站规则"
                        );
                    }
                }

                $fallback = trim((string) data_get($balancer, 'fallback_tag', ''));
                if ($fallback !== '' && !isset($availableTagSet[$fallback])) {
                    $validator->errors()->add(
                        "custom_balancers.{$index}.fallback_tag",
                        'Fallback 必须选择当前节点已启用的出站规则'
                    );
                }
            }

            foreach ((array) $this->input('custom_route_rules', []) as $index => $rule) {
                if (!empty(data_get($rule, 'match.user_ids')) && !Cache::get('dboard_user_routes_capable:' . (int) $this->input('id'))) {
                    $validator->errors()->add(
                        "custom_route_rules.{$index}.match.user_ids",
                        '节点程序需要先升级到支持按用户分流的版本，升级并重新连接后才能保存此规则'
                    );
                }
                $action = trim((string) data_get($rule, 'action.type', ''));
                $target = trim((string) data_get($rule, 'action.target', ''));

                if ($action === 'route') {
                    if ($target === '' || !isset($availableTagSet[$target])) {
                        $validator->errors()->add(
                            "custom_route_rules.{$index}.action.target",
                            '指定出站动作必须选择当前节点已启用的出站规则'
                        );
                    }
                } elseif ($action === 'balancer') {
                    if ($target === '' || !isset($balancerTags[$target])) {
                        $validator->errors()->add(
                            "custom_route_rules.{$index}.action.target",
                            '负载均衡动作必须选择当前节点已创建的负载均衡组'
                        );
                    }
                }
            }
        });
    }

    public function attributes(): array
    {
        return [
            'protocol_settings.cipher' => '加密方式',
            'protocol_settings.obfs' => '混淆类型',
            'protocol_settings.network' => '传输协议',
            'protocol_settings.port_range' => '端口范围',
            'protocol_settings.traffic_pattern' => 'Traffic Pattern',
            'protocol_settings.transport' => '传输方式',
            'protocol_settings.version' => '协议版本',
            'protocol_settings.password' => '密码',
            'protocol_settings.handshake.server' => '握手服务器',
            'protocol_settings.handshake.server_port' => '握手端口',
            'protocol_settings.multiplex.enabled' => '多路复用',
            'protocol_settings.multiplex.protocol' => '复用协议',
            'protocol_settings.multiplex.max_connections' => '最大连接数',
            'protocol_settings.multiplex.min_streams' => '最小流数',
            'protocol_settings.multiplex.max_streams' => '最大流数',
            'protocol_settings.multiplex.padding' => '复用填充',
            'protocol_settings.multiplex.brutal.enabled' => 'Brutal加速',
            'protocol_settings.multiplex.brutal.up_mbps' => 'Brutal上行速率',
            'protocol_settings.multiplex.brutal.down_mbps' => 'Brutal下行速率',
            'protocol_settings.utls.enabled' => 'uTLS',
            'protocol_settings.utls.fingerprint' => 'uTLS指纹',
            'protocol_settings.tls_settings.ech.enabled' => 'ECH',
            'protocol_settings.tls_settings.ech.config' => 'ECH配置',
            'protocol_settings.tls_settings.ech.query_server_name' => 'ECH查询域名',
            'protocol_settings.tls_settings.ech.key' => 'ECH密钥',
            'protocol_settings.tls.ech.enabled' => 'ECH',
            'protocol_settings.tls.ech.config' => 'ECH配置',
            'protocol_settings.tls.ech.query_server_name' => 'ECH查询域名',
            'protocol_settings.tls.ech.key' => 'ECH密钥',
        ];
    }

    public function messages()
    {
        return [
            'name.required' => '节点名称不能为空',
            'group_ids.required' => '权限组不能为空',
            'group_ids.array' => '权限组格式不正确',
            'route_ids.array' => '路由组格式不正确',
            'parent_id.integer' => '父ID格式不正确',
            'host.required' => '节点地址不能为空',
            'port.required' => '连接端口不能为空',
            'server_port.required' => '后端服务端口不能为空',
            'tls.required' => 'TLS不能为空',
            'tags.array' => '标签格式不正确',
            'rate.required' => '倍率不能为空',
            'rate.numeric' => '倍率格式不正确',
            'network.required' => '传输协议不能为空',
            'network.in' => '传输协议格式不正确',
            'networkSettings.array' => '传输协议配置有误',
            'ruleSettings.array' => '规则配置有误',
            'tlsSettings.array' => 'tls配置有误',
            'dnsSettings.array' => 'dns配置有误',
            'protocol_settings.*.required' => ':attribute 不能为空',
            'protocol_settings.*.required_if' => ':attribute 不能为空',
            'protocol_settings.*.string' => ':attribute 必须是字符串',
            'protocol_settings.*.integer' => ':attribute 必须是整数',
            'protocol_settings.*.in' => ':attribute 的值不合法',
            'transfer_enable.integer' => '流量上限必须是整数',
            'transfer_enable.min' => '流量上限不能小于0',
        ];
    }
}

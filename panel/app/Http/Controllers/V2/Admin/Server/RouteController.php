<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerOutbound;
use App\Services\OutboundLinkParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RouteController extends Controller
{
    public function fetch(Request $request)
    {
        return [
            'data' => ServerOutbound::query()
                ->orderBy('sort')
                ->orderByDesc('id')
                ->get(),
        ];
    }

    public function sort(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|integer|distinct|min:1',
        ]);
        $ids = array_map('intval', $data['ids']);
        DB::transaction(function () use ($ids) {
            $current = ServerOutbound::query()->lockForUpdate()->pluck('id')->map(fn($id) => (int) $id)->all();
            sort($current);
            $incoming = $ids;
            sort($incoming);
            if ($current !== $incoming) {
                throw new ApiException('出站列表已更新，请刷新后重新排序');
            }
            foreach ($ids as $position => $id) {
                ServerOutbound::whereKey($id)->update(['sort' => $position + 1]);
            }
        });
        return $this->success(true);
    }

    public function parse(Request $request, OutboundLinkParser $parser)
    {
        $request->validate([
            'link' => 'required|string',
        ]);

        return $this->success($parser->parse($request->input('link')));
    }

    public function save(Request $request, OutboundLinkParser $parser)
    {
        $params = $request->validate([
            'id' => 'nullable|integer',
            'name' => 'nullable|string|max:255',
            'tag' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
            'link' => 'nullable|string',
            'protocol' => 'nullable|string|in:vmess,vless,trojan,shadowsocks,socks,http',
            'settings' => 'nullable|array',
            'proxy_tag' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
            'enabled' => 'nullable|boolean',
            'remarks' => 'nullable|string|max:1000',
        ], [
            'tag.regex' => '出站标签只能包含字母、数字、点、下划线和连字符',
            'proxy_tag.regex' => '前置代理标签格式不正确',
        ]);

        $id = isset($params['id']) ? (int) $params['id'] : null;
        $existing = $id ? ServerOutbound::find($id) : null;
        if ($id && !$existing) {
            throw new ApiException('出站规则不存在');
        }

        try {
            $data = $this->buildSaveData($params, $parser);

            $duplicate = ServerOutbound::where('tag', $data['tag'])
                ->when($id, fn($query) => $query->where('id', '!=', $id))
                ->exists();
            if ($duplicate) {
                throw new ApiException('出站标签已存在，请更换标签');
            }

            if ($existing && $existing->enabled && !$data['enabled']) {
                if ($usedBy = $this->firstServerUsingOutbound($existing->id)) {
                    throw new ApiException("该出站规则正在被节点「{$usedBy->name}」使用，请先解除引用再停用");
                }
            }

            $outbound = DB::transaction(function () use ($existing, $data) {
                $oldTag = $existing?->tag;
                $outbound = $existing ?: new ServerOutbound();
                if (!$existing) {
                    $outbound->sort = ((int) ServerOutbound::max('sort')) + 1;
                }
                $outbound->fill($data);
                $outbound->save();

                if ($oldTag && $oldTag !== $outbound->tag) {
                    $this->syncTagReferences($outbound->id, $oldTag, $outbound->tag);
                }

                return $outbound;
            });

            return $this->success($outbound->fresh());
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('保存出站规则失败', [
                'error' => $e->getMessage(),
                'id' => $id,
            ]);
            return $this->fail([500, '保存失败']);
        }
    }

    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);

        $outbound = ServerOutbound::find($request->input('id'));
        if (!$outbound) {
            throw new ApiException('出站规则不存在');
        }

        if ($usedBy = $this->firstServerUsingOutbound($outbound->id)) {
            throw new ApiException("该出站规则正在被节点「{$usedBy->name}」使用，请先解除引用");
        }

        if ($ruleUsedBy = $this->firstServerUsingTag($outbound->tag)) {
            throw new ApiException("节点「{$ruleUsedBy->name}」的路由规则仍引用此出站标签，请先修改路由规则");
        }

        $chained = ServerOutbound::where('proxy_tag', $outbound->tag)->first();
        if ($chained) {
            throw new ApiException("出站规则「{$chained->name}」仍将此出站作为前置代理，请先解除链式引用");
        }

        if (!$outbound->delete()) {
            throw new ApiException('删除失败');
        }

        return [
            'data' => true,
        ];
    }

    private function buildSaveData(array $params, OutboundLinkParser $parser): array
    {
        if (!empty($params['link'])) {
            $data = $parser->parse($params['link']);
        } else {
            if (empty($params['protocol']) || empty($params['settings'])) {
                throw new ApiException('请填写第三方节点链接或完整出站配置');
            }

            $data = [
                'protocol' => $params['protocol'],
                'settings' => $params['settings'],
                'raw_link' => null,
                'name' => $params['name'] ?? strtoupper($params['protocol']) . ' Outbound',
                'tag' => $params['tag'] ?? ('out-' . substr(sha1(json_encode($params['settings'])), 0, 10)),
            ];
        }

        foreach (['name', 'tag', 'proxy_tag', 'enabled', 'remarks'] as $field) {
            if (array_key_exists($field, $params)) {
                $data[$field] = $params[$field];
            }
        }

        $data['name'] = trim((string) ($data['name'] ?? ''));
        $data['tag'] = trim((string) ($data['tag'] ?? ''));
        $data['protocol'] = strtolower(trim((string) ($data['protocol'] ?? '')));
        $data['enabled'] = array_key_exists('enabled', $data) ? (bool) $data['enabled'] : true;

        if ($data['name'] === '' || $data['tag'] === '') {
            throw new ApiException('名称和出站标签不能为空');
        }

        return $data;
    }

    private function firstServerUsingOutbound(int $outboundId): ?Server
    {
        return Server::query()
            ->get(['id', 'name', 'outbound_ids'])
            ->first(fn(Server $server) => in_array(
                $outboundId,
                array_map('intval', $server->outbound_ids ?? []),
                true
            ));
    }

    private function firstServerUsingTag(string $tag): ?Server
    {
        return Server::query()
            ->get(['id', 'name', 'custom_route_rules'])
            ->first(function (Server $server) use ($tag) {
                foreach ($server->custom_route_rules ?? [] as $rule) {
                    if (
                        data_get($rule, 'action.type') === 'route'
                        && data_get($rule, 'action.target') === $tag
                    ) {
                        return true;
                    }
                }
                return false;
            });
    }

    private function syncTagReferences(int $outboundId, string $oldTag, string $newTag): void
    {
        Server::query()
            ->get(['id', 'outbound_ids', 'custom_route_rules', 'custom_balancers'])
            ->filter(fn(Server $server) => in_array(
                $outboundId,
                array_map('intval', $server->outbound_ids ?? []),
                true
            ))
            ->each(function (Server $server) use ($oldTag, $newTag) {
                $rules = $server->custom_route_rules ?? [];
                $changed = false;

                foreach ($rules as &$rule) {
                    if (
                        data_get($rule, 'action.type') === 'route'
                        && data_get($rule, 'action.target') === $oldTag
                    ) {
                        data_set($rule, 'action.target', $newTag);
                        $changed = true;
                    }
                }
                unset($rule);
                $balancers = $server->custom_balancers ?? [];
                foreach ($balancers as &$balancer) {
                    foreach ($balancer['selector'] ?? [] as $i => $member) {
                        if ($member === $oldTag) {
                            $balancer['selector'][$i] = $newTag;
                            $changed = true;
                        }
                    }
                    if (($balancer['fallback_tag'] ?? '') === $oldTag) {
                        $balancer['fallback_tag'] = $newTag;
                        $changed = true;
                    }
                }
                unset($balancer);

                if ($changed) {
                    $server->custom_balancers = $balancers;
                    $server->custom_route_rules = $rules;
                    $server->save();
                }
            });

        ServerOutbound::where('proxy_tag', $oldTag)
            ->get()
            ->each(function (ServerOutbound $outbound) use ($newTag) {
                $outbound->proxy_tag = $newTag;
                $outbound->save();
            });
    }
}

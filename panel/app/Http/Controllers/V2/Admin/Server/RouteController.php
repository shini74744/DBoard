<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerOutbound;
use App\Models\User;
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

    public function nodes(Request $request)
    {
        $sourceId = (int)$request->input('source_id');
        return $this->success(Server::orderBy('sort')->orderBy('id')->get()->map(function (Server $node) use ($sourceId) {
            $reason = $node->id === $sourceId ? '不能选择当前节点' : \App\Services\NodeOutboundService::unavailableReason($node);
            return ['id'=>$node->id, 'name'=>$node->name, 'type'=>$node->type, 'host'=>$node->host,
                'port'=>$node->port, 'unavailable_reason'=>$reason];
        }));
    }

    public function fromNode(Request $request)
    {
        $data=$request->validate(['node_id'=>'required|integer|exists:v2_server,id','source_id'=>'nullable|integer']);
        if ((int)$data['node_id'] === (int)($data['source_id']??0)) throw new ApiException('不能选择当前节点作为出口');
        $outbound=\App\Services\NodeOutboundService::reference(Server::findOrFail($data['node_id']));
        if (!$outbound->enabled) throw new ApiException('该节点对应出站已停用，请先在出站规则管理中启用');
        return $this->success($outbound);
    }

    public function users(Request $request)
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:255',
            'selected' => 'nullable|string|max:600',
        ]);
        $search = trim((string) ($data['search'] ?? ''));
        $selected = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) ($data['selected'] ?? ''))),
            fn (int $id) => $id > 0
        )));
        $selected = array_slice($selected, 0, 100);

        $users = User::query()->whereNull('parent_id')->select(['id', 'email'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('email', 'like', '%' . $search . '%');
                    if (ctype_digit($search)) {
                        $query->orWhere('id', (int) $search);
                    }
                });
            })
            ->orderByDesc('id')
            ->limit(30)
            ->get();
        if ($selected) {
            $users = User::query()->select(['id', 'email'])->whereIn('id', $selected)
                ->get()->concat($users)->unique('id')->values();
        }
        return $this->success($users);
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
            'tag' => ['nullable', 'string', 'max:128'],
            'link' => 'nullable|string',
            'protocol' => 'nullable|string|in:vmess,vless,trojan,shadowsocks,socks,http',
            'settings' => 'nullable|array',
            'proxy_tag' => ['nullable', 'string', 'max:128'],
            'enabled' => 'nullable|boolean',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $id = isset($params['id']) ? (int) $params['id'] : null;
        $existing = $id ? ServerOutbound::find($id) : null;
        if ($id && !$existing) {
            throw new ApiException('出站规则不存在');
        }

        if ($existing?->target_server_id) {
            // Endpoint and credentials are always resolved from the referenced node.
            $params = array_intersect_key($params, array_flip(['id','name','tag','enabled','remarks']));
            $params['protocol'] = $existing->protocol;
            $params['settings'] = $existing->settings;
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

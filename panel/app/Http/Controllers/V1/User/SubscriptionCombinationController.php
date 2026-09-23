<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionCombination;
use App\Models\User;
use App\Services\MultiSubscriptionService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SubscriptionCombinationController extends Controller
{
    public function index(Request $request)
    {
        $account = MultiSubscriptionService::account($request->user());
        $items = SubscriptionCombination::where('user_id', $account->id)->orderBy('id')->get();
        return $this->success($items->map(fn ($item) => $this->present($item))->all());
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer|min:1',
            'name' => 'required|string|max:60',
            'package_ids' => 'required',
            'duplicate_node_mode' => 'required|in:all,first',
        ]);
        $ids = is_string($data['package_ids']) ? json_decode($data['package_ids'], true) : $data['package_ids'];
        Validator::make(['package_ids' => $ids], [
            'package_ids' => 'required|array|min:2|max:20',
            'package_ids.*' => 'required|integer|distinct|min:1',
        ])->validate();
        $ids = array_map('intval', $ids);
        $account = MultiSubscriptionService::account($request->user());
        $owned = MultiSubscriptionService::packages($account)
            ->filter(fn (User $item) => $item->plan_id && in_array($item->id, $ids, true));
        if ($owned->count() !== count($ids)) {
            throw ValidationException::withMessages(['package_ids' => '所选套餐不属于当前账号或尚未开通']);
        }

        $item = !empty($data['id'])
            ? SubscriptionCombination::where('user_id', $account->id)->findOrFail($data['id'])
            : new SubscriptionCombination(['user_id' => $account->id, 'token' => bin2hex(random_bytes(24))]);
        if (!$item->exists && SubscriptionCombination::where('user_id', $account->id)->count() >= 10) {
            throw ValidationException::withMessages(['name' => '最多保存 10 个组合订阅']);
        }
        if (trim($data['name']) === '') {
            throw ValidationException::withMessages(['name' => '请输入组合名称']);
        }
        $item->name = trim($data['name']);
        $item->package_ids = $ids;
        $item->duplicate_node_mode = $data['duplicate_node_mode'];
        $item->saveOrFail();
        return $this->success($this->present($item));
    }

    public function delete(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer|min:1']);
        $account = MultiSubscriptionService::account($request->user());
        SubscriptionCombination::where('user_id', $account->id)->findOrFail($data['id'])->delete();
        return $this->success(true);
    }

    private function present(SubscriptionCombination $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'package_ids' => $item->package_ids,
            'duplicate_node_mode' => $item->duplicate_node_mode,
            'subscribe_url' => Helper::getSubscribeUrl($item->token),
        ];
    }
}

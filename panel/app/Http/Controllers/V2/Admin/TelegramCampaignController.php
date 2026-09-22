<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\TelegramCampaign;
use App\Models\User;
use App\Services\TelegramCampaignDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TelegramCampaignController extends Controller
{
    public function options(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $users = [];
        if ($search !== '') {
            $users = User::query()->select(['id', 'email', 'plan_id', 'telegram_id', 'remind_telegram', 'banned'])
                ->where(function ($query) use ($search) {
                    $query->where('email', 'like', '%' . addcslashes($search, '%_\\') . '%');
                    if (ctype_digit($search)) $query->orWhere('id', (int) $search);
                })->orderBy('id')->limit(30)->get()
                ->map(fn ($user) => [
                    'id' => $user->id, 'email' => $user->email, 'plan_id' => $user->plan_id,
                    'eligible' => !$user->banned && $user->remind_telegram && (int) $user->telegram_id > 0,
                ]);
        }
        return $this->success([
            'plans' => Plan::query()->select(['id', 'name'])->orderBy('sort')->get(),
            'users' => $users,
            'telegram_ready' => (bool) admin_setting('telegram_bot_enable', false) && (bool) admin_setting('telegram_bot_token'),
        ]);
    }

    public function preview(Request $request)
    {
        $data = $this->validateAudience($request);
        return $this->success([
            'eligible_count' => TelegramCampaignDispatcher::eligibleUsers(
                $data['audience'], $data['plan_id'] ?? null, $data['user_ids'] ?? []
            )->count(),
        ]);
    }

    public function fetch()
    {
        return $this->success(TelegramCampaign::query()->orderByDesc('id')->limit(30)
            ->get(['id', 'audience', 'plan_id', 'message', 'scheduled_at', 'status',
                'queued_count', 'last_error', 'created_at', 'finished_at']));
    }

    public function create(Request $request)
    {
        if (!admin_setting('telegram_bot_enable', false) || !admin_setting('telegram_bot_token')) {
            throw ValidationException::withMessages(['telegram' => 'Telegram 机器人未启用或令牌未配置']);
        }
        $data = $this->validateAudience($request);
        $message = trim((string) $request->input('message', ''));
        if ($message === '' || mb_strlen($message) > 3000) {
            throw ValidationException::withMessages(['message' => '通知内容需为 1 到 3000 个字符']);
        }
        $scheduledAt = $request->input('scheduled_at');
        if ($scheduledAt !== null && $scheduledAt !== '') {
            if ($data['audience'] !== 'global') {
                throw ValidationException::withMessages(['scheduled_at' => '只有全局通知可以定时发送']);
            }
            if (!is_numeric($scheduledAt) || (int) $scheduledAt < time() + 60 || (int) $scheduledAt > time() + 366 * 86400) {
                throw ValidationException::withMessages(['scheduled_at' => '定时时间需在 1 分钟后至 1 年内']);
            }
        }
        $campaign = TelegramCampaign::create([
            'audience' => $data['audience'],
            'plan_id' => $data['plan_id'] ?? null,
            'user_ids' => $data['user_ids'] ?? null,
            'message' => $message,
            'scheduled_at' => $scheduledAt ? (int) $scheduledAt : time(),
            'status' => 'pending',
            'created_by' => Auth::guard('sanctum')->id(),
        ]);
        return $this->success(['id' => $campaign->id, 'status' => $campaign->status]);
    }

    public function cancel(Request $request)
    {
        $id = $request->validate(['id' => 'required|integer|min:1'])['id'];
        $cancelled = TelegramCampaign::query()->whereKey($id)->where('status', 'pending')
            ->update(['status' => 'cancelled', 'finished_at' => time()]);
        if (!$cancelled) throw ValidationException::withMessages(['id' => '通知已开始或不存在，无法取消']);
        return $this->success(true);
    }

    private function validateAudience(Request $request): array
    {
        $data = $request->validate([
            'audience' => ['required', Rule::in(['global', 'plan', 'users'])],
            'plan_id' => 'nullable|integer|exists:v2_plan,id',
            'user_ids' => 'nullable|array|max:5000',
            'user_ids.*' => 'integer|distinct|min:1',
        ]);
        if ($data['audience'] === 'plan' && empty($data['plan_id'])) {
            throw ValidationException::withMessages(['plan_id' => '请选择套餐']);
        }
        if ($data['audience'] === 'users' && empty($data['user_ids'])) {
            throw ValidationException::withMessages(['user_ids' => '请选择用户']);
        }
        return $data;
    }
}

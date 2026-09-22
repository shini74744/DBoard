<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Plan;
use App\Services\TelegramBindingRewardService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TelegramBindingController extends Controller
{
    public function fetch(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $page = max(1, (int) $request->query('page', 1));
        $binding = (string) $request->query('binding', 'telegram');
        $planId = (string) $request->query('plan_id', 'all');
        if (!in_array($binding, ['all', 'telegram', 'email_only'], true)
            || !($planId === 'all' || $planId === 'none' || (ctype_digit($planId) && (int) $planId > 0))) {
            throw ValidationException::withMessages(['filter' => '筛选条件无效']);
        }
        $query = User::query()->with('plan:id,name');
        if ($binding === 'telegram') $query->whereNotNull('telegram_id')->where('telegram_id', '>', 0);
        if ($binding === 'email_only') $query->where(function ($builder) {
            $builder->whereNull('telegram_id')->orWhere('telegram_id', '<=', 0);
        });
        if ($planId === 'none') $query->whereNull('plan_id');
        elseif ($planId !== 'all') $query->where('plan_id', (int) $planId);
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('email', 'like', '%' . addcslashes($search, '%_\\') . '%')
                    ->orWhere('telegram_username', 'like', '%' . addcslashes($search, '%_\\') . '%');
                if (ctype_digit($search)) {
                    $builder->orWhere('id', (int) $search)
                        ->orWhere('telegram_id', (int) $search);
                }
            });
        }
        $users = $query->orderByDesc('id')->paginate(20, ['*'], 'page', $page);
        $items = $users->getCollection()->map(function (User $user) {
            $expiredAt = $user->expired_at === null ? null : (int) $user->expired_at;
            return [
                'id' => (int) $user->id,
                'telegram_id' => (int) $user->telegram_id,
                'telegram_username' => $user->telegram_username,
                'telegram_username_synced_at' => $user->telegram_username_synced_at,
                'email' => $user->email,
                'plan' => $user->plan?->name,
                'expired_at' => $expiredAt,
                'remaining_days' => $expiredAt === null ? null : max(0, (int) ceil(($expiredAt - time()) / 86400)),
                'remaining_bytes' => max(0, (int) $user->transfer_enable - (int) $user->u - (int) $user->d),
                'cycle_bonus_bytes' => (int) $user->telegram_bonus_cycle,
                'permanent_bonus_bytes' => (int) $user->telegram_bonus_permanent,
                'timed_bonus_bytes' => (int) $user->telegram_bonus_timed,
            ];
        });
        return $this->success([
            'items' => $items,
            'plans' => Plan::query()->orderBy('id')->get(['id', 'name']),
            'total' => $users->total(),
            'page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
            'site' => (string) admin_setting('app_url', config('app.url')),
            'settings' => TelegramBindingRewardService::settings(),
            'telegram_ready' => (bool) admin_setting('telegram_bot_enable', false)
                && (bool) admin_setting('telegram_bot_token'),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'new_gb' => 'required|numeric|min:0|max:10000',
            'new_days' => 'required|integer|min:1|max:3650',
            'existing_gb' => 'required|numeric|min:0|max:10000',
            'existing_days' => 'required|integer|min:1|max:3650',
        ]);
        $current = TelegramBindingRewardService::settings();
        $data['new_gb'] = round((float) $data['new_gb'], 2);
        $data['existing_gb'] = round((float) $data['existing_gb'], 2);
        $data['start_at'] = $current['start_at'] ?: time();
        admin_setting(['telegram_bind_reward' => json_encode($data)]);
        return $this->success($data);
    }

    public function grant(Request $request)
    {
        if (!admin_setting('telegram_bot_enable', false) || !admin_setting('telegram_bot_token')) {
            throw ValidationException::withMessages(['telegram' => '请先启用 Telegram 机器人并配置令牌']);
        }
        $data = $request->validate([
            'user_id' => 'required|integer|exists:v2_user,id',
            'amount_gb' => 'required|numeric|min:0.01|max:10000',
            'duration_days' => 'required|integer|min:1|max:3650',
            'request_id' => 'required|uuid',
            'reason' => 'nullable|string|max:255',
        ]);
        return $this->success(TelegramBindingRewardService::grant(
            (int) $data['user_id'],
            (float) $data['amount_gb'],
            'timed',
            'manual',
            'manual:' . $data['request_id'],
            Auth::guard('sanctum')->id(),
            $data['reason'] ?? null,
            (int) $data['duration_days']
        ));
    }

    public function refreshUsername(Request $request, TelegramService $telegram)
    {
        $data = $request->validate(['user_id' => 'required|integer|exists:v2_user,id']);
        $user = User::findOrFail($data['user_id']);
        if (!$user->telegram_id) {
            throw ValidationException::withMessages(['user_id' => '用户尚未绑定 Telegram']);
        }
        if (!admin_setting('telegram_bot_enable', false) || !admin_setting('telegram_bot_token')) {
            throw ValidationException::withMessages(['telegram' => 'Telegram 机器人未启用或令牌未配置']);
        }
        $response = $telegram->getChat((int) $user->telegram_id);
        $username = $response->result->username ?? null;
        $user->telegram_username = $username;
        $user->telegram_username_synced_at = time();
        $user->save();
        return $this->success(['telegram_username' => $username]);
    }

    public function history()
    {
        $grants = DB::table('v2_telegram_traffic_grant as grant')
            ->leftJoin('v2_user as user', 'user.id', '=', 'grant.user_id')
            ->orderByDesc('grant.id')->limit(30)
            ->get(['grant.id', 'grant.user_id', 'user.email', 'grant.amount_bytes',
                'grant.mode', 'grant.source', 'grant.reason', 'grant.duration_days',
                'grant.expires_at', 'grant.revoked_at', 'grant.created_at']);
        return $this->success($grants);
    }
}

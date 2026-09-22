<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserChangePassword;
use App\Http\Requests\User\UserTransfer;
use App\Http\Requests\User\UserUpdate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\AuthService;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    protected $loginService;

    public function __construct(
        LoginService $loginService
    ) {
        $this->loginService = $loginService;
    }

    public function getActiveSession(Request $request)
    {
        $user = $request->user();
        $authService = new AuthService($user);
        return $this->success($authService->getSessions());
    }

    public function removeActiveSession(Request $request)
    {
        $user = $request->user();
        $authService = new AuthService($user);
        return $this->success($authService->removeSession($request->input('session_id')));
    }

    public function checkLogin(Request $request)
    {
        $data = [
            'is_login' => $request->user()?->id ? true : false
        ];
        if ($request->user()?->is_admin) {
            $data['is_admin'] = true;
        }
        return $this->success($data);
    }

    public function changePassword(UserChangePassword $request)
    {
        $user = $request->user();
        if (
            !Helper::multiPasswordVerify(
                $user->password_algo,
                $user->password_salt,
                $request->input('old_password'),
                $user->password
            )
        ) {
            return $this->fail([400, __('The old password is wrong')]);
        }
        $user->password = password_hash($request->input('new_password'), PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;
        if (!$user->save()) {
            return $this->fail([400, __('Save failed')]);
        }
        
        $currentToken = $user->currentAccessToken();
        if ($currentToken) {
            $user->tokens()->where('id', '!=', $currentToken->id)->delete();
        } else {
            $user->tokens()->delete();
        }
        
        return $this->success(true);
    }

    public function info(Request $request)
    {
        $user = User::where('id', $request->user()->id)
            ->select([
                'email',
                'transfer_enable',
                'last_login_at',
                'created_at',
                'banned',
                'remind_expire',
                'remind_traffic',
                'remind_telegram',
                'navigation_hidden',
                'expired_at',
                'balance',
                'commission_balance',
                'plan_id',
                'discount',
                'commission_rate',
                'telegram_id',
                'uuid'
            ])
            ->first();
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        $user['avatar_url'] = 'https://cdn.v2ex.com/gravatar/' . md5($user->email) . '?s=64&d=identicon';
        return $this->success($user);
    }

    public function getStat(Request $request)
    {
        $stat = [
            Order::where('status', 0)
                ->where('user_id', $request->user()->id)
                ->count(),
            Ticket::where('status', 0)
                ->where('user_id', $request->user()->id)
                ->count(),
            User::where('invite_user_id', $request->user()->id)
                ->count()
        ];
        return $this->success($stat);
    }

    public function getSubscribe(Request $request)
    {
        $user = User::where('id', $request->user()->id)
            ->select([
                'plan_id',
                'token',
                'expired_at',
                'u',
                'd',
                'transfer_enable',
                'email',
                'uuid',
                'device_limit',
                'speed_limit',
                'next_reset_at',
                'created_at'
            ])
            ->first();
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        if ($user->plan_id && $user->expired_at) {
            // Use the current plan's latest completed purchase or renewal as its
            // cycle start. Migrated accounts without orders use account creation.
            $order = Order::where('user_id', $request->user()->id)
                ->where('plan_id', $user->plan_id)
                ->where('status', Order::STATUS_COMPLETED)
                ->whereIn('type', [Order::TYPE_NEW_PURCHASE, Order::TYPE_RENEWAL, Order::TYPE_UPGRADE])
                ->whereNotIn('period', [Plan::PERIOD_ONETIME, Plan::PERIOD_RESET_TRAFFIC])
                ->orderByDesc('id')
                ->first(['paid_at', 'created_at']);
            $startedAt = (int) ($order?->paid_at ?: $order?->getRawOriginal('created_at'));
            if ($startedAt <= 0 || $startedAt >= (int) $user->expired_at) {
                $startedAt = (int) $user->getRawOriginal('created_at');
            }
            $user['subscription_started_at'] = $startedAt > 0 && $startedAt < (int) $user->expired_at
                ? $startedAt : null;
        }
        if ($user->plan_id) {
            $user['plan'] = Plan::find($user->plan_id);
            if (!$user['plan']) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }
        }
        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);
        $userService = new UserService();
        $user['reset_day'] = $userService->getResetDay($user);
        $user = HookManager::filter('user.subscribe.response', $user);
        return $this->success($user);
    }

    public function resetSecurity(Request $request)
    {
        $user = $request->user();
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if (!$user->save()) {
            return $this->fail([400, __('Reset failed')]);
        }
        return $this->success(Helper::getSubscribeUrl($user->token));
    }

    public function update(UserUpdate $request)
    {
        $updateData = $request->only([
            'remind_expire',
            'remind_traffic',
            'remind_telegram'
        ]);

        if ($request->has('navigation_hidden')) {
            $hidden = json_decode((string) $request->input('navigation_hidden'), true);
            $allowed = ['shop', 'invite', 'docs', 'tickets', 'nodes', 'orders', 'traffic', 'wallet', 'profile'];
            if (!is_array($hidden) || !array_is_list($hidden) || count($hidden) > count($allowed)) {
                return $this->fail([422, '导航设置无效']);
            }
            foreach ($hidden as $item) {
                if (!is_string($item) || !in_array($item, $allowed, true)) {
                    return $this->fail([422, '导航设置无效']);
                }
            }
            if (count(array_unique($hidden)) !== count($hidden)) {
                return $this->fail([422, '导航设置无效']);
            }
            $updateData['navigation_hidden'] = $hidden;
        }

        $user = $request->user();
        try {
            $user->update($updateData);
        } catch (\Exception $e) {
            return $this->fail([400, __('Save failed')]);
        }

        return $this->success(true);
    }

    public function transfer(UserTransfer $request)
    {
        $amount = $request->input('transfer_amount');
        try {
            DB::transaction(function () use ($request, $amount) {
                $user = User::lockForUpdate()->find($request->user()->id);
                if (!$user) {
                    throw new \Exception(__('The user does not exist'));
                }
                if ($amount > $user->commission_balance) {
                    throw new \Exception(__('Insufficient commission balance'));
                }
                $user->commission_balance -= $amount;
                $user->balance += $amount;
                if (!$user->save()) {
                    throw new \Exception(__('Transfer failed'));
                }
            });
        } catch (\Exception $e) {
            return $this->fail([400, $e->getMessage()]);
        }
        return $this->success(true);
    }

    public function getQuickLoginUrl(Request $request)
    {
        $user = $request->user();

        $url = $this->loginService->generateQuickLoginUrl($user, $request->input('redirect'));
        return $this->success($url);
    }
}

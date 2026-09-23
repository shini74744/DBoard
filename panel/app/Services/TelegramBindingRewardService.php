<?php

namespace App\Services;

use App\Jobs\SendTelegramJob;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TelegramBindingRewardService
{
    private const BYTES_PER_GB = 1073741824;

    public static function settings(): array
    {
        $stored = admin_setting('telegram_bind_reward', null);
        $stored = is_string($stored) ? json_decode($stored, true) : $stored;
        return array_merge([
            'new_gb' => 0, 'new_days' => 30,
            'existing_gb' => 0, 'existing_days' => 30,
            'start_at' => null,
        ], is_array($stored) ? $stored : []);
    }

    public static function rewardOnBind(User $user): ?array
    {
        if (DB::table('v2_telegram_traffic_grant')->where('reward_key', 'bind:' . $user->id)->exists()) {
            return null;
        }
        $settings = self::settings();
        $kind = $settings['start_at'] && (int) $user->getRawOriginal('created_at') >= (int) $settings['start_at']
            ? 'new' : 'existing';
        $gb = (float) ($settings[$kind . '_gb'] ?? 0);
        if ($gb <= 0) return null;

        return self::grant(
            (int) $user->id,
            $gb,
            'timed',
            'bind',
            'bind:' . $user->id,
            null,
            $kind === 'new' ? '新用户绑定机器人赠送' : '现有用户绑定机器人赠送',
            (int) ($settings[$kind . '_days'] ?? 30)
        );
    }

    /** Keep active timed gifts until their own deadline; discard old gifts after a plan lapse. */
    public static function bonusesForPlanActivation(User $user): array
    {
        $now = time();
        $lapsed = $user->plan_id !== null && $user->expired_at !== null
            && (int) $user->expired_at < $now;
        $timed = DB::table('v2_telegram_traffic_grant')
            ->where('user_id', $user->id)->where('mode', 'timed');
        $stale = (clone $timed)->whereNull('revoked_at')
            ->where(function ($query) use ($now, $lapsed, $user) {
                $query->where('expires_at', '<=', $now);
                if ($lapsed) $query->orWhere('created_at', '<=', (int) $user->expired_at);
            });
        $stale->update(['revoked_at' => $now]);
        $activeTimed = (int) (clone $timed)->whereNull('revoked_at')
            ->where('expires_at', '>', $now)->sum('amount_bytes');
        (clone $timed)->whereNull('revoked_at')->where('expires_at', '>', $now)
            ->whereNull('applied_at')->update(['applied_at' => $now]);

        if ($user->plan_id === null) {
            return [(int) $user->telegram_bonus_cycle, (int) $user->telegram_bonus_permanent, $activeTimed];
        }
        if ($lapsed) {
            $recent = DB::table('v2_telegram_traffic_grant')
                ->where('user_id', $user->id)
                ->where('created_at', '>', (int) $user->expired_at)
                ->whereIn('mode', ['cycle', 'permanent'])
                ->selectRaw('mode, SUM(amount_bytes) as bytes')
                ->groupBy('mode')->pluck('bytes', 'mode');
            return [(int) ($recent['cycle'] ?? 0), (int) ($recent['permanent'] ?? 0), $activeTimed];
        }
        return [0, (int) $user->telegram_bonus_permanent, $activeTimed];
    }

    public static function grant(
        int $userId,
        float $gb,
        string $mode,
        string $source,
        string $rewardKey,
        ?int $createdBy,
        ?string $reason = null,
        ?int $durationDays = null
    ): array {
        if (!is_finite($gb) || $gb < 0.01 || $gb > 10000 || !in_array($mode, ['cycle', 'permanent', 'timed'], true)) {
            throw ValidationException::withMessages(['amount_gb' => '赠送流量需为 0.01 至 10000 GB']);
        }
        if ($mode === 'timed' && ($durationDays === null || $durationDays < 1 || $durationDays > 3650)) {
            throw ValidationException::withMessages(['duration_days' => '赠送有效期需为 1 至 3650 天']);
        }
        $bytes = (int) round($gb * self::BYTES_PER_GB);
        $reason = mb_substr(trim((string) $reason) ?: ($source === 'bind' ? '绑定机器人赠送' : '管理员补发'), 0, 255);
        $result = DB::transaction(function () use ($userId, $bytes, $mode, $source, $rewardKey, $createdBy, $reason, $durationDays) {
            $now = time();
            $expiresAt = $mode === 'timed' ? $now + $durationDays * 86400 : null;
            $account = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            if (!$account->telegram_id) {
                throw ValidationException::withMessages(['user_id' => '用户尚未绑定 Telegram 机器人']);
            }
            $target = MultiSubscriptionService::validPackages($account)->first() ?: $account;
            $user = $target->id === $account->id
                ? $account : User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            $previous = DB::table('v2_telegram_traffic_grant')->where('reward_key', $rewardKey)->first();
            if ($previous) {
                if ($source === 'bind' && $previous->source === 'bind' && (int) ($previous->account_user_id ?: $previous->user_id) === $userId) {
                    return ['awarded' => false, 'amount_bytes' => (int) $previous->amount_bytes, 'mode' => $previous->mode];
                }
                if ((int) ($previous->account_user_id ?: $previous->user_id) !== $userId || (int) $previous->amount_bytes !== $bytes
                    || $previous->mode !== $mode || $previous->source !== $source
                    || ($mode === 'timed' && (int) $previous->duration_days !== $durationDays)) {
                    throw ValidationException::withMessages(['request_id' => '赠送请求编号已被使用']);
                }
                return ['awarded' => false, 'amount_bytes' => $bytes, 'mode' => $mode];
            }

            $bonusField = match ($mode) {
                'permanent' => 'telegram_bonus_permanent',
                'timed' => 'telegram_bonus_timed',
                default => 'telegram_bonus_cycle',
            };
            $pending = $user->plan_id === null
                || ($user->expired_at !== null && (int) $user->expired_at < $now);
            if ((!$pending && (int) $user->transfer_enable > PHP_INT_MAX - $bytes)
                || (int) $user->{$bonusField} > PHP_INT_MAX - $bytes) {
                throw ValidationException::withMessages(['amount_gb' => '赠送后总流量超出可用范围']);
            }
            $user->{$bonusField} = (int) $user->{$bonusField} + $bytes;
            if (!$pending) $user->transfer_enable = (int) $user->transfer_enable + $bytes;
            $user->save();

            DB::table('v2_telegram_traffic_grant')->insert([
                'user_id' => $user->id,
                'account_user_id' => $account->id,
                'telegram_id' => (int) $account->telegram_id,
                'amount_bytes' => $bytes,
                'mode' => $mode,
                'source' => $source,
                'reason' => $reason,
                'duration_days' => $mode === 'timed' ? $durationDays : null,
                'expires_at' => $expiresAt,
                'applied_at' => $mode === 'timed' && !$pending ? $now : null,
                'revoked_at' => null,
                'reward_key' => $rewardKey,
                'created_by' => $createdBy,
                'created_at' => $now,
            ]);

            return [
                'awarded' => true, 'amount_bytes' => $bytes, 'mode' => $mode,
                'pending_plan' => $pending,
                'reason' => $reason,
                'duration_days' => $mode === 'timed' ? $durationDays : null,
                'expires_at' => $expiresAt,
                'telegram_id' => (int) $account->telegram_id,
                'package_name' => ($user->plan?->name ?? '待开通套餐') . ' #' . $user->id,
                'remaining_bytes' => max(0, (int) $user->transfer_enable - (int) $user->u - (int) $user->d),
            ];
        });

        $result['notification_queued'] = false;
        if ($result['awarded'] && admin_setting('telegram_bot_enable', false) && admin_setting('telegram_bot_token')) {
            $amount = number_format($bytes / self::BYTES_PER_GB, 2, '.', '');
            $remaining = number_format($result['remaining_bytes'] / self::BYTES_PER_GB, 2, '.', '');
            $period = match ($mode) {
                'timed' => "有效期 {$durationDays} 天，至 " . date('Y-m-d H:i', $result['expires_at']),
                'permanent' => '有效套餐期间每次重置后保留；到期断档后新购会清除',
                default => '仅当前流量周期有效',
            };
            $package = $result['package_name'];
            $message = "🎁 DBoard 流量赠送\n{$package} 已获得 {$amount} GB 流量（{$period}）。\n原因：{$reason}\n";
            $message .= $result['pending_plan']
                ? '仅在有效套餐期间可用；赠送已记录，有效期从现在开始计算，开通套餐后才能使用。'
                : "仅在当前有效套餐期间可用；套餐到期断档后赠送失效。提前续费保持套餐连续时，可用至赠送期限。\n当前剩余流量：{$remaining} GB";
            try {
                SendTelegramJob::dispatch($result['telegram_id'], $message, '');
                $result['notification_queued'] = true;
            } catch (\Throwable $error) {
                Log::error('Telegram traffic gift notification failed', [
                    'user_id' => $userId, 'error' => $error->getMessage(),
                ]);
            }
        }
        unset($result['telegram_id'], $result['remaining_bytes'], $result['package_name']);
        return $result;
    }

    public static function expireDue(int $batchSize = 100): int
    {
        $batchSize = max(1, $batchSize);
        $expired = 0;
        do {
            $ids = DB::table('v2_telegram_traffic_grant')
                ->where('mode', 'timed')->whereNull('revoked_at')
                ->where('expires_at', '<=', time())
                ->orderBy('id')->limit($batchSize)->pluck('id');
            foreach ($ids as $id) {
                $expired += DB::transaction(function () use ($id) {
                    $userId = DB::table('v2_telegram_traffic_grant')->where('id', $id)->value('user_id');
                    $user = User::query()->whereKey($userId)->lockForUpdate()->first();
                    $grant = DB::table('v2_telegram_traffic_grant')->where('id', $id)->lockForUpdate()->first();
                    if (!$grant || $grant->revoked_at !== null || $grant->mode !== 'timed'
                        || (int) $grant->expires_at > time()) return 0;
                    if ($user) {
                        $amount = min((int) $grant->amount_bytes, (int) $user->telegram_bonus_timed);
                        $user->telegram_bonus_timed = max(0, (int) $user->telegram_bonus_timed - $amount);
                        if ($grant->applied_at !== null) {
                            $user->transfer_enable = max(0, (int) $user->transfer_enable - $amount);
                        }
                        $user->save();
                        Cache::forget('user_traffic_' . $user->id);
                        Cache::forget('user_subscription_' . $user->token);
                    }
                    DB::table('v2_telegram_traffic_grant')->where('id', $id)
                        ->update(['revoked_at' => time()]);
                    return 1;
                });
            }
        } while ($ids->count() === $batchSize);
        return $expired;
    }
}

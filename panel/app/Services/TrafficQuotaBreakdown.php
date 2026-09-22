<?php

namespace App\Services;

use App\Models\GiftCardUsage;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TrafficQuotaBreakdown
{
    private const GB = 1073741824;

    public static function forUser(User $user): array
    {
        $total = max(0, (int) $user->transfer_enable);
        $cycle = max(0, (int) $user->telegram_bonus_cycle);
        $permanent = max(0, (int) $user->telegram_bonus_permanent);
        $timed = max(0, (int) $user->telegram_bonus_timed);
        $used = max(0, (int) $user->u + (int) $user->d);
        $planQuota = $user->plan_id
            ? (int) Plan::query()->whereKey($user->plan_id)->value('transfer_enable') * self::GB : 0;
        $giftRecords = self::giftCardRecords($user->id);
        $giftHeadroom = max(0, $total - $cycle - $permanent - $timed - $planQuota);
        $giftActive = min($giftHeadroom, array_sum(array_column($giftRecords, 'amount_bytes')));
        $entries = array_merge(
            self::telegramEntries($user->id, $cycle, $permanent, $timed),
            self::allocate($giftRecords, $giftActive)
        );
        $status = $user->plan_id === null ? 'no_plan'
            : ($user->expired_at !== null && (int) $user->expired_at < time() ? 'expired' : 'active');
        return [
            'base_bytes' => max(0, $total - $cycle - $permanent - $timed - $giftActive),
            'cycle_bonus_bytes' => $cycle,
            'permanent_bonus_bytes' => $permanent,
            'timed_bonus_bytes' => $timed,
            'gift_card_bonus_bytes' => $giftActive,
            'total_bytes' => $total,
            'used_bytes' => $used,
            'remaining_bytes' => max(0, $total - $used),
            'status' => $status,
            'entries' => $entries,
        ];
    }

    private static function telegramEntries(int $userId, int $cycle, int $permanent, int $timed): array
    {
        $remaining = ['cycle' => $cycle, 'permanent' => $permanent, 'timed' => $timed];
        $entries = [];
        $grants = DB::table('v2_telegram_traffic_grant')
            ->where('user_id', $userId)->orderByDesc('id')->limit(100)->get();
        foreach ($grants as $grant) {
            if (!isset($remaining[$grant->mode])) continue;
            if ($grant->mode === 'timed' && ($grant->revoked_at !== null || (int) $grant->expires_at <= time())) continue;
            $amount = min($remaining[$grant->mode], (int) $grant->amount_bytes);
            if ($amount <= 0) continue;
            $entries[] = [
                'bucket' => $grant->mode,
                'amount_bytes' => $amount,
                'reason' => $grant->reason ?: ($grant->source === 'bind' ? '机器人绑定赠送' : '管理员补发'),
                'source' => $grant->source === 'bind' ? '机器人绑定' : '管理员补发',
                'created_at' => (int) $grant->created_at,
                'expires_at' => $grant->mode === 'timed' ? (int) $grant->expires_at : null,
            ];
            $remaining[$grant->mode] -= $amount;
        }
        foreach ($remaining as $mode => $amount) {
            if ($amount <= 0) continue;
            $entries[] = [
                'bucket' => $mode, 'amount_bytes' => $amount,
                'reason' => '较早的赠送记录', 'source' => '历史赠送', 'created_at' => null,
            ];
        }
        return $entries;
    }

    private static function giftCardRecords(int $userId): array
    {
        $usages = GiftCardUsage::query()->with('template:id,name')
            ->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)->orWhere('invite_user_id', $userId);
            })->orderByDesc('id')->limit(100)->get();
        $records = [];
        foreach ($usages as $usage) {
            $name = $usage->template?->name ?: '礼品卡';
            $at = (int) $usage->getRawOriginal('created_at');
            if ((int) $usage->user_id === $userId) {
                $amount = (int) ($usage->rewards_given['transfer_enable'] ?? 0);
                if ($amount > 0) $records[] = [
                    'bucket' => 'gift_card', 'amount_bytes' => $amount,
                    'reason' => '礼品卡：' . $name . ($usage->notes ? ' · ' . $usage->notes : ''),
                    'source' => '礼品卡兑换', 'created_at' => $at,
                ];
            }
            if ((int) $usage->invite_user_id === $userId) {
                $amount = (int) ($usage->invite_rewards['transfer_enable'] ?? 0);
                if ($amount > 0) $records[] = [
                    'bucket' => 'gift_card', 'amount_bytes' => $amount,
                    'reason' => '礼品卡邀请奖励：' . $name,
                    'source' => '礼品卡邀请奖励', 'created_at' => $at,
                ];
            }
        }
        return $records;
    }

    private static function allocate(array $records, int $budget): array
    {
        $active = [];
        foreach ($records as $record) {
            if ($budget <= 0) break;
            $amount = min($budget, $record['amount_bytes']);
            if ($amount <= 0) continue;
            $record['amount_bytes'] = $amount;
            $active[] = $record;
            $budget -= $amount;
        }
        return $active;
    }
}

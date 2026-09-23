<?php

namespace App\Services;

use App\Jobs\SendTelegramJob;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class TelegramUserAlertService
{
    private const ALERT_COOLDOWN = 86400;
    private const SUBSCRIPTION_WINDOW = 86400;
    private const GB = 1073741824;

    public static function trafficThreshold(): int
    {
        return max(50, min(99, (int) admin_setting('telegram_traffic_warn_percent', 80)));
    }

    public static function subscriptionIpLimit(): int
    {
        return max(2, min(50, (int) admin_setting('telegram_subscribe_ip_limit', 5)));
    }

    public static function checkTraffic(User $user): bool
    {
        if (!self::eligible($user, 'telegram_user_notify_traffic_low')
            || !self::recipient($user)->remind_traffic || !$user->plan_id || (int) $user->transfer_enable <= 0
            || ($user->expired_at !== null && (int) $user->expired_at <= time())) {
            return false;
        }

        $total = (int) $user->transfer_enable;
        $used = (int) $user->u + (int) $user->d;
        $percent = $used / $total * 100;
        $threshold = self::trafficThreshold();
        if ($percent < $threshold || $used >= $total) return false;

        $remaining = number_format(($total - $used) / self::GB, 2, '.', '');
        $package = '套餐 #' . $user->id;
        $message = "📊 DBoard 流量提醒（{$package}）\n本周期流量已使用 " . number_format($percent, 1)
            . "%，达到 {$threshold}% 提醒线。\n剩余流量：{$remaining} GB。请留意用量。";
        $key = "dboard:tg-alert:traffic:{$user->id}:{$user->plan_id}:"
            . (int) $user->last_reset_at;
        return self::queueOnce($user, $key, $message);
    }

    public static function checkDeviceCount(User $user, int $count): bool
    {
        $limit = (int) $user->device_limit;
        if (!self::eligible($user, 'telegram_user_notify_device_over_limit')
            || !$user->plan_id || $limit <= 0 || $count <= $limit
            || ($user->expired_at !== null && (int) $user->expired_at <= time())) {
            return false;
        }

        $package = '套餐 #' . $user->id;
        $message = "⚠️ DBoard 在线设备提醒（{$package}）\n节点当前检测到 {$count} 个不同 IP 在线，"
            . "超过套餐允许的 {$limit} 个。请检查正在使用的设备。";
        return self::queueOnce(
            $user,
            "dboard:tg-alert:devices:{$user->id}:{$user->plan_id}:{$limit}",
            $message
        );
    }

    public static function recordSubscriptionAccess(User $user, string $ip): int
    {
        try {
            if (!self::eligible($user, 'telegram_user_notify_subscription_sharing')
                || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 0;
            }

            $tokenFingerprint = substr(hash_hmac('sha256', (string) $user->token, (string) config('app.key')), 0, 16);
            $key = "dboard:subscribe:public-ips:{$user->id}:{$tokenFingerprint}";
            $now = time();
            $ipFingerprint = hash_hmac('sha256', $ip, (string) config('app.key'));
            Redis::zadd($key, $now, $ipFingerprint);
            Redis::zremrangebyscore($key, '-inf', (string) ($now - self::SUBSCRIPTION_WINDOW));
            Redis::expire($key, self::SUBSCRIPTION_WINDOW + 3600);
            $count = (int) Redis::zcard($key);
            $limit = self::subscriptionIpLimit();
            if ($count > $limit) {
                $message = "🔐 DBoard 订阅链接异常提醒\n过去 24 小时有 {$count} 个不同公网 IP "
                    . "获取您的订阅，超过设置的 {$limit} 个。换网也可能造成这种情况；"
                    . "若非本人操作，请在用户中心重置订阅链接。";
                self::queueOnce($user, "dboard:tg-alert:subscribe:{$user->id}:{$tokenFingerprint}:{$limit}", $message);
            }
            return $count;
        } catch (\Throwable $e) {
            Log::warning('Subscription IP alert check failed', [
                'user_id' => $user->id, 'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    private static function recipient(User $user): User
    {
        return $user->parent_id ? ($user->parent ?? $user) : $user;
    }

    private static function eligible(User $user, string $setting): bool
    {
        $recipient = self::recipient($user);
        return (bool) admin_setting('telegram_bot_enable', false)
            && (bool) admin_setting('telegram_bot_token')
            && (bool) admin_setting($setting, false)
            && !$user->banned && !$recipient->banned
            && $recipient->remind_telegram && (int) $recipient->telegram_id > 0;
    }

    private static function queueOnce(User $user, string $key, string $message): bool
    {
        try {
            if (!Cache::add($key, true, self::ALERT_COOLDOWN)) return false;
            try {
                SendTelegramJob::dispatch((int) self::recipient($user)->telegram_id, $message, '');
            } catch (\Throwable $e) {
                Cache::forget($key);
                throw $e;
            }
            return true;
        } catch (\Throwable $e) {
            Log::warning('Telegram user alert enqueue failed', [
                'user_id' => $user->id, 'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

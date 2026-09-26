<?php

namespace App\Services;

use App\Jobs\SendTelegramJob;
use App\Models\User;
use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class UserTelegramNotifier
{
    public static function broadcast(string $setting, string $message): int
    {
        return self::enqueue(User::query(), $setting, $message);
    }

    /** Notify account owners whose current packages include this node's permission group. */
    public static function nodeOffline(Server $server, string $message): int
    {
        if (!$server->enabled || !$server->show || empty($server->group_ids)) {
            return 0;
        }

        // Each additional package is a child User; notify its owner, never the child identity.
        // Match saved package permissions, including administrator overrides, not the catalog plan.
        $owners = User::query()
            ->selectRaw('COALESCE(parent_id, id)')
            ->whereNotNull('plan_id')
            ->whereIn('group_id', $server->group_ids)
            ->where('banned', false)
            ->where(fn (Builder $query) => $query->whereNull('expired_at')
                ->orWhere('expired_at', '>', time()));

        return self::enqueue(
            User::query()->whereNull('parent_id')->whereIn('id', $owners),
            'telegram_user_notify_node_offline',
            $message
        );
    }

    private static function enqueue(Builder $recipients, string $setting, string $message): int
    {
        if (!admin_setting('telegram_bot_enable', false)
            || !admin_setting('telegram_bot_token')
            || !admin_setting($setting, false)) {
            return 0;
        }

        $queued = 0;
        try {
            $recipients->select(['id', 'telegram_id'])
                ->where('banned', false)
                ->where('remind_telegram', true)
                ->whereNotNull('telegram_id')
                ->where('telegram_id', '!=', 0)
                ->chunkById(500, function ($users) use ($message, &$queued) {
                    foreach ($users as $user) {
                        SendTelegramJob::dispatch((int) $user->telegram_id, $message, '');
                        $queued++;
                    }
                });
        } catch (\Throwable $e) {
            Log::error('User Telegram notification enqueue failed', [
                'setting' => $setting,
                'error' => $e->getMessage(),
            ]);
        }

        return $queued;
    }

    public static function user(User $user, string $setting, string $message): void
    {
        $user = $user->parent_id ? ($user->parent ?? $user) : $user;
        if (admin_setting('telegram_bot_enable', false)
            && admin_setting('telegram_bot_token')
            && admin_setting($setting, false)
            && $user->remind_telegram
            && $user->telegram_id) {
            SendTelegramJob::dispatch((int) $user->telegram_id, $message, '');
        }
    }
}

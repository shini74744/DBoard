<?php

namespace App\Services;

use App\Jobs\SendTelegramJob;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class UserTelegramNotifier
{
    public static function broadcast(string $setting, string $message): int
    {
        if (!admin_setting('telegram_bot_enable', false)
            || !admin_setting('telegram_bot_token')
            || !admin_setting($setting, false)) {
            return 0;
        }

        $queued = 0;
        try {
            User::query()->select(['id', 'telegram_id'])
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
        if (admin_setting('telegram_bot_enable', false)
            && admin_setting('telegram_bot_token')
            && admin_setting($setting, false)
            && $user->remind_telegram
            && $user->telegram_id) {
            SendTelegramJob::dispatch((int) $user->telegram_id, $message, '');
        }
    }
}

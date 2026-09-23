<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TelegramUserAlertService;
use Illuminate\Console\Command;

class CheckTelegramTrafficAlerts extends Command
{
    protected $signature = 'dboard:check-telegram-traffic-alerts';
    protected $description = 'Check active subscriptions for Telegram low-traffic alerts';

    public function handle(): int
    {
        if (!admin_setting('telegram_user_notify_traffic_low', false)
            || !admin_setting('telegram_bot_enable', false)
            || !admin_setting('telegram_bot_token')) {
            return self::SUCCESS;
        }

        User::query()
            ->select([
                'id', 'parent_id', 'telegram_id', 'remind_telegram', 'remind_traffic', 'banned',
                'plan_id', 'expired_at', 'transfer_enable', 'u', 'd', 'last_reset_at',
            ])
            ->whereNotNull('plan_id')
            ->where('banned', false)
            ->where('transfer_enable', '>', 0)
            ->chunkById(500, function ($users) {
                foreach ($users as $user) {
                    TelegramUserAlertService::checkTraffic($user);
                }
            });

        return self::SUCCESS;
    }
}

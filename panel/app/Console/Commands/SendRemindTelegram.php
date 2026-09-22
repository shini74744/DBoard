<?php

namespace App\Console\Commands;

use App\Jobs\SendTelegramJob;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SendRemindTelegram extends Command
{
    protected $signature = 'send:remindTelegram';
    protected $description = 'Notify bound Telegram users of approaching expiration';

    public function handle(): int
    {
        if (!admin_setting('telegram_bot_enable', false) || !admin_setting('telegram_bot_token')) {
            $this->warn('Telegram bot is not configured');
            return self::SUCCESS;
        }

        $now = time();
        $sent = 0;
        User::query()
            ->select(['id', 'telegram_id', 'expired_at'])
            ->where('banned', false)
            ->where('remind_telegram', true)
            ->whereNotNull('telegram_id')
            ->where('telegram_id', '!=', 0)
            ->where('expired_at', '>', $now)
            ->where('expired_at', '<=', $now + 86400)
            ->chunkById(500, function ($users) use (&$sent, $now) {
                foreach ($users as $user) {
                    $key = "dboard:telegram:expiry:{$user->id}:{$user->expired_at}";
                    if (!Cache::add($key, true, 172800)) {
                        continue;
                    }
                    $date = date('Y-m-d H:i', (int) $user->expired_at);
                    $hours = max(1, (int) ceil(($user->expired_at - $now) / 3600));
                    $message = "⏰ 您的 DBoard 套餐将在约 {$hours} 小时后到期（{$date}）。请及时续费，以免服务中断。";
                    SendTelegramJob::dispatch((int) $user->telegram_id, $message, '');
                    $sent++;
                }
            });

        User::query()
            ->select(['id', 'telegram_id', 'expired_at'])
            ->where('banned', false)
            ->where('remind_telegram', true)
            ->whereNotNull('telegram_id')
            ->where('telegram_id', '!=', 0)
            ->where('expired_at', '<=', $now)
            ->where('expired_at', '>', $now - 86400)
            ->chunkById(500, function ($users) use (&$sent) {
                foreach ($users as $user) {
                    $key = "dboard:telegram:expired:{$user->id}:{$user->expired_at}";
                    if (!Cache::add($key, true, 172800)) {
                        continue;
                    }
                    $date = date('Y-m-d H:i', (int) $user->expired_at);
                    $message = "⏰ 您的 DBoard 套餐已于 {$date} 到期。请及时续费，以免服务中断。";
                    SendTelegramJob::dispatch((int) $user->telegram_id, $message, '');
                    $sent++;
                }
            });

        $this->info("Queued {$sent} Telegram expiry reminders");
        return self::SUCCESS;
    }
}

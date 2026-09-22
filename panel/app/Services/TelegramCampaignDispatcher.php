<?php

namespace App\Services;

use App\Jobs\SendTelegramJob;
use App\Models\Order;
use App\Models\TelegramCampaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class TelegramCampaignDispatcher
{
    public static function eligibleUsers(string $audience, ?int $planId = null, array $userIds = []): Builder
    {
        $query = User::query()
            ->where('banned', false)
            ->where('remind_telegram', true)
            ->whereNotNull('telegram_id')
            ->where('telegram_id', '>', 0);

        if ($audience === 'plan') {
            $query->whereIn('id', Order::query()->select('user_id')
                ->where('plan_id', $planId)
                ->whereIn('status', [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED])
                ->where('type', '!=', Order::TYPE_RESET_TRAFFIC));
        } elseif ($audience === 'users') {
            $query->whereIn('id', $userIds);
        }

        return $query;
    }

    public static function dispatch(TelegramCampaign $campaign): void
    {
        if (!TelegramCampaign::query()->whereKey($campaign->id)->where('status', 'pending')
            ->update(['status' => 'processing', 'started_at' => time()])) {
            return;
        }

        try {
            if (!admin_setting('telegram_bot_enable', false) || !admin_setting('telegram_bot_token')) {
                throw new \RuntimeException('Telegram 机器人未启用或令牌未配置');
            }
            $queued = 0;
            self::eligibleUsers($campaign->audience, $campaign->plan_id, $campaign->user_ids ?? [])
                ->select(['id', 'telegram_id'])->chunkById(500, function ($users) use ($campaign, &$queued) {
                    foreach ($users as $user) {
                        SendTelegramJob::dispatch((int) $user->telegram_id, $campaign->message, '')
                            ->delay(now()->addSeconds(intdiv($queued, 10)));
                        $queued++;
                    }
                    $campaign->update(['queued_count' => $queued]);
                });
            $campaign->update(['status' => 'queued', 'queued_count' => $queued, 'finished_at' => time()]);
        } catch (\Throwable $error) {
            $campaign->update(['status' => 'failed', 'last_error' => mb_substr($error->getMessage(), 0, 1000), 'finished_at' => time()]);
            Log::error('DBoard Telegram campaign queue failed', ['campaign_id' => $campaign->id, 'error' => $error->getMessage()]);
        }
    }
}

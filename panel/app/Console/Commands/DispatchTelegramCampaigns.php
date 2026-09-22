<?php

namespace App\Console\Commands;

use App\Models\TelegramCampaign;
use App\Services\TelegramCampaignDispatcher;
use Illuminate\Console\Command;

class DispatchTelegramCampaigns extends Command
{
    protected $signature = 'dboard:dispatch-telegram-campaigns';
    protected $description = 'Queue due DBoard Telegram notification campaigns';

    public function handle(): int
    {
        TelegramCampaign::query()->where('status', 'pending')
            ->where('scheduled_at', '<=', time())->orderBy('scheduled_at')
            ->limit(10)->get()->each(fn (TelegramCampaign $campaign) => TelegramCampaignDispatcher::dispatch($campaign));
        return self::SUCCESS;
    }
}

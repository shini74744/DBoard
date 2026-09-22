<?php

namespace App\Console\Commands;

use App\Services\TelegramBindingRewardService;
use Illuminate\Console\Command;

class ExpireTelegramTrafficGrants extends Command
{
    protected $signature = 'dboard:expire-telegram-traffic-grants';
    protected $description = 'Remove Telegram traffic gifts after their selected duration';

    public function handle(): int
    {
        $count = TelegramBindingRewardService::expireDue();
        if ($count > 0) $this->info("Expired {$count} Telegram traffic grants.");
        return self::SUCCESS;
    }
}

<?php

namespace Plugin\SubscriptionGuard\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupLogsCommand extends Command
{
    protected $signature = 'subscription-guard:cleanup {--days=30 : 清理多少天前的日志}';
    protected $description = '清理订阅拉取日志';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $before = now()->subDays($days);

        $logCount = DB::table('v2_subscribe_log')
            ->where('created_at', '<', $before)
            ->delete();

        $this->info("已清理 {$logCount} 条订阅拉取日志 ({$days}天前)");

        return 0;
    }
}

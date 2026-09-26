<?php

namespace App\Console\Commands;

use App\Services\ServerService;
use App\Services\TelegramService;
use App\Services\UserTelegramNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CheckServer extends Command
{
    protected $signature = 'check:server';
    protected $description = 'Check node health and queue configured offline reminders';

    public function handle(): int
    {
        $now = time();
        $limit = max(1, min(10, (int) admin_setting('telegram_node_offline_reminders', 3)));
        $interval = max(5, min(1440, (int) admin_setting('telegram_node_offline_interval', 60))) * 60;

        foreach (ServerService::getAllServers() as $server) {
            if ($server->parent_id || !$server->enabled) continue;

            $seenKey = "dboard:node:last-seen:{$server->id}";
            $stateKey = "dboard:node:offline:{$server->id}";
            $heartbeat = (int) $server->last_check_at;
            if ($heartbeat > 0) {
                Cache::put($seenKey, $heartbeat, 30 * 86400);
            } else {
                $heartbeat = (int) Cache::get($seenKey, 0);
            }
            if ($heartbeat === 0) continue;
            if ($now - $heartbeat <= 1800) {
                Cache::forget($stateKey);
                continue;
            }

            $state = Cache::get($stateKey, ['count' => 0, 'last_notice' => 0, 'admin_notified' => false]);
            if (empty($state['admin_notified']) && admin_setting('telegram_bot_enable', false)
                && admin_setting('telegram_bot_token')) {
                (new TelegramService())->sendMessageWithAdmin(
                    "节点掉线通知\n----\n节点名称：{$server->name}\n节点地址：{$server->host}"
                );
                $state['admin_notified'] = true;
            }
            if (!$server->show || !admin_setting('telegram_user_notify_node_offline', false)) {
                Cache::put($stateKey, $state, 30 * 86400);
                continue;
            }
            if ($state['count'] >= $limit || $now - $state['last_notice'] < $interval) {
                Cache::put($stateKey, $state, 30 * 86400);
                continue;
            }

            $queued = UserTelegramNotifier::nodeOffline(
                $server,
                "⚠️ 节点暂时离线：{$server->name}。我们正在处理，请稍后重试。"
            );
            if ($queued > 0) {
                $state['count']++;
                $state['last_notice'] = $now;
            }
            Cache::put($stateKey, $state, 30 * 86400);
        }

        return self::SUCCESS;
    }
}

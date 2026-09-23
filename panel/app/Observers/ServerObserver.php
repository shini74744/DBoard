<?php

namespace App\Observers;

use App\Models\Server;
use App\Services\NodeSyncService;
use App\Services\UserTelegramNotifier;

class ServerObserver
{
    public bool $afterCommit = true;

    public function created(Server $server): void
    {
        \App\Services\NodeOutboundService::notifyChanged($server);
        $this->notifyMachineNodesChanged($server->machine_id);
        if ($server->show && $server->enabled) {
            UserTelegramNotifier::broadcast('telegram_user_notify_node_new', "🆕 新节点上线：{$server->name}");
        }
    }

    public function updated(Server $server): void
    {
        if ($server->wasChanged(['host','port','protocol_settings','type','enabled','outbound_ids'])) {
            \App\Services\NodeOutboundService::notifyChanged($server);
        }
        if ($server->wasChanged('name') && $server->show) {
            $oldName = $server->getPrevious()['name'] ?? '';
            UserTelegramNotifier::broadcast(
                'telegram_user_notify_node_name',
                "📢 节点名称变动（ID: {$server->id}）\n原名称：{$oldName}\n新名称：{$server->name}"
            );
        }
        if ($server->wasChanged('rate') && $server->show) {
            $oldRate = $server->getPrevious()['rate'] ?? '';
            UserTelegramNotifier::broadcast(
                'telegram_user_notify_node_rate',
                "📊 节点倍率变动：{$server->name}\n原倍率：{$oldRate} 倍\n新倍率：{$server->rate} 倍"
            );
        }
        if ($server->wasChanged('group_ids')) {
            NodeSyncService::notifyFullSync($server->id);
        } elseif ($server->wasChanged([
            'server_port',
            'protocol_settings',
            'type',
            'route_ids',
            'outbound_ids',
            'custom_outbounds',
            'custom_routes',
            'custom_route_rules',
            'custom_balancers',
            'cert_config',
        ])) {
            NodeSyncService::notifyConfigUpdated($server->id);
        }

        if ($server->wasChanged(['machine_id', 'enabled'])) {
            $this->notifyMachineChange(
                $server->machine_id,
                $server->getOriginal('machine_id')
            );
        }
    }

    public function deleted(Server $server): void
    {
        \App\Services\NodeOutboundService::notifyChanged($server);
        $this->notifyMachineChange(null, $server->getOriginal('machine_id') ?: $server->machine_id);
    }

    private function notifyMachineChange(?int $newMachineId, ?int $oldMachineId): void
    {
        $notified = [];

        if ($newMachineId) {
            NodeSyncService::notifyMachineNodesChanged($newMachineId);
            $notified[] = $newMachineId;
        }

        if ($oldMachineId && !in_array($oldMachineId, $notified, true)) {
            NodeSyncService::notifyMachineNodesChanged($oldMachineId);
        }
    }

    private function notifyMachineNodesChanged(?int $machineId): void
    {
        if ($machineId) {
            NodeSyncService::notifyMachineNodesChanged($machineId);
        }
    }
}

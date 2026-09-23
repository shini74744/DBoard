<?php

namespace App\Observers;

use App\Models\Server;
use App\Models\ServerOutbound;
use App\Services\NodeSyncService;

class ServerOutboundObserver
{
    public bool $afterCommit = true;

    public function updated(ServerOutbound $outbound): void
    {
        $this->notifyAffectedNodes($outbound->id);
    }

    public function deleted(ServerOutbound $outbound): void
    {
        $this->notifyAffectedNodes($outbound->id);
    }

    private function notifyAffectedNodes(int $outboundId): void
    {
        foreach (Server::all() as $node) {
            if (\App\Models\ServerOutbound::where('target_server_id', $node->id)->exists()) {
                NodeSyncService::notifyFullSync($node->id);
            }
        }
        Server::query()
            ->get(['id', 'outbound_ids'])
            ->filter(fn(Server $server) => in_array(
                $outboundId,
                array_map('intval', $server->outbound_ids ?? []),
                true
            ))
            ->each(fn(Server $server) => NodeSyncService::notifyConfigUpdated($server->id));
    }
}

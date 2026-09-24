<?php
namespace App\Services;

use App\Models\{Server,ServerOutbound};
use Illuminate\Support\Facades\DB;

class OutboundTrafficService
{
    // Cumulative snapshots are scoped to an authenticated node and a random
    // kernel session. The cursor and totals commit together, even on retries.
    public static function record(Server $node, mixed $report): void
    {
        if (!is_array($report) || !is_string($report['session'] ?? null)
            || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $report['session'])
            || !is_array($report['traffic'] ?? null) || count($report['traffic']) > 1000) return;

        $session = strtolower($report['session']);
        $configured = array_map('intval', $node->outbound_ids ?: []);
        $rows = $report['traffic'];
        ksort($rows, SORT_NUMERIC); // Consistent row lock ordering across reports.
        DB::transaction(function () use ($node, $session, $configured, $rows) {
            foreach ($rows as $id => $bytes) {
                if (!ctype_digit((string)$id) || (int)$id <= 0 || !is_array($bytes) || count($bytes) !== 2
                    || !is_int($bytes[0] ?? null) || !is_int($bytes[1] ?? null) || $bytes[0] < 0 || $bytes[1] < 0) continue;
                $outbound = DB::table('v2_server_outbound')->where('id', (int)$id)->lockForUpdate()->first();
                if (!$outbound) continue;
                $key = ['outbound_id'=>(int)$id, 'server_id'=>$node->id, 'session'=>$session];
                $cursor = DB::table('v2_outbound_traffic_cursor')->where($key)->first();
                // Existing cursor permits final bytes from a detached, draining outbound.
                if (!$cursor && !in_array((int)$id, $configured, true)) continue;
                $up = max((int)($cursor->upload ?? 0), $bytes[0]);
                $down = max((int)($cursor->download ?? 0), $bytes[1]);
                $du = $up - (int)($cursor->upload ?? 0);
                $dd = $down - (int)($cursor->download ?? 0);
                if ($du > PHP_INT_MAX - (int)$outbound->traffic_upload || $dd > PHP_INT_MAX - (int)$outbound->traffic_download) continue;
                DB::table('v2_outbound_traffic_cursor')->updateOrInsert($key, ['upload'=>$up,'download'=>$down]);
                NodeOutboundTrafficService::add($node, (int)$id, $du, $dd);
                DB::table('v2_server_outbound')->where('id',(int)$id)->update([
                    'traffic_upload'=>(int)$outbound->traffic_upload + $du,
                    'traffic_download'=>(int)$outbound->traffic_download + $dd,
                    'traffic_started_at'=>$outbound->traffic_started_at ?: time(),
                    'traffic_updated_at'=>time(),
                ]);
            }
        }, 3);
    }
}

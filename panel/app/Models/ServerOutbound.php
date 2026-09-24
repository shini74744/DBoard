<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerOutbound extends Model
{
    protected $table = 'v2_server_outbound';
    protected $guarded = ['id'];

    protected $casts = [
        'display_id' => 'integer',
        'settings' => 'array',
        'traffic_upload' => 'integer',
        'traffic_download' => 'integer',
        'traffic_started_at' => 'integer',
        'traffic_updated_at' => 'integer',
        'target_server_id' => 'integer',
        'enabled' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $outbound) {
            $outbound->traffic_started_at = time();
            // Reserve a number under one shared DB lock, including node-created outbounds.
            $outbound->display_id = \Illuminate\Support\Facades\DB::transaction(function () {
                $db = \Illuminate\Support\Facades\DB::table('dboard_outbound_sequence')->where('id', 1);
                $next = (int)$db->lockForUpdate()->value('last_number') + 1;
                $db->update(['last_number'=>$next]);
                return $next;
            });
        });
    }

    public function toNodeConfig(): array
    {
        if ($this->target_server_id) return ['id'=>$this->id] + \App\Services\NodeOutboundService::config($this);
        $config = [
            'id' => $this->id,
            'tag' => $this->tag,
            'protocol' => $this->protocol,
            'settings' => $this->settings ?: [],
        ];

        if ($this->proxy_tag) {
            $config['proxy_tag'] = $this->proxy_tag;
        }

        return $config;
    }
}

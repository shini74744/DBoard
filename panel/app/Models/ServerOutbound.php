<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerOutbound extends Model
{
    protected $table = 'v2_server_outbound';
    protected $guarded = ['id'];

    protected $casts = [
        'settings' => 'array',
        'target_server_id' => 'integer',
        'enabled' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function toNodeConfig(): array
    {
        if ($this->target_server_id) return \App\Services\NodeOutboundService::config($this);
        $config = [
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerOutbound extends Model
{
    protected $table = 'v2_server_outbound';
    protected $guarded = ['id'];

    protected $casts = [
        'settings' => 'array',
        'enabled' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function toNodeConfig(): array
    {
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

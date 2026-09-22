<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramCampaign extends Model
{
    protected $table = 'dboard_telegram_campaigns';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $casts = [
        'user_ids' => 'array',
        'plan_id' => 'integer',
        'scheduled_at' => 'integer',
        'queued_count' => 'integer',
        'started_at' => 'integer',
        'finished_at' => 'integer',
    ];
}

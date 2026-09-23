<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionCombination extends Model
{
    protected $table = 'v2_subscription_combination';
    protected $guarded = ['id'];
    protected $casts = ['package_ids' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

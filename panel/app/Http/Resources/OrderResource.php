<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        \App\Services\SubscriptionActivityService::forCustomer($this->resource);
        $base=parent::toArray($request);
        unset($base['admin_actor_id']);
        return [
            ...$base,
            'period' => $this->period ? PlanService::getLegacyPeriod((string)$this->period) : null,
            'plan' => $this->whenLoaded('plan', fn() => $this->record_kind==='activity' ? ['id'=>$this->plan_id,'name'=>$this->activity_plan_name] : ($this->plan ? PlanResource::make($this->plan) : null)),
            'payment' => $this->whenLoaded('payment', fn() => $this->payment ? [
                'id' => $this->payment->id,
                'name' => $this->payment->name,
                'payment' => $this->payment->payment,
                'icon' => $this->payment->icon,
            ] : null),
        ];
    }
}

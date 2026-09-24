<?php
namespace App\Services;

use App\Models\{Order, Plan, User};

class PackageBillingService
{
    public const PERIODS = ['monthly','quarterly','half_yearly','yearly','two_yearly','three_yearly','onetime'];

    // Never infer billing period from expiry: administrators can assign custom durations.
    public static function info(User $package): array
    {
        $period = $package->billing_period;
        $source = $period ? 'saved' : 'unknown';
        if (!$period && $package->plan_id) {
            $order = Order::where('plan_id',$package->plan_id)
                ->where('status',Order::STATUS_COMPLETED)
                ->whereIn('period',array_merge(self::PERIODS,array_keys(Plan::LEGACY_PERIOD_MAPPING)))
                ->whereNotIn('period',['reset_traffic','reset_price'])
                ->where(function($query)use($package){
                    $query->where('subscription_user_id',$package->id);
                    if (!$package->parent_id) {
                        $query->orWhere(function($legacy)use($package){
                            $legacy->whereNull('subscription_user_id')->where('user_id',$package->id)
                                ->where(function($action){$action->whereNull('subscription_action')->orWhere('subscription_action','!=','add');});
                        });
                    }
                })->orderByDesc('id')->first(['period']);
            if ($order) {$period=PlanService::getPeriodKey($order->period);$source='order';}
        }
        if (!in_array($period,self::PERIODS,true)) {$period=null;$source='unknown';}
        $prices=$package->billing_prices ?? [];
        $custom=$period!==null && array_key_exists($period,$prices);
        $standard=$period!==null ? ($package->plan?->prices[$period] ?? null) : null;
        return [
            'billing_period'=>$period,
            'billing_period_source'=>$source,
            'billing_price'=>$custom ? (int)$prices[$period] : ($standard===null ? null : (int)round($standard*100)),
            'billing_price_custom'=>$custom,
            'billing_prices'=>$prices,
            'billing_plan_prices'=>$package->plan?->prices ?? [],
        ];
    }

    // Clone the model: quoted prices must never mutate the shared plan.
    public static function forPackage(Plan $plan, User $package): Plan
    {
        $quoted=clone $plan;
        if ((int)$package->plan_id !== (int)$plan->id) return $quoted;
        $prices=$quoted->prices ?? [];
        foreach ($package->billing_prices ?? [] as $period=>$cents) {
            if (in_array($period,self::PERIODS,true) && is_int($cents) && $cents>=0) $prices[$period]=$cents/100;
        }
        $quoted->prices=$prices;
        $quoted->setAttribute('billing_period',self::info($package)['billing_period']);
        return $quoted;
    }
}

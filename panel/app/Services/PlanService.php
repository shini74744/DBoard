<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;

class PlanService
{
    public Plan $plan;

    public function __construct(Plan $plan)
    {
        $this->plan = $plan;
    }

    /**
     * 获取所有可销售的订阅计划列表
     * 条件：show 和 sell 为 true，且容量充足
     *
     * @return Collection
     */
    public function getAvailablePlans(): Collection
    {
        return Plan::where('show', true)
            ->where('sell', true)
            ->withCount(['users as capacity_used' => fn ($query) => $query->where(fn ($q) => $q->where('expired_at', '>=', time())->orWhereNull('expired_at'))])
            ->orderBy('sort')
            ->get()
            ->filter(function ($plan) {
                return $this->hasCapacity($plan);
            });
    }

    /**
     * 获取指定订阅计划的可用状态
     * 条件：renew 和 sell 为 true
     *
     * @param int $planId
     * @return Plan|null
     */
    public function getAvailablePlan(int $planId): ?Plan
    {
        return Plan::where('id', $planId)
            ->where('sell', true)
            ->where('renew', true)
            ->first();
    }

    /**
     * 检查指定计划是否可用于指定用户
     * 
     * @param Plan $plan
     * @param User $user
     * @return bool
     */
    public function isPlanAvailableForUser(Plan $plan, User $user): bool
    {
        // 如果是续费
        if ($user->plan_id === $plan->id) {
            return $plan->renew && ($this->occupiesCapacity($user, $plan) || $this->hasCapacity($plan));
        }

        // 如果是新购
        return $plan->show && $plan->sell && $this->hasCapacity($plan);
    }

    public function validatePurchase(User $user, string $period): void
    {
        if (!$this->plan) {
            throw new ApiException(__('Subscription plan does not exist'));
        }

        // 转换周期格式为新版格式
        $periodKey = self::getPeriodKey($period);
        $price = $this->plan->prices[$periodKey] ?? null;

        if ($price === null) {
            throw new ApiException(__('This payment period cannot be purchased, please choose another period'));
        }

        if ($periodKey === Plan::PERIOD_RESET_TRAFFIC) {
            $this->validateResetTrafficPurchase($user);
            return;
        }

        if (!$this->occupiesCapacity($user, $this->plan) && !$this->hasCapacity($this->plan)) {
            throw new ApiException(__('Current product is sold out'));
        }

        $this->validatePlanAvailability($user);
    }

    /**
     * 智能转换周期格式为新版格式
     * 如果是新版格式直接返回，如果是旧版格式则转换为新版格式
     *
     * @param string $period
     * @return string
     */
    public static function getPeriodKey(string $period): string
    {
        // 如果是新版格式直接返回
        if (in_array($period, self::getNewPeriods())) {
            return $period;
        }

        // 如果是旧版格式则转换为新版格式
        return Plan::LEGACY_PERIOD_MAPPING[$period] ?? $period;
    }
    /**
     * 只能转换周期格式为旧版本
     */
    public static function convertToLegacyPeriod(string $period): string
    {
        $flippedMapping = array_flip(Plan::LEGACY_PERIOD_MAPPING);
        return $flippedMapping[$period] ?? $period;
    }

    /**
     * 获取所有支持的新版周期格式
     *
     * @return array
     */
    public static function getNewPeriods(): array
    {
        return array_values(Plan::LEGACY_PERIOD_MAPPING);
    }

    /**
     * 获取旧版周期格式
     *
     * @param string $period
     * @return string
     */
    public static function getLegacyPeriod(string $period): string
    {
        $flipped = array_flip(Plan::LEGACY_PERIOD_MAPPING);
        return $flipped[$period] ?? $period;
    }

    protected function validateResetTrafficPurchase(User $user): void
    {
        if (!app(UserService::class)->isAvailable($user) || $this->plan->id !== $user->plan_id) {
            throw new ApiException(__('Subscription has expired or no active subscription, unable to purchase Data Reset Package'));
        }
    }

    protected function validatePlanAvailability(User $user): void
    {
        if ((!$this->plan->show && !$this->plan->renew) || (!$this->plan->show && $user->plan_id !== $this->plan->id)) {
            throw new ApiException(__('This subscription has been sold out, please choose another subscription'));
        }

        if (!$this->plan->renew && $user->plan_id == $this->plan->id) {
            throw new ApiException(__('This subscription cannot be renewed, please change to another subscription'));
        }

        if (!$this->plan->show && $this->plan->renew && !app(UserService::class)->isAvailable($user)) {
            throw new ApiException(__('This subscription has expired, please change to another subscription'));
        }
    }

    /** Call at the start of a transaction, before reading stock or account data. */
    public static function lockCapacity(int $planId): ?Plan
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite ignores FOR UPDATE. Acquire its writer lock before any
            // snapshot reads, otherwise two buyers can both see the last slot.
            DB::table('v2_plan')->where('id', $planId)
                ->update(['capacity_limit' => DB::raw('capacity_limit')]);
        }
        return Plan::whereKey($planId)->lockForUpdate()->first();
    }

    private function occupiesCapacity(User $user, Plan $plan): bool
    {
        return (int) $user->plan_id === (int) $plan->id
            && ($user->expired_at === null || $user->expired_at >= time());
    }

    /**
     * Reservations are derived from orders, so cancellation cannot refund a slot
     * twice. Expired pending orders stop occupying stock even if a worker is late.
     * Processing orders retain their slot until activation commits.
     */
    public static function reservedCapacity(Plan $plan): int
    {
        return Order::where('plan_id', $plan->id)
            ->whereNotIn('period', [Plan::PERIOD_RESET_TRAFFIC, 'reset_price'])
            ->where(function ($query) {
                $query->where('status', Order::STATUS_PROCESSING)
                    ->orWhere(fn ($pending) => $pending->where('status', Order::STATUS_PENDING)
                        ->where('created_at', '>', time() - Order::PAYMENT_TIMEOUT_SECONDS));
            })
            ->where(function ($query) {
                $query->whereIn('type', [
                    Order::TYPE_NEW_PURCHASE, Order::TYPE_ADDITIONAL, Order::TYPE_UPGRADE,
                ])->orWhere(function ($renewal) {
                    // If an existing package expires before renewal is paid,
                    // its occupied slot becomes the renewal order's reservation.
                    $renewal->where('type', Order::TYPE_RENEWAL)
                        ->whereNotExists(function ($user) {
                            $user->selectRaw('1')->from('v2_user as capacity_user')
                                ->whereRaw('capacity_user.id = COALESCE(v2_order.subscription_user_id, v2_order.user_id)')
                                ->whereColumn('capacity_user.plan_id', 'v2_order.plan_id')
                                ->where(fn ($expiry) => $expiry->whereNull('capacity_user.expired_at')
                                    ->orWhere('capacity_user.expired_at', '>=', time()));
                        });
                });
            })->count();
    }

    /** Available slots = configured capacity - active packages - reservations. */
    public static function remainingCapacity(Plan $plan): ?int
    {
        if ($plan->capacity_limit === null) return null;
        $used = $plan->getAttribute('capacity_used');
        if ($used === null) {
            $used = $plan->users()->where(fn ($q) => $q->where('expired_at', '>=', time())->orWhereNull('expired_at'))->count();
        }
        return max(0, (int) $plan->capacity_limit - (int) $used - self::reservedCapacity($plan));
    }

    public function hasCapacity(Plan $plan): bool
    {
        $remaining = self::remainingCapacity($plan);
        return $remaining === null || $remaining > 0;
    }

    public function getAvailablePeriods(Plan $plan): array
    {
        return array_filter(
            $plan->getActivePeriods(),
            fn($period) => isset($plan->prices[$period]) && $plan->prices[$period] > 0
        );
    }

    public function canResetTraffic(Plan $plan): bool
    {
        return $plan->reset_traffic_method !== Plan::RESET_TRAFFIC_NEVER
            && $plan->getResetTrafficPrice() > 0;
    }
}

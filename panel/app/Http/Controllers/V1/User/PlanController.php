<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    protected PlanService $planService;

    public function __construct(PlanService $planService)
    {
        $this->planService = $planService;
    }
    public function fetch(Request $request)
    {
        $user = User::find($request->user()->id);
        if ($request->input('id')) {
            $request->validate([
                'subscription_action' => 'nullable|in:add,renew',
                'purchase_source' => 'nullable|in:shop,package',
                'subscription_user_id' => 'required_if:subscription_action,renew|nullable|integer|min:1',
            ]);
            $newPackage = $request->input('subscription_action') === 'add';
            if ($newPackage && $request->filled('subscription_user_id')) throw new ApiException('新开套餐不能指定续费目标', 422);
            $plan = Plan::where('id', $request->input('id'))->first();
            if (!$plan) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }
            $target=$newPackage ? new User(['plan_id'=>null]) : $user;
            if ($request->filled('subscription_user_id')) {
                $request->validate(['subscription_user_id'=>'integer|min:1']);
                if (!\App\Services\MultiSubscriptionService::owns($user,$request->integer('subscription_user_id')))
                    throw new ApiException('所选套餐不属于该用户',422);
                $target=User::findOrFail($request->integer('subscription_user_id'));
                if ((int)$target->plan_id !== (int)$plan->id) throw new ApiException('续费套餐与所选套餐不一致',422);
            }
            if (!$this->planService->isPlanAvailableForUser($plan, $target)) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }
            return $this->success(PlanResource::make(($newPackage || $request->input('purchase_source') === 'shop') ? $plan : \App\Services\PackageBillingService::forPackage($plan,$target)));
        }

        $plans = $this->planService->getAvailablePlans();
        return $this->success(PlanResource::collection($plans));
    }
}

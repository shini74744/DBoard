<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use App\Models\User;
use App\Models\SubscriptionCombination;
use Illuminate\Support\Facades\Auth;

class Client
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $token = $request->input('token', $request->route('token'));
        if (empty($token)) {
            throw new ApiException('token is null',403);
        }
        $user = User::where('token', $token)->first();
        if (!$user) {
            $user = User::where('primary_package_token', $token)->whereNull('parent_id')->first();
            if ($user) $request->attributes->set('primary_package_subscription', true);
        }
        if (!$user) {
            $combination = SubscriptionCombination::where('token', $token)->first();
            if (!$combination || !$combination->user) {
                throw new ApiException('token is error', 403);
            }
            $request->attributes->set('subscription_combination', $combination);
            $user = $combination->user;
        }

        Auth::setUser($user);
        return $next($request);
    }
}

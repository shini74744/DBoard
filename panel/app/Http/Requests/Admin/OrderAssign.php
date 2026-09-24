<?php

namespace App\Http\Requests\Admin;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderAssign extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'plan_id' => 'required',
            'email' => 'required',
            'total_amount' => 'required|integer|min:0|max:2147483647',
            'renewal_price' => 'sometimes|nullable|integer|min:0|max:2147483647',
            'period' => ['required', Rule::in(array_merge(array_keys(Plan::LEGACY_PERIOD_MAPPING), array_values(Plan::LEGACY_PERIOD_MAPPING)))],
            'subscription_action' => 'nullable|in:auto,add,renew,extend',
            'subscription_user_id' => 'nullable|integer',
            'custom_duration_days' => 'nullable|integer|min:1|max:3650',
            'custom_expired_at' => ['nullable', 'integer', 'min:' . (time() + 60), 'max:' . (time() + 315360000)]
        ];
    }

    public function messages()
    {
        return [
            'plan_id.required' => '订阅不能为空',
            'email.required' => '邮箱不能为空',
            'total_amount.required' => '支付金额不能为空',
            'period.required' => '订阅周期不能为空',
            'period.in' => '订阅周期格式有误'
        ];
    }
}

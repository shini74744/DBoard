<?php

namespace Tests\Unit\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\MultiSubscriptionService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_plan_can_be_added_twice_without_overwriting_existing_package(): void
    {
        $plan = $this->plan();
        $root = $this->account($plan);
        $oldExpiry = $root->expired_at;
        $order = OrderService::createFromRequest($root, $plan, Plan::PERIOD_MONTHLY, null, 'add');
        $this->assertSame(Order::TYPE_ADDITIONAL, $order->type);
        $order->update(['status' => Order::STATUS_PROCESSING]);
        (new OrderService($order))->open();

        $child = User::where('parent_id', $root->id)->sole();
        $this->assertSame($plan->id, $child->plan_id);
        $this->assertNotSame($root->uuid, $child->uuid);
        $this->assertNotSame($root->token, $child->token);
        $this->assertSame($oldExpiry, $root->fresh()->expired_at);
        $this->assertSame($child->id, $order->fresh()->subscription_user_id);
        $this->assertCount(2, MultiSubscriptionService::summary($root));

        (new OrderService($order))->open();
        $this->assertSame(1, User::where('parent_id', $root->id)->count());
    }

    public function test_new_package_does_not_replace_expired_primary_when_another_package_is_active(): void
    {
        $plan = $this->plan();
        $root = $this->account($plan);
        $root->update(['expired_at' => time() - 60]);
        $existing = MultiSubscriptionService::createPackage($root);
        $existing->update([
            'plan_id' => $plan->id, 'group_id' => $plan->group_id,
            'transfer_enable' => 100 * 1073741824, 'expired_at' => time() + 86400,
        ]);
        $order = OrderService::createFromRequest($root, $plan, Plan::PERIOD_MONTHLY, null, 'add');
        $order->update(['status' => Order::STATUS_PROCESSING]);
        (new OrderService($order))->open();
        $this->assertSame(2, User::where('parent_id', $root->id)->count());
        $this->assertNotSame($existing->id, $order->fresh()->subscription_user_id);
        $this->assertLessThan(time(), $root->fresh()->expired_at);
    }

    public function test_admin_can_assign_new_package_with_current_period_format(): void
    {
        $plan = $this->plan();
        $admin = $this->account($plan);
        $admin->update(['is_admin' => true]);
        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $response = $this->postJson('/api/v2/00000000/order/assign', [
            'email' => $admin->email,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'total_amount' => 0,
            'subscription_action' => 'add',
        ]);
        $response->assertOk();
        $tradeNo = $response->json('data');
        $order = Order::where('trade_no', $tradeNo)->sole();
        $this->assertSame(Plan::PERIOD_MONTHLY, $order->period);
        $order->update(['status' => Order::STATUS_PROCESSING]);
        (new OrderService($order))->open();
        $this->assertSame(1, User::where('parent_id', $admin->id)->count());
    }

    public function test_admin_can_set_independent_duration_for_an_added_package(): void
    {
        $plan = $this->plan();
        $admin = $this->account($plan);
        $admin->update(['is_admin' => true]);
        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $tradeNo = $this->postJson('/api/v2/00000000/order/assign', [
            'email' => $admin->email, 'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY, 'total_amount' => 0,
            'subscription_action' => 'add', 'custom_duration_days' => 7,
        ])->assertOk()->json('data');
        $order = Order::where('trade_no', $tradeNo)->sole();
        $this->assertSame(7, $order->custom_duration_days);
        $before = time();
        $order->update(['status' => Order::STATUS_PROCESSING]);
        (new OrderService($order))->open();
        $child = User::where('parent_id', $admin->id)->sole();
        $this->assertEqualsWithDelta($before + 7 * 86400, $child->expired_at, 3);
        $this->assertSame($admin->id, $admin->fresh()->id);
    }

    public function test_admin_can_set_exact_expiry_for_first_package(): void
    {
        $plan = $this->plan();
        $admin = $this->account($plan);
        $admin->update(['is_admin' => true, 'plan_id' => null, 'group_id' => null, 'expired_at' => 0]);
        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $expiry = time() + 9 * 86400;
        $tradeNo = $this->postJson('/api/v2/00000000/order/assign', [
            'email' => $admin->email, 'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY, 'total_amount' => 0,
            'subscription_action' => 'add', 'custom_expired_at' => $expiry,
        ])->assertOk()->json('data');
        $order = Order::where('trade_no', $tradeNo)->sole();
        $order->update(['status' => Order::STATUS_PROCESSING]);
        (new OrderService($order))->open();
        $this->assertSame($expiry, $admin->fresh()->expired_at);
        $this->assertSame($admin->id, $order->fresh()->subscription_user_id);
    }

    public function test_admin_rejects_invalid_custom_expiry(): void
    {
        $plan = $this->plan();
        $admin = $this->account($plan);
        $admin->update(['is_admin' => true]);
        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $base = [
            'email' => $admin->email, 'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY, 'total_amount' => 0,
            'subscription_action' => 'add',
        ];
        $this->postJson('/api/v2/00000000/order/assign', $base + ['custom_duration_days' => 0])->assertUnprocessable();
        $this->postJson('/api/v2/00000000/order/assign', $base + ['custom_expired_at' => time() - 10])->assertUnprocessable();
        $this->assertSame(0, Order::count());
    }

    public function test_renewal_targets_only_the_chosen_package(): void
    {
        $plan = $this->plan();
        $root = $this->account($plan);
        $child = MultiSubscriptionService::createPackage($root);
        $child->update([
            'plan_id' => $plan->id, 'group_id' => $plan->group_id,
            'transfer_enable' => $plan->transfer_enable * 1073741824,
            'expired_at' => time() + 86400,
        ]);
        $beforeChild = $child->fresh()->expired_at;
        $beforeRoot = $root->expired_at;
        $order = OrderService::createFromRequest($root, $plan, Plan::PERIOD_MONTHLY, null, 'renew', $child->id);
        $order->update(['status' => Order::STATUS_PROCESSING]);
        (new OrderService($order))->open();

        $this->assertGreaterThan($beforeChild, $child->fresh()->expired_at);
        $this->assertSame($beforeRoot, $root->fresh()->expired_at);
        $this->assertSame($child->id, $order->fresh()->subscription_user_id);
    }

    public function test_two_pending_additional_orders_open_as_two_independent_packages(): void
    {
        $plan = $this->plan();
        $root = $this->account($plan);
        $first = OrderService::createFromRequest($root, $plan, Plan::PERIOD_MONTHLY, null, 'add');
        $second = OrderService::createFromRequest($root, $plan, Plan::PERIOD_MONTHLY, null, 'add');
        $this->assertNotSame($first->id, $second->id);
        foreach ([$first, $second] as $order) {
            $order->update(['status' => Order::STATUS_PROCESSING]);
            (new OrderService($order))->open();
        }
        $this->assertSame(2, User::where('parent_id', $root->id)->count());
        $this->assertCount(3, MultiSubscriptionService::summary($root));
        $this->assertSame(300 * 1073741824, MultiSubscriptionService::mergedHeaderUser($root)->transfer_enable);
    }

    public function test_dashboard_shows_active_additional_package_when_primary_expired(): void
    {
        $plan = $this->plan();
        $root = $this->account($plan);
        $root->update(['expired_at' => time() - 60]);
        $child = MultiSubscriptionService::createPackage($root);
        $child->update([
            'plan_id' => $plan->id, 'group_id' => $plan->group_id,
            'transfer_enable' => 100 * 1073741824,
            'expired_at' => time() + 86400,
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($root);
        $response = $this->getJson('/api/v1/user/getSubscribe');
        $response->assertOk();
        $this->assertSame($plan->id, $response->json('data.plan_id'));
        $this->assertSame(100 * 1073741824, $response->json('data.transfer_enable'));
        $this->assertCount(2, $response->json('data.subscriptions'));
    }

    public function test_gift_card_traffic_uses_valid_additional_package_when_primary_expired(): void
    {
        $plan = $this->plan();
        $root = $this->account($plan);
        $root->update(['expired_at' => time() - 60]);
        $child = MultiSubscriptionService::createPackage($root);
        $child->update([
            'plan_id' => $plan->id, 'group_id' => $plan->group_id,
            'transfer_enable' => 100 * 1073741824,
            'expired_at' => time() + 86400,
        ]);
        $template = \App\Models\GiftCardTemplate::create([
            'name' => 'Traffic Gift', 'description' => 'Gift',
            'type' => \App\Models\GiftCardTemplate::TYPE_GENERAL,
            'status' => 1, 'rewards' => ['transfer_enable' => 10 * 1073741824],
            'admin_id' => 1,
        ]);
        \App\Models\GiftCardCode::create([
            'template_id' => $template->id, 'code' => 'MULTIGIFT01',
            'status' => \App\Models\GiftCardCode::STATUS_UNUSED,
            'usage_count' => 0, 'max_usage' => 1,
        ]);
        (new \App\Services\GiftCardService('MULTIGIFT01'))->setUser($root)->validate()->redeem();
        $this->assertSame(110 * 1073741824, $child->fresh()->transfer_enable);
        $this->assertSame(100 * 1073741824, $root->fresh()->transfer_enable);
        $breakdown = \App\Services\TrafficQuotaBreakdown::forUser($child->fresh());
        $this->assertSame(10 * 1073741824, $breakdown['gift_card_bonus_bytes']);
    }

    public function test_telegram_gift_and_expiry_target_the_active_package(): void
    {
        $plan = $this->plan();
        $root = $this->account($plan);
        $root->update(['expired_at' => time() - 60, 'telegram_id' => 123456]);
        $child = MultiSubscriptionService::createPackage($root);
        $child->update([
            'plan_id' => $plan->id, 'group_id' => $plan->group_id,
            'transfer_enable' => 100 * 1073741824,
            'expired_at' => time() + 86400,
        ]);
        $grant = \App\Services\TelegramBindingRewardService::grant(
            $root->id, 5, 'timed', 'manual', 'manual:multi-test', null, 'Test gift', 30
        );
        $this->assertTrue($grant['awarded']);
        $this->assertSame(105 * 1073741824, $child->fresh()->transfer_enable);
        $record = \Illuminate\Support\Facades\DB::table('v2_telegram_traffic_grant')->where('reward_key', 'manual:multi-test')->first();
        $this->assertSame($child->id, (int) $record->user_id);
        $this->assertSame($root->id, (int) $record->account_user_id);
        \Illuminate\Support\Facades\DB::table('v2_telegram_traffic_grant')->where('id', $record->id)->update(['expires_at' => time() - 1]);
        $this->assertSame(1, \App\Services\TelegramBindingRewardService::expireDue());
        $this->assertSame(100 * 1073741824, $child->fresh()->transfer_enable);
    }

    public function test_daily_traffic_includes_all_owned_packages_and_can_filter_one(): void
    {
        $plan = $this->plan();
        $root = $this->account($plan);
        $child = MultiSubscriptionService::createPackage($root);
        $child->update(['plan_id' => $plan->id, 'group_id' => $plan->group_id]);
        $at = now()->startOfDay()->timestamp;
        foreach ([[$root->id, 1073741824], [$child->id, 2 * 1073741824]] as [$id, $bytes]) {
            \App\Models\StatUser::create([
                'user_id' => $id, 'server_rate' => 1, 'record_type' => 'd', 'record_at' => $at,
                'u' => 0, 'd' => $bytes,
            ]);
        }
        \Laravel\Sanctum\Sanctum::actingAs($root);
        $this->assertSame(3 * 1073741824, $this->getJson('/api/v1/user/stat/getDailyTraffic?days=1')->json('data.0.total'));
        $this->assertSame(2 * 1073741824, $this->getJson('/api/v1/user/stat/getDailyTraffic?days=1&subscription_user_id=' . $child->id)->json('data.0.total'));
    }

    public function test_first_additional_purchase_uses_primary_slot_and_activates_pending_gift(): void
    {
        $plan = $this->plan();
        $root = $this->account($plan);
        $root->update(['plan_id' => null, 'group_id' => null, 'transfer_enable' => 0,
            'expired_at' => 0, 'telegram_id' => 123456]);
        $grant = \App\Services\TelegramBindingRewardService::grant(
            $root->id, 5, 'timed', 'manual', 'manual:first-package', null, 'Welcome', 30
        );
        $this->assertTrue($grant['pending_plan']);
        $order = OrderService::createFromRequest($root, $plan, Plan::PERIOD_MONTHLY, null, 'add');
        $order->update(['status' => Order::STATUS_PROCESSING]);
        (new OrderService($order))->open();
        $this->assertSame($root->id, $order->fresh()->subscription_user_id);
        $this->assertSame(0, User::where('parent_id', $root->id)->count());
        $this->assertSame(105 * 1073741824, $root->fresh()->transfer_enable);
    }

    public function test_account_route_matches_each_package_identity(): void
    {
        $root = $this->account($this->plan());
        $child = MultiSubscriptionService::createPackage($root);
        $rules = [[
            'match' => ['user_ids' => [$root->id], 'domain_suffixes' => ['example.com']],
            'action' => ['type' => 'route', 'target' => 'exit-b'],
        ]];
        $expanded = \App\Services\ServerService::expandAccountRouteRules($rules);
        $this->assertCount(1, $expanded);
        $this->assertSame([$root->id, $child->id], $expanded[0]['match']['user_ids']);
    }

    public function test_primary_package_card_has_its_own_link_while_legacy_account_link_stays_merged(): void
    {
        $plan = $this->plan();
        $root = $this->account($plan);
        $root->update(['subscription_link_mode' => 'merged']);
        $child = MultiSubscriptionService::createPackage($root);
        $child->update([
            'plan_id' => $plan->id, 'group_id' => $plan->group_id,
            'transfer_enable' => 100 * 1073741824, 'expired_at' => time() + 86400,
        ]);
        $cards = MultiSubscriptionService::summary($root);
        $this->assertCount(2, $cards);
        $this->assertNotSame($root->token, $root->fresh()->primary_package_token);
        $this->assertStringContainsString($root->fresh()->primary_package_token, $cards[0]['subscribe_url']);
        $this->assertStringContainsString($child->token, $cards[1]['subscribe_url']);
        $single = $this->get('/api/v1/client/subscribe?token=' . $root->fresh()->primary_package_token)->assertOk();
        $this->assertStringContainsString('total=' . (100 * 1073741824), $single->headers->get('subscription-userinfo'));
        $merged = $this->get('/api/v1/client/subscribe?token=' . $root->token)->assertOk();
        $this->assertStringContainsString('total=' . (200 * 1073741824), $merged->headers->get('subscription-userinfo'));
    }

    public function test_admin_can_remove_individual_packages_without_deleting_the_account(): void
    {
        $plan = $this->plan();
        $root = $this->account($plan);
        $root->update(['is_admin' => true]);
        $child = MultiSubscriptionService::createPackage($root);
        $child->update([
            'plan_id' => $plan->id, 'group_id' => $plan->group_id,
            'transfer_enable' => 100 * 1073741824,
            'expired_at' => time() + 86400,
        ]);
        $combination = \App\Models\SubscriptionCombination::create([
            'user_id' => $root->id, 'name' => 'Both', 'token' => bin2hex(random_bytes(24)),
            'package_ids' => [$root->id, $child->id], 'duplicate_node_mode' => 'all',
        ]);
        $oldChildToken = $child->token;
        $oldRootToken = $root->token;
        \Laravel\Sanctum\Sanctum::actingAs($root);
        $url = '/api/v2/00000000/user/subscription/remove';
        $this->postJson($url, ['user_id' => $root->id, 'subscription_user_id' => $child->id])->assertOk();
        $this->assertNull($child->fresh()->plan_id);
        $this->assertCount(1, MultiSubscriptionService::summary($root));
        $this->assertDatabaseMissing('v2_subscription_combination', ['id' => $combination->id]);
        $this->get('/api/v1/client/subscribe?token=' . $oldChildToken)->assertStatus(403);
        $this->postJson($url, ['user_id' => $root->id, 'subscription_user_id' => $root->id])->assertOk();
        $this->assertDatabaseHas('v2_user', ['id' => $root->id, 'email' => $root->email, 'plan_id' => null]);
        $this->assertCount(0, MultiSubscriptionService::activePackages($root->fresh()));
        $this->get('/api/v1/client/subscribe?token=' . $oldRootToken)->assertStatus(403);
    }

    private function plan(): Plan
    {
        return Plan::create([
            'name' => 'Test Plan', 'group_id' => 1, 'transfer_enable' => 100,
            'show' => 1, 'sell' => 1, 'renew' => 1, 'sort' => 0,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
            'created_at' => time(), 'updated_at' => time(),
        ]);
    }

    private function account(Plan $plan): User
    {
        return User::create([
            'email' => 'multi@example.invalid', 'password' => 'unused',
            'uuid' => '00000000-0000-0000-0000-000000000111',
            'token' => '11111111111111111111111111111111',
            'plan_id' => $plan->id, 'group_id' => $plan->group_id,
            'transfer_enable' => 100 * 1073741824, 'u' => 0, 'd' => 0,
            'expired_at' => time() + 30 * 86400,
            'balance' => 0, 'commission_balance' => 0,
            'created_at' => time(), 'updated_at' => time(),
        ]);
    }
}
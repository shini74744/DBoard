<?php

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Models\SubscriptionCombination;
use App\Models\User;
use App\Services\MultiSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionCombinationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_edit_and_delete_a_stable_combination(): void
    {
        [$account, $children] = $this->accountWithPackages();
        Sanctum::actingAs($account);
        $url = '/api/v1/user/subscriptions/combinations';
        $payload = [
            'name' => 'Daily',
            'package_ids' => json_encode([$account->id, $children[0]->id]),
            'duplicate_node_mode' => 'all',
        ];
        $created = $this->postJson($url . '/save', $payload)->assertOk()->json('data');
        $this->assertSame([$account->id, $children[0]->id], $created['package_ids']);
        $this->assertCount(1, $this->getJson($url)->assertOk()->json('data'));
        $savedToken = SubscriptionCombination::findOrFail($created['id'])->token;
        $payload['id'] = $created['id'];
        $payload['package_ids'] = json_encode([$children[0]->id, $children[1]->id]);
        $payload['duplicate_node_mode'] = 'first';
        $this->postJson($url . '/save', $payload)->assertOk();
        $this->assertSame($savedToken, SubscriptionCombination::findOrFail($created['id'])->token);
        $this->assertSame([$children[0]->id, $children[1]->id], SubscriptionCombination::findOrFail($created['id'])->package_ids);
        $other = User::create([
            'email' => 'other@example.invalid', 'password' => 'unused',
            'uuid' => '00000000-0000-0000-0000-000000000222',
            'token' => '22222222222222222222222222222222',
            'plan_id' => $account->plan_id,
            'group_id' => $account->group_id,
            'transfer_enable' => 100 * 1073741824,
            'expired_at' => time() + 86400,
        ]);
        $payload['package_ids'] = json_encode([$account->id, $other->id]);
        $this->postJson($url . '/save', $payload)->assertStatus(422);
        $this->assertSame([$children[0]->id, $children[1]->id], SubscriptionCombination::findOrFail($created['id'])->package_ids);
        $this->postJson($url . '/delete', ['id' => $created['id']])->assertOk();
        $this->assertDatabaseMissing('v2_subscription_combination', ['id' => $created['id']]);
        $this->get('/api/v1/client/subscribe?token=' . $savedToken)->assertStatus(403);
    }

    public function test_combination_filters_packages_and_revokes_on_security_reset(): void
    {
        [$account, $children] = $this->accountWithPackages();
        $selected = [$account->id, $children[1]->id];
        $item = SubscriptionCombination::create([
            'user_id' => $account->id, 'name' => 'Two packages',
            'token' => bin2hex(random_bytes(24)), 'package_ids' => $selected,
            'duplicate_node_mode' => 'all',
        ]);
        $this->assertSame($selected, MultiSubscriptionService::activePackages($account, $item->package_ids)->pluck('id')->all());
        $this->assertSame(200 * 1073741824, MultiSubscriptionService::mergedHeaderUser($account, $item->package_ids)->transfer_enable);
        $children[1]->update(['expired_at' => time() - 1]);
        $this->assertSame([$account->id], MultiSubscriptionService::activePackages($account, $item->package_ids)->pluck('id')->all());
        $this->assertSame(100 * 1073741824, MultiSubscriptionService::mergedHeaderUser($account, $item->package_ids)->transfer_enable);
        Sanctum::actingAs($account);
        $oldToken = $item->token;
        $this->getJson('/api/v1/user/resetSecurity')->assertOk();
        $this->assertNotSame($oldToken, $item->fresh()->token);
    }

    public function test_combination_link_uses_only_selected_packages(): void
    {
        [$account, $children] = $this->accountWithPackages();
        $item = SubscriptionCombination::create([
            'user_id' => $account->id, 'name' => 'Subset',
            'token' => bin2hex(random_bytes(24)),
            'package_ids' => [$account->id, $children[1]->id],
            'duplicate_node_mode' => 'all',
        ]);
        $response = $this->get('/api/v1/client/subscribe?token=' . $item->token);
        $response->assertOk();
        $this->assertStringContainsString('total=' . (200 * 1073741824), $response->headers->get('subscription-userinfo'));
        $children[1]->update(['expired_at' => time() - 1]);
        $response = $this->get('/api/v1/client/subscribe?token=' . $item->token);
        $response->assertOk();
        $this->assertStringContainsString('total=' . (100 * 1073741824), $response->headers->get('subscription-userinfo'));
        $account->update(['expired_at' => time() - 1]);
        $this->get('/api/v1/client/subscribe?token=' . $item->token)->assertStatus(403);
    }

    private function accountWithPackages(): array
    {
        $plan = Plan::create([
            'name' => 'Plan', 'group_id' => 1, 'transfer_enable' => 100,
            'show' => 1, 'sell' => 1, 'renew' => 1, 'sort' => 0,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $account = User::create([
            'email' => 'combination@example.invalid', 'password' => 'unused',
            'uuid' => '00000000-0000-0000-0000-000000000111',
            'token' => '11111111111111111111111111111111',
            'plan_id' => $plan->id, 'group_id' => $plan->group_id,
            'transfer_enable' => 100 * 1073741824, 'u' => 0, 'd' => 0,
            'expired_at' => time() + 30 * 86400,
            'balance' => 0, 'commission_balance' => 0,
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $children = [];
        for ($i = 0; $i < 2; $i++) {
            $child = MultiSubscriptionService::createPackage($account);
            $child->update([
                'plan_id' => $plan->id, 'group_id' => $plan->group_id,
                'transfer_enable' => 100 * 1073741824,
                'expired_at' => time() + 30 * 86400,
            ]);
            $children[] = $child;
        }
        return [$account, $children];
    }
}

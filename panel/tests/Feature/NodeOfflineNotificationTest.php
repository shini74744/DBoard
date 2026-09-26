<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramJob;
use App\Models\{Server, User};
use App\Services\UserTelegramNotifier;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Bus, Cache, DB, Http};
use Tests\TestCase;

class NodeOfflineNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app->detectEnvironment(fn () => 'testing');
        $app['config']->set('app.env', 'testing');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('cache.stores.redis', ['driver' => 'array']);
        $app['config']->set('cache.default', 'array');
        DB::purge('sqlite');
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting([
            'telegram_bot_enable' => 1, 'telegram_bot_token' => 'test-token',
            'telegram_user_notify_node_offline' => 1,
            'telegram_node_offline_reminders' => 3,
            'telegram_node_offline_interval' => 60,
        ]);
        Bus::fake([SendTelegramJob::class]);
        Http::preventStrayRequests();
    }

    private function account(array $attributes = []): User
    {
        $key = bin2hex(random_bytes(8));
        return User::create(array_replace([
            'email' => $key . '@example.invalid', 'password' => 'unused',
            'uuid' => $key, 'token' => $key, 'plan_id' => 1, 'group_id' => 7,
            'expired_at' => time() + 86400, 'transfer_enable' => 1024,
            'telegram_id' => random_int(100000, 999999),
            'remind_telegram' => true, 'banned' => false,
        ], $attributes));
    }

    private function node(array $attributes = []): Server
    {
        return new Server(array_replace([
            'name' => 'Offline fixture', 'type' => 'vless',
            'host' => 'node.example.invalid', 'port' => '443', 'server_port' => 443,
            'rate' => 1, 'group_ids' => ['7'], 'enabled' => true, 'show' => true,
        ], $attributes));
    }

    private function recipients(?string $contains = null): array
    {
        return Bus::dispatched(SendTelegramJob::class)
            ->filter(function ($job) use ($contains) {
                $text = (new \ReflectionProperty($job, 'text'))->getValue($job);
                return $contains === null || str_contains($text, $contains);
            })->map(fn ($job) => (int) (new \ReflectionProperty($job, 'telegramId'))->getValue($job))
            ->sort()->values()->all();
    }

    public function test_only_matching_package_owners_receive_the_alert(): void
    {
        $matching = $this->account(['telegram_id' => 1001]);
        $this->account(['telegram_id' => 1002, 'group_id' => 8]);
        // A different catalog plan can grant the same node permission group.
        $shared = $this->account(['telegram_id' => 1003, 'plan_id' => 2]);
        $this->assertSame(2, UserTelegramNotifier::nodeOffline($this->node(), 'offline'));
        $this->assertSame([1001, 1003], $this->recipients());
    }

    public function test_child_packages_notify_the_owner_once_even_when_multiple_packages_match(): void
    {
        $owner = $this->account(['telegram_id' => 1101, 'group_id' => 8, 'expired_at' => time() - 10]);
        $this->account(['parent_id' => $owner->id, 'telegram_id' => 1198, 'remind_telegram' => false]);
        $this->account(['parent_id' => $owner->id, 'telegram_id' => 1199]);
        $this->assertSame(1, UserTelegramNotifier::nodeOffline($this->node(), 'offline'));
        $this->assertSame([1101], $this->recipients());
        $owner->update(['group_id' => 7, 'expired_at' => time() + 86400]);
        Bus::fake([SendTelegramJob::class]);
        $this->assertSame(1, UserTelegramNotifier::nodeOffline($this->node(), 'offline'));
        $this->assertSame([1101], $this->recipients());
    }

    public function test_expired_missing_and_banned_packages_do_not_grant_notification_access(): void
    {
        $this->account(['expired_at' => time() - 1]);
        $this->account(['plan_id' => null]);
        $this->account(['banned' => true]);
        $owner = $this->account(['group_id' => 8]);
        $this->account(['parent_id' => $owner->id, 'expired_at' => time() - 1]);
        $this->account(['parent_id' => $owner->id, 'banned' => true]);
        $bannedOwner = $this->account(['banned' => true, 'group_id' => 8]);
        $this->account(['parent_id' => $bannedOwner->id]);
        $this->assertSame(0, UserTelegramNotifier::nodeOffline($this->node(), 'offline'));
        Bus::assertNothingDispatched();
    }

    public function test_non_expiring_packages_and_second_permission_group_are_supported(): void
    {
        $this->account(['telegram_id' => 1201, 'group_id' => 9, 'expired_at' => null]);
        $this->assertSame(1, UserTelegramNotifier::nodeOffline($this->node(['group_ids' => [7, '9']]), 'offline'));
        $this->assertSame([1201], $this->recipients());
    }

    public function test_owner_opt_out_and_unbound_accounts_are_excluded(): void
    {
        foreach ([
            ['remind_telegram' => false], ['telegram_id' => null], ['telegram_id' => 0],
        ] as $attributes) {
            $owner = $this->account($attributes + ['group_id' => 8]);
            $this->account(['parent_id' => $owner->id, 'remind_telegram' => true]);
        }
        $this->assertSame(0, UserTelegramNotifier::nodeOffline($this->node(), 'offline'));
        Bus::assertNothingDispatched();
    }

    public function test_hidden_disabled_and_groupless_nodes_never_broadcast(): void
    {
        $this->account();
        foreach ([['show' => false], ['enabled' => false], ['group_ids' => []]] as $attributes) {
            $this->assertSame(0, UserTelegramNotifier::nodeOffline($this->node($attributes), 'offline'));
        }
        admin_setting(['telegram_user_notify_node_offline' => 0]);
        $this->assertSame(0, UserTelegramNotifier::nodeOffline($this->node(), 'offline'));
        Bus::assertNothingDispatched();
    }

    public function test_offline_command_filters_users_preserves_admin_alert_and_throttles(): void
    {
        $this->account(['telegram_id' => 1301]);
        $this->account(['telegram_id' => 1302, 'group_id' => 8]);
        $this->account(['telegram_id' => 1303, 'group_id' => 8, 'is_admin' => true]);
        $server = $this->node();
        $server->save();
        $heartbeat = CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', $server->id);
        Cache::put($heartbeat, time() - 3600);
        $this->artisan('check:server')->assertSuccessful();
        $this->assertSame([1301], $this->recipients('节点暂时离线'));
        $this->assertSame([1303], $this->recipients('节点掉线通知'));
        $this->artisan('check:server')->assertSuccessful();
        Bus::assertDispatched(SendTelegramJob::class, 2);
        Cache::put($heartbeat, time());
        $this->artisan('check:server')->assertSuccessful();
        $this->assertNull(Cache::get('dboard:node:offline:' . $server->id));
        Cache::put($heartbeat, time() - 3600);
        $this->artisan('check:server')->assertSuccessful();
        Bus::assertDispatched(SendTelegramJob::class, 4);
        Http::assertNothingSent();
    }

    public function test_unrelated_broadcasts_keep_their_existing_audience(): void
    {
        $this->account(['telegram_id' => 1401]);
        $this->account(['telegram_id' => 1402, 'group_id' => 8]);
        admin_setting(['telegram_user_notify_announcement' => 1]);
        $this->assertSame(2, UserTelegramNotifier::broadcast('telegram_user_notify_announcement', 'announcement'));
        $this->assertSame([1401, 1402], $this->recipients());
    }
}

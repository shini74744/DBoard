<?php
// Isolated alert checks. Uses in-memory SQLite and a mocked Redis IP window.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\SendTelegramJob;
use App\Models\User;
use App\Services\TelegramUserAlertService as Alerts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

$app['config']->set('database.default', 'sqlite');
$app['config']->set('database.connections.sqlite.database', ':memory:');
$app['config']->set('cache.stores.redis', ['driver' => 'array']);
$app['config']->set('cache.default', 'array');
$app['cache']->forgetDriver('redis');
$app['cache']->forgetDriver('array');
$app->forgetInstance(App\Support\Setting::class);
DB::purge('sqlite');
Model::unsetEventDispatcher();
Schema::create('v2_settings', function (Blueprint $table) {
    $table->id(); $table->string('name')->unique(); $table->text('value')->nullable();
    $table->timestamps();
});
Schema::create('v2_user', function (Blueprint $table) {
    $table->id(); $table->string('email'); $table->string('token');
    $table->bigInteger('telegram_id')->nullable();
    $table->boolean('remind_telegram')->default(true);
    $table->boolean('remind_traffic')->default(true);
    $table->boolean('banned')->default(false);
    $table->unsignedBigInteger('plan_id')->nullable();
    $table->unsignedBigInteger('expired_at')->nullable();
    $table->unsignedBigInteger('last_reset_at')->nullable();
    $table->unsignedInteger('device_limit')->nullable();
    $table->bigInteger('transfer_enable')->default(0);
    $table->bigInteger('u')->default(0); $table->bigInteger('d')->default(0);
    $table->timestamps();
});
DB::table('v2_user')->insert([
    'id' => 1, 'email' => 'alerts@example.invalid', 'token' => 'test-subscription-token',
    'telegram_id' => 123456,
    'plan_id' => 9, 'device_limit' => 2,
    'expired_at' => time() + 86400, 'transfer_enable' => 100 * 1073741824,
    'u' => 79 * 1073741824, 'd' => 0,
    'created_at' => time(), 'updated_at' => time(),
]);
admin_setting([
    'telegram_bot_enable' => 1, 'telegram_bot_token' => 'test-token',
    'telegram_user_notify_traffic_low' => 1,
    'telegram_user_notify_device_over_limit' => 1,
    'telegram_user_notify_subscription_sharing' => 1,
    'telegram_traffic_warn_percent' => 80,
    'telegram_subscribe_ip_limit' => 2,
]);
Bus::fake([SendTelegramJob::class]);
function verifyAlert(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException($label);
    echo "PASS {$label}\n";
}
$user = User::find(1);
verifyAlert(!Alerts::checkTraffic($user), 'traffic below threshold is quiet');
$user->u = 80 * 1073741824;
verifyAlert(Alerts::checkTraffic($user), 'traffic at threshold queues TG');
verifyAlert(!Alerts::checkTraffic($user), 'traffic warning is throttled');
$user->remind_traffic = false;
$user->last_reset_at = time();
verifyAlert(!Alerts::checkTraffic($user), 'traffic opt-out is respected');
$user->remind_traffic = true;
$user->u = 100 * 1073741824;
verifyAlert(!Alerts::checkTraffic($user), 'exhausted traffic is not called low');
verifyAlert(!Alerts::checkDeviceCount($user, 2), 'devices at limit are quiet');
verifyAlert(Alerts::checkDeviceCount($user, 3), 'devices over limit queue TG');
verifyAlert(!Alerts::checkDeviceCount($user, 4), 'device warning is throttled');

$seen = [];
Redis::shouldReceive('zadd')->andReturnUsing(function ($key, $score, $member) use (&$seen) {
    $seen[$key][$member] = $score;
});
Redis::shouldReceive('zremrangebyscore')->andReturnUsing(function ($key, $minimum, $maximum) use (&$seen) {
    foreach ($seen[$key] ?? [] as $member => $score) {
        if ($score <= (int) $maximum) unset($seen[$key][$member]);
    }
});
Redis::shouldReceive('expire')->andReturn(true);
Redis::shouldReceive('zcard')->andReturnUsing(function ($key) use (&$seen) {
    return count($seen[$key] ?? []);
});
verifyAlert(Alerts::recordSubscriptionAccess($user, '192.168.1.2') === 0,
    'private proxy IP is ignored');
verifyAlert(Alerts::recordSubscriptionAccess($user, '1.1.1.1') === 1,
    'first public IP is counted');
verifyAlert(Alerts::recordSubscriptionAccess($user, '1.1.1.1') === 1,
    'same public IP is deduplicated');
verifyAlert(Alerts::recordSubscriptionAccess($user, '8.8.8.8') === 2,
    'two public IPs stay below limit');
verifyAlert(Alerts::recordSubscriptionAccess($user, '9.9.9.9') === 3,
    'third public IP exceeds the limit');
verifyAlert(Alerts::recordSubscriptionAccess($user, '4.2.2.2') === 4,
    'later public IP is counted without a repeated warning');
Bus::assertDispatched(SendTelegramJob::class, 3);
$window = 'dboard:subscribe:public-ips:1:'
    . substr(hash_hmac('sha256', $user->token, (string) config('app.key')), 0, 16);
verifyAlert(count($seen[$window]) === 4 && !array_key_exists('1.1.1.1', $seen[$window]),
    'raw subscription IPs are not stored');
$user->token = 'rotated-subscription-token';
verifyAlert(Alerts::recordSubscriptionAccess($user, '1.1.1.1') === 1,
    'reset subscription link starts a fresh IP window');
Alerts::recordSubscriptionAccess($user, '8.8.8.8');
Alerts::recordSubscriptionAccess($user, '9.9.9.9');
verifyAlert(Bus::dispatched(SendTelegramJob::class)->count() === 4,
    'new subscription link can warn independently');
echo "TOTAL_PASS=17\n";

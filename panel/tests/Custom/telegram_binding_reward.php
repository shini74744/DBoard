<?php
// Isolated regression checks. This script uses only an in-memory SQLite database.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\SendTelegramJob;
use App\Models\User;
use App\Services\TelegramBindingRewardService as Rewards;
use App\Services\TrafficResetService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$app['config']->set('database.default', 'sqlite');
$app['config']->set('database.connections.sqlite.database', ':memory:');
$app['config']->set('cache.stores.redis', ['driver' => 'array']);
DB::purge('sqlite');
Model::unsetEventDispatcher();

Schema::create('v2_settings', function (Blueprint $table) {
    $table->id(); $table->string('name')->unique(); $table->text('value')->nullable(); $table->timestamps();
});
Schema::create('v2_user', function (Blueprint $table) {
    $table->id(); $table->string('email'); $table->unsignedBigInteger('parent_id')->nullable(); $table->bigInteger('telegram_id')->nullable();
    $table->bigInteger('transfer_enable')->default(0); $table->bigInteger('u')->default(0);
    $table->bigInteger('d')->default(0); $table->unsignedBigInteger('plan_id')->nullable();
    $table->unsignedBigInteger('group_id')->nullable(); $table->unsignedBigInteger('invite_user_id')->nullable();
    $table->unsignedInteger('speed_limit')->nullable(); $table->unsignedInteger('device_limit')->nullable();
    $table->boolean('banned')->default(false);
    $table->unsignedInteger('expired_at')->nullable(); $table->unsignedInteger('last_reset_at')->nullable();
    $table->unsignedInteger('next_reset_at')->nullable(); $table->unsignedInteger('reset_count')->default(0);
    $table->string('token')->default('test-token'); $table->timestamps();
});
Schema::create('v2_plan', function (Blueprint $table) {
    $table->id(); $table->string('name'); $table->integer('reset_traffic_method')->default(2);
    $table->integer('transfer_enable'); $table->unsignedBigInteger('group_id')->nullable();
    $table->unsignedInteger('speed_limit')->nullable(); $table->unsignedInteger('device_limit')->nullable();
    $table->timestamps();
});
Schema::create('v2_traffic_reset_logs', function (Blueprint $table) {
    $table->id(); $table->unsignedBigInteger('user_id'); $table->string('reset_type');
    $table->timestamp('reset_time'); $table->bigInteger('old_upload'); $table->bigInteger('old_download');
    $table->bigInteger('old_total'); $table->bigInteger('new_upload'); $table->bigInteger('new_download');
    $table->bigInteger('new_total'); $table->string('trigger_source');
    $table->text('metadata')->nullable(); $table->timestamps();
});
Schema::create('v2_gift_card_template', function (Blueprint $table) {
    $table->id(); $table->string('name');
});
Schema::create('v2_gift_card_usage', function (Blueprint $table) {
    $table->id(); $table->unsignedBigInteger('template_id');
    $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('subscription_user_id')->nullable(); $table->unsignedBigInteger('invite_user_id')->nullable();
    $table->json('rewards_given'); $table->json('invite_rewards')->nullable();
    $table->text('notes')->nullable(); $table->unsignedInteger('created_at');
});
(require dirname(__DIR__, 2) . '/database/migrations/2026_09_22_000008_add_telegram_binding_rewards.php')->up();
(require dirname(__DIR__, 2) . '/database/migrations/2026_09_22_000009_add_telegram_traffic_grant_reason.php')->up();
(require dirname(__DIR__, 2) . '/database/migrations/2026_09_22_000010_add_timed_telegram_traffic_grants.php')->up();
Schema::table('v2_telegram_traffic_grant', function (Blueprint $table) { $table->unsignedBigInteger('account_user_id')->nullable(); });
DB::table('v2_plan')->insert([
    'id' => 10, 'name' => 'test plan', 'transfer_enable' => 100,
    'reset_traffic_method' => 2, 'group_id' => 1,
]);

function checkReward(bool $condition, string $name): void {
    if (!$condition) throw new RuntimeException($name);
    echo "PASS {$name}\n";
}
$gb = 1073741824;
DB::table('v2_user')->insert([
    'id' => 1, 'email' => 'test@example.invalid', 'telegram_id' => 123456,
    'transfer_enable' => 100 * $gb, 'u' => 5 * $gb, 'd' => 10 * $gb,
    'plan_id' => 10, 'expired_at' => time() + 86400,
    'created_at' => time() - 86400, 'updated_at' => time(),
]);
admin_setting(['telegram_bot_enable' => 1, 'telegram_bot_token' => 'test-token']);
Bus::fake([SendTelegramJob::class]);

$cycle = Rewards::grant(1, 5, 'cycle', 'manual', 'manual:test-cycle', 99);
checkReward($cycle['awarded'] && $cycle['notification_queued'], 'cycle grant queues notification');
checkReward((int) User::find(1)->transfer_enable === 105 * $gb, 'cycle grant increases quota');
checkReward((int) User::find(1)->telegram_bonus_cycle === 5 * $gb, 'cycle bonus tracked');
$repeat = Rewards::grant(1, 5, 'cycle', 'manual', 'manual:test-cycle', 99);
checkReward(!$repeat['awarded'] && (int) User::find(1)->transfer_enable === 105 * $gb,
    'retry does not award twice');
$permanent = Rewards::grant(1, 3, 'permanent', 'manual', 'manual:test-permanent', 99);
checkReward($permanent['awarded'] && (int) User::find(1)->telegram_bonus_permanent === 3 * $gb,
    'permanent bonus tracked');
checkReward((new TrafficResetService())->performReset(User::find(1), 'cron'), 'traffic reset succeeds');
$user = User::find(1);
checkReward((int) $user->transfer_enable === 103 * $gb, 'cycle bonus expires at reset');
checkReward((int) $user->telegram_bonus_cycle === 0 && (int) $user->telegram_bonus_permanent === 3 * $gb,
    'permanent bonus survives reset');
checkReward((int) $user->u === 0 && (int) $user->d === 0, 'usage resets');
admin_setting(['telegram_bind_reward' => json_encode([
    'new_gb' => 2, 'new_days' => 30,
    'existing_gb' => 1, 'existing_days' => 7,
    'start_at' => time() - 100,
])]);
DB::table('v2_user')->insert([
    'id' => 2, 'email' => 'new@example.invalid', 'telegram_id' => 654321,
    'transfer_enable' => 0, 'created_at' => time(), 'updated_at' => time(),
]);
checkReward(Rewards::rewardOnBind(User::find(1))['awarded'], 'existing user binding gets existing reward');
$newBinding = Rewards::rewardOnBind(User::find(2));
checkReward($newBinding['awarded'] && $newBinding['pending_plan'], 'new user gift waits for a plan');
checkReward((int) User::find(2)->telegram_bonus_timed === 2 * $gb
    && $newBinding['duration_days'] === 30, 'new user receives thirty-day gift');
checkReward(Rewards::rewardOnBind(User::find(2)) === null, 'binding reward is once per account');
Rewards::grant(2, 3, 'cycle', 'manual', 'manual:before-plan-change', 99);
checkReward((int) User::find(2)->transfer_enable === 0
    && (int) User::find(2)->telegram_bonus_cycle === 3 * $gb,
    'no-plan gift is recorded without unlocking traffic');
$plan = new App\Models\Plan(['transfer_enable' => 100, 'group_id' => 1]);
$plan->id = 10;
$orderService = new App\Services\OrderService(new App\Models\Order());
$orderService->user = User::find(2);
(new ReflectionMethod($orderService, 'buyByOneTime'))->invoke($orderService, $plan);
$orderService->user->save();
checkReward((int) User::find(2)->transfer_enable === 105 * $gb
    && (int) User::find(2)->telegram_bonus_cycle === 3 * $gb
    && (int) User::find(2)->telegram_bonus_timed === 2 * $gb,
    'first plan activates pending cycle and timed gifts');
$orderService->user = User::find(2);
(new ReflectionMethod($orderService, 'buyByOneTime'))->invoke($orderService, $plan);
$orderService->user->save();
checkReward((int) User::find(2)->transfer_enable === 102 * $gb
    && (int) User::find(2)->telegram_bonus_cycle === 0
    && (int) User::find(2)->telegram_bonus_timed === 2 * $gb,
    'later plan purchase clears cycle gift but retains timed gift');
$breakdown = App\Services\TrafficQuotaBreakdown::forUser(User::find(2));
checkReward($breakdown['base_bytes'] === 100 * $gb
    && $breakdown['timed_bonus_bytes'] === 2 * $gb
    && $breakdown['total_bytes'] === 102 * $gb,
    'traffic detail reconciles plan and gift');
DB::table('v2_gift_card_template')->insert(['id' => 1, 'name' => '夏日礼品卡']);
DB::table('v2_gift_card_usage')->insert([
    'id' => 1, 'template_id' => 1, 'user_id' => 2, 'invite_user_id' => null,
    'rewards_given' => json_encode(['transfer_enable' => 5 * $gb]),
    'invite_rewards' => null, 'notes' => '活动赠送', 'created_at' => time(),
]);
DB::table('v2_user')->where('id', 2)->increment('transfer_enable', 5 * $gb);
$breakdown = App\Services\TrafficQuotaBreakdown::forUser(User::find(2));
$giftEntries = array_values(array_filter($breakdown['entries'], fn ($entry) => $entry['bucket'] === 'gift_card'));
checkReward($breakdown['gift_card_bonus_bytes'] === 5 * $gb
    && str_contains($giftEntries[0]['reason'], '夏日礼品卡'),
    'existing gift card traffic appears with its reason');
DB::table('v2_gift_card_usage')->insert([
    'id' => 2, 'template_id' => 1, 'user_id' => 1, 'invite_user_id' => 2,
    'rewards_given' => '{}',
    'invite_rewards' => json_encode(['transfer_enable' => 2 * $gb]),
    'notes' => null, 'created_at' => time(),
]);
DB::table('v2_user')->where('id', 2)->increment('transfer_enable', 2 * $gb);
$breakdown = App\Services\TrafficQuotaBreakdown::forUser(User::find(2));
checkReward($breakdown['gift_card_bonus_bytes'] === 7 * $gb
    && count(array_filter($breakdown['entries'], fn ($entry) => $entry['bucket'] === 'gift_card')) === 2,
    'multiple gift card and invitation rewards reconcile');
Rewards::grant(2, 1, 'cycle', 'manual', 'manual:reason-one', 99, '客服补偿');
Rewards::grant(2, 2, 'cycle', 'manual', 'manual:reason-two', 99, '节日活动');
$breakdown = App\Services\TrafficQuotaBreakdown::forUser(User::find(2));
$cycleReasons = array_column(array_filter($breakdown['entries'],
    fn ($entry) => $entry['bucket'] === 'cycle'), 'reason');
checkReward($breakdown['cycle_bonus_bytes'] === 3 * $gb
    && in_array('客服补偿', $cycleReasons, true) && in_array('节日活动', $cycleReasons, true),
    'multiple manual gifts keep separate reasons');
$expiredAt = time() - 86400;
DB::table('v2_telegram_traffic_grant')->where('user_id', 2)
    ->update(['created_at' => $expiredAt - 86400]);
DB::table('v2_user')->where('id', 2)->update(['expired_at' => $expiredAt]);
$orderService->user = User::find(2);
(new ReflectionMethod($orderService, 'buyByOneTime'))->invoke($orderService, $plan);
$orderService->user->save();
checkReward((int) User::find(2)->transfer_enable === 100 * $gb
    && (int) User::find(2)->telegram_bonus_permanent === 0,
    'new purchase after a lapse discards old continuing gift');
checkReward(App\Services\TrafficQuotaBreakdown::forUser(User::find(2))['gift_card_bonus_bytes'] === 0,
    'old gift cards remain in history but are not counted twice');
DB::table('v2_user')->where('id', 2)->update(['expired_at' => $expiredAt]);
$lapsedGift = Rewards::grant(2, 1, 'permanent', 'manual', 'manual:after-expiry', 99);
checkReward($lapsedGift['pending_plan'] && (int) User::find(2)->transfer_enable === 100 * $gb,
    'gift after expiry waits for an active plan');
$orderService->user = User::find(2);
(new ReflectionMethod($orderService, 'buyByOneTime'))->invoke($orderService, $plan);
$orderService->user->save();
checkReward((int) User::find(2)->transfer_enable === 101 * $gb
    && (int) User::find(2)->telegram_bonus_permanent === $gb,
    'new purchase keeps only gifts made after expiry');
$periodOrder = new App\Models\Order();
$periodOrder->type = App\Models\Order::TYPE_RENEWAL;
$periodOrder->period = App\Models\Plan::PERIOD_MONTHLY;
$periodOrder->plan_id = 10;
$periodService = new App\Services\OrderService($periodOrder);
$periodService->user = User::find(2);
$periodService->user->expired_at = time() + 86400;
(new ReflectionMethod($periodService, 'buyByPeriod'))->invoke($periodService, $periodOrder, $plan);
$periodService->user->save();
checkReward((int) User::find(2)->transfer_enable === 101 * $gb
    && (int) User::find(2)->telegram_bonus_permanent === $gb,
    'uninterrupted period renewal keeps continuing gift');
DB::table('v2_telegram_traffic_grant')->where('reward_key', 'manual:after-expiry')
    ->update(['created_at' => $expiredAt - 86400]);
DB::table('v2_user')->where('id', 2)->update(['expired_at' => $expiredAt]);
$periodOrder->type = App\Models\Order::TYPE_NEW_PURCHASE;
$periodService->user = User::find(2);
(new ReflectionMethod($periodService, 'buyByPeriod'))->invoke($periodService, $periodOrder, $plan);
$periodService->user->save();
checkReward((int) User::find(2)->transfer_enable === 100 * $gb
    && (int) User::find(2)->telegram_bonus_permanent === 0,
    'period purchase after a lapse clears continuing gift');
DB::table('v2_user')->insert([
    'id' => 3, 'email' => 'email-only@example.invalid', 'telegram_id' => null,
    'plan_id' => 10, 'transfer_enable' => 100 * $gb,
    'created_at' => time(), 'updated_at' => time(),
]);
$controller = new App\Http\Controllers\V2\Admin\TelegramBindingController();
$emailOnly = $controller->fetch(Illuminate\Http\Request::create('/fetch', 'GET', [
    'binding' => 'email_only', 'plan_id' => '10',
]))->getData(true)['data'];
checkReward($emailOnly['total'] === 1 && $emailOnly['items'][0]['id'] === 3,
    'plan filter lists email-only users without Telegram');
$telegramOnly = $controller->fetch(Illuminate\Http\Request::create('/fetch', 'GET', [
    'binding' => 'telegram', 'plan_id' => '10',
]))->getData(true)['data'];
checkReward($telegramOnly['total'] === 2,
    'plan filter keeps Telegram-bound users separate');
$giftTemplate = new App\Models\GiftCardTemplate();
$giftTemplate->type = App\Models\GiftCardTemplate::TYPE_GENERAL;
$giftTemplate->rewards = ['transfer_enable' => $gb];
DB::table('v2_user')->insert([
    'id' => 4, 'email' => 'no-plan@example.invalid', 'transfer_enable' => 0,
    'invite_user_id' => 5, 'created_at' => time(), 'updated_at' => time(),
]);
DB::table('v2_user')->insert([
    'id' => 5, 'email' => 'expired@example.invalid', 'plan_id' => 10,
    'transfer_enable' => 100 * $gb, 'expired_at' => time() - 100,
    'created_at' => time(), 'updated_at' => time(),
]);
checkReward(!$giftTemplate->checkUserConditions(User::find(4)),
    'traffic gift preview rejects account without plan');
checkReward(!$giftTemplate->checkUserConditions(User::find(5)),
    'traffic gift preview rejects expired plan');
checkReward($giftTemplate->checkUserConditions(User::find(3)),
    'traffic gift preview accepts active plan');
$giftService = (new ReflectionClass(App\Services\GiftCardService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($giftService, 'template'))->setValue($giftService, $giftTemplate);
$giveGift = new ReflectionMethod($giftService, 'giveRewards');
$giftService->setUser(User::find(4));
checkReward($giftService->checkUserEligibility()['reason'] === '请先开通有效套餐后使用流量礼品卡',
    'traffic gift preview explains missing plan');
try {
    $giveGift->invoke($giftService, ['transfer_enable' => $gb]);
    throw new RuntimeException('traffic gift incorrectly accepted without plan');
} catch (App\Exceptions\ApiException $exception) {
    checkReward(str_contains($exception->getMessage(), '有效套餐')
        && (int) User::find(4)->transfer_enable === 0,
        'redeem guard rejects traffic without active plan');
}
$giftService->setUser(User::find(5));
try {
    $giveGift->invoke($giftService, ['transfer_enable' => $gb]);
    throw new RuntimeException('traffic gift incorrectly accepted with expired plan');
} catch (App\Exceptions\ApiException $exception) {
    checkReward((int) User::find(5)->transfer_enable === 100 * $gb,
        'redeem guard rejects expired plan without changing quota');
}
$giftTemplate->rewards = ['plan_id' => 10, 'plan_validity_days' => 30, 'transfer_enable' => 2 * $gb];
checkReward($giftTemplate->checkUserConditions(User::find(4)),
    'bundle of plan and traffic is redeemable without existing plan');
$giftService->setUser(User::find(4));
$giveGift->invoke($giftService, $giftTemplate->rewards);
checkReward((int) User::find(4)->transfer_enable === 102 * $gb
    && User::find(4)->isActive(),
    'plan and traffic on the same gift card are both retained');
$giftService->setUser(User::find(4));
$inviteRewards = (new ReflectionMethod($giftService, 'giveInviteRewards'))
    ->invoke($giftService, ['transfer_enable' => 10 * $gb, 'invite_reward_rate' => 0.2]);
checkReward(empty($inviteRewards) && (int) User::find(5)->transfer_enable === 100 * $gb,
    'expired inviter receives no gift card traffic');
$bindGrant = DB::table('v2_telegram_traffic_grant')->where('reward_key', 'bind:1')->first();
checkReward((int) $bindGrant->duration_days === 7
    && (int) $bindGrant->expires_at - (int) $bindGrant->created_at === 7 * 86400,
    'existing-user gift follows its selected seven-day duration');
DB::table('v2_user')->where('id', 3)->update(['telegram_id' => 333333]);
$short = Rewards::grant(3, 2, 'timed', 'manual', 'manual:one-day', 99, '一天活动', 1);
$long = Rewards::grant(3, 3, 'timed', 'manual', 'manual:five-days', 99, '五天活动', 5);
checkReward($short['expires_at'] - time() <= 86400
    && $short['expires_at'] - time() > 86390
    && (int) User::find(3)->transfer_enable === 105 * $gb,
    'annual or no-reset plan receives a fixed one-day gift');
checkReward((new TrafficResetService())->performReset(User::find(3), 'cron')
    && (int) User::find(3)->telegram_bonus_timed === 5 * $gb,
    'plan traffic reset does not end timed gifts');
DB::table('v2_telegram_traffic_grant')->where('reward_key', 'manual:one-day')
    ->update(['expires_at' => time() - 1]);
checkReward(Rewards::expireDue() === 1
    && (int) User::find(3)->transfer_enable === 103 * $gb
    && (int) User::find(3)->telegram_bonus_timed === 3 * $gb,
    'first timed gift expires without removing overlapping gift');
checkReward(Rewards::expireDue() === 0
    && (int) User::find(3)->transfer_enable === 103 * $gb,
    'expiry processing is idempotent');
$oldPlanExpiry = time() - 3600;
DB::table('v2_user')->where('id', 3)->update(['expired_at' => $oldPlanExpiry]);
DB::table('v2_telegram_traffic_grant')->where('reward_key', 'manual:five-days')
    ->update(['created_at' => $oldPlanExpiry - 3600]);
$orderService->user = User::find(3);
(new ReflectionMethod($orderService, 'buyByOneTime'))->invoke($orderService, $plan);
$orderService->user->save();
checkReward((int) User::find(3)->telegram_bonus_timed === 0
    && (int) User::find(3)->transfer_enable === 100 * $gb
    && DB::table('v2_telegram_traffic_grant')->where('reward_key', 'manual:five-days')->value('revoked_at') !== null,
    'new plan after lapse discards old timed gift');
DB::table('v2_telegram_traffic_grant')->where('reward_key', 'manual:five-days')
    ->update(['expires_at' => time() - 1]);
checkReward(Rewards::expireDue() === 0
    && (int) User::find(3)->transfer_enable === 100 * $gb,
    'discarded gift cannot later subtract from new plan');
DB::table('v2_user')->insert([
    'id' => 6, 'email' => 'pending@example.invalid', 'telegram_id' => 666666,
    'transfer_enable' => 0, 'created_at' => time(), 'updated_at' => time(),
]);
Rewards::grant(6, 1, 'timed', 'manual', 'manual:pending-expiry', 99, '待套餐', 1);
DB::table('v2_telegram_traffic_grant')->where('reward_key', 'manual:pending-expiry')
    ->update(['expires_at' => time() - 1]);
checkReward(Rewards::expireDue() === 1 && (int) User::find(6)->transfer_enable === 0
    && (int) User::find(6)->telegram_bonus_timed === 0,
    'pending gift expires without subtracting plan quota');
$activation = app(App\Services\UserService::class)->assignPlan(User::find(6), App\Models\Plan::find(10), 30);
checkReward((int) $activation->transfer_enable === 100 * $gb,
    'expired pending gift does not activate with a later plan');
DB::table('v2_plan')->insert([
    'id' => 11, 'name' => 'upgrade plan', 'transfer_enable' => 50,
    'reset_traffic_method' => 2, 'group_id' => 1,
]);
DB::table('v2_user')->insert([
    'id' => 7, 'email' => 'upgrade@example.invalid', 'telegram_id' => 777777,
    'plan_id' => 10, 'transfer_enable' => 100 * $gb,
    'expired_at' => time() + 86400 * 90,
    'created_at' => time(), 'updated_at' => time(),
]);
Rewards::grant(7, 1, 'timed', 'manual', 'manual:upgrade', 99, '升级保留', 30);
$upgradeOrder = new App\Models\Order();
$upgradeOrder->type = App\Models\Order::TYPE_UPGRADE;
$upgradeOrder->period = App\Models\Plan::PERIOD_MONTHLY;
$upgradeOrder->plan_id = 11;
$upgradeService = new App\Services\OrderService($upgradeOrder);
$upgradeService->user = User::find(7);
(new ReflectionMethod($upgradeService, 'buyByPeriod'))
    ->invoke($upgradeService, $upgradeOrder, App\Models\Plan::find(11));
$upgradeService->user->save();
checkReward((int) User::find(7)->telegram_bonus_timed === $gb
    && (int) User::find(7)->transfer_enable === 51 * $gb,
    'upgrading an active plan keeps an unexpired timed gift');
Bus::assertDispatchedTimes(SendTelegramJob::class, 12);
echo "TOTAL_PASS=47\n";

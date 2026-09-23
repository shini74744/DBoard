<?php

namespace App\Services;

use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/** Each package is a separate node identity, even when its links are combined. */
class MultiSubscriptionService
{
    public static function account(User $user): User
    {
        return $user->parent_id ? User::findOrFail($user->parent_id) : $user;
    }

    public static function owns(User $account, int $subscriptionUserId): bool
    {
        return $subscriptionUserId === $account->id
            || User::whereKey($subscriptionUserId)->where('parent_id', $account->id)->whereNotNull('plan_id')->exists();
    }

    public static function packages(User $account): Collection
    {
        $account = self::account($account);
        return collect([$account])->concat(
            User::where('parent_id', $account->id)->whereNotNull('plan_id')->orderBy('id')->get()
        );
    }

    public static function validPackages(User $account): Collection
    {
        $account = self::account($account);
        if ($account->banned) return collect();
        return self::packages($account)->filter(fn (User $item) => $item->isActive())->values();
    }

    public static function activePackages(User $account, ?array $selectedIds = null): Collection
    {
        $account = self::account($account);
        if ($account->banned) {
            return collect();
        }
        return self::packages($account)->filter(fn (User $item) =>
            ($selectedIds === null || in_array($item->id, $selectedIds, true)) && $item->isAvailable()
        )->values();
    }

    public static function createPackage(User $account): User
    {
        $account = self::account($account);
        $key = bin2hex(random_bytes(16));
        return User::create([
            'parent_id' => $account->id,
            'email' => 's-' . $key . '@internal.invalid',
            'password' => Hash::make(bin2hex(random_bytes(32))),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'expired_at' => 0,
            'banned' => false,
            'remind_expire' => false,
            'remind_traffic' => false,
            'remind_telegram' => false,
        ]);
    }

    public static function primaryPackageToken(User $account): string
    {
        $account = self::account($account);
        if (!$account->primary_package_token) {
            $candidate = bin2hex(random_bytes(24));
            User::whereKey($account->id)->whereNull('primary_package_token')
                ->update(['primary_package_token' => $candidate]);
            $account->primary_package_token = User::whereKey($account->id)->value('primary_package_token');
        }
        return (string) $account->primary_package_token;
    }

    /** Public labels distinguish duplicate purchases without exposing database IDs. */
    public static function displayNames(User $account): array
    {
        $packages = self::packages($account)->whereNotNull('plan_id');
        $counts = $packages->countBy(fn (User $item) => $item->plan?->name ?? '套餐');
        $seen = [];
        $names = [];
        foreach ($packages as $item) {
            $name = $item->plan?->name ?? '套餐';
            $ordinal = ($seen[$name] ?? 0) + 1;
            $seen[$name] = $ordinal;
            $names[$item->id] = $counts[$name] > 1 ? $name . '（第' . $ordinal . '份）' : $name;
        }
        return $names;
    }

    public static function summary(User $account): array
    {
        $account = self::account($account);
        $packages = self::packages($account);
        if (!$account->plan_id && $packages->count() > 1) {
            $packages = $packages->filter(fn (User $item) => $item->id !== $account->id)->values();
        }
        $primaryToken = $account->plan_id ? self::primaryPackageToken($account) : null;
        $displayNames = self::displayNames($account);
        return $packages->map(function (User $item) use ($account, $primaryToken, $displayNames) {
            return [
                'id' => $item->id,
                'primary' => $item->id === $account->id,
                'plan_id' => $item->plan_id,
                'plan_name' => $item->plan?->name,
                'display_name' => $displayNames[$item->id] ?? $item->plan?->name,
                'expired_at' => $item->expired_at,
                'transfer_enable' => (int) $item->transfer_enable,
                'u' => (int) $item->u,
                'd' => (int) $item->d,
                'remaining' => $item->getRemainingTraffic(),
                'speed_limit' => $item->speed_limit,
                'device_limit' => $item->device_limit,
                'traffic_breakdown' => TrafficQuotaBreakdown::forUser($item),
                'active' => !$account->banned && $item->isAvailable(),
                'subscribe_url' => Helper::getSubscribeUrl($item->id === $account->id && $primaryToken
                    ? $primaryToken : $item->token),
            ];
        })->all();
    }

    public static function mergedServers(User $account, ?array $selectedIds = null, ?string $duplicateMode = null): array
    {
        $account = self::account($account);
        $servers = [];
        $displayNames = self::displayNames($account);
        $seen = [];
        foreach (self::activePackages($account, $selectedIds) as $item) {
            foreach (ServerService::getAvailableServers($item) as $server) {
                $serverId = (int) $server['id'];
                if (($duplicateMode ?? $account->duplicate_node_mode) === 'first' && isset($seen[$serverId])) {
                    continue;
                }
                $seen[$serverId] = true;
                $server['name'] = ($displayNames[$item->id] ?? '套餐') . ' · ' . $server['name'];
                $servers[] = $server;
            }
        }
        return $servers;
    }

    public static function mergedBreakdown(User $account): array
    {
        $keys = ['base_bytes', 'cycle_bonus_bytes', 'permanent_bonus_bytes', 'timed_bonus_bytes',
            'gift_card_bonus_bytes', 'total_bytes', 'used_bytes', 'remaining_bytes'];
        $result = array_fill_keys($keys, 0);
        $result['status'] = 'no_plan';
        $result['entries'] = [];
        $displayNames = self::displayNames($account);
        foreach (self::activePackages($account) as $item) {
            $part = TrafficQuotaBreakdown::forUser($item);
            foreach ($keys as $key) $result[$key] += (int) ($part[$key] ?? 0);
            foreach ($part['entries'] ?? [] as $entry) {
                $entry['reason'] = ($displayNames[$item->id] ?? '套餐') . ' · ' . $entry['reason'];
                $result['entries'][] = $entry;
            }
            $result['status'] = 'active';
        }
        return $result;
    }

    /** The merged link's standard traffic header covers only currently usable packages. */
    public static function mergedHeaderUser(User $account, ?array $selectedIds = null): User
    {
        $account = self::account($account);
        $packages = self::activePackages($account, $selectedIds);
        $header = clone $account;
        $header->u = $packages->sum(fn (User $item) => (int) $item->u);
        $header->d = $packages->sum(fn (User $item) => (int) $item->d);
        $header->transfer_enable = $packages->sum(fn (User $item) => (int) $item->transfer_enable);
        $header->expired_at = $packages->contains(fn (User $item) => $item->expired_at === null)
            ? null : $packages->max('expired_at');
        return $header;
    }
}

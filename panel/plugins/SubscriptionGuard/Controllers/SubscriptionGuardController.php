<?php

namespace Plugin\SubscriptionGuard\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionGuardController extends Controller
{
    /**
     * 获取订阅拉取日志
     */
    public function getLogs(Request $request)
    {
        $query = DB::table('v2_subscribe_log')
            ->leftJoin('v2_user', 'v2_subscribe_log.user_id', '=', 'v2_user.id')
            ->select(
                'v2_subscribe_log.*',
                'v2_user.email'
            );

        // 按用户筛选
        if ($userId = $request->input('user_id')) {
            $query->where('v2_subscribe_log.user_id', $userId);
        }

        // 按邮箱搜索
        if ($email = $request->input('email')) {
            $query->where('v2_user.email', 'like', "%{$email}%");
        }

        // 按IP搜索
        if ($ip = $request->input('ip')) {
            $query->where('v2_subscribe_log.ip', 'like', "%{$ip}%");
        }

        $pageSize = min((int) $request->input('page_size', 20), 100);
        $logs = $query->orderByDesc('v2_subscribe_log.created_at')
            ->paginate($pageSize);

        return response()->json([
            'data' => $logs
        ]);
    }

    /**
     * 获取滥用记录列表
     */
    public function getAbuseList(Request $request)
    {
        try {
            $email = $request->input('email');
            $page = max((int) $request->input('page', 1), 1);
            $pageSize = min(max((int) $request->input('page_size', 20), 1), 100);
            $records = collect();
            $recordedUserIds = [];

            // 1. 读取插件自动/手动写入的滥用记录
            if (\Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_abuse')) {
                $abuseQuery = DB::table('v2_subscribe_abuse')
                    ->leftJoin('v2_user', 'v2_subscribe_abuse.user_id', '=', 'v2_user.id')
                    ->select(
                        'v2_subscribe_abuse.id',
                        'v2_subscribe_abuse.user_id',
                        'v2_subscribe_abuse.unique_ip_count',
                        'v2_subscribe_abuse.ip_list',
                        'v2_subscribe_abuse.action_taken',
                        'v2_subscribe_abuse.details',
                        'v2_subscribe_abuse.created_at',
                        'v2_user.email',
                        'v2_user.banned'
                    );

                if ($email) {
                    $abuseQuery->where('v2_user.email', 'like', "%{$email}%");
                }

                $records = $abuseQuery->orderByDesc('v2_subscribe_abuse.created_at')->get();
                $recordedUserIds = $records->pluck('user_id')->filter()->unique()->values()->all();
            }

            // 2. 兼容旧版本/手动封禁：已封禁但没有 v2_subscribe_abuse 记录的用户也显示出来
            $bannedQuery = DB::table('v2_user')
                ->where('banned', 1)
                ->select('id', 'email', 'banned', 'updated_at', 'created_at');

            if (!empty($recordedUserIds)) {
                $bannedQuery->whereNotIn('id', $recordedUserIds);
            }

            if ($email) {
                $bannedQuery->where('email', 'like', "%{$email}%");
            }

            $manualRecords = $bannedQuery->get()->map(function ($user) {
                $time = $user->updated_at ?: $user->created_at ?: time();

                return (object) [
                    'id'              => null,
                    'user_id'         => $user->id,
                    'unique_ip_count' => 1,
                    'ip_list'         => '[]',
                    'action_taken'    => 'manual_ban',
                    'details'         => null,
                    'created_at'      => is_numeric($time) ? date('Y-m-d H:i:s', (int) $time) : $time,
                    'email'           => $user->email,
                    'banned'          => $user->banned,
                ];
            });

            $records = $records->merge($manualRecords)
                ->sortByDesc(function ($item) {
                    return strtotime((string) $item->created_at) ?: 0;
                })
                ->values();

            $total = $records->count();
            $items = $records->slice(($page - 1) * $pageSize, $pageSize)->values();

            return response()->json([
                'data' => [
                    'current_page' => $page,
                    'data'         => $items,
                    'last_page'    => max((int) ceil($total / $pageSize), 1),
                    'per_page'     => $pageSize,
                    'total'        => $total,
                ]
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[SubscriptionGuard] 获取封禁记录失败: ' . $e->getMessage());

            return response()->json([
                'data' => [
                    'current_page' => 1,
                    'data'         => [],
                    'last_page'    => 1,
                    'per_page'     => 20,
                    'total'        => 0,
                ],
                'message' => '获取封禁记录失败：' . $e->getMessage()
            ]);
        }
    }

    /**
     * 获取用户订阅拉取统计
     */
    public function getUserStats(Request $request)
    {
        $hours = (int) $request->input('hours', 24);
        $since = now()->subHours($hours);
        $minIps = (int) $request->input('min_ips', 2);

        $stats = DB::table('v2_subscribe_log')
            ->leftJoin('v2_user', 'v2_subscribe_log.user_id', '=', 'v2_user.id')
            ->where('v2_subscribe_log.created_at', '>=', $since)
            ->groupBy('v2_subscribe_log.user_id', 'v2_user.email', 'v2_user.banned')
            ->havingRaw('COUNT(DISTINCT v2_subscribe_log.ip) >= ?', [$minIps])
            ->select(
                'v2_subscribe_log.user_id',
                'v2_user.email',
                'v2_user.banned',
                DB::raw('COUNT(DISTINCT v2_subscribe_log.ip) as unique_ips'),
                DB::raw('COUNT(*) as total_pulls'),
                DB::raw('MAX(v2_subscribe_log.created_at) as last_pull')
            )
            ->orderByDesc('unique_ips')
            ->get();

        return response()->json([
            'data' => $stats
        ]);
    }

    /**
     * 获取指定用户的IP详情
     */
    public function getUserIpDetail(Request $request)
    {
        $userId = $request->input('user_id');
        if (!$userId) {
            return response()->json(['message' => 'user_id is required'], 400);
        }

        $hours = (int) $request->input('hours', 24);
        $since = now()->subHours($hours);

        $details = DB::table('v2_subscribe_log')
            ->where('user_id', $userId)
            ->where('created_at', '>=', $since)
            ->select(
                'ip',
                'ip_region',
                'user_agent',
                DB::raw('COUNT(*) as pull_count'),
                DB::raw('MIN(created_at) as first_pull'),
                DB::raw('MAX(created_at) as last_pull')
            )
            ->groupBy('ip', 'ip_region', 'user_agent')
            ->orderByDesc('pull_count')
            ->get();

        return response()->json([
            'data' => $details
        ]);
    }

    /**
     * 手动封禁用户（标记为共享滥用）
     */
    public function banUser(Request $request)
    {
        $userId = $request->input('user_id');
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => '用户不存在'], 404);
        }

        $user->banned = true;
        $user->token = Str::random(32);
        $user->remarks = ($user->remarks ? $user->remarks . "\n" : '')
            . "[" . now()->format('Y-m-d H:i') . "] 管理员手动封禁 - 订阅共享";
        $user->save();

        // 手动封禁记录不能影响封禁主流程：记录失败时只写日志，不返回 500
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_abuse')) {
                $recentLogs = collect();

                if (\Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_log')) {
                    $recentLogs = DB::table('v2_subscribe_log')
                        ->where('user_id', $userId)
                        ->where('created_at', '>=', now()->subHours(24))
                        ->select('ip', 'ip_region', DB::raw('COUNT(*) as pull_count'), DB::raw('MAX(created_at) as last_pull'))
                        ->groupBy('ip', 'ip_region')
                        ->orderByDesc('pull_count')
                        ->get();
                }

                $ips = $recentLogs->pluck('ip')->filter()->unique()->values()->all();

                DB::table('v2_subscribe_abuse')->updateOrInsert(
                    ['user_id' => $user->id],
                    [
                        'unique_ip_count' => max(count($ips), 1),
                        'ip_list'         => json_encode($ips, JSON_UNESCAPED_UNICODE),
                        'action_taken'    => 'manual_ban',
                        'details'         => json_encode($recentLogs, JSON_UNESCAPED_UNICODE),
                        'created_at'      => now(),
                    ]
                );
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[SubscriptionGuard] 手动封禁记录写入失败: ' . $e->getMessage(), [
                'user_id' => $user->id,
            ]);
        }

        return response()->json(['message' => '用户已封禁并重置订阅']);
    }

    /**
     * 解封用户
     */
    public function unbanUser(Request $request)
    {
        $userId = $request->input('user_id');
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => '用户不存在'], 404);
        }

        $user->banned = false;
        $user->remarks = ($user->remarks ? $user->remarks . "\n" : '')
            . "[" . now()->format('Y-m-d H:i') . "] 管理员解封";
        $user->save();

        // 清除滥用缓存
        \Illuminate\Support\Facades\Cache::forget("sub_guard:abused:{$userId}");

        // 删除滥用记录（兼容未执行迁移或旧版安装场景）
        if (\Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_abuse')) {
            DB::table('v2_subscribe_abuse')->where('user_id', $userId)->delete();
        }

        return response()->json(['message' => '用户已解封']);
    }

    /**
     * Dashboard 统计概览
     */
    public function dashboard(Request $request)
    {
        $today = now()->startOfDay();
        $last24h = now()->subHours(24);

        // 今日拉取总数
        $todayPulls = DB::table('v2_subscribe_log')
            ->where('created_at', '>=', $today)
            ->count();

        // 今日独立用户数
        $todayUsers = DB::table('v2_subscribe_log')
            ->where('created_at', '>=', $today)
            ->distinct('user_id')
            ->count('user_id');

        // 24小时内可疑用户数（>=3个IP）
        $suspiciousUsers = DB::table('v2_subscribe_log')
            ->where('created_at', '>=', $last24h)
            ->groupBy('user_id')
            ->havingRaw('COUNT(DISTINCT ip) >= 3')
            ->select('user_id')
            ->get()
            ->count();

        // 总封禁数：兼容旧版手动封禁（只有 v2_user.banned，没有 v2_subscribe_abuse 记录）
        $abuseUserIds = \Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_abuse')
            ? DB::table('v2_subscribe_abuse')->pluck('user_id')->toArray()
            : [];
        $bannedUserIds = DB::table('v2_user')->where('banned', 1)->pluck('id')->toArray();
        $totalAbuse = count(array_unique(array_merge($abuseUserIds, $bannedUserIds)));

        // 日志总量
        $totalLogs = DB::table('v2_subscribe_log')->count();

        return response()->json([
            'data' => [
                'today_pulls'      => $todayPulls,
                'today_users'      => $todayUsers,
                'suspicious_users' => $suspiciousUsers,
                'total_abuse'      => $totalAbuse,
                'total_logs'       => $totalLogs,
            ]
        ]);
    }

    /**
     * 手动清理日志
     */
    public function cleanLogs(Request $request)
    {
        $days = (int) $request->input('days', 30);
        $before = now()->subDays($days);

        $deleted = DB::table('v2_subscribe_log')
            ->where('created_at', '<', $before)
            ->delete();

        return response()->json([
            'message' => "已清理 {$deleted} 条日志"
        ]);
    }
}

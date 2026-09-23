<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrafficLogResource;
use App\Models\StatUser;
use App\Services\MultiSubscriptionService;
use App\Services\StatisticalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatController extends Controller
{
    public function getDailyTraffic(Request $request)
    {
        $days = (int) ($request->validate([
            'days' => 'sometimes|integer|min:1|max:90',
        ])['days'] ?? 30);
        $targetId = $request->validate(['subscription_user_id' => 'sometimes|integer|min:1'])['subscription_user_id'] ?? null;
        if ($targetId && !MultiSubscriptionService::owns($request->user(), (int) $targetId)) abort(403);
        $userIds = $targetId ? [(int) $targetId] : MultiSubscriptionService::packages($request->user())->pluck('id')->all();
        $today = now()->startOfDay();
        $start = $today->copy()->subDays($days - 1);

        $records = StatUser::query()
            ->whereIn('user_id', $userIds)
            ->where('record_type', 'd')
            ->whereBetween('record_at', [$start->timestamp, $today->copy()->endOfDay()->timestamp])
            ->selectRaw('record_at, SUM(u) as upload, SUM(d) as download')
            ->groupBy('record_at')
            ->get()
            ->keyBy(fn ($row) => $row->record_at);

        $daily = [];
        for ($day = $today->copy(); $day->gte($start); $day->subDay()) {
            $row = $records->get($day->timestamp);
            $upload = (int) ($row->upload ?? 0);
            $download = (int) ($row->download ?? 0);
            $daily[] = [
                'date' => $day->toDateString(),
                'u' => $upload,
                'd' => $download,
                'total' => $upload + $download,
            ];
        }

        return $this->success($daily)->header('Cache-Control', 'private, no-store');
    }

    public function getTrafficLog(Request $request)
    {
        $targetId = $request->validate(['subscription_user_id' => 'sometimes|integer|min:1'])['subscription_user_id'] ?? null;
        if ($targetId && !MultiSubscriptionService::owns($request->user(), (int) $targetId)) abort(403);
        $userIds = $targetId ? [(int) $targetId] : MultiSubscriptionService::packages($request->user())->pluck('id')->all();
        $startDate = now()->startOfMonth()->timestamp;
        $records = StatUser::query()
            ->whereIn('user_id', $userIds)
            ->where('record_at', '>=', $startDate)
            ->orderBy('record_at', 'DESC')
            ->get();

        $data = TrafficLogResource::collection(collect($records));
        return $this->success($data);
    }
}

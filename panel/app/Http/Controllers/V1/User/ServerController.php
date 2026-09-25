<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\NodeResource;
use App\Models\User;
use App\Services\MultiSubscriptionService;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    public function fetch(Request $request)
    {
        $user = User::find($request->user()->id);
        $servers = [];
        $userService = new UserService();
        if ($user->subscription_link_mode === 'merged') {
            $servers = MultiSubscriptionService::mergedServers($user);
        } elseif ($userService->isAvailable($user)) {
            $servers = ServerService::getAvailableServers($user);
        }
        $eTag = sha1(json_encode(array_map(fn ($server) => [$server['cache_key'], $server['name'], $server['password'], $server['rate']], $servers)));
        if (strpos($request->header('If-None-Match', ''), $eTag) !== false ) {
            return response(null,304);
        }
        $data = NodeResource::collection($servers);
        return response([
            'data' => $data
        ])->header('ETag', "\"{$eTag}\"");
    }
}

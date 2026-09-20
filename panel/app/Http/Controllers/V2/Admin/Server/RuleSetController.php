<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\RuleSetService;
use Illuminate\Http\Request;

class RuleSetController extends Controller
{
    public function apps(Request $request, RuleSetService $service)
    {
        return $this->success($service->apps($request->boolean('refresh')));
    }

    public function resolve(Request $request, RuleSetService $service)
    {
        $params = $request->validate([
            'path' => 'required|string|max:255',
        ]);

        return $this->success($service->resolve($params['path']));
    }

    public function resolveUrl(Request $request, RuleSetService $service)
    {
        $params = $request->validate([
            'url' => 'required|string|max:2048',
        ]);

        return $this->success($service->resolveUrl($params['url']));
    }

    public function resolveMany(Request $request, RuleSetService $service)
    {
        $params = $request->validate([
            'paths' => 'required|array|min:1|max:40',
            'paths.*' => 'required|string|max:255',
        ]);

        return $this->success($service->resolveMany($params['paths']));
    }
}

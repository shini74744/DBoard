<?php

namespace Plugin\SubscribeGuard\Controllers;

use App\Http\Controllers\PluginController;
use Illuminate\Http\Request;
use Plugin\SubscribeGuard\Services\GuardService;

class LogController extends PluginController
{
    public function index(Request $request)
    {
        $limit = (int) $request->input('limit', 100);
        $guard = new GuardService($this->getConfig());

        return $this->success([
            'logs' => $guard->getRecentLogs($limit),
        ]);
    }

    public function clear()
    {
        $guard = new GuardService($this->getConfig());
        $guard->clearLogs();

        return $this->success([
            'message' => '日志已清空',
        ]);
    }

    public function page()
    {
        return view('SubscribeGuard::logs');
    }
}
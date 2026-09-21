<?php

namespace Plugin\SubscribeGuard;

use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use Plugin\SubscribeGuard\Services\GuardService;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->listen('client.subscribe.before', function () {
            if (!$this->getConfig('enabled', true)) {
                return;
            }

            $request = request();
            $guard = new GuardService($this->getConfig());

            $result = $guard->inspect($request);
            if ($result['allowed']) {
                return;
            }

            $guard->recordBlockedRequest($request, $result);
            HookManager::intercept(response('', 404, ['Content-Type' => 'text/plain']));
        }, 1);
    }
}
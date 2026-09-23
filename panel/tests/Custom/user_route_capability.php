<?php

require __DIR__ . '/../../vendor/autoload.php';

use App\Services\ServerService;

$rules = [
    ['match' => ['user_ids' => [11], 'domain_suffixes' => ['example.com']], 'action' => ['type' => 'route', 'target' => 'exit-b']],
    ['match' => [], 'action' => ['type' => 'route', 'target' => 'exit-a']],
];

$legacy = ServerService::compatibleRouteRules($rules, false);
if (count($legacy) !== 1 || $legacy[0]['action']['target'] !== 'exit-a') {
    throw new RuntimeException('An old node received the user-specific route');
}
$current = ServerService::compatibleRouteRules($rules, true);
if (count($current) !== 2 || $current[0]['match']['user_ids'] !== [11]) {
    throw new RuntimeException('A capable node lost the user-specific route');
}
echo "PASS unsupported node keeps only the default exit\n";
echo "PASS capable node receives user-specific exit first\n";

// Delivery must use the current socket's capability even if a cached HTTP
// capability temporarily belongs to another process during a node upgrade.
class UserRouteTestConnection extends \Workerman\Connection\TcpConnection
{
    public array $messages = [];

    public function __construct() {}

    public function send(mixed $sendBuffer, bool $raw = false): bool|null
    {
        $this->messages[] = json_decode($sendBuffer, true);
        return true;
    }
}

$oldConnection = new UserRouteTestConnection();
$oldConnection->userRoutesCapable = false;
\App\Services\NodeRegistry::add(11, $oldConnection);
\App\Services\NodeRegistry::send(11, 'sync.config', ['config' => ['custom_route_rules' => $rules]]);
$delivered = $oldConnection->messages[0]['data']['config']['custom_route_rules'] ?? [];
if (count($delivered) !== 1 || $delivered[0]['action']['target'] !== 'exit-a') {
    throw new RuntimeException('Old WebSocket node received a user-specific rule');
}
\App\Services\NodeRegistry::remove(11, $oldConnection);

$newConnection = new UserRouteTestConnection();
$newConnection->userRoutesCapable = true;
\App\Services\NodeRegistry::add(11, $newConnection);
\App\Services\NodeRegistry::send(11, 'sync.config', ['config' => ['custom_route_rules' => $rules]]);
$delivered = $newConnection->messages[0]['data']['config']['custom_route_rules'] ?? [];
if (count($delivered) !== 2 || $delivered[0]['action']['target'] !== 'exit-b') {
    throw new RuntimeException('New WebSocket node lost a user-specific rule');
}
\App\Services\NodeRegistry::remove(11, $newConnection);
echo "PASS WebSocket delivery respects each node connection's capability\n";

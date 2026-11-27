<?php
/**
 * TEMPORARY probe: what the live Epson Connect API actually returns, and one
 * POST to clear the notification callback left pointing at the deleted script.
 * Admin-only; deleted again once the answer is recorded.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

require_admin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$epson = EpsonClient::fromSettings();
$epson->bypassCircuit = true;

$method = new ReflectionMethod(EpsonClient::class, 'api');
$method->setAccessible(true);

$call = static function (string $verb, string $path, ?array $body = null) use ($method, $epson): array {
    try {
        return ['ok' => true, 'body' => $method->invoke($epson, $verb, $path, $body, 30, 1)];
    } catch (EpsonException $e) {
        return ['ok' => false, 'code' => $e->statusCode, 'message' => $e->getMessage(), 'payload' => $e->payload];
    }
};

$out = ['connected' => $epson->isConnected()];
$out['device_info']        = $call('GET', '/printing/devices/info');
$out['notification_before'] = $call('GET', '/printing/settings/notification');
$out['notification_off']    = $call('POST', '/printing/settings/notification', ['notification' => false]);
$out['notification_after']  = $call('GET', '/printing/settings/notification');

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

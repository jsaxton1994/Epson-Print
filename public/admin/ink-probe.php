<?php
/**
 * TEMPORARY probe: what the live Epson Connect API actually returns.
 * Admin-only, read-only; deleted again once the ink question is settled.
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

$paths = [
    '/printing/devices/info',
    '/printing/devices',
    '/printing/devices/status',
    '/printing/capability?capability=photo',
    '/printing/settings/notification',
];

$out = ['connected' => $epson->isConnected(), 'results' => []];

foreach ($paths as $path) {
    try {
        $body = $method->invoke($epson, 'GET', $path, null, 25, 1);
        $out['results'][$path] = ['ok' => true, 'body' => $body];
    } catch (EpsonException $e) {
        $out['results'][$path] = [
            'ok'      => false,
            'code'    => $e->statusCode,
            'message' => $e->getMessage(),
            'payload' => $e->payload,
        ];
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

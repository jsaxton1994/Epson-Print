<?php
/**
 * TEMPORARY diagnostic: dump raw Epson Connect responses so we can see whether
 * any supply/ink field exists for this printer. Admin-only, deleted after use.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

require_admin();

set_time_limit(180);
ini_set('display_errors', '1');
error_reporting(E_ALL);

$epson = EpsonClient::fromSettings();
$epson->bypassCircuit = true;

$raw = new ReflectionMethod(EpsonClient::class, 'api');
$raw->setAccessible(true);

$out = [
    'model'  => $epson->deviceName(),
    'serial' => setting('epson_device_serial', ''),
];

try {
    $out['device_info'] = $epson->deviceInfo();
} catch (EpsonException $e) {
    $out['device_info'] = 'ERR ' . $e->statusCode . ': ' . $e->getMessage();
}

foreach ([
    '/printing/devices/status',
    '/printing/devices/ink',
    '/printing/supplies',
] as $path) {
    try {
        $out['GET ' . $path] = $raw->invoke($epson, 'GET', $path, null, 10, 1);
    } catch (EpsonException $e) {
        $out['GET ' . $path] = 'ERR ' . $e->statusCode . ': ' . $e->getMessage();
    }
}

$job = db_one(
    "SELECT epson_job_id FROM photos
     WHERE epson_job_id IS NOT NULL AND epson_job_id <> ''
     ORDER BY id DESC LIMIT 1"
);

if (is_array($job) && (string) $job['epson_job_id'] !== '') {
    try {
        $out['job_status'] = $epson->jobStatus((string) $job['epson_job_id']);
    } catch (EpsonException $e) {
        $out['job_status'] = 'ERR ' . $e->statusCode . ': ' . $e->getMessage();
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

<?php
/**
 * Epson print-job notification (webhook).
 *
 * Epson posts job status changes here instead of the queue polling for them.
 * The URL carries a long random token; everything else about the request is
 * untrusted, so the body is only used to look up a job id we already know.
 *
 * Deliberately light: no Epson calls, no heavy queries - just record the status
 * of the matching photo and answer 200 so Epson does not retry.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$respond = static function (int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);

    exit;
};

$secret = (string) setting('epson_notify_secret', '');
$token  = (string) ($_GET['token'] ?? '');

if ($secret === '' || $token === '' || !hash_equals($secret, $token)) {
    // 404 rather than 403: do not confirm that the path exists.
    $respond(404, ['ok' => false]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $respond(405, ['ok' => false, 'error' => 'POST only']);
}

$raw = (string) file_get_contents('php://input', false, null, 0, 32768);

if ($raw === '') {
    $respond(400, ['ok' => false, 'error' => 'Empty body']);
}

$payload = json_decode($raw, true);

if (!is_array($payload)) {
    $respond(400, ['ok' => false, 'error' => 'Invalid JSON']);
}

// Epson wraps the event as { "Param": { "JobId": "...", "JobStatus": { ... } } }.
$param         = $payload['Param'] ?? $payload['param'] ?? null;
$statusPayload = $payload;
$jobId         = '';

if (is_array($param)) {
    $jobId         = (string) ($param['JobId'] ?? $param['jobId'] ?? '');
    $statusPayload = $param['JobStatus'] ?? $param['jobStatus'] ?? $param;
}

if ($jobId === '') {
    $jobId = (string) ($payload['JobId'] ?? $payload['jobId'] ?? '');
}

$epson  = EpsonClient::fromSettings();
$result = $epson->interpretJobStatus(is_array($statusPayload) ? $statusPayload : []);
$applied = epson_apply_job_status($jobId, $result);

epson_log_notification([
    'at'       => now(),
    'job_id'   => mb_substr($jobId, 0, 64),
    'status'   => (string) $result['status'],
    'photo_id' => $applied['photo_id'],
    'matched'  => $applied['matched'],
    'result'   => $applied['status'],
]);

$respond(200, [
    'ok'      => true,
    'matched' => $applied['matched'],
    'status'  => $applied['status'],
]);

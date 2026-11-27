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

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$secret = (string) setting('epson_notify_secret', '');
$token  = (string) ($_GET['token'] ?? '');
$raw    = $method === 'POST' ? (string) file_get_contents('php://input', false, null, 0, 32768) : '';

if ($secret === '' || $token === '' || !hash_equals($secret, $token)) {
    // Record that something arrived but was not accepted, so a callback that
    // loses its query string is visible instead of silently 404ing.
    epson_log_notification([
        'at'       => now(),
        'job_id'   => '',
        'status'   => 'rejected',
        'photo_id' => null,
        'matched'  => false,
        'result'   => mb_substr($method . ' query=' . (implode(',', array_keys($_GET)) ?: 'none')
            . ' ua=' . mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 40)
            . ' body=' . mb_substr(preg_replace('/\s+/', ' ', $raw) ?? '', 0, 90), 0, 240),
    ]);

    // 404 rather than 403: do not confirm that the path exists.
    $respond(404, ['ok' => false]);
}

if ($method !== 'POST') {
    $respond(405, ['ok' => false, 'error' => 'POST only']);
}

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

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
 *
 * Every POST that reaches this file is also written to a short diagnostic ring
 * buffer (epson_notify_debug) so a delivery can be told apart from a missing or
 * wrong token or an unparseable body. The token itself is never stored.
 *
 * @see TEMPORARY DIAGNOSTIC note in README.md - remove with epson_notify_debug_*.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$method  = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$secret  = (string) setting('epson_notify_secret', '');
$token   = (string) ($_GET['token'] ?? '');
$tokenOk = $secret !== '' && $token !== '' && hash_equals($secret, $token);
$raw     = $method === 'POST' ? (string) file_get_contents('php://input', false, null, 0, 65536) : '';

$debug = [
    'at'           => now(),
    'method'       => $method,
    'token'        => $token === '' ? 'missing' : ($tokenOk ? 'match' : 'mismatch'),
    'query_keys'   => implode(',', array_keys($_GET)) ?: 'none',
    'content_type' => mb_substr((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 0, 80),
    'user_agent'   => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 90),
    'accept'       => mb_substr((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 0, 60),
    'length'       => (int) ($_SERVER['CONTENT_LENGTH'] ?? strlen($raw)),
    'body'         => mb_substr($raw, 0, 1500),
    'outcome'      => 'unknown',
    'status'       => 200,
    'note'         => '',
];

$finish = static function (int $status, array $body, string $outcome, string $note = '') use (&$debug): never {
    $debug['status']  = $status;
    $debug['outcome'] = $outcome;
    $debug['note']    = mb_substr($note, 0, 240);
    epson_notify_debug_log($debug);

    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);

    exit;
};

if (!$tokenOk) {
    // Record that something arrived but was not accepted, so a callback that
    // loses its query string is visible instead of silently 404ing.
    epson_log_notification([
        'at'       => now(),
        'job_id'   => '',
        'status'   => 'rejected',
        'photo_id' => null,
        'matched'  => false,
        'result'   => mb_substr($method . ' query=' . $debug['query_keys']
            . ' ua=' . mb_substr($debug['user_agent'], 0, 40)
            . ' body=' . mb_substr(preg_replace('/\s+/', ' ', $raw) ?? '', 0, 90), 0, 240),
    ]);

    // 404 rather than 403: do not confirm that the path exists.
    $finish(404, ['ok' => false], 'token_missing');
}

if ($method !== 'POST') {
    $finish(405, ['ok' => false, 'error' => 'POST only'], 'method_not_allowed');
}

if ($raw === '') {
    $finish(400, ['ok' => false, 'error' => 'Empty body'], 'empty_body');
}

$payload = json_decode($raw, true);

if (!is_array($payload)) {
    $finish(400, ['ok' => false, 'error' => 'Invalid JSON'], 'invalid_json', json_last_error_msg());
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

if ($jobId === '') {
    $finish(400, ['ok' => false, 'error' => 'No JobId'], 'no_job_id', 'keys=' . implode(',', array_keys($payload)));
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

$finish(200, [
    'ok'      => true,
    'matched' => $applied['matched'],
    'status'  => $applied['status'],
], $applied['matched'] ? 'applied' : 'unknown_job', 'job=' . mb_substr($jobId, 0, 40)
    . ' status=' . (string) $result['status']
    . ' photo=' . ($applied['photo_id'] ?? '-'));

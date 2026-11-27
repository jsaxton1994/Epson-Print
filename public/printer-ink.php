<?php
declare(strict_types=1);

/**
 * Ink feed: the shop-network SNMP bridge posts the ET-2926's four tank levels
 * here, because the Epson Connect API publishes none.
 *
 *   POST /printer-ink.php
 *   Authorization: Bearer <bridge token>
 *   Content-Type: application/json
 *   {"black":82,"cyan":74,"magenta":61,"yellow":55,"model":"ET-2926"}
 *
 * The token is created in Admin → Printer Settings → Ink bridge (SNMP). This is
 * not a session endpoint: there is no CSRF check because there is no cookie,
 * and the token is compared against its stored SHA-256 hash.
 */

require_once __DIR__ . '/../includes/config.php';

date_default_timezone_set(APP_TIMEZONE);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ink.php';

function ink_feed_reply(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    ink_feed_reply(405, ['ok' => false, 'error' => 'Use POST with a JSON body.']);
}

$token = '';
$auth  = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

if ($auth !== '' && preg_match('/^Bearer\s+(.+)$/i', trim($auth), $match)) {
    $token = trim($match[1]);
}

if ($token === '') {
    $token = trim((string) ($_SERVER['HTTP_X_INK_TOKEN'] ?? ''));
}

if (!ink_feed_token_check($token)) {
    header('WWW-Authenticate: Bearer');
    ink_feed_reply(401, ['ok' => false, 'error' => 'A valid bridge token is required.']);
}

$raw     = (string) file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    ink_feed_reply(400, ['ok' => false, 'error' => 'The body must be a JSON object of ink levels.']);
}

$normalised = ink_feed_normalise($payload);

if (!$normalised['ok']) {
    ink_feed_reply(422, ['ok' => false, 'error' => $normalised['error']]);
}

$stored = ink_feed_store($normalised);

ink_feed_reply(200, [
    'ok'          => true,
    'received_at' => $stored['received_at'],
    'levels'      => $stored['levels'],
]);

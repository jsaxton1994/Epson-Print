<?php
declare(strict_types=1);

/**
 * Ink bridge — read the ET-2926's tank levels over SNMP and post them to the
 * PrintFree ink feed.
 *
 * Run this on an always-on machine on the same network as the printer. It needs
 * PHP 8.1 CLI only: the SNMP codec is bundled in snmp.php, so neither the
 * php-snmp extension nor the net-snmp command-line tools are required.
 *
 *   php ink-bridge.php --host 192.168.1.42 --dry-run
 *   php ink-bridge.php --url https://snapprints.co.uk/printer-ink.php \
 *       --token "$INK_FEED_TOKEN" --host 192.168.1.42 --every 300
 *
 * The token is created in Admin → Printer Settings → Ink bridge (SNMP). It can
 * also come from INK_FEED_TOKEN, and the feed address from INK_FEED_URL, so a
 * cron line does not have to carry it.
 *
 * Exit codes: 0 posted (or a clean dry run), 1 SNMP failed, 2 posting failed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/snmp.php';

const INK_BRIDGE_OID_SUPPLIES_DESCRIPTION = '1.3.6.1.2.1.43.11.1.1.6.1';
const INK_BRIDGE_OID_SUPPLIES_MAX         = '1.3.6.1.2.1.43.11.1.1.8.1';
const INK_BRIDGE_OID_SUPPLIES_LEVEL       = '1.3.6.1.2.1.43.11.1.1.9.1';

const INK_BRIDGE_COLOURS = ['black', 'cyan', 'magenta', 'yellow'];

/**
 * @param list<string> $argv
 * @return array<string,mixed>
 */
function ink_bridge_options(array $argv): array
{
    $options = [
        'url'       => (string) (getenv('INK_FEED_URL') ?: ''),
        'token'     => (string) (getenv('INK_FEED_TOKEN') ?: ''),
        'host'      => (string) (getenv('INK_PRINTER_HOST') ?: ''),
        'community' => (string) (getenv('INK_SNMP_COMMUNITY') ?: 'public'),
        'port'      => (int) (getenv('INK_SNMP_PORT') ?: 161),
        'timeout'   => (float) (getenv('INK_SNMP_TIMEOUT') ?: 1.5),
        'retries'   => (int) (getenv('INK_SNMP_RETRIES') ?: 2),
        'model'     => (string) (getenv('INK_PRINTER_MODEL') ?: 'ET-2926'),
        'every'     => (int) (getenv('INK_EVERY') ?: 0),
        'version'   => defined('SNMP_VERSION_2C') ? SNMP_VERSION_2C : 1,
        'dry_run'   => false,
        'help'      => false,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];

        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }

        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }

        if ($arg === '--v1') {
            $options['version'] = SNMP_VERSION_1;
            continue;
        }

        if ($arg === '--v2c') {
            $options['version'] = SNMP_VERSION_2C;
            continue;
        }

        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $pair  = explode('=', substr($arg, 2), 2);
        $key   = $pair[0];
        $value = $pair[1] ?? null;

        if ($value === null) {
            $value = $argv[$i + 1] ?? '';
            $i++;
        }

        switch ($key) {
            case 'url':
            case 'token':
            case 'host':
            case 'community':
            case 'model':
                $options[$key] = trim($value);
                break;
            case 'port':
            case 'retries':
            case 'every':
                $options[$key] = (int) $value;
                break;
            case 'timeout':
                $options[$key] = (float) $value;
                break;
            default:
                fwrite(STDERR, 'Unknown option --' . $key . ' (try --help).' . PHP_EOL);
                break;
        }
    }

    return $options;
}

function ink_bridge_usage(): string
{
    return <<<'TXT'
Ink bridge — post Epson ET-2926 tank levels to PrintFree over SNMP.

Usage:
  php ink-bridge.php --host 192.168.1.42 --dry-run
  php ink-bridge.php --url https://example.com/printer-ink.php \
      --token TOKEN --host 192.168.1.42 --every 300

Options:
  --host HOST          Printer address on the shop network (INK_PRINTER_HOST)
  --url URL            Ink feed address (INK_FEED_URL)
  --token TOKEN        Bridge token from Printer Settings (INK_FEED_TOKEN)
  --community NAME     SNMP community, default "public" (INK_SNMP_COMMUNITY)
  --port PORT          SNMP port, default 161 (INK_SNMP_PORT)
  --timeout SECONDS    Per-request timeout, default 1.5 (INK_SNMP_TIMEOUT)
  --retries N          Retries per request, default 2 (INK_SNMP_RETRIES)
  --model NAME         Model name posted with the levels (INK_PRINTER_MODEL)
  --every SECONDS      Keep running, polling this often; 0 posts once
  --dry-run            Read and print the levels without posting
  --v1                 Use SNMPv1 instead of v2c
  --help               Show this text

Reading needs no password by default on the ET-2926. The bridge only ever
sends SNMP GET/GETNEXT, so the printer is never changed.
TXT;
}

/**
 * Read the four tank percentages from the printer.
 *
 * @return array{black:?int,cyan:?int,magenta:?int,yellow:?int}
 */
function ink_bridge_read(array $options): array
{
    $host      = (string) $options['host'];
    $port      = (int) $options['port'];
    $community = (string) $options['community'];
    $timeout   = max(0.2, (float) $options['timeout']);
    $retries   = max(0, (int) $options['retries']);
    $version   = (int) $options['version'];

    $descriptionRows = snmp_walk($host, $port, $community, INK_BRIDGE_OID_SUPPLIES_DESCRIPTION, $timeout, $retries, $version);
    $levelRows       = snmp_walk($host, $port, $community, INK_BRIDGE_OID_SUPPLIES_LEVEL, $timeout, $retries, $version);
    $maxRows         = snmp_walk($host, $port, $community, INK_BRIDGE_OID_SUPPLIES_MAX, $timeout, $retries, $version);

    if ($levelRows === []) {
        throw new RuntimeException(
            'the printer answered but published no prtMarkerSuppliesLevel values'
            . ' (is SNMP enabled, and is the community "' . $community . '" correct?)'
        );
    }

    $descriptions = [];
    foreach ($descriptionRows as $oid => $value) {
        $descriptions[snmp_oid_index(INK_BRIDGE_OID_SUPPLIES_DESCRIPTION, (string) $oid)] = is_string($value) ? $value : '';
    }

    $levels = [];
    foreach ($levelRows as $oid => $value) {
        $levels[snmp_oid_index(INK_BRIDGE_OID_SUPPLIES_LEVEL, (string) $oid)] = is_int($value) ? $value : null;
    }

    $maxima = [];
    foreach ($maxRows as $oid => $value) {
        $maxima[snmp_oid_index(INK_BRIDGE_OID_SUPPLIES_MAX, (string) $oid)] = is_int($value) ? $value : null;
    }

    $indexes = array_keys($levels);
    $indexes = array_map('strval', $indexes);
    usort($indexes, static fn(string $a, string $b): int => snmp_oid_compare($a, $b));

    // Find each tank by its description ("Black ink", "Cyan ink", …), which
    // survives a model that numbers its supplies differently. If the printer
    // publishes no usable descriptions, the first four supplies are taken in
    // the usual black, cyan, magenta, yellow order.
    $map = [];

    foreach ($indexes as $index) {
        $text = strtolower($descriptions[$index] ?? '');

        foreach (INK_BRIDGE_COLOURS as $colour) {
            if (str_contains($text, $colour) && !isset($map[$colour])) {
                $map[$colour] = $index;
                break;
            }
        }
    }

    if ($map === []) {
        foreach (array_slice($indexes, 0, count(INK_BRIDGE_COLOURS)) as $position => $index) {
            $map[INK_BRIDGE_COLOURS[$position]] = $index;
        }
    }

    $reading = [];

    foreach (INK_BRIDGE_COLOURS as $colour) {
        $index        = $map[$colour] ?? null;
        $reading[$colour] = $index === null
            ? null
            : snmp_level_percent($levels[$index] ?? null, $maxima[$index] ?? null);
    }

    if (array_filter($reading, static fn(?int $value): bool => $value !== null) === []) {
        throw new RuntimeException('the printer reported no usable tank levels (all values were unknown).');
    }

    return $reading;
}

/**
 * POST the reading to the site.
 *
 * @return array{ok:bool,status:int,body:string,error:string}
 */
function ink_bridge_post(string $url, string $token, array $payload, float $timeout = 15.0): array
{
    $body    = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
        'User-Agent: PrintFree-ink-bridge/1.0',
    ];

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => (int) max(1, ceil($timeout)),
            CURLOPT_TIMEOUT        => (int) max(1, ceil($timeout)),
        ]);

        $response = curl_exec($curl);
        $status   = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error    = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => $error];
        }

        return [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'body'   => (string) $response,
            'error'  => '',
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    $status   = 0;

    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match)) {
            $status = (int) $match[1];
        }
    }

    if ($response === false) {
        $last = error_get_last();

        return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $last['message'] ?? 'request failed'];
    }

    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string) $response, 'error' => ''];
}

function ink_bridge_line(string $message): void
{
    fwrite(STDOUT, date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL);
}

function ink_bridge_run(array $options): int
{
    try {
        $reading = ink_bridge_read($options);
    } catch (RuntimeException $e) {
        fwrite(STDERR, date('Y-m-d H:i:s') . ' SNMP failed: ' . $e->getMessage() . PHP_EOL);

        return 1;
    }

    $parts = [];

    foreach (INK_BRIDGE_COLOURS as $colour) {
        $parts[] = $colour . ' ' . ($reading[$colour] === null ? '—' : $reading[$colour] . '%');
    }

    $summary = (string) $options['model'] . ' at ' . $options['host'] . ': ' . implode(' · ', $parts);

    if ((bool) $options['dry_run']) {
        ink_bridge_line($summary);
        ink_bridge_line('Payload: ' . json_encode($reading, JSON_UNESCAPED_SLASHES));

        return 0;
    }

    $payload = $reading + [
        'model'  => (string) $options['model'],
        'source' => 'snmp',
    ];

    $result = ink_bridge_post((string) $options['url'], (string) $options['token'], $payload);

    if (!$result['ok']) {
        fwrite(STDERR, date('Y-m-d H:i:s') . ' Posting failed'
            . ($result['status'] > 0 ? ' (HTTP ' . $result['status'] . ')' : '')
            . ': ' . ($result['error'] !== '' ? $result['error'] : $result['body']) . PHP_EOL);

        return 2;
    }

    $reply = json_decode($result['body'], true);
    $at    = is_array($reply) && isset($reply['received_at']) ? (string) $reply['received_at'] : 'accepted';

    ink_bridge_line($summary);
    ink_bridge_line('Posted to ' . $options['url'] . ' — ' . $at . '.');

    return 0;
}

$options = ink_bridge_options($argv);

if ((bool) $options['help']) {
    fwrite(STDOUT, ink_bridge_usage() . PHP_EOL);
    exit(0);
}

if ((string) $options['host'] === '') {
    fwrite(STDERR, 'Set the printer address with --host or INK_PRINTER_HOST.' . PHP_EOL);
    exit(2);
}

if (!(bool) $options['dry_run']) {
    if ((string) $options['url'] === '' || (string) $options['token'] === '') {
        fwrite(STDERR, 'Set --url and --token (or INK_FEED_URL and INK_FEED_TOKEN), or use --dry-run.' . PHP_EOL);
        exit(2);
    }

    if (!preg_match('#^https?://#i', (string) $options['url'])) {
        fwrite(STDERR, 'The feed address must start with http:// or https://.' . PHP_EOL);
        exit(2);
    }
}

$exit = 0;

while (true) {
    $exit = ink_bridge_run($options);

    if ((int) $options['every'] <= 0) {
        break;
    }

    sleep(max(10, (int) $options['every']));
}

exit($exit);

<?php
declare(strict_types=1);

/**
 * Ink levels posted by the shop-network SNMP bridge.
 *
 * Epson Connect API v2 has no tank data, so the ET-2926's own Printer MIB is
 * read by tools/ink-bridge.php on a machine beside the printer and posted to
 * public/printer-ink.php. This file holds the feed's validation, the reading
 * shown on the cards, and the bridge token.
 */

const INK_COLOURS = ['black', 'cyan', 'magenta', 'yellow'];

/**
 * Validate a posted reading.
 *
 * Every colour is optional, but at least one must carry a number, so a bridge
 * that cannot read a tank can still post the others.
 *
 * @return array{ok:bool,error:string,levels:array<string,int|null>,model:string,source:string}
 */
function ink_feed_normalise(array $payload): array
{
    $levels = [];

    foreach (INK_COLOURS as $colour) {
        if (!array_key_exists($colour, $payload)) {
            continue;
        }

        $raw = $payload[$colour];

        if ($raw === null || $raw === '') {
            $levels[$colour] = null;
            continue;
        }

        if (!is_int($raw) && !is_float($raw) && !(is_string($raw) && is_numeric($raw))) {
            return ['ok' => false, 'error' => ucfirst($colour) . ' must be a number from 0 to 100.', 'levels' => [], 'model' => '', 'source' => ''];
        }

        $value = (int) round((float) $raw);

        if ($value < 0 || $value > 100) {
            return ['ok' => false, 'error' => ucfirst($colour) . ' must be between 0 and 100.', 'levels' => [], 'model' => '', 'source' => ''];
        }

        $levels[$colour] = $value;
    }

    if ($levels === []) {
        return [
            'ok'     => false,
            'error'  => 'No ink levels in the request. Send black, cyan, magenta and yellow as percentages.',
            'levels' => [],
            'model'  => '',
            'source' => '',
        ];
    }

    if (array_filter($levels, static fn(?int $value): bool => $value !== null) === []) {
        return ['ok' => false, 'error' => 'At least one ink level must be a number.', 'levels' => [], 'model' => '', 'source' => ''];
    }

    $model  = preg_replace('/[\x00-\x1F\x7F]/', '', (string) ($payload['model'] ?? '')) ?? '';
    $source = preg_replace('/[^a-z0-9_-]/i', '', (string) ($payload['source'] ?? 'snmp')) ?? 'snmp';

    return [
        'ok'     => true,
        'error'  => '',
        'levels' => $levels,
        'model'  => mb_substr(trim($model), 0, 80),
        'source' => $source !== '' ? $source : 'snmp',
    ];
}

/**
 * Store a normalised reading, replacing the previous one.
 *
 * @return array{levels:array<string,int|null>,model:string,source:string,received_at:string}
 */
function ink_feed_store(array $reading): array
{
    $stored = [
        'levels'      => $reading['levels'],
        'model'       => (string) ($reading['model'] ?? ''),
        'source'      => (string) ($reading['source'] ?? 'snmp'),
        'received_at' => now(),
    ];

    set_setting('printer_ink_levels', json_encode($stored, JSON_UNESCAPED_SLASHES));

    return $stored;
}

/**
 * The last reading, with its age and whether it is too old to trust.
 *
 * @return array{levels:array<string,int>,model:string,source:string,received_at:string,age:?int,stale:bool,has_readings:bool,lowest:?int,lowest_colour:string}
 */
function printer_ink_reading(): array
{
    $reading = [
        'levels'        => [],
        'model'         => '',
        'source'        => '',
        'received_at'   => '',
        'age'           => null,
        'stale'         => true,
        'has_readings'  => false,
        'lowest'        => null,
        'lowest_colour' => '',
    ];

    $stored = json_decode((string) setting('printer_ink_levels', ''), true);

    if (!is_array($stored) || !is_array($stored['levels'] ?? null)) {
        return $reading;
    }

    $levels = [];

    foreach (INK_COLOURS as $colour) {
        $value = $stored['levels'][$colour] ?? null;

        if ($value !== null && is_numeric($value)) {
            $levels[$colour] = max(0, min(100, (int) $value));
        }
    }

    if ($levels === []) {
        return $reading;
    }

    $reading['levels']  = $levels;
    $reading['model']   = (string) ($stored['model'] ?? '');
    $reading['source']  = (string) ($stored['source'] ?? 'snmp');
    $receivedAt         = (string) ($stored['received_at'] ?? '');
    $timestamp          = $receivedAt !== '' ? strtotime($receivedAt) : false;

    if ($timestamp !== false) {
        $reading['received_at'] = $receivedAt;
        $reading['age']         = max(0, time() - $timestamp);
        $reading['stale']       = $reading['age'] > PRINTER_INK_STALE_MINUTES * 60;
    }

    $lowest  = min($levels);
    $colour  = array_search($lowest, $levels, true);

    $reading['has_readings']  = true;
    $reading['lowest']        = $lowest;
    $reading['lowest_colour'] = is_string($colour) ? $colour : '';

    return $reading;
}

/* -------------------------------------------------------------------------
 |  Bridge token
 * ---------------------------------------------------------------------- */

function ink_feed_has_token(): bool
{
    return (string) setting('ink_token_hash', '') !== '';
}

/** Create a token, store only its hash, and return the token once. */
function ink_feed_new_token(): string
{
    $token = bin2hex(random_bytes(24));

    set_setting('ink_token_hash', hash('sha256', $token));
    set_setting('ink_token_created_at', now());

    return $token;
}

function ink_feed_token_check(string $provided): bool
{
    $hash = (string) setting('ink_token_hash', '');

    if ($hash === '' || $provided === '') {
        return false;
    }

    return hash_equals($hash, hash('sha256', $provided));
}

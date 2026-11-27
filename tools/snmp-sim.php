<?php
declare(strict_types=1);

/**
 * Local SNMP agent for testing the ink bridge without a printer.
 *
 * It answers GET and GETNEXT for the Printer MIB supply subtree with the values
 * given on the command line, so the bridge and the feed can be exercised on a
 * machine that cannot reach the ET-2926. CLI only; never run it on the site.
 *
 *   php tools/snmp-sim.php --port 1161 --levels 51,30,12,3 --max 60
 *   php tools/ink-bridge.php --host 127.0.0.1 --port 1161 --dry-run
 *
 * --levels are the raw prtMarkerSuppliesLevel units (black, cyan, magenta,
 * yellow), so --levels 51,30,12,3 --max 60 reads as 85%, 50%, 20%, 5%. A
 * negative value is passed through as-is, which is how a printer says
 * "unknown" (-2) or "some remaining" (-3).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/snmp.php';

$options = [
    'bind'    => '127.0.0.1',
    'port'    => 1161,
    'levels'  => '82,74,61,55',
    'max'     => 100,
    'seconds' => 0,
];

for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];

    if (!str_starts_with($arg, '--')) {
        continue;
    }

    $pair   = explode('=', substr($arg, 2), 2);
    $key    = $pair[0];
    $inline = count($pair) > 1;

    if ($inline) {
        $value = $pair[1];
    } else {
        $value = $argv[$i + 1] ?? '';
        $i++;
    }

    switch ($key) {
        case 'bind':
            $options['bind'] = trim($value);
            break;
        case 'port':
        case 'max':
        case 'seconds':
            $options[$key] = (int) $value;
            break;
        case 'levels':
            $options['levels'] = trim($value);
            break;
        default:
            fwrite(STDERR, 'Unknown option --' . $key . '.' . PHP_EOL);
            exit(2);
    }
}

$levels = array_map('intval', array_pad(explode(',', (string) $options['levels']), 4, 0));
$levels = array_slice($levels, 0, 4);
$max    = max(1, (int) $options['max']);

$base   = '1.3.6.1.2.1.43.11.1.1';
$names  = ['Black ink', 'Cyan ink', 'Magenta ink', 'Yellow ink'];
$table  = [];

foreach ($names as $position => $name) {
    $index = $position + 1;

    $table[$base . '.6.1.' . $index] = ['value' => $name, 'tag' => SNMP_TAG_OCTET];
    $table[$base . '.8.1.' . $index] = ['value' => $max, 'tag' => SNMP_TAG_INTEGER];
    $table[$base . '.9.1.' . $index] = ['value' => $levels[$position], 'tag' => SNMP_TAG_INTEGER];
}

$oids = array_keys($table);
usort($oids, 'snmp_oid_compare');

$errno  = 0;
$errstr = '';
$server = @stream_socket_server('udp://' . $options['bind'] . ':' . $options['port'], $errno, $errstr, STREAM_SERVER_BIND);

if ($server === false) {
    fwrite(STDERR, 'snmp-sim: cannot listen on udp://' . $options['bind'] . ':' . $options['port'] . ': ' . $errstr . PHP_EOL);
    exit(2);
}

$percent = [];
foreach ($levels as $position => $level) {
    $percent[] = $names[$position] . ' ' . ($level < 0 ? '—' : (int) round($level * 100 / $max) . '%');
}

fwrite(STDOUT, 'snmp-sim: listening on udp://' . $options['bind'] . ':' . $options['port']
    . ' · ' . implode(' · ', $percent) . ' (max ' . $max . ')' . PHP_EOL);

$started  = microtime(true);
$answered = 0;

while (true) {
    if ((int) $options['seconds'] > 0 && (microtime(true) - $started) >= (int) $options['seconds']) {
        break;
    }

    $read   = [$server];
    $write  = null;
    $except = null;

    if (@stream_select($read, $write, $except, 1) < 1) {
        continue;
    }

    $peer   = '';
    $packet = @stream_socket_recvfrom($server, 65535, 0, $peer);

    if (!is_string($packet) || $packet === '') {
        continue;
    }

    try {
        $request = snmp_parse($packet);
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'snmp-sim: unreadable request: ' . $e->getMessage() . PHP_EOL);
        continue;
    }

    $oid   = $request['oid'];
    $tag   = SNMP_TAG_NO_SUCH_OBJECT;
    $value = null;

    if ($request['pdu_tag'] === SNMP_PDU_GET) {
        if (isset($table[$oid])) {
            $tag   = $table[$oid]['tag'];
            $value = $table[$oid]['value'];
        }
    } elseif ($request['pdu_tag'] === SNMP_PDU_GETNEXT) {
        $next = null;

        foreach ($oids as $candidate) {
            if (snmp_oid_compare($candidate, $oid) > 0) {
                $next = $candidate;
                break;
            }
        }

        if ($next === null) {
            $tag = SNMP_TAG_END_OF_MIB;
        } else {
            $oid   = $next;
            $tag   = $table[$next]['tag'];
            $value = $table[$next]['value'];
        }
    } else {
        continue;
    }

    $reply = snmp_build_response(
        (string) $request['community'],
        (int) $request['request_id'],
        $oid,
        $value,
        $tag,
        (int) $request['version']
    );

    @stream_socket_sendto($server, $reply, 0, $peer);
    $answered++;
}

fclose($server);

fwrite(STDOUT, 'snmp-sim: stopped after answering ' . $answered . ' request' . ($answered === 1 ? '' : 's') . '.' . PHP_EOL);

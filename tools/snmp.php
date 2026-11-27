<?php
declare(strict_types=1);

/**
 * A very small SNMPv1/v2c GET / GETNEXT codec and client.
 *
 * The ink bridge has to run on whatever small box sits on the shop network, so
 * it brings its own BER codec instead of requiring the php-snmp extension or
 * the net-snmp command-line tools. Only reads are implemented, which is all a
 * tank-level poll needs.
 *
 * OIDs are handled as dotted strings ("1.3.6.1.2.1.43.11.1.1.9.1.1").
 */

const SNMP_VERSION_1  = 0;
const SNMP_VERSION_2C = 1;

const SNMP_PDU_GET      = 0xA0;
const SNMP_PDU_GETNEXT  = 0xA1;
const SNMP_PDU_RESPONSE = 0xA2;

const SNMP_TAG_INTEGER          = 0x02;
const SNMP_TAG_OCTET            = 0x04;
const SNMP_TAG_NULL             = 0x05;
const SNMP_TAG_OID              = 0x06;
const SNMP_TAG_SEQUENCE         = 0x30;
const SNMP_TAG_NO_SUCH_OBJECT   = 0x80;
const SNMP_TAG_NO_SUCH_INSTANCE = 0x81;
const SNMP_TAG_END_OF_MIB       = 0x82;

/* -------------------------------------------------------------------------
 |  BER encoding
 * ---------------------------------------------------------------------- */

function snmp_ber_length(int $length): string
{
    if ($length < 0x80) {
        return chr($length);
    }

    $bytes = ltrim(pack('N', $length), "\x00");

    return chr(0x80 | strlen($bytes)) . $bytes;
}

function snmp_ber_tlv(int $tag, string $content): string
{
    return chr($tag) . snmp_ber_length(strlen($content)) . $content;
}

function snmp_ber_integer(int $value): string
{
    if ($value === 0) {
        return snmp_ber_tlv(SNMP_TAG_INTEGER, "\x00");
    }

    $negative = $value < 0;
    // Two's complement in the smallest number of bytes that keeps the sign.
    $bytes = '';
    if ($negative) {
        $remaining = $value;
        while ($remaining !== -1 || $bytes === '') {
            $bytes = chr($remaining & 0xFF) . $bytes;
            $remaining >>= 8;
            if ($remaining === -1 && $bytes !== '' && (ord($bytes[0]) & 0x80)) {
                break;
            }
        }
    } else {
        $remaining = $value;
        while ($remaining > 0) {
            $bytes = chr($remaining & 0xFF) . $bytes;
            $remaining >>= 8;
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
    }

    return snmp_ber_tlv(SNMP_TAG_INTEGER, $bytes);
}

function snmp_ber_oid(string $oid): string
{
    $arcs = array_map('intval', explode('.', trim($oid, '.')));

    if (count($arcs) < 2) {
        throw new RuntimeException('SNMP: an OID needs at least two arcs, got "' . $oid . '".');
    }

    $body = chr($arcs[0] * 40 + $arcs[1]);

    foreach (array_slice($arcs, 2) as $arc) {
        $stack = [chr($arc & 0x7F)];
        $arc >>= 7;

        while ($arc > 0) {
            array_unshift($stack, chr(0x80 | ($arc & 0x7F)));
            $arc >>= 7;
        }

        $body .= implode('', $stack);
    }

    return snmp_ber_tlv(SNMP_TAG_OID, $body);
}

/* -------------------------------------------------------------------------
 |  BER decoding
 * ---------------------------------------------------------------------- */

/**
 * Read one TLV and advance $offset.
 *
 * @return array{0:int,1:string} tag, content
 */
function snmp_ber_read(string $buffer, int &$offset): array
{
    $size = strlen($buffer);

    if ($offset + 2 > $size) {
        throw new RuntimeException('SNMP: truncated BER value.');
    }

    $tag    = ord($buffer[$offset++]);
    $length = ord($buffer[$offset++]);

    if (($length & 0x80) !== 0) {
        $count = $length & 0x7F;

        if ($count === 0 || $offset + $count > $size) {
            throw new RuntimeException('SNMP: invalid BER length.');
        }

        $length = 0;

        for ($i = 0; $i < $count; $i++) {
            $length = ($length << 8) | ord($buffer[$offset++]);
        }
    }

    if ($offset + $length > $size) {
        throw new RuntimeException('SNMP: BER value runs past the packet.');
    }

    $content = substr($buffer, $offset, $length);
    $offset += $length;

    return [$tag, $content];
}

function snmp_ber_integer_value(string $content): int
{
    $value  = 0;
    $length = strlen($content);

    for ($i = 0; $i < $length; $i++) {
        $value = ($value << 8) | ord($content[$i]);
    }

    if ($length > 0 && (ord($content[0]) & 0x80) !== 0) {
        $value -= 1 << ($length * 8);
    }

    return $value;
}

function snmp_ber_oid_value(string $content): string
{
    if ($content === '') {
        return '';
    }

    $first = ord($content[0]);
    $arcs  = [intdiv($first, 40), $first % 40];
    $value = 0;
    $length = strlen($content);

    for ($i = 1; $i < $length; $i++) {
        $byte   = ord($content[$i]);
        $value  = ($value << 7) | ($byte & 0x7F);

        if (($byte & 0x80) === 0) {
            $arcs[] = $value;
            $value  = 0;
        }
    }

    return implode('.', $arcs);
}

/* -------------------------------------------------------------------------
 |  Messages
 * ---------------------------------------------------------------------- */

function snmp_build_request(string $community, int $pduTag, string $oid, int $requestId, int $version = SNMP_VERSION_2C): string
{
    $varbind = snmp_ber_tlv(SNMP_TAG_SEQUENCE, snmp_ber_oid($oid) . snmp_ber_tlv(SNMP_TAG_NULL, ''));
    $pdu     = snmp_ber_tlv(
        $pduTag,
        snmp_ber_integer($requestId)
        . snmp_ber_integer(0)
        . snmp_ber_integer(0)
        . snmp_ber_tlv(SNMP_TAG_SEQUENCE, $varbind)
    );

    return snmp_ber_tlv(
        SNMP_TAG_SEQUENCE,
        snmp_ber_integer($version) . snmp_ber_tlv(SNMP_TAG_OCTET, $community) . $pdu
    );
}

function snmp_build_response(
    string $community,
    int $requestId,
    string $oid,
    mixed $value,
    int $valueTag,
    int $version = SNMP_VERSION_2C,
    int $errorStatus = 0,
    int $errorIndex = 0
): string {
    $encoded = match ($valueTag) {
        SNMP_TAG_INTEGER => snmp_ber_integer((int) $value),
        SNMP_TAG_OCTET   => snmp_ber_tlv(SNMP_TAG_OCTET, (string) $value),
        default          => snmp_ber_tlv($valueTag, ''),
    };

    $varbind = snmp_ber_tlv(SNMP_TAG_SEQUENCE, snmp_ber_oid($oid) . $encoded);
    $pdu     = snmp_ber_tlv(
        SNMP_PDU_RESPONSE,
        snmp_ber_integer($requestId)
        . snmp_ber_integer($errorStatus)
        . snmp_ber_integer($errorIndex)
        . snmp_ber_tlv(SNMP_TAG_SEQUENCE, $varbind)
    );

    return snmp_ber_tlv(
        SNMP_TAG_SEQUENCE,
        snmp_ber_integer($version) . snmp_ber_tlv(SNMP_TAG_OCTET, $community) . $pdu
    );
}

/**
 * Parse any SNMP message (request or response).
 *
 * @return array{version:int,community:string,pdu_tag:int,request_id:int,error_status:int,error_index:int,oid:string,value:mixed,value_tag:int}
 */
function snmp_parse(string $packet): array
{
    $offset = 0;
    [$tag, $message] = snmp_ber_read($packet, $offset);

    if ($tag !== SNMP_TAG_SEQUENCE) {
        throw new RuntimeException('SNMP: the message is not a sequence.');
    }

    $pos = 0;
    [$tag, $versionBytes] = snmp_ber_read($message, $pos);
    $version = snmp_ber_integer_value($versionBytes);

    [$tag, $community] = snmp_ber_read($message, $pos);
    if ($tag !== SNMP_TAG_OCTET) {
        throw new RuntimeException('SNMP: the community string is missing.');
    }

    [$pduTag, $pdu] = snmp_ber_read($message, $pos);

    $pos = 0;
    [, $requestIdBytes] = snmp_ber_read($pdu, $pos);
    [, $errorStatusBytes] = snmp_ber_read($pdu, $pos);
    [, $errorIndexBytes] = snmp_ber_read($pdu, $pos);
    [, $varbindList] = snmp_ber_read($pdu, $pos);

    $oid      = '';
    $value    = null;
    $valueTag = SNMP_TAG_NULL;
    $listPos  = 0;

    if ($varbindList !== '') {
        [, $varbind] = snmp_ber_read($varbindList, $listPos);
        $varbindPos  = 0;
        [, $oidBytes] = snmp_ber_read($varbind, $varbindPos);
        $oid = snmp_ber_oid_value($oidBytes);

        [$valueTag, $valueBytes] = snmp_ber_read($varbind, $varbindPos);

        $value = match (true) {
            $valueTag === SNMP_TAG_INTEGER,
            $valueTag >= 0x40 && $valueTag <= 0x46 => snmp_ber_integer_value($valueBytes),
            $valueTag === SNMP_TAG_OCTET            => $valueBytes,
            default                                 => null,
        };
    }

    return [
        'version'      => $version,
        'community'    => $community,
        'pdu_tag'      => $pduTag,
        'request_id'   => snmp_ber_integer_value($requestIdBytes),
        'error_status' => snmp_ber_integer_value($errorStatusBytes),
        'error_index'  => snmp_ber_integer_value($errorIndexBytes),
        'oid'          => $oid,
        'value'        => $value,
        'value_tag'    => $valueTag,
    ];
}

/* -------------------------------------------------------------------------
 |  Client
 * ---------------------------------------------------------------------- */

/**
 * One SNMP exchange over UDP, with retries on a lost datagram.
 *
 * @return array{version:int,community:string,pdu_tag:int,request_id:int,error_status:int,error_index:int,oid:string,value:mixed,value_tag:int}
 */
function snmp_exchange(
    string $host,
    int $port,
    string $community,
    int $pduTag,
    string $oid,
    float $timeout = 1.5,
    int $retries = 2,
    int $version = SNMP_VERSION_2C
): array {
    $lastError = 'no answer';

    for ($attempt = 0; $attempt <= $retries; $attempt++) {
        $errno   = 0;
        $errstr  = '';
        $socket  = @stream_socket_client('udp://' . $host . ':' . $port, $errno, $errstr, $timeout);

        if ($socket === false) {
            $lastError = 'cannot open UDP ' . $host . ':' . $port . ' (' . ($errstr !== '' ? $errstr : 'errno ' . $errno) . ')';
            continue;
        }

        $requestId = random_int(1, 0x7FFFFFFF);
        $packet    = snmp_build_request($community, $pduTag, $oid, $requestId, $version);

        if (@fwrite($socket, $packet) === false) {
            $lastError = 'could not send the SNMP request';
            fclose($socket);
            continue;
        }

        $seconds = (int) floor($timeout);
        $micros  = (int) round(($timeout - $seconds) * 1000000);
        stream_set_timeout($socket, $seconds, $micros);

        $response = fread($socket, 65535);
        fclose($socket);

        if (!is_string($response) || $response === '') {
            $lastError = 'no answer from ' . $host . ':' . $port . ' within ' . $timeout . ' s';
            continue;
        }

        try {
            $parsed = snmp_parse($response);
        } catch (RuntimeException $e) {
            $lastError = $e->getMessage();
            continue;
        }

        if ($parsed['request_id'] !== $requestId) {
            $lastError = 'answer had a different request id';
            continue;
        }

        return $parsed;
    }

    throw new RuntimeException('SNMP: ' . $lastError . '.');
}

/**
 * @return array{oid:string,value:mixed,value_tag:int}
 */
function snmp_get(
    string $host,
    int $port,
    string $community,
    string $oid,
    float $timeout = 1.5,
    int $retries = 2,
    int $version = SNMP_VERSION_2C
): array {
    $response = snmp_exchange($host, $port, $community, SNMP_PDU_GET, $oid, $timeout, $retries, $version);

    if ($response['pdu_tag'] !== SNMP_PDU_RESPONSE) {
        throw new RuntimeException('SNMP: expected a response PDU, got 0x' . dechex($response['pdu_tag']) . '.');
    }

    if ($response['error_status'] !== 0) {
        throw new RuntimeException('SNMP: agent returned error status ' . $response['error_status'] . '.');
    }

    return ['oid' => $response['oid'], 'value' => $response['value'], 'value_tag' => $response['value_tag']];
}

/**
 * Walk a subtree with GETNEXT.
 *
 * @return array<string,mixed> full OID => value
 */
function snmp_walk(
    string $host,
    int $port,
    string $community,
    string $base,
    float $timeout = 1.5,
    int $retries = 2,
    int $version = SNMP_VERSION_2C,
    int $limit = 256
): array {
    $base   = rtrim($base, '.');
    $prefix = $base . '.';
    $rows   = [];
    $current = $base;

    for ($i = 0; $i < $limit; $i++) {
        $response = snmp_exchange($host, $port, $community, SNMP_PDU_GETNEXT, $current, $timeout, $retries, $version);

        if ($response['pdu_tag'] !== SNMP_PDU_RESPONSE || $response['error_status'] !== 0) {
            break;
        }

        $oid = $response['oid'];

        if ($oid === '' || !str_starts_with($oid, $prefix)) {
            break;
        }

        if (in_array($response['value_tag'], [SNMP_TAG_END_OF_MIB, SNMP_TAG_NO_SUCH_OBJECT, SNMP_TAG_NO_SUCH_INSTANCE], true)) {
            break;
        }

        if ($oid === $current) {
            break;
        }

        $rows[$oid] = $response['value'];
        $current    = $oid;
    }

    return $rows;
}

/** The part of an OID after a subtree base, e.g. "1.3" for base ".1" and ".1.1.3". */
function snmp_oid_index(string $base, string $oid): string
{
    $base = rtrim($base, '.');

    return str_starts_with($oid, $base . '.') ? substr($oid, strlen($base) + 1) : $oid;
}

function snmp_oid_compare(string $a, string $b): int
{
    $left  = array_map('intval', explode('.', trim($a, '.')));
    $right = array_map('intval', explode('.', trim($b, '.')));
    $count = max(count($left), count($right));

    for ($i = 0; $i < $count; $i++) {
        $diff = ($left[$i] ?? 0) <=> ($right[$i] ?? 0);

        if ($diff !== 0) {
            return $diff;
        }
    }

    return 0;
}

/**
 * A Printer MIB supply level as a percentage.
 *
 * Level is in the supply's own units, so a level of 27 with a max capacity of
 * 60 is 45%, not 27%. The Printer MIB sentinels for "unknown" (-2) and "some
 * remaining" (-3) have no number to show and become null.
 */
function snmp_level_percent(mixed $level, mixed $max): ?int
{
    if (!is_int($level) || $level < 0) {
        return null;
    }

    if (is_int($max) && $max > 0 && $level <= $max) {
        return max(0, min(100, (int) round($level * 100 / $max)));
    }

    return $level <= 100 ? $level : null;
}

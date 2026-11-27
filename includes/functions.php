<?php
/**
 * Shared helpers: URLs, formatting, settings, uploads, orders and pagination.
 */

declare(strict_types=1);

/* -------------------------------------------------------------------------
 |  Output & URLs
 * ---------------------------------------------------------------------- */

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Web path of public/, e.g. '' when public/ is the document root (Wasmer,
 * Laravel-style hosts) or '/printfree/public' when the project root is.
 *
 * Internal links stay root-relative so the site works over HTTP or HTTPS and
 * inside a subdirectory without mixed-content surprises. Only the Epson
 * redirect URI needs a full URL, and that uses absolute_url().
 */
function public_base_uri(): string
{
    static $base = null;

    if ($base !== null) {
        return $base;
    }

    if (PHP_SAPI === 'cli' || empty($_SERVER['SCRIPT_NAME'])) {
        return $base = '';
    }

    $scriptName = str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']);
    $scriptFile = isset($_SERVER['SCRIPT_FILENAME']) ? realpath($_SERVER['SCRIPT_FILENAME']) : false;
    $publicDir  = realpath(__DIR__ . '/../public');

    // A script inside public/ tells us where that folder is mounted, whether
    // the host serves public/ as its root or the project root above it.
    if ($publicDir !== false && $scriptFile !== false
        && str_starts_with($scriptFile, $publicDir . DIRECTORY_SEPARATOR)) {
        $relative = str_replace('\\', '/', substr($scriptFile, strlen($publicDir) + 1));
        if ($relative !== '' && str_ends_with($scriptName, '/' . $relative)) {
            return $base = rtrim(substr($scriptName, 0, -strlen('/' . $relative)), '/');
        }
    }

    // Root-level entry points (index.php) sit one level above public/.
    $projectDir = realpath(__DIR__ . '/..');
    if ($projectDir !== false && $scriptFile !== false
        && str_starts_with($scriptFile, $projectDir . DIRECTORY_SEPARATOR)) {
        $relative = str_replace('\\', '/', substr($scriptFile, strlen($projectDir) + 1));
        if ($relative !== '' && str_ends_with($scriptName, '/' . $relative)) {
            $prefix = rtrim(substr($scriptName, 0, -strlen('/' . $relative)), '/');

            return $base = $prefix . '/public';
        }
    }

    return $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
}

/**
 * Build an internal URL. Paths are relative to public/, which is the web root.
 * A legacy 'public/' prefix is accepted and removed.
 */
function url(string $path = ''): string
{
    $base = public_base_uri();
    $path = ltrim($path, '/');

    if (str_starts_with($path, 'public/')) {
        $path = substr($path, 7);
    }

    if ($path === '') {
        return $base === '' ? '/' : $base . '/';
    }

    return $base . '/' . $path;
}

function absolute_url(string $path = ''): string
{
    if (APP_URL !== '') {
        return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return ($https ? 'https' : 'http') . '://' . $host . url($path);
}

function asset(string $path): string
{
    $url = url(ltrim($path, '/'));
    $version = defined('APP_VERSION') ? APP_VERSION : '1';

    return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . rawurlencode($version);
}

function redirect(string $path): never
{
    header('Location: ' . (preg_match('#^https?://#i', $path) ? $path : url($path)));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

/* -------------------------------------------------------------------------
 |  Settings
 * ---------------------------------------------------------------------- */

function settings_all(bool $refresh = false): array
{
    static $cache = null;

    if ($cache === null || $refresh) {
        $cache = [];
        foreach (db_all('SELECT setting_key, setting_value FROM settings') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }

    return $cache;
}

function setting(string $key, ?string $default = null): ?string
{
    $value = settings_all()[$key] ?? null;

    return ($value === null || $value === '') ? $default : $value;
}

function set_setting(string $key, ?string $value): void
{
    $exists = (int) db_val('SELECT COUNT(*) FROM settings WHERE setting_key = ?', [$key]);

    if ($exists > 0) {
        db_query('UPDATE settings SET setting_value = ? WHERE setting_key = ?', [$value, $key]);
    } else {
        db_query('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)', [$key, $value]);
    }

    settings_all(true);
}

/**
 * Delete settings left behind by features that no longer exist.
 *
 * The relay, the heartbeat ping, Epson job notifications and the ready email
 * were removed in 1.5.0, but their rows are still in the settings table on an
 * existing install. Nothing reads them any more, yet they are still there for
 * anything that lists settings, so they are deleted once, guarded by a marker
 * that stops the scan running on every request.
 *
 * A backup that never produced a file predates folder backups: its note talks
 * about the relay and email, so it is dropped too rather than shown again.
 */
function settings_prune_removed_features(): void
{
    if (setting('settings_pruned', '') === '1.5') {
        return;
    }

    $prefixes = ['epson_notify_', 'monitor_', 'relay_', 'order_ready_email_'];
    $exact    = ['customer_emails_enabled', 'backup_last_photos', 'backup_last_where'];
    $drop     = [];

    try {
        $keys = array_map(
            static fn(array $row): string => (string) $row['setting_key'],
            db_all('SELECT setting_key FROM settings')
        );

        foreach ($keys as $key) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $drop[] = $key;
                    continue 2;
                }
            }

            if (in_array($key, $exact, true)) {
                $drop[] = $key;
            }
        }

        if ((string) setting('backup_last_file', '') === '') {
            foreach ($keys as $key) {
                if (str_starts_with($key, 'backup_last_')) {
                    $drop[] = $key;
                }
            }
        }

        foreach (array_unique($drop) as $key) {
            db_query('DELETE FROM settings WHERE setting_key = ?', [$key]);
        }

        set_setting('settings_pruned', '1.5');
    } catch (Throwable $e) {
        // A failed prune must never take a page down; it is retried next request.
        error_log('[PrintFree] Could not prune retired settings: ' . $e->getMessage());
    }

    settings_all(true);
}

/**
 * Delete the settings left behind by the retired SNMP ink monitor.
 *
 * The bridge, its token and the posted levels are gone; on an existing install
 * their rows would otherwise sit in the settings table for ever.
 */
function settings_prune_ink_monitor(): void
{
    if (setting('settings_pruned_ink', '') === '1') {
        return;
    }

    try {
        foreach (['ink_token_hash', 'ink_token_created_at', 'printer_ink_levels'] as $key) {
            db_query('DELETE FROM settings WHERE setting_key = ?', [$key]);
        }

        set_setting('settings_pruned_ink', '1');
    } catch (Throwable $e) {
        // A failed prune must never take a page down; it is retried next request.
        error_log('[PrintFree] Could not prune ink monitor settings: ' . $e->getMessage());
    }

    settings_all(true);
}

function site_name(): string
{
    return setting('site_name', APP_NAME) ?? APP_NAME;
}

/* -------------------------------------------------------------------------
 |  Print-queue monitoring
 * ---------------------------------------------------------------------- */

/**
 * Where queue alerts go. The explicit alert address wins, then the support
 * address, then the first active admin account.
 */
function queue_alert_recipient(): string
{
    foreach ([setting('queue_alert_email', ''), setting('support_email', '')] as $candidate) {
        $candidate = trim((string) $candidate);
        if (filter_var($candidate, FILTER_VALIDATE_EMAIL) && !str_ends_with($candidate, '@printfree.test')) {
            return $candidate;
        }
    }

    $adminEmail = db_val("SELECT email FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id ASC LIMIT 1");

    return is_string($adminEmail) && filter_var($adminEmail, FILTER_VALIDATE_EMAIL) ? $adminEmail : '';
}

/**
 * Send a plain-text operational alert through the host's mail transport.
 * The watchdog prints the same information, so a cPanel cron email can act as
 * a fallback if mail() is unavailable.
 */
function send_queue_alert(string $subject, string $body): bool
{
    $to = queue_alert_recipient();
    if ($to === '') {
        error_log('[PrintFree] Queue alert recipient is not configured.');
        return false;
    }

    $support = trim((string) setting('support_email', ''));
    if (!filter_var($support, FILTER_VALIDATE_EMAIL) || str_ends_with($support, '@printfree.test')) {
        $support = $to;
    }
    $domain = str_contains($support, '@') ? substr(strrchr($support, '@'), 1) : 'localhost';
    $from   = 'no-reply@' . ($domain !== '' ? $domain : 'localhost');

    $siteName = str_replace(["\r", "\n"], ' ', site_name());
    $headers  = implode("\r\n", [
        'From: ' . $siteName . ' <' . $from . '>',
        'Reply-To: ' . $to,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . phpversion(),
    ]);

    $sent = @mail($to, '[' . $siteName . '] ' . $subject, $body, $headers);

    if (!$sent) {
        error_log('[PrintFree] Could not send queue alert to ' . $to . '.');
    }

    return $sent;
}

/* -------------------------------------------------------------------------
 |  Print-time estimates
 * ---------------------------------------------------------------------- */

/**
 * Seconds one sheet takes, measured from the last week of completed photos.
 *
 * Cached for ten minutes so a page load does not run the aggregate query, and
 * clamped so one odd job cannot turn an estimate absurd.
 */
function sheet_seconds_estimate(): int
{
    $cached = (int) setting('print_sheet_seconds', '0');
    $stamp  = strtotime((string) setting('print_sheet_seconds_at', '')) ?: 0;

    if ($cached >= 60 && $cached <= 600 && (time() - $stamp) < 600) {
        return $cached;
    }

    if (!print_started_column_ready()) {
        return $cached >= 60 && $cached <= 600 ? $cached : PRINT_ESTIMATE_SECONDS;
    }

    $average = db_val(
        'SELECT AVG(TIMESTAMPDIFF(SECOND, print_started_at, printed_at))
         FROM order_photos
         WHERE printed_at IS NOT NULL AND print_started_at IS NOT NULL
           AND printed_at >= print_started_at
           AND printed_at > ?',
        [date('Y-m-d H:i:s', time() - 7 * 86400)]
    );
    $average = $average !== null ? (int) round((float) $average) : 0;

    if ($average >= 60 && $average <= 600) {
        set_setting('print_sheet_seconds', (string) $average);
        set_setting('print_sheet_seconds_at', now());

        return $average;
    }

    return $cached >= 60 && $cached <= 600 ? $cached : PRINT_ESTIMATE_SECONDS;
}

/**
 * Seconds of printing left for every order currently in the queue.
 *
 * One query, walked in the worker's own order, so a page can show a finish
 * estimate for each active order without asking Epson anything.
 *
 * @return array<int,int> order id => seconds from now
 */
function queue_estimates(): array
{
    $per         = sheet_seconds_estimate();
    $startedSql  = print_started_column_ready() ? 'p.print_started_at' : 'NULL AS print_started_at';
    $kindSql     = order_photo_kind_column_ready() ? " AND p.kind = 'photo'" : '';
    $queueOrder  = accepted_at_column_ready()
        ? 'COALESCE(o.accepted_at, o.created_at) ASC, o.id ASC'
        : 'o.created_at ASC, o.id ASC';

    $rows = db_all(
        "SELECT p.id, p.order_id, p.status, {$startedSql}
         FROM order_photos p
         JOIN orders o ON o.id = p.order_id
         WHERE o.status IN ('accepted', 'printing')
           AND p.status IN ('printing', 'pending'){$kindSql}
         ORDER BY {$queueOrder}, p.position ASC, p.id ASC"
    );

    $clock = microtime(true);
    $out   = [];

    foreach ($rows as $row) {
        $seconds = $per;

        if ($row['status'] === 'printing' && !empty($row['print_started_at'])) {
            $started = strtotime((string) $row['print_started_at']);

            if ($started !== false) {
                $seconds = max(5, $per - (time() - $started));
            }
        }

        $clock += $seconds;
        $out[(int) $row['order_id']] = (int) max(1, round($clock - microtime(true)));
    }

    return $out;
}

/** "About 1 h 20 min" — a rounded span for customers. */
function human_duration(int $seconds): string
{
    $seconds = max(0, $seconds);

    if ($seconds < 60) {
        return 'under a minute';
    }

    $minutes = (int) round($seconds / 60);

    if ($minutes < 60) {
        return $minutes . ' min';
    }

    $hours   = intdiv($minutes, 60);
    $rest    = $minutes % 60;

    return $hours . ' h' . ($rest > 0 ? ' ' . $rest . ' min' : '');
}

/* -------------------------------------------------------------------------
 |  Printer supplies (paper, ink, jams)
 * ---------------------------------------------------------------------- */

/** Friendly name for an Epson stop reason. */
function printer_supply_label(string $reason): string
{
    return match ($reason) {
        'media_empty'         => 'Out of photo paper',
        'media_jam'           => 'Paper jam',
        'marker_supply_empty' => 'Out of ink',
        default               => 'Printer needs attention',
    };
}

/**
 * The stop currently holding the queue, if any.
 *
 * @return array{active:bool,reason:string,label:string,since:string,age:int|null}
 */
function printer_supply_block(): array
{
    $reason = (string) setting('printer_supply_block_reason', '');
    $since  = (string) setting('printer_supply_block_at', '');
    $ts     = $since !== '' ? strtotime($since) : false;

    return [
        'active' => $reason !== '' && $ts !== false,
        'reason' => $reason,
        'label'  => (string) setting('printer_supply_block_label', printer_supply_label($reason)),
        'since'  => $since,
        'age'    => $ts === false ? null : max(0, time() - $ts),
    ];
}

/**
 * The paper stop currently holding the printer, if any.
 *
 * The worker's supply hold is authoritative. A recent Epson reading counts too
 * — a stopped job names the same reason — so the alert appears before the next
 * worker pass. An old cached reading is ignored, so paper that has since been
 * loaded cannot raise a false alarm.
 *
 * @param array<string,mixed>|null $hold    supply hold (read from settings when null)
 * @param array<string,mixed>      $reading cached Epson printer status
 * @return array{reason:string,held:bool,since:string}|null
 */
function printer_paper_stop(?array $hold = null, array $reading = [], int $maxAge = 600): ?array
{
    $hold = $hold ?? printer_supply_block();

    if (!empty($hold['active']) && in_array((string) ($hold['reason'] ?? ''), ['media_empty', 'media_jam'], true)) {
        return [
            'reason' => (string) $hold['reason'],
            'held'   => true,
            'since'  => (string) ($hold['since'] ?? ''),
        ];
    }

    $reason = (string) ($reading['job_reason'] ?? '');

    if (!in_array($reason, ['media_empty', 'media_jam'], true) || ($reading['state'] ?? '') !== 'attention') {
        return null;
    }

    $checkedAt = strtotime((string) ($reading['checked_at'] ?? '')) ?: 0;

    if ($checkedAt === 0 || (time() - $checkedAt) > $maxAge) {
        return null;
    }

    return ['reason' => $reason, 'held' => false, 'since' => ''];
}

/**
 * Record a paper/ink stop and email the operator about it.
 *
 * Sent once when the stop starts, then reminded hourly while it lasts. Returns
 * 'alert', 'reminder' or null when no email was due.
 */
function printer_supply_note_stop(string $reason): ?string
{
    $label  = printer_supply_label($reason);
    $block  = printer_supply_block();
    $health = queue_health();

    $body = implode(PHP_EOL, [
        'PrintFree queue alert',
        '',
        'Issue: ' . $label,
        'Time: ' . format_date(now()),
        'Printer reason: ' . $reason,
        'Queue: ' . $health['queued'] . ' order' . ($health['queued'] === 1 ? '' : 's') . ' waiting',
        'Photos left for the active order: ' . $health['queued'],
        '',
        'Printing has been held so no more photos are sent until the printer is ready.',
        'Clear the alert from the dashboard\'s Maintenance link (or press Resume) once the printer is loaded.',
    ]);

    if (!$block['active']) {
        set_setting('printer_supply_block_reason', $reason);
        set_setting('printer_supply_block_label', $label);
        set_setting('printer_supply_block_at', now());
        set_setting('printer_supply_alerted_at', now());
        send_queue_alert($label . ' — printing is held', $body);

        return 'alert';
    }

    $alertedAt = strtotime((string) setting('printer_supply_alerted_at', '')) ?: 0;

    if ($alertedAt === 0 || (time() - $alertedAt) >= PRINT_SUPPLY_REMINDER_MINUTES * 60) {
        set_setting('printer_supply_alerted_at', now());
        send_queue_alert($label . ' — still holding the queue', $body);

        return 'reminder';
    }

    return null;
}

/** Clear the supply stop so the queue can send photos again. */
function printer_supply_clear(): void
{
    set_setting('printer_supply_block_reason', '');
    set_setting('printer_supply_block_label', '');
    set_setting('printer_supply_block_at', '');
    set_setting('printer_supply_alerted_at', '');
}

/**
 * Record that the worker is alive.
 *
 * Called at the start of every loop iteration and from inside slow Epson
 * transfers, so a hung run stops beating long before its slice deadline. The
 * write is throttled: a fast loop does not need a database write a second.
 */
function print_worker_alive(bool $force = false): void
{
    static $last = 0.0;

    $now = microtime(true);

    if (!$force && ($now - $last) < 5.0) {
        return;
    }

    $last = $now;
    set_setting('print_queue_liveness', now());
}

/**
 * Current worker/queue health, shared by the dashboard and the watchdog.
 *
 * @return array<string, mixed>
 */
/**
 * Epson request counters, used to stop hammering an endpoint that is down.
 *
 * @return array{total:int,failed:int,consecutive:int,circuit_until:int,last_ok_at:string,last_error:string,last_error_at:string}
 */
function epson_request_stats(): array
{
    $stats    = json_decode((string) setting('epson_request_stats', ''), true);
    $defaults = [
        'total'         => 0,
        'failed'        => 0,
        'consecutive'   => 0,
        'circuit_until' => 0,
        'last_ok_at'    => '',
        'last_error'    => '',
        'last_error_at' => '',
    ];

    return is_array($stats) ? array_merge($defaults, $stats) : $defaults;
}

/**
 * Record one Epson HTTP attempt. Two transport failures in a row open a short
 * circuit so the worker fails fast instead of spending minutes in timeouts.
 */
function epson_log_request(array $entry): void
{
    $entry += [
        'at'         => now(),
        'host'       => '',
        'path'       => '',
        'ok'         => false,
        'errno'      => 0,
        'http'       => 0,
        'connect_ms' => 0,
        'total_ms'   => 0,
        'error'      => '',
    ];

    $log = json_decode((string) setting('epson_request_log', ''), true);
    $log = is_array($log) ? $log : [];
    array_unshift($log, $entry);

    set_setting('epson_request_log', json_encode(array_slice($log, 0, EPSON_LOG_LIMIT), JSON_UNESCAPED_SLASHES));

    $stats              = epson_request_stats();
    $stats['total']     = (int) $stats['total'] + 1;

    if (!empty($entry['ok'])) {
        $stats['consecutive']   = 0;
        $stats['circuit_until'] = 0;
        $stats['last_ok_at']    = (string) $entry['at'];

        // Only a queue call proves printing can continue. A settings or token
        // call can succeed during a flapping connection while every job status
        // check still times out, and that must not end the outage.
        $path = (string) ($entry['path'] ?? '');
        if (str_starts_with($path, '/printing/jobs') || str_starts_with($path, '/printing/devices')) {
            epson_outage_note_success();
        }
    } else {
        $stats['failed']        = (int) $stats['failed'] + 1;
        $stats['consecutive']   = (int) $stats['consecutive'] + 1;
        $stats['last_error']    = mb_substr((string) $entry['error'], 0, 200);
        $stats['last_error_at'] = (string) $entry['at'];

        if ((int) $stats['consecutive'] >= EPSON_CIRCUIT_THRESHOLD) {
            $stats['circuit_until'] = time() + EPSON_CIRCUIT_SECONDS;
        }

        // A connection that never opened is an outage; an HTTP rejection is not.
        if ((int) ($entry['errno'] ?? 0) !== 0) {
            epson_outage_note_failure('cURL ' . (int) $entry['errno'] . ': ' . (string) $entry['error']);
        }
    }

    set_setting('epson_request_stats', json_encode($stats, JSON_UNESCAPED_SLASHES));
}

function epson_circuit_seconds(): int
{
    return max(0, (int) epson_request_stats()['circuit_until'] - time());
}

/**
 * Setting key holding the Epson edge address that answered for one host.
 */
function epson_edge_setting_key(string $host): string
{
    return 'epson_edge_ip_' . preg_replace('/[^a-z0-9.]/', '', strtolower($host));
}

/**
 * The Epson edge address remembered for a host, or '' for normal DNS.
 */
function epson_edge_ip(string $host): string
{
    return (string) setting(epson_edge_setting_key($host), '');
}

function epson_set_edge_ip(string $host, string $ip): void
{
    $key = epson_edge_setting_key($host);

    if ($ip === '') {
        if ((string) setting($key, '') !== '') {
            set_setting($key, '');
            set_setting('epson_edge_cleared_at', now());
        }

        return;
    }

    if ($ip !== (string) setting($key, '')) {
        set_setting($key, $ip);
        set_setting('epson_edge_pinned_at', now());
    }
}

/**
 * Addresses worth trying for an Epson host: the known working edges first, then
 * whatever DNS currently returns.
 *
 * @return list<string>
 */
function epson_edge_candidates(string $host): array
{
    if (!str_ends_with(strtolower($host), 'epsonconnect.com')) {
        return [];
    }

    $ips = EPSON_EDGE_FALLBACK_IPS;

    foreach ((array) @gethostbynamel($host) as $ip) {
        $ips[] = (string) $ip;
    }

    $ips = array_values(array_unique(array_filter(
        $ips,
        static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
    )));

    return $ips;
}

/**
 * How long Epson has been unreachable, and what has already been said about it.
 *
 * @return array{active:bool,since:string,age:?int,attempts:int,last_error:string,alerted_at:string,recovered_at:string,last_duration:?int}
 */
function epson_outage_state(): array
{
    $since    = (string) setting('epson_outage_since', '');
    $sinceTs  = $since !== '' ? strtotime($since) : false;
    $duration = (string) setting('epson_outage_last_duration_seconds', '');

    return [
        'active'        => $sinceTs !== false,
        'since'         => $since,
        'age'           => $sinceTs === false ? null : max(0, time() - $sinceTs),
        'attempts'      => (int) setting('epson_outage_attempts', '0'),
        'last_error'    => (string) setting('epson_outage_last_error', ''),
        'alerted_at'    => (string) setting('epson_outage_alerted_at', ''),
        'recovered_at'  => (string) setting('epson_outage_recovered_at', ''),
        'last_duration' => $duration !== '' ? (int) $duration : null,
    ];
}

/**
 * Called for every transport failure, so an outage has a start time and a count
 * rather than only a growing "consecutive failures" number.
 */
function epson_outage_note_failure(string $message): void
{
    $state = epson_outage_state();

    if (!$state['active']) {
        set_setting('epson_outage_since', now());
        set_setting('epson_outage_attempts', '0');
    }

    set_setting('epson_outage_attempts', (string) ($state['attempts'] + 1));
    set_setting('epson_outage_last_error', mb_substr(trim($message), 0, 300));
}

/**
 * Called on every successful Epson request. Returns true when this is the first
 * answer after an outage, so the caller can log the recovery.
 */
function epson_outage_note_success(): bool
{
    $state = epson_outage_state();

    if (!$state['active']) {
        return false;
    }

    set_setting('epson_outage_last_duration_seconds', (string) (int) ($state['age'] ?? 0));
    set_setting('epson_outage_recovered_at', now());
    set_setting('epson_outage_since', '');
    set_setting('epson_outage_attempts', '0');
    set_setting('epson_outage_last_error', '');

    return true;
}

/**
 * One human line about the outage: "unreachable since 01:02 (41 min, 18 attempts)".
 */
function epson_outage_summary(array $state = []): string
{
    $state = $state !== [] ? $state : epson_outage_state();

    if (!$state['active']) {
        return 'Epson is answering normally';
    }

    $minutes = max(1, (int) floor(((int) $state['age']) / 60));

    return 'Epson unreachable since ' . date('H:i', strtotime((string) $state['since']))
        . ' (' . $minutes . ' min, ' . (int) $state['attempts'] . ' attempt' . ($state['attempts'] === 1 ? '' : 's') . ')';
}

/**
 * Email when an outage lasts long enough to matter, remind while it lasts, and
 * send one note when Epson answers again.
 *
 * @return string|null 'alert', 'reminder' or 'recovery' when a mail was sent
 */
function epson_outage_alert_if_needed(): ?string
{
    $state = epson_outage_state();

    if (!$state['active']) {
        if ($state['alerted_at'] === '') {
            return null;
        }

        $health   = queue_health();
        $minutes  = max(1, (int) floor(((int) ($state['last_duration'] ?? 0)) / 60));
        $body = implode(PHP_EOL, [
            'PrintFree queue alert',
            '',
            'Issue: Epson Connect is reachable again',
            'Time: ' . format_date(now()),
            'Outage length: ' . $minutes . ' min',
            'Recovered: ' . format_date((string) $state['recovered_at']),
            'Accepted orders waiting: ' . $health['queued'],
            'Printing resumes by itself: the next worker run sends the next photo.',
        ]);

        $sent = send_queue_alert('Epson Connect is reachable again', $body);
        set_setting('epson_outage_alerted_at', '');

        return $sent ? 'recovery' : null;
    }

    $age = (int) ($state['age'] ?? 0);

    if ($age < EPSON_OUTAGE_ALERT_MINUTES * 60) {
        return null;
    }

    $health = queue_health();

    // Nothing is waiting (or printing is paused) - no reason to email anyone.
    if ($health['paused'] || $health['queued'] === 0) {
        return null;
    }

    $alertedTs = $state['alerted_at'] !== '' ? strtotime($state['alerted_at']) : false;
    $reminder  = EPSON_OUTAGE_REMINDER_MINUTES * 60;

    if ($alertedTs !== false && (time() - $alertedTs) < $reminder) {
        return null;
    }

    $kind     = $alertedTs === false ? 'alert' : 'reminder';
    $minutes  = max(1, (int) floor($age / 60));
    $oldest   = $health['oldest_reference'] !== null ? (string) $health['oldest_reference'] : 'none';
    $oldestAt = $health['oldest_age'] !== null ? max(1, (int) floor(((int) $health['oldest_age']) / 60)) . ' min waiting' : 'unknown';

    $body = implode(PHP_EOL, [
        'PrintFree queue alert',
        '',
        'Issue: Epson Connect unreachable (' . $minutes . ' min)',
        'Time: ' . format_date(now()),
        'Unreachable since: ' . format_date((string) $state['since']),
        'Failed attempts: ' . (int) $state['attempts'],
        'Last error: ' . ($state['last_error'] !== '' ? $state['last_error'] : 'unknown'),
        'Accepted orders waiting: ' . $health['queued'],
        'Oldest accepted order: ' . $oldest . ' (' . $oldestAt . ')',
        'Print progress: ' . ($health['progress_age'] !== null
            ? max(1, (int) floor(((int) $health['progress_age']) / 60)) . ' min since the last photo finished'
            : 'no photo printed yet'),
        '',
        'Nothing is lost: a photo that cannot be sent stays pending and the queue resumes by itself as soon as Epson answers.',
        'This message repeats every ' . EPSON_OUTAGE_REMINDER_MINUTES . ' minutes while the outage lasts, and one recovery note follows.',
    ]);

    $sent = send_queue_alert('Epson Connect unreachable - ' . $health['queued'] . ' order' . ($health['queued'] === 1 ? '' : 's') . ' waiting', $body);

    if ($sent) {
        set_setting('epson_outage_alerted_at', now());
    }

    return $sent ? $kind : null;
}

/**
 * @return list<array<string,mixed>>
 */
function epson_recent_requests(int $limit = 10): array
{
    $log = json_decode((string) setting('epson_request_log', ''), true);

    return is_array($log) ? array_slice($log, 0, $limit) : [];
}

/**
 * Whether automatic printing is paused for maintenance.
 */
function printing_paused(): bool
{
    return setting('print_queue_paused', '') === '1';
}

/**
 * @return array{paused:bool,at:string,by:string,note:string}
 */
function printing_pause_info(): array
{
    return [
        'paused' => printing_paused(),
        'at'     => (string) setting('print_queue_paused_at', ''),
        'by'     => (string) setting('print_queue_paused_by', ''),
        'note'   => (string) setting('print_queue_paused_note', ''),
    ];
}

function pause_printing(string $actor, string $note = ''): void
{
    set_setting('print_queue_paused', '1');
    set_setting('print_queue_paused_at', now());
    set_setting('print_queue_paused_by', $actor);
    set_setting('print_queue_paused_note', mb_substr(trim($note), 0, 200));
}

function resume_printing(string $actor): void
{
    // Resuming after a paper or ink stop is the operator saying the printer is
    // ready again, so the supply hold goes with it.
    printer_supply_clear();

    set_setting('print_queue_paused', '');
    set_setting('print_queue_paused_at', '');
    set_setting('print_queue_paused_by', $actor);
    set_setting('print_queue_paused_note', '');
}

function queue_health(): array
{
    $now       = time();
    $lastRun   = setting('print_queue_last_run');
    $lastRunTs = $lastRun !== null && $lastRun !== '' ? strtotime($lastRun) : false;
    $lastRunAge = $lastRunTs === false ? null : max(0, $now - $lastRunTs);

    $watchdogRun   = setting('print_queue_watchdog_last_run');
    $watchdogTs    = $watchdogRun !== null && $watchdogRun !== '' ? strtotime($watchdogRun) : false;
    $watchdogAge   = $watchdogTs === false ? null : max(0, $now - $watchdogTs);

    $queued = (int) db_val(
        "SELECT COUNT(*) FROM orders o
         WHERE o.status = 'accepted'
           AND EXISTS (SELECT 1 FROM order_photos p WHERE p.order_id = o.id AND p.status = 'pending')"
    );

    $oldest = db_one(
        "SELECT o.reference, o.updated_at
         FROM orders o
         WHERE o.status = 'accepted'
           AND EXISTS (SELECT 1 FROM order_photos p WHERE p.order_id = o.id AND p.status = 'pending')
         ORDER BY o.updated_at ASC, o.id ASC
         LIMIT 1"
    );

    $oldestTs  = $oldest !== null && !empty($oldest['updated_at']) ? strtotime((string) $oldest['updated_at']) : false;
    $oldestAge = $oldestTs === false ? null : max(0, $now - $oldestTs);
    $staleAfter = PRINT_QUEUE_STALE_MINUTES * 60;

    // A run that is still inside its slice budget is alive, however long ago it
    // started: the heartbeat is the run's start, and one run can print for 15
    // minutes without writing another one.
    $sliceUntil    = strtotime((string) setting('print_queue_slice_deadline', '')) ?: false;
    $runInProgress = $sliceUntil !== false && $sliceUntil > $now;

    // A run that is inside its slice but has stopped writing liveness is hung,
    // not working: the deadline only describes how long a run may take.
    $liveness    = (string) setting('print_queue_liveness', '');
    $livenessTs  = $liveness !== '' ? strtotime($liveness) : false;
    $livenessAge = $livenessTs === false ? null : max(0, $now - $livenessTs);

    // Between two runs the newest activity is when the last one finished; the
    // start time alone can be a whole slice old by then.
    $lastFinished   = (string) setting('print_queue_last_finished', '');
    $lastFinishedTs = $lastFinished !== '' ? strtotime($lastFinished) : false;
    $activityTs     = max($lastRunTs !== false ? $lastRunTs : 0, $lastFinishedTs !== false ? $lastFinishedTs : 0, $livenessTs !== false ? $livenessTs : 0);
    $activityAge    = $activityTs > 0 ? max(0, $now - $activityTs) : null;
    // A run always writes its first heartbeat as it starts, so a missing one
    // means the run predates this check (or died before writing): fall back to
    // the last-run threshold rather than calling it hung on no evidence.
    $runHung     = $runInProgress
        && $livenessAge !== null
        && $livenessAge > PRINT_QUEUE_LIVENESS_SECONDS;

    // One order at a time means a big order takes hours and the orders behind it
    // wait: that is normal. A stall is orders waiting while nothing is on the
    // printer and nothing has come off it for a while.
    $printingNow   = (int) db_val("SELECT COUNT(*) FROM order_photos WHERE status = 'printing'");
    $lastPrinted   = db_val('SELECT MAX(printed_at) FROM order_photos WHERE printed_at IS NOT NULL');
    $lastPrintedTs = $lastPrinted !== null ? strtotime((string) $lastPrinted) : false;
    $progressAge   = $lastPrintedTs === false ? null : max(0, $now - $lastPrintedTs);

    return [
        'paused'            => printing_paused(),
        'paused_at'         => (string) setting('print_queue_paused_at', ''),
        'paused_note'       => (string) setting('print_queue_paused_note', ''),
        'last_run'          => $lastRun,
        'last_run_age'      => $lastRunAge,
        'last_success'      => setting('print_queue_last_success'),
        'last_finished'     => setting('print_queue_last_finished'),
        'last_status'       => setting('print_queue_last_status', 'unknown') ?? 'unknown',
        'last_error'        => (string) setting('print_queue_last_error', ''),
        'worker_never_run'  => $lastRunTs === false,
        'worker_stale'      => $runHung
            || ($activityAge !== null && $activityAge > $staleAfter && !$runInProgress),
        'last_activity_age' => $activityAge,
        'run_in_progress'   => $runInProgress,
        'slice_deadline'    => $sliceUntil === false ? '' : date('Y-m-d H:i:s', $sliceUntil),
        'slice_left'        => $sliceUntil === false ? null : max(0, $sliceUntil - $now),
        'liveness'          => $liveness,
        'liveness_age'      => $livenessAge,
        'run_hung'          => $runHung,
        'queued'            => $queued,
        'oldest_reference'  => $oldest['reference'] ?? null,
        'oldest_age'        => $oldestAge,
        'queue_stalled'     => $queued > 0
            && $printingNow === 0
            && ($progressAge === null || $progressAge > (PRINT_QUEUE_ORDER_STALL_MINUTES * 60)),
        'printing_now'      => $printingNow,
        'progress_age'      => $progressAge,
        'watchdog_last_run' => $watchdogRun,
        'watchdog_age'      => $watchdogAge,
        'watchdog_stale'    => $watchdogTs !== false && $watchdogAge !== null
            && $watchdogAge > (PRINT_QUEUE_WATCHDOG_STALE_MINUTES * 60),
        'watchdog_status'   => setting('print_queue_watchdog_last_status', 'unknown') ?? 'unknown',
        'watchdog_error'    => (string) setting('print_queue_watchdog_last_error', ''),
        'alert_email'       => queue_alert_recipient(),
    ];
}

/**
 * What the printer is doing right now, for Maintenance and on-demand checks.
 *
 * Combines the Epson device check with the live status of the job on the
 * printer, so the dashboard can say "printing photo 4 of 12" or "out of paper"
 * instead of only "linked". Epson prints one photo per job, so the job being
 * watched is the photo with status "printing".
 *
 * Cached for PRINT_STATUS_CACHE_SECONDS so repeated page loads (and several
 * admins) do not each wait on a slow Epson API call.
 *
 * @return array<string,mixed>
 */
function printer_live_status(?EpsonClient $epson = null, bool $allowNetwork = true): array
{
    $cached = json_decode((string) setting('printer_status_cache', ''), true);

    if (is_array($cached) && isset($cached['checked_at'])
        && strtotime((string) $cached['checked_at']) > time() - PRINT_STATUS_CACHE_SECONDS) {
        $cached['cached'] = true;

        return $cached;
    }

    // Page loads never wait on Epson: they show the last known state (marked
    // stale) and the Refresh button does the call.
    if (!$allowNetwork) {
        if (is_array($cached)) {
            $cached['cached'] = true;
            $cached['stale']  = true;

            return $cached;
        }

        return [
            'state'           => 'unknown',
            'label'           => 'Not checked yet',
            'detail'          => 'Press Refresh to ask Epson for the printer state.',
            'order_id'        => null,
            'order_url'       => null,
            'order_reference' => null,
            'photo_position'  => null,
            'photo_total'     => null,
            'job_status'      => null,
            'job_reason'      => null,
            'queued_orders'   => 0,
            'connected'       => null,
            'checked_at'      => now(),
            'never_checked'   => true,
            'cached'          => false,
        ];
    }

    $status = printer_live_status_now($epson);
    $status['cached'] = false;

    // When Epson cannot be reached, keep showing the last known state instead of
    // replacing the card with an error; it is marked stale so the dashboard can
    // say so. The circuit breaker stops this from costing a timeout every poll.
    $reachable = ($status['state'] ?? '') !== 'unreachable';

    if (!$reachable && is_array($cached) && !empty($cached['checked_at'])
        && strtotime((string) $cached['checked_at']) > time() - 600) {
        $cached['cached']       = true;
        $cached['stale']        = true;
        $cached['last_attempt'] = now();

        return $cached;
    }

    set_setting('printer_status_cache', json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return $status;
}

/**
 * Fresh status for a page that must ask Epson on every load. The last reading is
 * kept and marked stale when Epson cannot be reached, so a timeout never blanks
 * the cards and the checked-at time stays honest.
 *
 * @return array<string,mixed>
 */
function printer_live_status_fresh(?EpsonClient $epson = null): array
{
    $cached = json_decode((string) setting('printer_status_cache', ''), true);
    $status = printer_live_status_now($epson);
    $status['cached'] = false;

    if (($status['state'] ?? '') === 'unreachable') {
        if (is_array($cached) && !empty($cached['checked_at'])) {
            $cached['cached']       = true;
            $cached['stale']        = true;
            $cached['last_attempt'] = now();

            return $cached;
        }

        return $status;
    }

    set_setting('printer_status_cache', json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return $status;
}

/**
 * Uncached printer status: the live job on the printer, or a device check when
 * nothing is printing. One Epson call per refresh.
 *
 * @return array<string,mixed>
 */
function printer_live_status_now(?EpsonClient $epson = null): array
{
    $epson ??= EpsonClient::fromSettings();

    $status = [
        'state'           => 'not_set_up',
        'label'           => 'Not set up',
        'detail'          => 'Add Epson Connect credentials in Printer Settings to monitor the printer.',
        'order_id'        => null,
        'order_url'       => null,
        'order_reference' => null,
        'photo_position'  => null,
        'photo_total'     => null,
        'job_status'      => null,
        'job_reason'      => null,
        'queued_orders'   => (int) db_val(
            "SELECT COUNT(*) FROM orders o
             WHERE o.status = 'accepted'
               AND EXISTS (SELECT 1 FROM order_photos p WHERE p.order_id = o.id AND p.status = 'pending')"
        ),
        'connected'       => null,
        'checked_at'      => now(),
    ];

    if (!$epson->isConfigured()) {
        return $status;
    }

    if (!$epson->isConnected()) {
        return array_merge($status, [
            'state'  => 'not_linked',
            'label'  => 'Not linked',
            'detail' => 'Authorise the printer in Printer Settings.',
        ]);
    }

    $photo = db_one(
        "SELECT p.id, p.order_id, p.position, p.epson_job_id, o.reference"
        . (order_photo_kind_column_ready() ? ', p.kind' : '') . ",
                (SELECT COUNT(*) FROM order_photos x WHERE x.order_id = o.id" . order_photo_only('x') . ") AS photo_total
         FROM order_photos p
         JOIN orders o ON o.id = p.order_id
         WHERE p.status = 'printing'
           AND p.epson_job_id IS NOT NULL AND p.epson_job_id <> ''
           AND o.status <> 'cancelled'
         ORDER BY p.id ASC
         LIMIT 1"
    );

    if ($photo === null) {
        // Nothing on the printer: the device check is the only Epson call needed.
        try {
            $device = $epson->deviceInfo();
            $status['connected'] = !empty($device['connected']);
        } catch (EpsonException $e) {
            return array_merge($status, [
                'state'  => 'unreachable',
                'label'  => 'Epson unreachable',
                'detail' => $e->getMessage(),
            ]);
        }

        $waiting = (int) $status['queued_orders'];

        return array_merge($status, [
            'state'  => 'idle',
            'label'  => 'Idle',
            'detail' => $waiting > 0
                ? 'Nothing on the printer right now; ' . $waiting . ' order' . ($waiting === 1 ? '' : 's') . ' waiting in the queue.'
                : 'Nothing on the printer — the queue is empty.',
        ]);
    }

    $status['order_id']        = (int) $photo['order_id'];
    $status['order_url']       = url('admin/order.php?id=' . (int) $photo['order_id']);
    $status['order_reference'] = (string) $photo['reference'];
    $status['photo_position']  = (int) $photo['position'];
    $status['photo_total']     = (int) $photo['photo_total'];

    $isDetailsSheet = ($photo['kind'] ?? 'photo') === 'details';

    $started = order_photo_print_started((int) $photo['order_id']);
    $minutes = photo_printing_minutes($started[(int) $photo['id']] ?? null);

    try {
        $job = $epson->jobState($epson->jobStatus((string) $photo['epson_job_id']));
    } catch (EpsonException $e) {
        return array_merge($status, [
            'state'  => 'unreachable',
            'label'  => 'Job status unavailable',
            'detail' => $e->getMessage(),
        ]);
    }

    $status['job_status'] = $job['status'];
    $status['job_reason'] = $job['reason'];

    $progress = $isDetailsSheet
        ? 'Order details sheet' . ($minutes !== null ? ' · ' . $minutes . ' min on the printer' : '')
        : 'Photo ' . $status['photo_position'] . ' of ' . $status['photo_total']
            . ($minutes !== null ? ' · ' . $minutes . ' min on the printer' : '');

    $stops = [
        'media_jam'           => ['Paper jam', 'Clear the jam; printing resumes when the printer is ready.'],
        'media_empty'         => ['Out of paper', 'Load photo paper; printing resumes when the printer is ready.'],
        'marker_supply_empty' => ['Out of ink', 'Replace the ink; printing resumes when the printer is ready.'],
        'attention_required'  => ['Needs attention', 'The printer paused this job — check its display.'],
    ];

    if ($job['status'] === 'processing_stopped' || isset($stops[$job['reason']])) {
        [$label, $hint] = $stops[$job['reason']] ?? $stops['attention_required'];

        return array_merge($status, [
            'state'  => 'attention',
            'label'  => $label,
            'detail' => $progress . ' · ' . $hint,
        ]);
    }

    if (in_array($job['status'], ['processing', 'printing', 'printing_in_progress'], true)) {
        return array_merge($status, [
            'state'  => 'printing',
            'label'  => 'Printing',
            'detail' => $progress . '.',
        ]);
    }

    if (in_array($job['status'], ['pending', 'pending_held'], true)) {
        return array_merge($status, [
            'state'  => 'waiting',
            'label'  => 'Sent, waiting for the printer',
            'detail' => $progress . ' · Epson has not started it yet.',
        ]);
    }

    if (in_array($job['status'], ['completed', 'finished', 'printed'], true)) {
        return array_merge($status, [
            'state'  => 'idle',
            'label'  => 'Sheet finished',
            'detail' => $progress . ' · the queue will hand over the next photo.',
        ]);
    }

    $readable = $job['status'] !== '' ? ucfirst(str_replace('_', ' ', $job['status'])) : 'Unknown';

    return array_merge($status, [
        'state'  => 'attention',
        'label'  => $readable,
        'detail' => $progress . ' · Epson reports ' . ($job['status'] !== '' ? '“' . $job['status'] . '”' : 'nothing') . '.',
    ]);
}

/**
 * Orders waiting on a person: a print that failed, including photos the queue
 * released as stuck, or a photo failure that left the order marked failed.
 *
 * @return array{count:int,photos:int,orders:list<array<string,mixed>>}
 */
function orders_needing_attention(int $limit = 6): array
{
    $where = "o.status <> 'cancelled'
        AND (o.status = 'failed'
             OR EXISTS (SELECT 1 FROM order_photos p WHERE p.order_id = o.id AND p.status = 'failed'))";

    $count = (int) db_val('SELECT COUNT(*) FROM orders o WHERE ' . $where);

    $orders = db_all(
        "SELECT o.id, o.reference, o.status, o.error_message, o.updated_at, u.name AS customer,
                (SELECT COUNT(*) FROM order_photos p WHERE p.order_id = o.id AND p.status = 'failed'" . order_photo_only('p') . ") AS failed_photos,
                (SELECT COUNT(*) FROM order_photos p WHERE p.order_id = o.id" . order_photo_only('p') . ") AS photo_count
         FROM orders o JOIN users u ON u.id = o.user_id
         WHERE " . $where . "
         ORDER BY o.updated_at ASC, o.id ASC
         LIMIT " . max(1, $limit)
    );

    $photos = (int) db_val(
        "SELECT COUNT(*) FROM order_photos p JOIN orders o ON o.id = p.order_id
         WHERE p.status = 'failed' AND o.status <> 'cancelled'" . order_photo_only('p')
    );

    return ['count' => $count, 'photos' => $photos, 'orders' => $orders];
}

/* -------------------------------------------------------------------------
 |  Dates & formatting
 * ---------------------------------------------------------------------- */

function now(): string
{
    return date('Y-m-d H:i:s');
}

function format_date(?string $value, string $format = 'j M Y, H:i'): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? '—' : date($format, $timestamp);
}

function time_ago(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '—';
    }

    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        $mins = (int) floor($diff / 60);
        return $mins . ' min ago';
    }
    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 604800) {
        $days = (int) floor($diff / 86400);
        return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }

    return date('j M Y', $timestamp);
}

function database_size_bytes(): int
{
    try {
        return (int) db_val(
            'SELECT COALESCE(SUM(data_length + index_length), 0)
             FROM information_schema.TABLES WHERE table_schema = DATABASE()'
        );
    } catch (Throwable $e) {
        return 0;
    }
}

function human_size(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 KB';
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

    return round($bytes / (1024 ** $power), $power === 0 ? 0 : 1) . ' ' . $units[$power];
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach ($parts as $part) {
        if ($part !== '') {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }
        if (strlen($letters) === 2) {
            break;
        }
    }

    return $letters !== '' ? $letters : 'PF';
}

/* -------------------------------------------------------------------------
 |  Order vocabulary
 * ---------------------------------------------------------------------- */

function paper_size_options(): array
{
    return [
        '4x6' => '4 × 6 in — 102 × 152 mm',
        '5x7' => '5 × 7 in — 127 × 178 mm',
        'A4'  => 'A4 — 210 × 297 mm',
    ];
}

function paper_size_label(string $key): string
{
    $label = paper_size_options()[$key] ?? $key;

    return explode(' — ', $label)[0];
}

function quality_options(): array
{
    return [
        'normal' => 'Standard — crisp, everyday prints',
        'high'   => 'High — maximum detail and colour',
    ];
}

function color_options(): array
{
    return ['color' => 'Colour', 'mono' => 'Black & white'];
}

function status_meta(string $status): array
{
    return match ($status) {
        'pending'   => ['Submitted', 'badge-pending', 'clock'],
        'accepted'  => ['In queue', 'badge-accepted', 'clock'],
        'printing'  => ['Printing', 'badge-printing', 'printer'],
        'completed' => ['Printed', 'badge-completed', 'check'],
        'cancelled' => ['Cancelled', 'badge-cancelled', 'close'],
        'failed'    => ['Failed', 'badge-failed', 'alert'],
        default     => [ucfirst($status), 'badge-neutral', 'file'],
    };
}

function status_badge(string $status): string
{
    [$label, $class, $iconName] = status_meta($status);

    return '<span class="badge ' . $class . '">' . icon($iconName, 'badge-icon') . e($label) . '</span>';
}

/**
 * Columns to select for photo listings — everything except the image bytes.
 */
function order_photo_columns(): string
{
    return 'id, order_id, file_name, mime_type, file_size, image_width, image_height,
            position, status, epson_job_id, error_message, printed_at, created_at'
        . (order_photo_kind_column_ready() ? ', kind' : '');
}

/**
 * Every photo in an order, in the order the customer picked them.
 */
function order_photos(int $orderId): array
{
    return db_all(
        'SELECT ' . order_photo_columns() . '
         FROM order_photos WHERE order_id = ?' . order_photo_only() . ' ORDER BY position ASC, id ASC',
        [$orderId]
    );
}

function order_photo_count(int $orderId): int
{
    return (int) db_val('SELECT COUNT(*) FROM order_photos WHERE order_id = ?' . order_photo_only(), [$orderId]);
}

/**
 * Dimensions for the order-details sheet, in landscape at roughly 200 dpi and
 * matching the order's paper so a borderless print is not cropped.
 *
 * @return array{0:int,1:int}
 */
function order_details_sheet_size(string $paperSize): array
{
    return match (strtolower($paperSize)) {
        '5x7'   => [1400, 1000],
        'a4'    => [1654, 1169],
        default => [1200, 800],
    };
}

/**
 * The facts printed on the sheet: what the packet is and who it belongs to.
 *
 * @return list<array{0:string,1:string}>
 */
function order_details_sheet_lines(array $order, int $photoCount): array
{
    $papers  = paper_size_options();
    $paper   = explode(' — ', $papers[(string) ($order['paper_size'] ?? '')] ?? '4 × 6 in')[0];
    $quality = explode(' — ', quality_options()[(string) ($order['print_quality'] ?? 'high')] ?? 'High')[0];
    $finish  = (int) ($order['borderless'] ?? 1) === 1 ? 'Borderless' : 'White border';
    $colour  = (string) ($order['color_mode'] ?? 'color') === 'mono' ? 'Black & white' : 'Colour';

    $lines = [
        ['Reference', (string) ($order['reference'] ?? '')],
        ['Customer', (string) ($order['customer'] ?? '')],
        ['Email', (string) ($order['customer_email'] ?? '')],
    ];

    $phone = trim((string) ($order['customer_phone'] ?? ''));

    if ($phone !== '') {
        $lines[] = ['Phone', $phone];
    }

    $lines[] = ['Photos', $photoCount . ' photo' . ($photoCount === 1 ? '' : 's')];
    $lines[] = ['Print', $paper . ' - ' . $finish];
    $lines[] = ['Quality', $colour . ' - ' . $quality];
    $lines[] = ['Ordered', format_date((string) ($order['created_at'] ?? ''))];
    $lines[] = ['Printed', format_date(now())];

    return $lines;
}

/**
 * A TrueType font for the sheet when the host has one; the built-in bitmap font
 * is used when it does not.
 */
function order_details_sheet_font(bool $bold = false): ?string
{
    static $fonts = [];

    $key = $bold ? 'bold' : 'regular';

    if (array_key_exists($key, $fonts)) {
        return $fonts[$key];
    }

    $directories = [
        '/usr/share/fonts/truetype/dejavu/',
        '/usr/share/fonts/dejavu/',
        '/usr/share/fonts/TTF/',
        '/usr/share/fonts/truetype/liberation/',
        '/usr/share/fonts/truetype/liberation2/',
        '/usr/share/fonts/liberation/',
        '/usr/share/fonts/',
    ];

    $names = $bold
        ? ['DejaVuSans-Bold.ttf', 'LiberationSans-Bold.ttf']
        : ['DejaVuSans.ttf', 'LiberationSans-Regular.ttf'];

    foreach ($directories as $directory) {
        foreach ($names as $name) {
            if (is_file($directory . $name) && is_readable($directory . $name)) {
                return $fonts[$key] = $directory . $name;
            }
        }
    }

    return $fonts[$key] = null;
}

/**
 * Draw the sheet with a TrueType font.
 */
function order_details_sheet_draw_ttf(int $width, int $height, array $lines, string $font, string $bold)
{
    $image = imagecreatetruecolor($width, $height);

    if ($image === false) {
        return null;
    }

    $white = imagecolorallocate($image, 255, 255, 255);
    $ink   = imagecolorallocate($image, 27, 36, 48);
    $muted = imagecolorallocate($image, 107, 119, 133);
    $rule  = imagecolorallocate($image, 216, 222, 232);

    imagefill($image, 0, 0, $white);

    $margin    = (int) round($height * 0.075);
    $titleSize = (int) round($height * 0.062);
    $bodySize  = (int) round($height * 0.036);
    $footSize  = (int) round($height * 0.028);
    $valueX    = (int) round($width * 0.33);
    $titleBase = $margin + $titleSize;
    $ruleY     = $titleBase + (int) round($height * 0.035);
    $bodyTop   = $ruleY + (int) round($height * 0.075);
    $rowHeight = (int) round($height * 0.058);

    imagettftext($image, $titleSize, 0, $margin, $titleBase, $ink, $bold, 'ORDER DETAILS');
    imageline($image, $margin, $ruleY, $width - $margin, $ruleY, $rule);

    foreach ($lines as $index => [$label, $value]) {
        $baseline = $bodyTop + ($index * $rowHeight);
        imagettftext($image, $bodySize, 0, $margin, $baseline, $muted, $font, $label);
        imagettftext($image, $bodySize, 0, $valueX, $baseline, $ink, $font, $value);
    }

    $reference = '';
    foreach ($lines as [$label, $value]) {
        if ($label === 'Reference') {
            $reference = $value;
            break;
        }
    }

    imagettftext(
        $image,
        $footSize,
        0,
        $margin,
        $height - $margin,
        $muted,
        $font,
        'This sheet follows the photos of order ' . $reference . '. Keep it with them.'
    );

    return $image;
}

/**
 * Draw the sheet with GD's built-in bitmap font, scaled up. Used only when the
 * host has no TrueType font installed.
 */
function order_details_sheet_draw_builtin(int $width, int $height, array $lines)
{
    if (!function_exists('imagescale')) {
        return null;
    }

    // Lay the text out at a size the bitmap font suits, then scale the whole
    // sheet to the print size.
    $designWidth  = 600;
    $designHeight = (int) round($designWidth * $height / max(1, $width));
    $small        = imagecreatetruecolor($designWidth, $designHeight);

    if ($small === false) {
        return null;
    }

    $white = imagecolorallocate($small, 255, 255, 255);
    $ink   = imagecolorallocate($small, 27, 36, 48);
    $muted = imagecolorallocate($small, 107, 119, 133);
    $rule  = imagecolorallocate($small, 216, 222, 232);

    imagefill($small, 0, 0, $white);

    // GD's bitmap font is ASCII only, so transliterate names and separators
    // rather than printing mojibake on the sheet.
    $accents = [
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'AE', 'Ç' => 'C',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ð' => 'D', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'Þ' => 'Th', 'ß' => 'ss',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ð' => 'd', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'þ' => 'th', 'ÿ' => 'y',
        '×' => 'x', '–' => '-', '—' => '-', '·' => '-', '’' => "'", '‘' => "'", '“' => '"', '”' => '"',
    ];

    $ascii = static function (string $text) use ($accents): string {
        $text = strtr($text, $accents);

        if (preg_match('/[^\x20-\x7E]/', $text)) {
            $converted = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $text) : false;

            if ($converted !== false) {
                $text = $converted;
            }
        }

        return (string) preg_replace('/[^\x20-\x7E]/', '?', $text);
    };

    imagestring($small, 5, 18, 16, 'ORDER DETAILS', $ink);
    imageline($small, 18, 42, $designWidth - 18, 42, $rule);

    $row = 0;
    foreach ($lines as [$label, $value]) {
        $y = 58 + ($row * 22);
        imagestring($small, 4, 18, $y, $ascii($label), $muted);
        imagestring($small, 4, 190, $y, mb_substr($ascii($value), 0, 46), $ink);
        $row++;
    }

    imagestring($small, 2, 18, $designHeight - 28, 'This sheet follows the photos of this order.', $muted);

    $image = imagescale($small, $width, $height, IMG_BILINEAR_FIXED);
    imagedestroy($small);

    return $image === false ? null : $image;
}

/**
 * Render the details sheet as a PNG Epson can print.
 *
 * @return array{data:string,width:int,height:int}|null
 */
function order_details_sheet_png(array $order, int $photoCount): ?array
{
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
        return null;
    }

    [$width, $height] = order_details_sheet_size((string) ($order['paper_size'] ?? '4x6'));
    $lines            = order_details_sheet_lines($order, $photoCount);
    $font             = order_details_sheet_font(false);
    $bold             = order_details_sheet_font(true) ?? $font;

    $image = $font !== null && function_exists('imagettftext')
        ? order_details_sheet_draw_ttf($width, $height, $lines, $font, (string) $bold)
        : order_details_sheet_draw_builtin($width, $height, $lines);

    if ($image === null) {
        return null;
    }

    ob_start();
    $ok   = imagepng($image);
    $data = ob_get_clean();
    imagedestroy($image);

    if (!$ok || !is_string($data) || $data === '') {
        return null;
    }

    return ['data' => $data, 'width' => $width, 'height' => $height];
}

/**
 * Add the order-details sheet to an order once, as its last print job.
 *
 * The sheet is an ordinary order_photos row of kind "details", so the print
 * worker sends it with the same one-sheet-at-a-time logic and the same status
 * tracking. Customer-facing lists and counts filter it out.
 */
function ensure_order_details_sheet(int $orderId): bool
{
    static $done = [];

    if ($orderId <= 0 || !order_details_sheet_enabled() || !order_photo_kind_column_ready()) {
        return false;
    }

    if (array_key_exists($orderId, $done)) {
        return $done[$orderId];
    }

    try {
        $exists = db_val("SELECT id FROM order_photos WHERE order_id = ? AND kind = 'details' LIMIT 1", [$orderId]);

        if ($exists !== null) {
            return $done[$orderId] = true;
        }

        $photoCount = order_photo_count($orderId);

        if ($photoCount === 0) {
            return $done[$orderId] = false;
        }

        $order = db_one(
            'SELECT o.*, u.name AS customer, u.email AS customer_email, u.phone AS customer_phone
             FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?',
            [$orderId]
        );

        if ($order === null) {
            return $done[$orderId] = false;
        }

        $sheet = order_details_sheet_png($order, $photoCount);

        if ($sheet === null) {
            error_log('[PrintFree] Could not render the details sheet for order ' . $orderId . '.');

            return $done[$orderId] = false;
        }

        $position = (int) db_val('SELECT COALESCE(MAX(position), 0) FROM order_photos WHERE order_id = ?', [$orderId]) + 1;

        db_insert('order_photos', [
            'order_id'     => $orderId,
            'file_name'    => 'order-details-' . strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $order['reference'])) . '.png',
            'mime_type'    => 'image/png',
            'image_data'   => $sheet['data'],
            'thumb_data'   => null,
            'file_size'    => strlen($sheet['data']),
            'image_width'  => $sheet['width'],
            'image_height' => $sheet['height'],
            'position'     => $position,
            'status'       => 'pending',
            'kind'         => 'details',
            'created_at'   => now(),
        ]);

        return $done[$orderId] = true;
    } catch (Throwable $e) {
        error_log('[PrintFree] Could not add the details sheet for order ' . $orderId . ': ' . $e->getMessage());

        return $done[$orderId] = false;
    }
}

/**
 * Add an optional DATETIME column to an existing database, once.
 *
 * New installs get the column from the schema; older ones are upgraded the
 * first time a feature needs it, guarded by a settings flag. A refused ALTER
 * only turns that feature off — pages and the print worker keep working.
 */
function schema_column_ready(string $table, string $column, string $after, string $flag): bool
{
    static $cache = [];

    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    if (setting($flag) === 'ready') {
        return $cache[$key] = true;
    }

    try {
        $exists = (int) db_val(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );

        if ($exists === 0) {
            db()->exec(
                'ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` DATETIME DEFAULT NULL AFTER `' . $after . '`'
            );
        }

        set_setting($flag, 'ready');

        return $cache[$key] = true;
    } catch (Throwable $e) {
        error_log('[PrintFree] Could not add ' . $key . ': ' . $e->getMessage());

        return $cache[$key] = false;
    }
}

/**
 * Timestamp support for stuck-print detection.
 */
function print_started_column_ready(): bool
{
    return schema_column_ready('order_photos', 'print_started_at', 'printed_at', 'schema_print_started_at');
}

/**
 * When an order joined the automatic print queue.
 *
 * `orders.accepted_at` ships in the schema for new installs and is added with
 * an ALTER on existing ones the first time it is needed. If the ALTER is
 * refused, the queue falls back to ordering by submission time.
 */
function accepted_at_column_ready(): bool
{
    return schema_column_ready('orders', 'accepted_at', 'updated_at', 'schema_orders_accepted_at');
}

/**
 * Add an optional text column to an existing database, once.
 *
 * Same guarded ALTER as schema_column_ready(); callers simply turn their
 * feature off when it returns false.
 */
function schema_text_column_ready(
    string $table,
    string $column,
    string $after,
    string $flag,
    string $definition
): bool {
    static $cache = [];

    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    if (setting($flag) === 'ready') {
        return $cache[$key] = true;
    }

    try {
        $exists = (int) db_val(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );

        if ($exists === 0) {
            db()->exec(
                'ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition . ' AFTER `' . $after . '`'
            );
        }

        set_setting($flag, 'ready');

        return $cache[$key] = true;
    } catch (Throwable $e) {
        error_log('[PrintFree] Could not add ' . $key . ': ' . $e->getMessage());

        return $cache[$key] = false;
    }
}

/**
 * Whether order_photos is able to tell the generated details sheet apart from
 * a customer's photo. A refused ALTER simply turns the sheet off.
 */
function order_photo_kind_column_ready(): bool
{
    return schema_text_column_ready(
        'order_photos',
        'kind',
        'position',
        'schema_order_photos_kind',
        "VARCHAR(20) NOT NULL DEFAULT 'photo'"
    );
}

/**
 * SQL keeping the order-details sheet out of customer photo counts and lists.
 * Empty when the column is unavailable, so every query still works.
 */
function order_photo_only(string $alias = ''): string
{
    if (!order_photo_kind_column_ready()) {
        return '';
    }

    return ' AND ' . ($alias !== '' ? $alias . '.' : '') . "kind = 'photo'";
}

/**
 * Whether the order-details sheet prints after each order's photos.
 */
function order_details_sheet_enabled(): bool
{
    return setting('order_details_sheet_enabled', '1') === '1';
}

/**
 * Drop sheets that are queued but not on the printer yet. Used when the Site
 * Settings option is turned off, so a pending sheet does not print anyway.
 */
function order_details_sheet_discard_pending(int $orderId = 0): void
{
    if (!order_photo_kind_column_ready()) {
        return;
    }

    try {
        if ($orderId > 0) {
            db_query(
                "DELETE FROM order_photos WHERE order_id = ? AND kind = 'details' AND status = 'pending'",
                [$orderId]
            );

            return;
        }

        db_query("DELETE FROM order_photos WHERE kind = 'details' AND status = 'pending'");
    } catch (Throwable $e) {
        error_log('[PrintFree] Could not drop queued details sheets: ' . $e->getMessage());
    }
}

/**
 * When each photo of an order was handed to the printer.
 *
 * @return array<int, string|null> photo id => print start time
 */
function order_photo_print_started(int $orderId): array
{
    if (!print_started_column_ready()) {
        return [];
    }

    try {
        $rows = db_all('SELECT id, print_started_at FROM order_photos WHERE order_id = ?', [$orderId]);
    } catch (Throwable) {
        return [];
    }

    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['id']] = $row['print_started_at'] !== null ? (string) $row['print_started_at'] : null;
    }

    return $map;
}

/**
 * Whole minutes a photo has been with the printer, or null when unknown.
 */
function photo_printing_minutes(?string $startedAt): ?int
{
    if ($startedAt === null || $startedAt === '') {
        return null;
    }

    $timestamp = strtotime($startedAt);

    return $timestamp === false ? null : max(0, (int) floor((time() - $timestamp) / 60));
}

/**
 * Fail photos Epson has left in "printing" far beyond any normal job.
 *
 * A job that never reports back would otherwise keep its order in "printing"
 * for ever, and the worker would keep re-checking the same oldest jobs while
 * newer photos wait their turn. Failing them puts the order back in the admin's
 * hands with the existing Retry & queue button.
 *
 * @return list<array{id:mixed,order_id:mixed,file_name:mixed}>
 */
function release_stuck_photos(string $actor = 'Cron'): array
{
    if (!print_started_column_ready()) {
        return [];
    }

    $cutoff = date('Y-m-d H:i:s', time() - (PRINT_JOB_STUCK_MINUTES * 60));

    try {
        $stuck = db_all(
            "SELECT p.id, p.order_id, p.file_name" . (order_photo_kind_column_ready() ? ', p.kind' : '') . "
             FROM order_photos p
             JOIN orders o ON o.id = p.order_id
             WHERE p.status = 'printing'
               AND o.status <> 'cancelled'
               AND COALESCE(p.print_started_at, p.created_at) < ?
             ORDER BY p.id ASC",
            [$cutoff]
        );
    } catch (Throwable $e) {
        error_log('[PrintFree] Could not look for stuck prints: ' . $e->getMessage());

        return [];
    }

    $perOrder   = [];
    $sheetOrder = [];

    foreach ($stuck as $photo) {
        $orderId   = (int) $photo['order_id'];
        $isDetails = ($photo['kind'] ?? 'photo') === 'details';

        db_update('order_photos', [
            'status'        => $isDetails ? 'cancelled' : 'failed',
            'printed_at'    => null,
            'error_message' => $isDetails ? null : 'No completion from the printer after ' . PRINT_JOB_STUCK_MINUTES
                . ' minutes, so it was marked as stuck. Check the printer, then print it again.',
        ], 'id = ?', [(int) $photo['id']]);

        if ($isDetails) {
            $sheetOrder[$orderId] = true;
            log_order_event(
                $orderId,
                'printing',
                'The order details sheet did not finish at the printer and was dropped; the photos are unaffected.',
                $actor
            );
            continue;
        }

        $perOrder[$orderId] = ($perOrder[$orderId] ?? 0) + 1;
    }

    foreach (array_keys($perOrder + $sheetOrder) as $orderId) {
        $status = sync_order_status((int) $orderId, $actor);

        if (!isset($perOrder[$orderId])) {
            continue;
        }

        log_order_event(
            (int) $orderId,
            $status,
            $perOrder[$orderId] . ' photo' . ($perOrder[$orderId] === 1 ? ' was' : 's were')
                . ' stuck at the printer and released so ' . ($perOrder[$orderId] === 1 ? 'it' : 'they') . ' can be retried.',
            $actor
        );
    }

    // Report photos only; the sheet is not something the operator reprints.
    return array_values(array_filter(
        $stuck,
        static fn (array $photo): bool => ($photo['kind'] ?? 'photo') !== 'details'
    ));
}

/**
 * Send one photo to Epson and record the job against that photo.
 *
 * Shared by the admin order page and the automatic print-queue cron. A MySQL
 * named lock claims the individual photo before the status check, so two
 * workers cannot send the same photo even if they start at the same moment.
 * The status is re-read after the claim because the caller's photo row may be
 * stale by then.
 *
 * @param list<string> $allowedStatuses Photo statuses this caller may claim.
 * @return array{ok:bool,skipped:bool,error:string}
 */
function send_photo_to_printer(
    EpsonClient $epson,
    array $order,
    array $photo,
    array $allowedStatuses = ['pending']
): array {
    if (printing_paused()) {
        return [
            'ok'      => false,
            'skipped' => true,
            'error'   => 'Printing is paused for maintenance. Turn maintenance mode off in Printer Settings to send photos again.',
        ];
    }

    $prepared = prepare_photo_for_printer($epson, $order, $photo, $allowedStatuses);

    if (!$prepared['ok']) {
        return $prepared;
    }

    return start_prepared_photo($epson, $order, $photo, $prepared['job'], $allowedStatuses);
}

/**
 * Build the Epson job and upload the photo without starting it.
 *
 * Uploading is the slow part, so the worker does it while the previous sheet is
 * still printing and later only has to send the start command.
 *
 * @param list<string> $allowedStatuses
 * @return array{ok:bool,skipped:bool,transient?:bool,error:string,job?:array}
 */
function prepare_photo_for_printer(
    EpsonClient $epson,
    array $order,
    array $photo,
    array $allowedStatuses = ['pending']
): array {
    $photoId = (int) ($photo['id'] ?? 0);
    $allowed = array_values(array_unique(array_filter(
        $allowedStatuses,
        static fn (mixed $status): bool => is_string($status) && $status !== ''
    )));

    if ($photoId <= 0 || $allowed === []) {
        return ['ok' => false, 'skipped' => true, 'error' => 'This photo is not available to send.'];
    }

    // A slow Epson upload can outlast PHP's web execution limit default.
    @set_time_limit(EPSON_UPLOAD_TIMEOUT + 120);

    $lockName = 'printfree_photo_' . $photoId;
    $locked   = db_val('SELECT GET_LOCK(?, 0)', [$lockName]);

    if ((int) $locked !== 1) {
        return [
            'ok'      => false,
            'skipped' => true,
            'error'   => $photo['file_name'] . ': another print process is already sending this photo.',
        ];
    }

    try {
        $placeholders = implode(', ', array_fill(0, count($allowed), '?'));
        $current = db_one(
            'SELECT status FROM order_photos WHERE id = ? AND status IN (' . $placeholders . ')',
            array_merge([$photoId], $allowed)
        );

        if ($current === null) {
            return [
                'ok'      => false,
                'skipped' => true,
                'error'   => $photo['file_name'] . ': this photo has already been handled.',
            ];
        }

        try {
            $job = $epson->preparePhoto($order, $photo);
        } catch (EpsonException $e) {
            return epson_send_failure($epson, $e, $photo);
        }

        return ['ok' => true, 'skipped' => false, 'error' => '', 'job' => $job];
    } finally {
        try {
            db_val('SELECT RELEASE_LOCK(?)', [$lockName]);
        } catch (Throwable) {
            // The connection closing also releases the lock.
        }
    }
}

/**
 * Start a job prepared earlier by prepare_photo_for_printer().
 *
 * @param list<string> $allowedStatuses
 * @return array{ok:bool,skipped:bool,transient?:bool,error:string}
 */
function start_prepared_photo(
    EpsonClient $epson,
    array $order,
    array $photo,
    array $job,
    array $allowedStatuses = ['pending']
): array {
    $photoId = (int) ($photo['id'] ?? 0);
    $allowed = array_values(array_unique(array_filter(
        $allowedStatuses,
        static fn (mixed $status): bool => is_string($status) && $status !== ''
    )));

    if ($photoId <= 0 || empty($job['jobId']) || $allowed === []) {
        return ['ok' => false, 'skipped' => true, 'error' => 'This photo is not available to send.'];
    }

    @set_time_limit(EPSON_UPLOAD_TIMEOUT + 120);

    $lockName = 'printfree_photo_' . $photoId;
    $locked   = db_val('SELECT GET_LOCK(?, 0)', [$lockName]);

    if ((int) $locked !== 1) {
        return [
            'ok'      => false,
            'skipped' => true,
            'error'   => $photo['file_name'] . ': another print process is already sending this photo.',
        ];
    }

    try {
        $placeholders = implode(', ', array_fill(0, count($allowed), '?'));
        $current = db_one(
            'SELECT status FROM order_photos WHERE id = ? AND status IN (' . $placeholders . ')',
            array_merge([$photoId], $allowed)
        );

        if ($current === null) {
            return [
                'ok'      => false,
                'skipped' => true,
                'error'   => $photo['file_name'] . ': this photo has already been handled.',
            ];
        }

        try {
            $epson->startPhoto($job);
        } catch (EpsonException $e) {
            return epson_send_failure($epson, $e, $photo);
        }

        $started = [
            'status'        => 'printing',
            'epson_job_id'  => (string) $job['jobId'],
            'error_message' => null,
            'printed_at'    => null,
        ];

        // Somewhere to measure a stuck job from. Skipped on installs where the
        // column could not be added, so the send still succeeds.
        if (print_started_column_ready()) {
            $started['print_started_at'] = now();
        }

        db_update('order_photos', $started, 'id = ?', [$photoId]);

        // Treat a successful send as queue progress so the watchdog does not
        // flag a large accepted order that is still moving.
        if (!empty($order['id'])) {
            db_update('orders', ['updated_at' => now()], 'id = ?', [(int) $order['id']]);
        }

        return ['ok' => true, 'skipped' => false, 'error' => ''];
    } finally {
        try {
            db_val('SELECT RELEASE_LOCK(?)', [$lockName]);
        } catch (Throwable) {
            // The connection closing also releases the lock.
        }
    }
}

/**
 * Record a failed send. A connection that never opened, or a failure before the
 * start command, cannot have printed anything, so the photo stays queued for
 * the next run instead of being marked as a failed print.
 *
 * @return array{ok:bool,skipped:bool,transient?:bool,error:string}
 */
function epson_send_failure(EpsonClient $epson, EpsonException $e, array $photo): array
{
    $photoId      = (int) ($photo['id'] ?? 0);
    $isDetails    = ($photo['kind'] ?? 'photo') === 'details';
    $neverPrinted = !$e->connected
        || in_array($e->curlErrno, [6, 7], true)
        || in_array($epson->lastStage, ['settings', 'create', 'upload'], true);

    if ($e->transport && $neverPrinted) {
        db_update('order_photos', [
            'status'        => 'pending',
            'error_message' => null,
        ], 'id = ?', [$photoId]);

        return [
            'ok'        => false,
            'skipped'   => false,
            'transient' => true,
            'error'     => $photo['file_name'] . ': Epson was unreachable, so the photo stays queued for the next run.',
        ];
    }

    if ($isDetails) {
        // The sheet is an extra for the operator, not the customer's photo, so a
        // failure drops it instead of marking the order failed.
        db_update('order_photos', [
            'status'        => 'cancelled',
            'error_message' => null,
        ], 'id = ?', [$photoId]);

        if (!empty($photo['order_id'])) {
            log_order_event(
                (int) $photo['order_id'],
                'printing',
                'The order details sheet could not be printed: ' . $e->getMessage(),
                'Printer'
            );
        }

        return [
            'ok'      => false,
            'skipped' => true,
            'error'   => 'The order details sheet could not be printed; the photos are unaffected.',
        ];
    }

    db_update('order_photos', [
        'status'        => 'failed',
        'error_message' => $e->getMessage(),
    ], 'id = ?', [$photoId]);

    return [
        'ok'      => false,
        'skipped' => false,
        'error'   => $photo['file_name'] . ': ' . $e->getMessage(),
    ];
}

/**
 * Accept an order for automatic printing. Failed or cancelled photos are
 * returned to the queue so a retry starts cleanly.
 */
function accept_order_for_printing(int $orderId, string $actor): void
{
    db_query(
        "UPDATE order_photos
         SET status = 'pending', epson_job_id = NULL, printed_at = NULL, error_message = NULL"
            . (print_started_column_ready() ? ', print_started_at = NULL' : '') . "
         WHERE order_id = ? AND status IN ('failed', 'cancelled')",
        [$orderId]
    );

    // Queue position is the acceptance time, not the order id, so an order
    // submitted later but accepted first prints first.
    if (accepted_at_column_ready()) {
        db_update('orders', ['accepted_at' => now()], 'id = ?', [$orderId]);
    }

    // A details sheet goes in last so it prints after every photo.
    ensure_order_details_sheet($orderId);

    update_order_status(
        $orderId,
        'accepted',
        'Accepted by ' . $actor . '; queued for automatic printing.',
        $actor
    );
}

/**
 * Total bytes of full-size photo data stored for one order.
 */
function order_storage_bytes(int $orderId): int
{
    return (int) db_val(
        'SELECT COALESCE(SUM(file_size), 0) FROM order_photos WHERE order_id = ?',
        [$orderId]
    );
}

function order_photo_mime(array $photo): string
{
    $mime = strtolower(trim((string) ($photo['mime_type'] ?? '')));

    if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
        return $mime;
    }

    $extension = strtolower(pathinfo((string) ($photo['file_name'] ?? ''), PATHINFO_EXTENSION));

    return $extension === 'png' ? 'image/png' : 'image/jpeg';
}

/**
 * Photo bytes from the database. Fetches them when the caller only has the
 * photo row (listings deliberately leave the BLOB out).
 */
function photo_binary(array $photo): ?string
{
    $data = $photo['image_data'] ?? null;

    if ($data === null && !empty($photo['id'])) {
        $data = db_val('SELECT image_data FROM order_photos WHERE id = ?', [$photo['id']]);
    }

    if (is_resource($data)) {
        $data = stream_get_contents($data);
    }

    return is_string($data) && $data !== '' ? $data : null;
}

/**
 * Generated thumbnail bytes for a photo, if one was stored.
 */
function photo_thumbnail(array $photo): ?string
{
    $data = $photo['thumb_data'] ?? null;

    if ($data === null && !empty($photo['id'])) {
        $data = db_val('SELECT thumb_data FROM order_photos WHERE id = ?', [$photo['id']]);
    }

    if (is_resource($data)) {
        $data = stream_get_contents($data);
    }

    return is_string($data) && $data !== '' ? $data : null;
}

/**
 * Build a small JPEG preview from photo bytes. Returns null when GD is
 * unavailable, the image is too large to decode safely, or it is unreadable
 * — the full photo is then served instead.
 */
function make_photo_thumbnail(string $data, int $maxEdge = 480): ?string
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg') || !function_exists('imagedestroy')) {
        return null;
    }

    $info = @getimagesizefromstring($data);
    if (!is_array($info)) {
        return null;
    }

    $width  = (int) $info[0];
    $height = (int) $info[1];

    // 30 MP is already ~120 MB decoded; skip anything that could exhaust memory.
    if ($width < 1 || $height < 1 || ($width * $height) > 30_000_000) {
        return null;
    }

    $image = @imagecreatefromstring($data);
    if ($image === false) {
        return null;
    }

    $scale = min(1.0, $maxEdge / max($width, $height));
    $thumbWidth  = max(1, (int) round($width * $scale));
    $thumbHeight = max(1, (int) round($height * $scale));

    $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
    if ($thumb === false) {
        imagedestroy($image);

        return null;
    }

    // Flatten transparent PNGs onto white photo paper.
    imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
    imagecopyresampled($thumb, $image, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);

    ob_start();
    $ok = imagejpeg($thumb, null, 82);
    $jpeg = ob_get_clean();

    imagedestroy($thumb);
    imagedestroy($image);

    return $ok && is_string($jpeg) && $jpeg !== '' ? $jpeg : null;
}

/**
 * Stream a stored photo, or its generated thumbnail, to its owner or an
 * admin. Inline for previews, attachment when the download flag is set.
 */
function serve_order_photo(array $photo, string $fallbackUrl, bool $download = false, bool $thumbnail = false): never
{
    $bytes     = null;
    $isThumb   = false;
    $name      = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $photo['file_name']) ?: 'photo.jpg';

    if ($thumbnail) {
        $bytes   = photo_thumbnail($photo);
        $isThumb = $bytes !== null;
    }

    if ($bytes === null) {
        $bytes = photo_binary($photo);
    }

    if ($bytes === null) {
        flash('error', 'That photo is no longer available in the database.');
        redirect($fallbackUrl);
    }

    if ($isThumb) {
        $name = preg_replace('/\.[a-z]+$/i', '', $name) . '-thumb.jpg';
    } elseif (!preg_match('/\.(jpe?g|png)$/i', $name)) {
        $name .= order_photo_mime($photo) === 'image/png' ? '.png' : '.jpg';
    }

    header('Content-Type: ' . ($isThumb ? 'image/jpeg' : order_photo_mime($photo)));
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $name . '"');
    header('Content-Length: ' . (string) strlen($bytes));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=' . ($isThumb ? '86400' : '600'));

    echo $bytes;
    exit;
}

/**
 * Turn PHP's $_FILES['photos'] shape into a list of individual files.
 *
 * @return array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function normalize_uploaded_files(array $field): array
{
    if (!isset($field['name'])) {
        return [];
    }

    if (!is_array($field['name'])) {
        return [$field];
    }

    $files = [];
    foreach (array_keys($field['name']) as $index) {
        $files[] = [
            'name'     => $field['name'][$index] ?? '',
            'type'     => $field['type'][$index] ?? '',
            'tmp_name' => $field['tmp_name'][$index] ?? '',
            'error'    => $field['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size'     => $field['size'][$index] ?? 0,
        ];
    }

    return $files;
}

/**
 * Parse a php.ini size value ("8M", "512K", "1G") into bytes. 0 means the
 * setting is unlimited or not configured.
 */
function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $unit   = strtolower(substr($value, -1));
    $number = (float) $value;

    return (int) match ($unit) {
        'g' => $number * 1024 ** 3,
        'm' => $number * 1024 ** 2,
        'k' => $number * 1024,
        default => $number,
    };
}

/**
 * Effective upload limits after PHP's ini settings are applied. Hosts such as
 * Wasmer or shared hosting often allow less than MAX_UPLOAD_MB per file or per
 * request, so the forms and validation use these numbers instead of guessing.
 *
 * @return array{file_bytes:int, post_bytes:int, max_files:int}
 */
function upload_limits(): array
{
    $fileBytes = MAX_UPLOAD_MB * 1024 * 1024;
    $iniFile   = ini_bytes((string) ini_get('upload_max_filesize'));
    $iniPost   = ini_bytes((string) ini_get('post_max_size'));

    if ($iniFile > 0) {
        $fileBytes = min($fileBytes, $iniFile);
    }
    if ($iniPost > 0) {
        $fileBytes = min($fileBytes, $iniPost);
    }

    $maxFiles = MAX_PHOTOS_PER_BATCH;
    $iniFiles = (int) ini_get('max_file_uploads');
    if ($iniFiles > 0) {
        $maxFiles = min($maxFiles, $iniFiles);
    }

    return [
        'file_bytes' => max(1, $fileBytes),
        'post_bytes' => max(0, $iniPost),
        'max_files'  => max(1, $maxFiles),
    ];
}

/**
 * The per-order storage cap in bytes. MB * 1024 * 1024 overflows a 32-bit
 * integer (the Wasmer host), turning the value into a float, so clamp the
 * multiplication to the platform's integer range.
 */
function order_storage_limit_bytes(): ?int
{
    if (MAX_ORDER_STORAGE_MB <= 0) {
        return null;
    }

    $perMb = 1024 * 1024;
    $maxMb = intdiv(PHP_INT_MAX, $perMb);

    return min(MAX_ORDER_STORAGE_MB, $maxMb) * $perMb;
}

/**
 * True when PHP discarded a POST because it was larger than post_max_size.
 * In that case $_POST and $_FILES are empty, so this must be checked before
 * CSRF validation to show a useful message instead of a generic failure.
 */
function post_request_too_large(): bool
{
    if (!is_post()) {
        return false;
    }

    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $max    = ini_bytes((string) ini_get('post_max_size'));

    return $length > 0 && $max > 0 && $length > $max;
}

/**
 * True when a form is uploading a chunk from the browser's batch uploader.
 */
function is_ajax_upload(): bool
{
    return input('ajax') === '1';
}

/**
 * Finish an upload request with JSON so the batch uploader can continue with
 * the next chunk without a page reload.
 */
function upload_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Report an upload failure as JSON for the batch uploader, or as a normal
 * flash + redirect when the form was submitted without JavaScript.
 */
function abort_upload(string $message, string $back): void
{
    if (is_ajax_upload()) {
        upload_json(['ok' => false, 'error' => $message], 422);
    }

    flash('error', $message);
    redirect($back);
}

/**
 * Check how many photos arrived and that the selection is allowed. Keeps the
 * files on disk untouched — each one is read and stored individually.
 *
 * @param array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}> $files
 * @return array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function validate_photo_uploads(array $files, ?int $maxFiles = null, ?int $maxTotalBytes = null): array
{
    $files = array_values(array_filter(
        $files,
        static fn (array $file): bool => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
    ));

    if ($files === []) {
        throw new RuntimeException('Please choose at least one photo to upload.');
    }

    $limits   = upload_limits();
    $maxFiles = max(1, min($maxFiles ?? MAX_PHOTOS_PER_BATCH, $limits['max_files']));

    if (count($files) > $maxFiles) {
        throw new RuntimeException(
            'You can add up to ' . $maxFiles . ' photo' . ($maxFiles === 1 ? '' : 's') . ' in one batch.'
        );
    }

    $maxBytes = $limits['file_bytes'];

    foreach ($files as $file) {
        $size = (int) ($file['size'] ?? 0);

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(upload_error_message((int) $file['error']));
        }

        if ($size <= 0) {
            throw new RuntimeException('One of those files looks empty. Please choose another photo.');
        }

        if ($size > $maxBytes) {
            throw new RuntimeException('Please keep photos under ' . human_size($maxBytes) . ' each on this server.');
        }

        if (!photo_upload_is_supported_name(trim((string) ($file['name'] ?? '')), (string) ($file['type'] ?? ''))) {
            throw new RuntimeException(
                'That file type is not a photo. Choose a JPG, PNG, HEIC, WebP, GIF, BMP, TIFF or AVIF image.'
            );
        }
    }

    $totalBytes = array_sum(array_map(static fn (array $file): int => (int) ($file['size'] ?? 0), $files));

    if ($maxTotalBytes !== null && $maxTotalBytes > 0 && $totalBytes > $maxTotalBytes) {
        throw new RuntimeException(
            'That batch is ' . human_size($totalBytes) . ', but only ' . human_size($maxTotalBytes)
            . ' of space is available here. Choose fewer photos or smaller files.'
        );
    }

    if ($limits['post_bytes'] > 0 && $totalBytes > $limits['post_bytes']) {
        throw new RuntimeException(
            'That batch is ' . human_size($totalBytes) . ', larger than this server accepts in one upload ('
            . human_size($limits['post_bytes']) . '). Add photos in smaller batches.'
        );
    }

    return $files;
}

function upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE   => 'That photo is larger than the server allows.',
        UPLOAD_ERR_FORM_SIZE  => 'That photo is larger than the server allows.',
        UPLOAD_ERR_PARTIAL    => 'The upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE    => 'Please choose a photo to upload.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server could not store the upload. Please try again later.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not store the upload. Please try again later.',
        UPLOAD_ERR_EXTENSION  => 'The upload was blocked by the server.',
        default               => 'The upload failed. Please try again.',
    };
}

/**
 * Read, validate and insert one uploaded photo, thumbnail included.
 *
 * Called once per file so only a single photo (at most MAX_UPLOAD_MB) is held
 * in memory at a time, rather than the whole order.
 */
function insert_photo_upload(int $orderId, array $file, int $position): int
{
    $photo = read_photo_upload($file, $position);

    insert_order_photo($orderId, $photo);
    $photoId = (int) db()->lastInsertId();

    // Release the full-size bytes before the next file is read.
    unset($photo['data'], $photo['thumb']);

    return $photoId;
}

/**
 * Image extensions the picker advertises. The server normalises whatever it
 * can decode, so storing is not limited to these.
 *
 * @return array<int,string>
 */
function photo_upload_extensions(): array
{
    return ['jpg', 'jpeg', 'jfif', 'png', 'webp', 'gif', 'bmp', 'avif', 'tif', 'tiff', 'heic', 'heif', 'tga', 'ico'];
}

/**
 * A broad check so the picker can accept any common image. The real test is
 * whether GD or Imagick can decode it when the photo is read.
 */
function photo_upload_is_supported_name(string $name, string $mime = ''): bool
{
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime      = strtolower($mime);

    return in_array($extension, photo_upload_extensions(), true) || str_starts_with($mime, 'image/');
}

/**
 * Convert image bytes to a JPEG Epson can print, flattening any transparency
 * onto white photo paper. Returns null when GD cannot decode the format.
 *
 * @return array{data:string,mime:string,width:int,height:int}|null
 */
function photo_gd_to_jpeg(string $data, int $width, int $height): ?array
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return null;
    }

    // Decoding a huge image can exhaust memory; reject before allocating it.
    if ($width < 1 || $height < 1 || ($width * $height) > 40_000_000) {
        return null;
    }

    $image = @imagecreatefromstring($data);
    if ($image === false) {
        return null;
    }

    $canvas = imagecreatetruecolor($width, $height);
    if ($canvas === false) {
        imagedestroy($image);

        return null;
    }

    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

    ob_start();
    $ok   = imagejpeg($canvas, null, 92);
    $jpeg = ob_get_clean();

    imagedestroy($canvas);
    imagedestroy($image);

    if (!$ok || !is_string($jpeg) || $jpeg === '') {
        return null;
    }

    return ['data' => $jpeg, 'mime' => 'image/jpeg', 'width' => $width, 'height' => $height];
}

/**
 * Fallback decoder for HEIC, TIFF and anything else GD was not built with.
 * Imagick is optional, so this quietly returns null when it is unavailable.
 *
 * @return array{data:string,mime:string,width:int,height:int}|null
 */
function photo_imagick_to_jpeg(string $data): ?array
{
    if (!class_exists('Imagick')) {
        return null;
    }

    try {
        $image = new Imagick();
        $image->readImageBlob($data);

        if ($image->getNumberImages() > 1) {
            $image->setIteratorIndex(0);
        }

        if (method_exists($image, 'autoOrientImage')) {
            $image->autoOrientImage();
        }

        $width  = (int) $image->getImageWidth();
        $height = (int) $image->getImageHeight();

        if ($width < 1 || $height < 1) {
            $image->clear();

            return null;
        }

        $image->setImageBackgroundColor('white');
        if (method_exists($image, 'setImageAlphaChannel') && defined('Imagick::ALPHACHANNEL_REMOVE')) {
            $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        }

        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(92);
        $image->stripImage();
        $jpeg = $image->getImageBlob();
        $image->clear();

        if (!is_string($jpeg) || $jpeg === '') {
            return null;
        }

        return ['data' => $jpeg, 'mime' => 'image/jpeg', 'width' => $width, 'height' => $height];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Validate and read one photo, normalising anything that is not already JPEG
 * or PNG to JPEG. The stored name and MIME always match the stored bytes, so
 * Epson receives a printable file even when the original was HEIC or WebP.
 */
function read_photo_upload(array $file, int $position): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Please choose a photo to upload.');
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_message((int) $file['error']));
    }

    $maxBytes = upload_limits()['file_bytes'];
    $size     = (int) ($file['size'] ?? 0);

    if ($size <= 0) {
        throw new RuntimeException('One of those files looks empty. Please choose another photo.');
    }
    if ($size > $maxBytes) {
        throw new RuntimeException('Please keep photos under ' . human_size($maxBytes) . ' each on this server.');
    }

    $original = trim((string) ($file['name'] ?? 'photo.jpg'));
    $info     = @getimagesize($file['tmp_name']);
    $mime     = is_array($info) ? strtolower((string) ($info['mime'] ?? '')) : '';
    $width    = is_array($info) ? (int) ($info[0] ?? 0) : 0;
    $height   = is_array($info) ? (int) ($info[1] ?? 0) : 0;

    $data = @file_get_contents($file['tmp_name']);

    if ($data === false || $data === '') {
        throw new RuntimeException('The server could not read your photo. Please try again.');
    }

    if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
        $stored = ['data' => $data, 'mime' => $mime, 'width' => $width, 'height' => $height];
    } else {
        $stored = photo_gd_to_jpeg($data, $width, $height) ?? photo_imagick_to_jpeg($data);
    }

    if ($stored === null) {
        throw new RuntimeException(
            'That photo could not be opened. Save it as JPG or PNG, or try another browser.'
        );
    }

    $baseName = pathinfo($original, PATHINFO_FILENAME);
    $baseName = mb_substr($baseName !== '' ? $baseName : 'photo', 0, 240);
    $extension = $stored['mime'] === 'image/png' ? 'png' : 'jpg';

    return [
        'data'     => $stored['data'],
        'thumb'    => make_photo_thumbnail($stored['data']),
        'mime'     => $stored['mime'],
        'name'     => $baseName . '.' . $extension,
        'size'     => strlen($stored['data']),
        'width'    => $stored['width'],
        'height'   => $stored['height'],
        'position' => $position,
    ];
}

/**
 * Store one photo row, full bytes and thumbnail included, in the database.
 *
 * The blobs are bound as LOBs so MySQL treats them as binary rather than
 * text and MySQL stores them as real BLOBs.
 */
function insert_order_photo(int $orderId, array $photo): void
{
    $stmt = db()->prepare(
        'INSERT INTO order_photos
            (order_id, file_name, mime_type, image_data, thumb_data, file_size,
             image_width, image_height, position, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $stmt->bindValue(1, $orderId, PDO::PARAM_INT);
    $stmt->bindValue(2, (string) $photo['name'], PDO::PARAM_STR);
    $stmt->bindValue(3, (string) $photo['mime'], PDO::PARAM_STR);
    $stmt->bindValue(4, (string) $photo['data'], PDO::PARAM_LOB);
    $stmt->bindValue(5, $photo['thumb'] ?? null, $photo['thumb'] === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    $stmt->bindValue(6, (int) $photo['size'], PDO::PARAM_INT);
    $stmt->bindValue(7, (int) $photo['width'], PDO::PARAM_INT);
    $stmt->bindValue(8, (int) $photo['height'], PDO::PARAM_INT);
    $stmt->bindValue(9, (int) $photo['position'], PDO::PARAM_INT);
    $stmt->bindValue(10, 'pending', PDO::PARAM_STR);
    $stmt->bindValue(11, now(), PDO::PARAM_STR);
    $stmt->execute();
}

/**
 * Recompute an order's status from its photos and log the change.
 *
 * all photos completed  -> completed
 * any photo failed      -> failed
 * accepted, still waiting -> accepted
 * any sent/printed      -> printing
 * otherwise             -> pending
 */
function sync_order_status(int $orderId, ?string $actor = null): string
{
    $order = db_one('SELECT status FROM orders WHERE id = ?', [$orderId]);
    if ($order === null) {
        return 'pending';
    }

    // Every row, including the generated order-details sheet, so an order is
    // only finished once that sheet has come off the printer too. A sheet that
    // fails is cancelled rather than failed, so it never marks the order failed.
    $rows = db_all(
        'SELECT status' . (order_photo_kind_column_ready() ? ', kind' : ", 'photo' AS kind") . '
         FROM order_photos WHERE order_id = ?',
        [$orderId]
    );

    $photos       = [];
    $sheetWaiting = false;

    foreach ($rows as $row) {
        if (($row['kind'] ?? 'photo') === 'details') {
            $sheetWaiting = $sheetWaiting || in_array((string) $row['status'], ['pending', 'printing'], true);
            continue;
        }

        $photos[] = $row;
    }

    $total = count($photos);

    if ($total === 0 || $order['status'] === 'cancelled') {
        return (string) $order['status'];
    }

    $counts    = array_count_values(array_map(static fn (array $p): string => (string) $p['status'], $photos));
    $completed = (int) ($counts['completed'] ?? 0);
    $failed    = (int) ($counts['failed'] ?? 0);
    $pending   = (int) ($counts['pending'] ?? 0);
    $sent      = $completed + (int) ($counts['printing'] ?? 0);

    if ($completed === $total && !$sheetWaiting) {
        $status  = 'completed';
        $message = 'All ' . $total . ' photo' . ($total === 1 ? '' : 's') . ' printed.';
    } elseif ($failed > 0) {
        $status  = 'failed';
        $message = $failed . ' of ' . $total . ' photos could not be printed.';
    } elseif ($sent > 0) {
        // As soon as one sheet has been handed over the order is printing, even
        // while the rest of it is still queued. This is what the dashboard's
        // "Printing now" count reads.
        $status  = 'printing';
        $message = $sent . ' of ' . $total . ' photos sent to the printer'
            . ($pending > 0 ? '; ' . $pending . ' still queued.' : '.')
            . ($sheetWaiting && $completed === $total ? ' The order details sheet prints next.' : '');
    } elseif ($order['status'] === 'accepted' && $pending > 0) {
        $status  = 'accepted';
        $message = 'Waiting to print: ' . $pending . ' of ' . $total . ' photos still in the automatic queue.';
    } else {
        $status  = 'pending';
        $message = 'Order waiting in the queue.';
    }

    if ($status === $order['status']) {
        return $status;
    }

    $data = ['status' => $status, 'updated_at' => now()];

    if ($status === 'completed') {
        $data['printed_at'] = now();
        $data['error_message'] = null;
    }

    if ($status === 'pending') {
        $data['error_message'] = null;
    }

    if ($status === 'failed') {
        $errors = array_values(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['error_message'] ?? '')),
            db_all(
                "SELECT error_message FROM order_photos
                 WHERE order_id = ? AND status = 'failed'" . order_photo_only() . '
                 ORDER BY id ASC',
                [$orderId]
            )
        )));
        $data['error_message'] = mb_substr(implode(' ', array_unique($errors)), 0, 500)
            ?: 'One or more photos could not be printed.';
    }

    db_update('orders', $data, 'id = ?', [$orderId]);
    log_order_event($orderId, $status, $message, $actor);

    return $status;
}

function generate_reference(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    do {
        $reference = 'PF-';
        for ($i = 0; $i < 6; $i++) {
            $reference .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $taken = (int) db_val('SELECT COUNT(*) FROM orders WHERE reference = ?', [$reference]);
    } while ($taken > 0);

    return $reference;
}

function log_order_event(int $orderId, string $status, string $message, ?string $actor = null): void
{
    db_insert('order_events', [
        'order_id'   => $orderId,
        'status'     => $status,
        'message'    => mb_substr($message, 0, 255),
        'actor'      => $actor !== null ? mb_substr($actor, 0, 120) : null,
        'created_at' => now(),
    ]);
}

function update_order_status(int $orderId, string $status, string $message, ?string $actor = null): void
{
    $data = ['status' => $status, 'updated_at' => now()];

    if ($status === 'completed') {
        $data['printed_at'] = now();
    }
    if (in_array($status, ['pending', 'accepted'], true)) {
        $data['error_message'] = null;
    }

    db_update('orders', $data, 'id = ?', [$orderId]);
    log_order_event($orderId, $status, $message, $actor);
}

function order_events(int $orderId): array
{
    return db_all('SELECT * FROM order_events WHERE order_id = ? ORDER BY id ASC', [$orderId]);
}

/* -------------------------------------------------------------------------
 |  Pagination
 * ---------------------------------------------------------------------- */

function paginate(int $total, int $perPage = 10): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = max(1, min($pages, (int) ($_GET['page'] ?? 1)));

    return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage, 'per_page' => $perPage];
}

function pager(int $page, int $pages, array $query = []): string
{
    if ($pages <= 1) {
        return '';
    }

    $link = static function (int $target, string $label, string $class = '') use ($query): string {
        $params = array_merge($query, ['page' => $target]);
        $href   = '?' . http_build_query($params);

        return '<a class="pager-link ' . $class . '" href="' . e($href) . '">' . $label . '</a>';
    };

    $html = '<nav class="pager" aria-label="Pagination">';

    if ($page > 1) {
        $html .= $link($page - 1, icon('arrow-left', 'pager-icon') . ' Prev');
    }

    $start = max(1, $page - 2);
    $end   = min($pages, $page + 2);

    if ($start > 1) {
        $html .= $link(1, '1');
        if ($start > 2) {
            $html .= '<span class="pager-gap">…</span>';
        }
    }

    for ($i = $start; $i <= $end; $i++) {
        $html .= $i === $page
            ? '<span class="pager-link is-active" aria-current="page">' . $i . '</span>'
            : $link($i, (string) $i);
    }

    if ($end < $pages) {
        if ($end < $pages - 1) {
            $html .= '<span class="pager-gap">…</span>';
        }
        $html .= $link($pages, (string) $pages);
    }

    if ($page < $pages) {
        $html .= $link($page + 1, 'Next ' . icon('arrow-right', 'pager-icon'));
    }

    return $html . '</nav>';
}

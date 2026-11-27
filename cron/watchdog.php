<?php
/**
 * Print-queue watchdog.
 *
 * Run this separately from the print worker, for example every five minutes:
 *
 *   /usr/local/bin/php /home/snapprin/public_html/cron/watchdog.php \
 *     >> /home/snapprin/queue-watchdog.log 2>&1
 *
 * It checks the worker heartbeat, the most recent worker result, the oldest
 * accepted order and the printer link. It emails the configured queue alert
 * address when something is wrong and sends one recovery email afterwards.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/config.php';

date_default_timezone_set(APP_TIMEZONE);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/epson.php';

@set_time_limit(60);

$lock = db_val("SELECT GET_LOCK('printfree_queue_watchdog', 0)");
if ((int) $lock !== 1) {
    exit(0);
}

$exitCode = 0;
$messages = [];
$errors   = [];

set_setting('print_queue_watchdog_last_run', now());

try {
    $health  = queue_health();
    $epson   = EpsonClient::fromSettings();
    $printer = $epson->isConnected();
    $outage  = epson_outage_state();

    $issue = null;

    if ($health['paused']) {
        // Printing was paused on purpose for maintenance: nothing to alert about.
    } elseif ($health['worker_never_run'] && $health['queued'] > 0) {
        $issue = 'worker_stopped';
    } elseif ($health['worker_stale']) {
        $issue = 'worker_stopped';
    } elseif ($health['last_status'] === 'error') {
        $issue = 'worker_error';
    } elseif ($health['queued'] > 0 && !$printer) {
        $issue = 'printer_unlinked';
    } elseif ($health['queue_stalled'] && !$outage['active']) {
        // While Epson is unreachable the worker sends its own outage alert
        // (and a recovery note), so a second "stalled" email would be noise.
        $issue = 'queue_stalled';
    }

    $lastSignature = (string) setting('print_queue_alert_signature', '');
    $lastAlertAt   = (string) setting('print_queue_alert_at', '');
    $lastAlertTs   = $lastAlertAt !== '' ? strtotime($lastAlertAt) : false;
    $cooldown      = PRINT_QUEUE_ALERT_COOLDOWN_MINUTES * 60;
    $cooldownOver  = $lastAlertTs === false || (time() - $lastAlertTs) >= $cooldown;

    $signature = $issue;
    if ($issue === 'worker_error') {
        $signature .= ':' . md5($health['last_error']);
    }

    $lastWorkerRun = $health['last_run'] !== null
        ? format_date((string) $health['last_run']) . ' (' . time_ago((string) $health['last_run']) . ')'
        : 'never';
    $lastSuccess = $health['last_success'] !== null
        ? format_date((string) $health['last_success']) . ' (' . time_ago((string) $health['last_success']) . ')'
        : 'never';
    $oldest = $health['oldest_reference'] !== null
        ? $health['oldest_reference'] . ' (' . ($health['oldest_age'] !== null
            ? max(1, (int) floor($health['oldest_age'] / 60)) . ' min waiting'
            : 'age unknown') . ')'
        : 'none';
    $progress = $health['progress_age'] !== null
        ? max(1, (int) floor($health['progress_age'] / 60)) . ' min since the last photo finished'
        : 'no photo printed yet';
    $lastError = $health['last_error'] !== '' ? $health['last_error'] : 'none';
    $recipient = $health['alert_email'] !== '' ? $health['alert_email'] : 'not configured';

    $body = implode(PHP_EOL, [
        'PrintFree queue alert',
        '',
        'Issue: ' . ($issue !== null ? $issue : 'recovered'),
        'Time: ' . format_date(now()),
        'Worker last run: ' . $lastWorkerRun,
        'Worker last success: ' . $lastSuccess,
        'Worker last status: ' . $health['last_status'],
        'Worker last error: ' . $lastError,
        'Accepted orders waiting: ' . $health['queued'],
        'Oldest accepted order: ' . $oldest,
        'Print progress: ' . $progress,
        'Printer linked: ' . ($printer ? 'yes' : 'no'),
        'Watchdog last run: ' . ($health['watchdog_last_run'] !== null
            ? format_date((string) $health['watchdog_last_run'])
            : 'never'),
        'Alert recipient: ' . $recipient,
    ]);

    $subjects = [
        'worker_stopped'  => 'Print queue worker is not running',
        'worker_error'    => 'Print queue worker is failing',
        'printer_unlinked' => 'Print orders are waiting because the printer is not linked',
        'queue_stalled'   => 'Print queue has stalled',
    ];

    if ($issue !== null && ($signature !== $lastSignature || $cooldownOver)) {
        $sent = send_queue_alert($subjects[$issue] ?? 'Print queue needs attention', $body);

        if ($sent) {
            set_setting('print_queue_alert_signature', (string) $signature);
            set_setting('print_queue_alert_at', now());
            $messages[] = 'Alert sent: ' . ($subjects[$issue] ?? $issue);
        } else {
            $errors[] = 'Could not send the queue alert; check the alert email and the server mail transport.';
            $exitCode = 1;
        }
    } elseif ($issue === null && $lastSignature !== '') {
        $sent = send_queue_alert('Print queue recovered', $body);

        if ($sent) {
            $messages[] = 'Recovery email sent.';
        } else {
            $errors[] = 'Could not send the queue recovery email.';
            $exitCode = 1;
        }

        // Clear the incident even if the recovery email failed, otherwise
        // every watchdog run would keep retrying the same recovery message.
        set_setting('print_queue_alert_signature', '');
        set_setting('print_queue_alert_at', now());
    }
} catch (Throwable $e) {
    $exitCode = 1;
    $errors[] = $e->getMessage();
} finally {
    try {
        set_setting('print_queue_watchdog_last_status', $exitCode === 0 ? 'ok' : 'error');
        set_setting('print_queue_watchdog_last_error', mb_substr(implode(' ', array_unique($errors)), 0, 1000));
        db_val("SELECT RELEASE_LOCK('printfree_queue_watchdog')");
    } catch (Throwable) {
        // The connection closing also releases the lock.
    }
}

foreach ($messages as $message) {
    echo date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
}

foreach (array_slice(array_values(array_unique($errors)), 0, 5) as $error) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' ' . $error . PHP_EOL);
}

exit($exitCode);

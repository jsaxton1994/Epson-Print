<?php
/**
 * Nightly database backup.
 *
 * Run from cPanel cron (CLI only), for example once a night:
 *
 *   /usr/local/bin/php /home/snapprin/public_html/cron/backup.php \
 *     >> /home/snapprin/backup.log 2>&1
 *
 * The database is dumped (schema and rows, photo BLOBs excluded) and written
 * gzipped into the backup folder: `backups/` beside public_html by default,
 * which the web server does not serve and a deploy does not touch. Only the
 * newest BACKUP_KEEP_FILES dumps are kept.
 *
 * Restore: download the newest dump, gunzip it and import with
 * `mysql db < db-YYYYmmdd-HHMMSS.sql`.
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
require_once __DIR__ . '/../includes/backup.php';

$lock = db_val("SELECT GET_LOCK('printfree_backup', 0)");

if ((int) $lock !== 1) {
    exit(0);
}

$exitCode = 0;

try {
    $result = run_backup();

    $line = 'backup ' . ($result['ok'] ? 'ok' : 'FAILED') . ': ' . $result['note']
        . ' dump=' . human_size($result['bytes'])
        . ' rows=' . $result['rows']
        . ' file=' . $result['file']
        . ' where=' . $result['where'];

    if ($result['ok']) {
        echo date('Y-m-d H:i:s') . ' ' . $line . PHP_EOL;
    } else {
        fwrite(STDERR, date('Y-m-d H:i:s') . ' ' . $line . PHP_EOL);
        $exitCode = 1;
    }
} catch (Throwable $e) {
    $exitCode = 1;
    fwrite(STDERR, date('Y-m-d H:i:s') . ' backup failed: ' . $e->getMessage() . PHP_EOL);
    set_setting('backup_last_run', now());
    set_setting('backup_last_status', 'error');
    set_setting('backup_last_note', mb_substr('Backup failed: ' . $e->getMessage(), 0, 500));
} finally {
    try {
        db_val("SELECT RELEASE_LOCK('printfree_backup')");
    } catch (Throwable) {
        // The connection closing also releases the lock.
    }
}

exit($exitCode);

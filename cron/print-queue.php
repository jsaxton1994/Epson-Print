<?php
/**
 * Automatic print queue.
 *
 * Run this from cPanel cron (CLI only):
 *
 *   /usr/local/bin/php /home/snapprin/public_html/cron/print-queue.php \
 *     >> /home/snapprin/print-queue.log 2>&1
 *
 * It sends accepted orders to Epson in small batches, then refreshes the
 * status of jobs already printing. A database lock prevents overlapping runs.
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

@set_time_limit(PRINT_BATCH_SECONDS + STATUS_BATCH_SECONDS + (2 * EPSON_TIMEOUT) + 10);

$lock = db_val("SELECT GET_LOCK('printfree_print_queue', 0)");
if ((int) $lock !== 1) {
    // The previous run is still working. The next cron tick will pick up.
    exit(0);
}

$exitCode = 0;
$messages = [];
$errors   = [];

try {
    $epson = EpsonClient::fromSettings();

    $queuedOrders = (int) db_val(
        "SELECT COUNT(*) FROM orders o
         WHERE o.status = 'accepted'
           AND EXISTS (SELECT 1 FROM order_photos p WHERE p.order_id = o.id AND p.status = 'pending')"
    );

    if (!$epson->isConnected()) {
        if ($queuedOrders > 0) {
            $messages[] = $queuedOrders . ' accepted order' . ($queuedOrders === 1 ? '' : 's')
                . ' waiting; the printer is not linked.';
        }
    } else {
        $sendDeadline = microtime(true) + PRINT_BATCH_SECONDS;
        $orders = db_all(
            "SELECT o.*
             FROM orders o
             WHERE o.status = 'accepted'
               AND EXISTS (SELECT 1 FROM order_photos p WHERE p.order_id = o.id AND p.status = 'pending')
             ORDER BY o.id ASC
             LIMIT 5"
        );

        $sentTotal    = 0;
        $failedTotal  = 0;
        $skippedTotal = 0;

        foreach ($orders as $order) {
            if (microtime(true) >= $sendDeadline) {
                break;
            }

            $orderId = (int) $order['id'];
            $pending = array_values(array_filter(
                order_photos($orderId),
                static fn (array $photo): bool => $photo['status'] === 'pending'
            ));

            if ($pending === []) {
                continue;
            }

            $batch   = array_slice($pending, 0, PRINT_BATCH_MAX);
            $sent    = 0;
            $skipped = 0;

            foreach ($batch as $index => $photo) {
                if ($index > 0 && microtime(true) >= $sendDeadline) {
                    break;
                }

                $result = send_photo_to_printer($epson, $order, $photo, ['pending']);

                if (!empty($result['skipped'])) {
                    $skipped++;
                    $skippedTotal++;
                    continue;
                }

                if (!$result['ok']) {
                    $failedTotal++;
                    $errors[] = $order['reference'] . ': ' . $result['error'];
                    break;
                }

                $sent++;
                $sentTotal++;
            }

            if ($sent === 0) {
                continue;
            }

            $remaining = max(0, count($pending) - $sent - $skipped);
            $status    = sync_order_status($orderId, 'Cron');

            // sync_order_status keeps an accepted order accepted while photos
            // are still waiting, so record the batch progress explicitly.
            if ($remaining > 0 && $status === 'accepted') {
                log_order_event(
                    $orderId,
                    'accepted',
                    $sent . ' photo' . ($sent === 1 ? '' : 's') . ' sent automatically; '
                        . $remaining . ' still waiting in the queue.',
                    'Cron'
                );
            }

            $messages[] = $order['reference'] . ': ' . $sent . ' photo'
                . ($sent === 1 ? '' : 's') . ' sent'
                . ($remaining > 0 ? ', ' . $remaining . ' waiting' : '');
        }

        if ($skippedTotal > 0) {
            $messages[] = $skippedTotal . ' photo' . ($skippedTotal === 1 ? '' : 's')
                . ' skipped because another process was already sending it.';
        }

        if ($failedTotal > 0) {
            $messages[] = $failedTotal . ' photo' . ($failedTotal === 1 ? '' : 's') . ' failed.';
            $exitCode = 1;
        }

        $jobs = db_all(
            'SELECT p.*
             FROM order_photos p
             JOIN orders o ON o.id = p.order_id
             WHERE p.status = \'printing\'
               AND p.epson_job_id IS NOT NULL
               AND p.epson_job_id <> \'\'
               AND o.status <> \'cancelled\'
             ORDER BY p.id ASC
             LIMIT ' . STATUS_BATCH_MAX
        );

        $statusDeadline = microtime(true) + STATUS_BATCH_SECONDS;
        $checked        = 0;
        $touchedOrders  = [];

        foreach ($jobs as $index => $photo) {
            if ($index > 0 && microtime(true) >= $statusDeadline) {
                break;
            }

            try {
                $result = $epson->interpretJobStatus(
                    $epson->jobStatus((string) $photo['epson_job_id'])
                );
                $checked++;
                $touchedOrders[(int) $photo['order_id']] = true;

                if ($result['status'] !== $photo['status']) {
                    db_update('order_photos', [
                        'status'        => $result['status'],
                        'printed_at'    => $result['status'] === 'completed' ? now() : null,
                        'error_message' => $result['status'] === 'failed' ? $result['detail'] : null,
                    ], 'id = ?', [$photo['id']]);
                }
            } catch (EpsonException $e) {
                $errors[] = $e->getMessage();
            }
        }

        foreach (array_keys($touchedOrders) as $orderId) {
            sync_order_status((int) $orderId, 'Cron');
        }

        if ($checked > 0) {
            $messages[] = $checked . ' Epson job' . ($checked === 1 ? '' : 's') . ' checked.';
        }
    }
} catch (Throwable $e) {
    $exitCode = 1;
    $errors[] = $e->getMessage();
} finally {
    try {
        db_val("SELECT RELEASE_LOCK('printfree_print_queue')");
    } catch (Throwable) {
        // The connection closing also releases the lock.
    }
}

if ($messages !== []) {
    echo date('Y-m-d H:i:s') . ' ' . implode(' ', $messages) . PHP_EOL;
}

foreach (array_slice(array_values(array_unique($errors)), 0, 5) as $error) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' ' . $error . PHP_EOL);
}

if ($errors !== []) {
    $exitCode = 1;
}

exit($exitCode);

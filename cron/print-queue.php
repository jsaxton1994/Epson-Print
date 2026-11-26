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

@set_time_limit(PRINT_SEQUENTIAL_SECONDS + STATUS_BATCH_SECONDS + (3 * EPSON_TIMEOUT) + 20);

$lock = db_val("SELECT GET_LOCK('printfree_print_queue', 0)");
if ((int) $lock !== 1) {
    // The previous run is still working. The next cron tick will pick up.
    exit(0);
}

// Record liveness after winning the worker lock, so a series of skipped cron
// ticks cannot hide a hung worker that still holds the lock.
set_setting('print_queue_last_run', now());

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
        // A paused queue still tracks the sheet already on the printer, but it
        // sends nothing new until an administrator resumes printing.
        $sendDeadline = printing_paused() ? 0.0 : microtime(true) + PRINT_SEQUENTIAL_SECONDS;

        if (printing_paused()) {
            $pauseNote  = (string) setting('print_queue_paused_note', '');
            $messages[] = 'Printing is paused' . ($pauseNote !== '' ? ' (' . $pauseNote . ')' : '') . '; no photos were sent.';
        }

        // Strictly one order, one sheet at a time. The worker only sends a
        // photo when nothing is already on the printer, then waits for that
        // job to finish before sending the next photo of the same order. A
        // second order is not touched until the first has no photos left, so
        // prints can no longer arrive from different orders mixed together.
        $sentTotal         = 0;
        $failedTotal       = 0;
        $skippedTotal      = 0;
        $transportFailures = 0;
        $frontRef     = '';
        $frontId      = 0;

        // The next photo is created and uploaded while the current sheet is
        // still printing, so the printer only waits for a start command.
        $prepared  = null;

        $prepareNext = static function (array $order) use ($epson, &$messages, &$errors): ?array {
            $next = db_one(
                'SELECT ' . order_photo_columns() . '
                 FROM order_photos
                 WHERE order_id = ? AND status = \'pending\'
                 ORDER BY position ASC, id ASC
                 LIMIT 1',
                [(int) $order['id']]
            );

            if ($next === null) {
                return null;
            }

            $ready = prepare_photo_for_printer($epson, $order, $next, ['pending']);

            if ($ready['ok']) {
                return ['order_id' => (int) $order['id'], 'photo' => $next, 'job' => $ready['job']];
            }

            if (!empty($ready['transient'])) {
                $messages[] = (string) $order['reference'] . ': ' . $ready['error'];
            } elseif (empty($ready['skipped'])) {
                $errors[] = (string) $order['reference'] . ': ' . $ready['error'];
            }

            return null;
        };

        // Queue position is the time the order was accepted, not its id, so an
        // order submitted later but accepted first prints first. Orders
        // accepted before the column existed fall back to their submit time.
        $queueOrder = accepted_at_column_ready()
            ? 'COALESCE(o.accepted_at, o.created_at) ASC, o.id ASC'
            : 'o.created_at ASC, o.id ASC';

        while (microtime(true) < $sendDeadline) {
            $frontOrder = db_one(
                "SELECT o.*
                 FROM orders o
                 WHERE o.status IN ('accepted', 'printing')
                   AND EXISTS (
                       SELECT 1 FROM order_photos p
                       WHERE p.order_id = o.id AND p.status IN ('pending', 'printing')
                   )
                 ORDER BY " . $queueOrder . "
                 LIMIT 1"
            );

            if ($frontOrder === null) {
                break;
            }

            $frontId  = (int) $frontOrder['id'];
            $frontRef = (string) $frontOrder['reference'];

            if ($prepared !== null && $prepared['order_id'] !== $frontId) {
                // The front order changed: the uploaded job is not needed.
                $prepared = null;
            }

            $printing = db_one(
                "SELECT p.id, p.order_id, p.status, p.epson_job_id
                 FROM order_photos p
                 JOIN orders o ON o.id = p.order_id
                 WHERE p.status = 'printing'
                   AND p.epson_job_id IS NOT NULL
                   AND p.epson_job_id <> ''
                   AND o.status <> 'cancelled'
                 ORDER BY p.id ASC
                 LIMIT 1"
            );

            if ($printing !== null) {
                // Something is on the printer. Only the front order may use it;
                // a photo from another order (a manual send) must finish first.
                if ((int) $printing['order_id'] !== $frontId) {
                    break;
                }

                $checkedAt = microtime(true);

                try {
                    $result = $epson->interpretJobStatus(
                        $epson->jobStatus((string) $printing['epson_job_id'])
                    );
                } catch (EpsonException $e) {
                    if (!$e->transport) {
                        $errors[] = $e->getMessage();
                        break;
                    }

                    // A dropped connection is not a print failure: the sheet is
                    // still printing, so keep the run alive and try again, but
                    // back off so a throttling Epson endpoint is not hammered.
                    $transportFailures++;
                    $transportNote ??= 'Epson did not answer a status check; retrying.';
                    usleep(min(30, PRINT_POLL_SECONDS << min(3, $transportFailures)) * 1000000);
                    continue;
                }

                $transportFailures = 0;

                if ($result['status'] !== 'printing') {
                    db_update('order_photos', [
                        'status'        => $result['status'],
                        'printed_at'    => $result['status'] === 'completed' ? now() : null,
                        'error_message' => $result['status'] === 'failed' ? $result['detail'] : null,
                    ], 'id = ?', [$printing['id']]);

                    if ($result['status'] === 'failed') {
                        $failedTotal++;
                    }

                    sync_order_status((int) $printing['order_id'], 'Cron');

                    // The printer is free now: start the photo that was uploaded
                    // while this sheet printed, then upload the next one.
                    if ($prepared !== null && $result['status'] !== 'printing') {
                        $started = start_prepared_photo($epson, $frontOrder, $prepared['photo'], $prepared['job']);
                        $prepared = null;

                        if ($started['ok']) {
                            $sentTotal++;
                            sync_order_status($frontId, 'Cron');
                            $prepared = $prepareNext($frontOrder);
                        } elseif (!empty($started['skipped'])) {
                            $skippedTotal++;
                        } elseif (!empty($started['transient'])) {
                            $messages[] = $frontRef . ': ' . $started['error'];
                        } else {
                            $failedTotal++;
                            $errors[] = $frontRef . ': ' . $started['error'];
                        }
                    }
                }

                // The status call itself takes several seconds, so only wait what
                // is left of the poll interval and start the next sheet sooner.
                $wait = PRINT_POLL_SECONDS - (microtime(true) - $checkedAt);

                if ($wait > 0) {
                    usleep((int) round($wait * 1000000));
                }

                continue;
            }

            // The printer is free: start the photo uploaded for it, if any.
            if ($prepared !== null) {
                $started  = start_prepared_photo($epson, $frontOrder, $prepared['photo'], $prepared['job']);
                $prepared = null;

                if ($started['ok']) {
                    $sentTotal++;
                    sync_order_status($frontId, 'Cron');
                    $prepared = $prepareNext($frontOrder);
                    continue;
                }

                if (!empty($started['skipped'])) {
                    $skippedTotal++;
                    continue;
                }

                if (!empty($started['transient'])) {
                    $messages[] = $frontRef . ': ' . $started['error'];
                    break;
                }

                $failedTotal++;
                $errors[] = $frontRef . ': ' . $started['error'];
                break;
            }

            // Nothing is uploaded yet: send the next photo of the front order.
            $pending = db_one(
                'SELECT ' . order_photo_columns() . '
                 FROM order_photos
                 WHERE order_id = ? AND status = \'pending\'
                 ORDER BY position ASC, id ASC
                 LIMIT 1',
                [$frontId]
            );

            if ($pending === null) {
                // Only failed photos left in front; the next run moves on.
                break;
            }

            $result = send_photo_to_printer($epson, $frontOrder, $pending, ['pending']);

            if (!empty($result['skipped'])) {
                $skippedTotal++;
                usleep(500000);
                continue;
            }

            if (!$result['ok']) {
                if (!empty($result['transient'])) {
                    // Nothing reached the printer: try again on the next run
                    // rather than reporting a failure or a failed photo.
                    $messages[] = $frontOrder['reference'] . ': ' . $result['error'];
                    break;
                }

                $failedTotal++;
                $errors[] = $frontOrder['reference'] . ': ' . $result['error'];
                break;
            }

            $sentTotal++;

            // Mark the order as printing straight away (not only when a photo
            // finishes) so the dashboard reflects the active order.
            sync_order_status($frontId, 'Cron');

            // Upload the following photo now, while this sheet prints, so the
            // printer starts it as soon as Epson reports this one finished.
            $prepared = $prepareNext($frontOrder);
        }

        // A job uploaded for the next photo that never got started (the slice
        // ended while this sheet was still printing) is dropped, so unstarted
        // jobs do not build up in the Epson account.
        if ($prepared !== null) {
            try {
                $epson->cancelJob((string) ($prepared['job']['jobId'] ?? ''));
            } catch (Throwable) {
                // Epson also expires jobs that are never started.
            }

            $prepared = null;
        }

        if ($sentTotal > 0) {
            $messages[] = $frontRef . ': ' . $sentTotal . ' photo'
                . ($sentTotal === 1 ? '' : 's') . ' sent one at a time.';
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
            'SELECT p.id, p.order_id, p.status, p.epson_job_id
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
                if ($e->transport) {
                    $transportNote ??= 'Epson did not answer a status check; retrying.';
                } else {
                    $errors[] = $e->getMessage();
                }
            }
        }

        if (isset($transportNote)) {
            $messages[] = $transportNote;
        }

        foreach (array_keys($touchedOrders) as $orderId) {
            sync_order_status((int) $orderId, 'Cron');
        }

        // Final completion sweep: even if no job was checked in this run, an
        // order whose photos are all completed must not stay stuck as
        // "printing". sync_order_status records the completion event.
        $finishedOrders = db_all(
            "SELECT o.id
             FROM orders o
             WHERE o.status IN ('accepted', 'printing', 'failed')
               AND EXISTS (SELECT 1 FROM order_photos p WHERE p.order_id = o.id)
               AND NOT EXISTS (
                   SELECT 1 FROM order_photos p
                   WHERE p.order_id = o.id AND p.status <> 'completed'
               )"
        );
        $completedOrders = 0;

        foreach ($finishedOrders as $finishedOrder) {
            if (sync_order_status((int) $finishedOrder['id'], 'Cron') === 'completed') {
                $completedOrders++;
            }
        }

        if ($completedOrders > 0) {
            $messages[] = $completedOrders . ' order' . ($completedOrders === 1 ? '' : 's') . ' marked completed.';
        }

        if ($checked > 0) {
            $messages[] = $checked . ' Epson job' . ($checked === 1 ? '' : 's') . ' checked.';
        }
    }

    // Photos the printer never reports back on are released here, whether or
    // not the printer is currently linked, so an order cannot sit in
    // "printing" for ever.
    $stuck = release_stuck_photos('Cron');

    if ($stuck !== []) {
        $messages[] = count($stuck) . ' stuck photo' . (count($stuck) === 1 ? ' was' : 's were')
            . ' released after ' . PRINT_JOB_STUCK_MINUTES . ' minutes for a retry.';
    }

    // An order accepted with no photos can never print. Close it instead of
    // leaving an empty job in the queue for ever.
    $emptyOrders = db_all(
        "SELECT o.id
         FROM orders o
         WHERE o.status IN ('accepted', 'printing')
           AND NOT EXISTS (SELECT 1 FROM order_photos p WHERE p.order_id = o.id)"
    );

    foreach ($emptyOrders as $emptyOrder) {
        update_order_status(
            (int) $emptyOrder['id'],
            'cancelled',
            'Cancelled automatically: the order has no photos to print.',
            'Cron'
        );
    }

    if ($emptyOrders !== []) {
        $messages[] = count($emptyOrders) . ' empty order' . (count($emptyOrders) === 1 ? '' : 's') . ' closed.';
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

// A connection that never opened is not a failed run: nothing was lost and
// the worker is retrying, so report it as a warning the dashboard can show.
$transportTrouble = isset($transportNote);
$lastError        = implode(' ', array_unique($errors));

if ($lastError === '' && $transportTrouble) {
    $lastError = 'Epson did not answer a status check; the worker is retrying. No photos were lost.';
}

set_setting('print_queue_last_finished', now());
set_setting(
    'print_queue_last_status',
    $exitCode === 0 ? ($transportTrouble ? 'warning' : 'ok') : 'error'
);
set_setting('print_queue_last_error', mb_substr($lastError, 0, 1000));

if ($exitCode === 0) {
    set_setting('print_queue_last_success', now());
}

exit($exitCode);

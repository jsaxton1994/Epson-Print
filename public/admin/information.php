<?php
/**
 * Information: the queue's own health, the service health and what the
 * database is holding. Read-only, so nothing here can change a print.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

require_admin();

$queueHealth = queue_health();
$queueWorkerState = $queueHealth['paused']
    ? 'Paused'
    : ($queueHealth['worker_never_run']
    ? 'Never run'
    : ($queueHealth['worker_stale']
        ? 'Stopped'
        : ($queueHealth['run_in_progress']
            ? 'Working'
            : ($queueHealth['last_status'] === 'error'
            ? 'Failing'
            : ($queueHealth['last_status'] === 'warning' ? 'Retrying' : 'Healthy')))));
$queueWatchdogState = $queueHealth['watchdog_last_run'] === null
    ? 'Never run'
    : ($queueHealth['watchdog_stale'] ? 'Stale' : ($queueHealth['watchdog_status'] === 'error' ? 'Failing' : 'Healthy'));

$counts = db_one(
    "SELECT COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed,
            COUNT(*) AS total
     FROM orders"
) ?? ['failed' => 0, 'total' => 0];

app_start('admin', 'information', 'Information', 'How the queue, the print service and the database are doing.');
?>

<div class="settings-layout">
    <div class="stack">
        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Queue monitoring</h3>
                    <p>Worker heartbeat and alert status</p>
                </div>
            </div>
            <div class="panel-body">
                <div class="summary-list" style="margin:0">
                    <div class="summary-row"><span>Queue worker</span><strong><?= e($queueWorkerState) ?></strong></div>
                    <div class="summary-row"><span>Worker last run</span><strong><?= $queueHealth['last_run'] !== null ? e(time_ago((string) $queueHealth['last_run'])) : 'Never' ?></strong></div>
                    <div class="summary-row"><span>Sign of life</span><strong><?php
                        if (!$queueHealth['run_in_progress']) {
                            echo 'No run in progress';
                        } elseif ($queueHealth['liveness_age'] === null) {
                            echo 'No heartbeat yet';
                        } else {
                            echo (int) $queueHealth['liveness_age'] . ' s ago';
                        }
                    ?></strong></div>
                    <div class="summary-row"><span>Watchdog</span><strong><?= e($queueWatchdogState) ?></strong></div>
                    <div class="summary-row"><span>Oldest accepted</span><strong><?= $queueHealth['oldest_reference'] !== null ? e((string) $queueHealth['oldest_reference']) . ' · ' . (int) floor(((int) $queueHealth['oldest_age']) / 60) . ' min' : 'None' ?></strong></div>
                    <div class="summary-row"><span>Queue alerts</span><strong><?= e($queueHealth['alert_email'] !== '' ? (string) $queueHealth['alert_email'] : 'Not configured') ?></strong></div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h3>Service health</h3></div>
            <div class="panel-body">
                <div class="summary-list">
                    <div class="summary-row"><span>Queue status</span><strong><?= setting('accepting_orders', '1') === '1' ? 'Accepting orders' : 'Paused' ?></strong></div>
                    <div class="summary-row"><span>Failed jobs</span><strong><?= (int) $counts['failed'] ?></strong></div>
                    <div class="summary-row"><span>All-time orders</span><strong><?= (int) $counts['total'] ?></strong></div>
                    <div class="summary-row"><span>Photos per order</span><strong>Up to <?= (int) MAX_PHOTOS_PER_ORDER ?></strong></div>
                    <div class="summary-row"><span>Price per print</span><strong>Free</strong></div>
                </div>
                <div class="free-callout"><?= icon('sparkles') ?> Every photo prints at no cost</div>
            </div>
        </section>
    </div>

    <div class="stack">
        <section class="panel">
            <div class="panel-head"><h3>Storage</h3></div>
            <div class="panel-body">
                <div class="summary-list">
                    <div class="summary-row"><span>Driver</span><strong><?= e(strtoupper(db_driver())) ?></strong></div>
                    <div class="summary-row"><span>Photos per order</span><strong><?= (int) MAX_PHOTOS_PER_ORDER ?></strong></div>
                    <div class="summary-row"><span>Photos per batch</span><strong><?= (int) MAX_PHOTOS_PER_BATCH ?></strong></div>
                    <div class="summary-row"><span>Upload limit</span><strong><?= e(MAX_UPLOAD_MB) ?> MB each</strong></div>
                    <div class="summary-row"><span>Order storage cap</span><strong><?= order_storage_limit_bytes() !== null ? e(human_size(order_storage_limit_bytes())) : 'No cap' ?></strong></div>
                    <div class="summary-row"><span>Database size</span><strong><?= e(human_size(database_size_bytes())) ?></strong></div>
                    <div class="summary-row"><span>Price per order</span><strong>Free</strong></div>
                </div>
                <p class="help">Photos are stored as BLOBs inside the database, not as files on disk. Large orders are added in batches and capped so one order cannot grow without bound.</p>
            </div>
        </section>
    </div>
</div>

<?php app_end(); ?>

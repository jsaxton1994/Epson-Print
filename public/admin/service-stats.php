<?php
/**
 * Service Stats: the queue's own health, the printer's last known state and
 * the service health. Read-only apart from the printer refresh, so nothing here
 * can change a print.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

require_admin();
$epson = EpsonClient::fromSettings();

if (is_post()) {
    verify_csrf();

    if (input('action') === 'refresh') {
        @set_time_limit(60);
        set_setting('printer_status_cache', '');

        $fresh = printer_live_status($epson);

        if (($fresh['state'] ?? '') === 'unreachable') {
            flash('error', 'Epson did not answer: ' . (string) ($fresh['detail'] ?? 'connection failed'));
        } else {
            flash('info', 'Printer status refreshed.');
        }

        redirect('admin/service-stats.php');
    }
}

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

$status        = printer_live_status($epson, false);
$supplyHold    = printer_supply_block();
$pendingPhotos = (int) db_val("SELECT COUNT(*) FROM order_photos WHERE status = 'pending'" . order_photo_only());
$waitingOrders = (int) $queueHealth['queued'];

app_start('admin', 'service-stats', 'Service Stats', 'How the queue, the printer and the service are doing.');
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
            <div class="panel-head">
                <div>
                    <h3>Printer right now</h3>
                    <p>
                        <?= !empty($status['never_checked']) ? 'Not checked yet' : 'Last checked ' . e(time_ago((string) $status['checked_at'])) ?><?= !empty($status['stale']) ? ' · cached while Epson is unreachable' : '' ?>
                    </p>
                </div>
                <form method="post" action="<?= e(url('admin/service-stats.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="refresh">
                    <button class="btn btn-soft btn-sm" type="submit"><?= icon('refresh') ?> Refresh</button>
                </form>
            </div>
            <div class="panel-body">
                <div class="summary-list" style="margin:0">
                    <div class="summary-row"><span>Status</span><strong><?= e($status['label']) ?></strong></div>
                    <div class="summary-row"><span>Detail</span><strong style="text-align:right"><?= e($status['detail']) ?></strong></div>
                    <?php if (!empty($status['order_reference'])): ?>
                        <div class="summary-row"><span>Order on printer</span><strong><?= e((string) $status['order_reference']) ?></strong></div>
                    <?php endif; ?>
                    <?php if (isset($status['connected'])): ?>
                        <div class="summary-row"><span>Linked to Epson Connect</span><strong><?= $status['connected'] ? 'Yes' : 'No' ?></strong></div>
                    <?php endif; ?>
                    <div class="summary-row"><span>Paper / ink hold</span><strong><?= $supplyHold['active'] ? e($supplyHold['label']) : 'None' ?></strong></div>
                    <div class="summary-row"><span>Waiting orders</span><strong><?= $waitingOrders ?></strong></div>
                    <div class="summary-row"><span>Photos waiting</span><strong><?= $pendingPhotos ?></strong></div>
                </div>
            </div>
        </section>
    </div>

    <div class="stack">
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
</div>

<?php app_end(); ?>

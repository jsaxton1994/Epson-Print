<?php
/**
 * Admin dashboard: photo queue, printer health and the numbers that matter.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$admin = require_admin();
$epson = EpsonClient::fromSettings();

if (is_post()) {
    verify_csrf();

    if (input('action') === 'printer_refresh') {
        $fresh = printer_live_status_fresh($epson);

        if (($fresh['state'] ?? '') === 'unreachable') {
            flash('error', 'Epson did not answer: ' . (string) ($fresh['detail'] ?? 'connection failed'));
        } else {
            flash('info', 'Printer state refreshed.');
        }

        redirect('admin/index.php');
    }
}

$counts = db_one(
    "SELECT COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END), 0) AS accepted,
            COALESCE((
                SELECT COUNT(DISTINCT o.id) FROM orders o
                WHERE o.status = 'printing'
                   OR EXISTS (SELECT 1 FROM order_photos p WHERE p.order_id = o.id AND p.status = 'printing')
            ), 0) AS printing,
            COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed,
            COUNT(*) AS total
     FROM orders"
) ?? ['pending' => 0, 'accepted' => 0, 'printing' => 0, 'failed' => 0, 'total' => 0];

$queueHealth = queue_health();
$epsonOutage = epson_outage_state();
$attention = orders_needing_attention();

$printedToday = (int) db_val(
    "SELECT COUNT(*) FROM orders WHERE status = 'completed' AND printed_at >= ?",
    [date('Y-m-d') . ' 00:00:00']
);

$customerCount = (int) db_val("SELECT COUNT(*) FROM users WHERE role = 'customer'");
$photosToday   = (int) db_val(
    "SELECT COUNT(*) FROM order_photos WHERE status = 'completed' AND printed_at >= ?" . order_photo_only(),
    [date('Y-m-d') . ' 00:00:00']
);

$photoSelect = "
    (SELECT COUNT(*) FROM order_photos p WHERE p.order_id = o.id" . order_photo_only('p') . ") AS photo_count,
    (SELECT p.id FROM order_photos p WHERE p.order_id = o.id ORDER BY p.position ASC, p.id ASC LIMIT 1) AS photo_id,
    (SELECT p.file_name FROM order_photos p WHERE p.order_id = o.id ORDER BY p.position ASC, p.id ASC LIMIT 1) AS photo_name";

// Same order the worker uses: the one accepted longest ago prints first.
$queueOrder = accepted_at_column_ready()
    ? 'COALESCE(o.accepted_at, o.created_at) ASC, o.id ASC'
    : 'o.created_at ASC, o.id ASC';

$queue = db_all(
    "SELECT o.*, u.name AS customer, {$photoSelect}
     FROM orders o JOIN users u ON u.id = o.user_id
     WHERE o.status IN ('pending', 'accepted', 'printing', 'failed')
     ORDER BY o.status = 'printing' DESC, " . $queueOrder . "
     LIMIT 6"
);

// The photo the worker has on the printer: its order, position and how much of
// that order is already done, so the dashboard can name the active order.
$hasPrintStarted = print_started_column_ready();
$hasPhotoKind    = order_photo_kind_column_ready();
$printingNowOrder = db_one(
    "SELECT o.id, o.reference, u.name AS customer, o.paper_size, p.position, p.id AS photo_id,"
    . ($hasPhotoKind ? ' p.kind,' : '')
    . ($hasPrintStarted ? ' p.print_started_at,' : ' NULL AS print_started_at,')
    . " (SELECT COUNT(*) FROM order_photos x WHERE x.order_id = o.id" . order_photo_only('x') . ") AS photo_total,
            (SELECT COUNT(*) FROM order_photos x WHERE x.order_id = o.id AND x.printed_at IS NOT NULL" . order_photo_only('x') . ") AS photo_done
     FROM order_photos p
     JOIN orders o ON o.id = p.order_id
     JOIN users u ON u.id = o.user_id
     WHERE p.status = 'printing'
     ORDER BY " . ($hasPrintStarted ? 'p.print_started_at DESC, ' : '') . "p.id DESC
     LIMIT 1"
);

// Labelled as the queue's own record: Printer Settings shows what Epson
// reports, so the two can differ for a moment while a sheet finishes.
$printingHint = 'Queue record · nothing on the printer';

if ($printingNowOrder !== null) {
    $printingHint = 'Queue record · ' . (string) $printingNowOrder['reference'];

    if (($printingNowOrder['kind'] ?? 'photo') === 'details') {
        $printingHint .= ' · order details sheet';
    } else {
        $printingHint .= ' · photo ' . max(1, (int) $printingNowOrder['position'])
            . ' of ' . max(1, (int) $printingNowOrder['photo_total']);
    }
} elseif ($queueHealth['paused']) {
    $printingHint = 'Queue record · paused — the queue is holding';
}

// Epson's last cached reading, taken from the settings cache only: a dashboard
// load never calls Epson. The age is shown so a queue/Epson mismatch is obvious.
$printerReading = printer_live_status($epson, false);
$epsonHint      = 'Epson: ' . (string) ($printerReading['label'] ?? 'not checked yet');

if (!empty($printerReading['never_checked'])) {
    $epsonHint = 'Epson: not checked yet';
} elseif (!empty($printerReading['checked_at'])) {
    $epsonHint .= ' · ' . time_ago((string) $printerReading['checked_at']);

    if (!empty($printerReading['stale'])) {
        $epsonHint .= ' (last known)';
    }
}

$printingHint .= ' — ' . $epsonHint;

// Paper status at a glance: the printer's last reported stop, read from the
// settings cache so a dashboard load never calls Epson.
$supplyHold = printer_supply_block();

$paperTone  = 'brand';
$paperValue = 'Ready';
$paperHint  = 'Photo paper loaded; the queue is sending sheets.';

// An empty tray or a jam is the operator's most urgent problem, so it gets a
// card state and an alert banner at the top of the dashboard.
$paperStop = printer_paper_stop($supplyHold, $printerReading);

if ($paperStop !== null) {
    $paperTone  = 'amber';
    $paperValue = $paperStop['reason'] === 'media_jam' ? 'Paper jam' : 'Out of paper';
    $paperHint  = $paperStop['held']
        ? 'Printing is held ' . ($paperStop['since'] !== '' ? time_ago($paperStop['since']) : 'just now') . '; load photo paper and clear the hold.'
        : 'Epson last reported this ' . time_ago((string) $printerReading['checked_at']) . '; load photo paper on the printer.';
}

$recent = db_all(
    "SELECT o.*, u.name AS customer, {$photoSelect}
     FROM orders o JOIN users u ON u.id = o.user_id
     ORDER BY o.id DESC
     LIMIT 6"
);

app_start('admin', 'dashboard', 'Dashboard', 'Photo queue, printer health and service activity for ' . site_name() . '.');
?>

<?php if (!$epson->isConfigured()): ?>
    <div class="banner banner-warn">
        <?= icon('alert') ?>
        <div>
            <strong>Epson Connect is not set up yet</strong>
            <p>Add your Client ID, Client Secret and API Key, then link the ET-2926. Until then you can still review and manage photo orders; printing starts once the printer is linked.</p>
            <div class="banner-actions">
                <a class="btn btn-primary btn-sm" href="<?= e(url('admin/printer-settings.php')) ?>"><?= icon('printer') ?> Open printer settings</a>
            </div>
        </div>
    </div>
<?php elseif (!$epson->isConnected()): ?>
    <div class="banner banner-info">
        <?= icon('printer') ?>
        <div>
            <strong>Printer credentials saved — one link to go</strong>
            <p>Authorise <?= e(EPSON_PRINTER_MODEL) ?> in Printer Settings so photo jobs can be sent without manual steps.</p>
            <div class="banner-actions">
                <a class="btn btn-primary btn-sm" href="<?= e(url('admin/printer-settings.php')) ?>"><?= icon('wifi') ?> Connect the printer</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($paperStop !== null): ?>
    <div class="banner banner-danger" role="alert">
        <?= icon('alert') ?>
        <div>
            <strong><?= $paperStop['reason'] === 'media_jam' ? 'Paper jam — printing has stopped' : 'The printer is out of photo paper' ?></strong>
            <p>
                <?php if ($paperStop['held']): ?>
                    The printer stopped <?= $paperStop['since'] !== '' ? e(time_ago($paperStop['since'])) : 'just now' ?>.
                    The queue is held, so no photo fails against an empty tray.
                <?php else: ?>
                    Epson last reported the stop <?= !empty($printerReading['checked_at']) ? e(time_ago((string) $printerReading['checked_at'])) : 'just now' ?>.
                    The next worker pass holds the queue rather than feeding it another sheet.
                <?php endif; ?>
                Load photo paper on the <?= e(EPSON_PRINTER_MODEL) ?>, then clear the hold.
            </p>
            <div class="banner-actions">
                <a class="btn btn-danger btn-sm" href="<?= e(url('admin/maintenance.php')) ?>"><?= icon('printer') ?> Load paper &amp; clear the hold</a>
            </div>
        </div>
    </div>
<?php elseif ($supplyHold['active']): ?>
    <div class="banner banner-danger">
        <?= icon('alert') ?>
        <div>
            <strong>Printing is held: <?= e($supplyHold['label']) ?></strong>
            <p>
                The printer has been stopped since
                <?= $supplyHold['since'] !== '' ? e(time_ago($supplyHold['since'])) : 'just now' ?>.
                No new photos are sent until the hold is cleared, so the queue waits instead of failing.
            </p>
            <div class="banner-actions">
                <a class="btn btn-danger btn-sm" href="<?= e(url('admin/maintenance.php')) ?>"><?= icon('printer') ?> Clear the hold</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($attention['count'] > 0): ?>
    <div class="banner banner-danger">
        <?= icon('alert') ?>
        <div>
            <strong><?= (int) $attention['count'] ?> order<?= $attention['count'] === 1 ? ' needs' : 's need' ?> reprinting</strong>
            <p>
                <?= (int) $attention['photos'] ?> photo<?= $attention['photos'] === 1 ? '' : 's' ?> failed at the printer.
                Open the order and press <strong>Retry &amp; queue</strong> to send
                <?= $attention['photos'] === 1 ? 'it' : 'them' ?> again.
            </p>
            <div class="banner-actions">
                <a class="btn btn-danger btn-sm" href="<?= e(url('admin/orders.php?status=failed')) ?>"><?= icon('printer') ?> Show failed orders</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($queueHealth['paused']): ?>
    <div class="banner banner-warn">
        <?= icon('clock') ?>
        <div>
            <strong>Printing is paused for maintenance</strong>
            <p>
                Nothing new is sent to the printer<?= $queueHealth['paused_note'] !== '' ? ': ' . e($queueHealth['paused_note']) : '' ?>.
                Photos stay queued in order and printing continues when you resume.
            </p>
            <div class="banner-actions">
                <a class="btn btn-soft btn-sm" href="<?= e(url('admin/printer-settings.php')) ?>"><?= icon('wrench') ?> Maintenance mode</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($queueHealth['worker_never_run'] && $queueHealth['queued'] > 0): ?>
    <div class="banner banner-warn">
        <?= icon('alert') ?>
        <div>
            <strong>The print-queue worker has never run</strong>
            <p><?= (int) $queueHealth['queued'] ?> accepted order<?= $queueHealth['queued'] === 1 ? ' is' : 's are' ?> waiting. Check the cron job and the print-queue log.</p>
        </div>
    </div>
<?php elseif ($queueHealth['worker_stale']): ?>
    <div class="banner banner-warn">
        <?= icon('alert') ?>
        <div>
            <strong>The print-queue worker has stopped</strong>
            <p>Last run <?= e(time_ago((string) $queueHealth['last_run'])) ?>. Check the cron job and the print-queue log.</p>
        </div>
    </div>
<?php elseif ($queueHealth['last_status'] === 'error'): ?>
    <div class="banner banner-warn">
        <?= icon('alert') ?>
        <div>
            <strong>The last print-queue run failed</strong>
            <p><?= e($queueHealth['last_error'] !== '' ? $queueHealth['last_error'] : 'Check the print-queue log for details.') ?></p>
        </div>
    </div>
<?php elseif ($epsonOutage['active'] && !$queueHealth['paused']): ?>
    <?php $outageMinutes = max(1, (int) floor(((int) $epsonOutage['age']) / 60)); ?>
    <div class="banner banner-warn">
        <?= icon('wifi') ?>
        <div>
            <strong>Epson Connect has been unreachable for <?= $outageMinutes ?> minute<?= $outageMinutes === 1 ? '' : 's' ?></strong>
            <p>
                <?= (int) $queueHealth['queued'] ?> order<?= $queueHealth['queued'] === 1 ? '' : 's' ?> waiting since
                <?= e(date('H:i', strtotime((string) $epsonOutage['since']))) ?>
                (<?= (int) $epsonOutage['attempts'] ?> failed attempt<?= $epsonOutage['attempts'] === 1 ? '' : 's' ?>).
                Printing resumes by itself as soon as Epson answers — no photo is lost.
                <?php if ($epsonOutage['alerted_at'] !== ''): ?>
                    Alert emailed <?= e(time_ago((string) $epsonOutage['alerted_at'])) ?>.
                <?php else: ?>
                    An alert email goes out after <?= (int) EPSON_OUTAGE_ALERT_MINUTES ?> minutes.
                <?php endif; ?>
                <a href="<?= e(url('admin/printer-settings.php')) ?>">Epson connection details</a>.
            </p>
        </div>
    </div>
<?php elseif ($queueHealth['last_status'] === 'warning'): ?>
    <div class="banner banner-info">
        <?= icon('clock') ?>
        <div>
            <strong>The last queue run could not reach Epson</strong>
            <p>
                <?= e($queueHealth['last_error'] !== '' ? $queueHealth['last_error'] : 'No photos were lost — the worker retries on its next run.') ?>
                <?php if ((string) $queueHealth['last_finished'] !== ''): ?>
                    Last run <?= e(time_ago((string) $queueHealth['last_finished'])) ?>.
                <?php endif; ?>
            </p>
        </div>
    </div>
<?php elseif ($queueHealth['queued'] > 0 && !$epson->isConnected()): ?>
    <div class="banner banner-warn">
        <?= icon('alert') ?>
        <div>
            <strong>Accepted orders are waiting for a printer</strong>
            <p><?= (int) $queueHealth['queued'] ?> order<?= $queueHealth['queued'] === 1 ? '' : 's' ?> cannot print until <?= e(EPSON_PRINTER_MODEL) ?> is linked. <a href="<?= e(url('admin/printer-settings.php')) ?>">Connect the printer</a>.</p>
        </div>
    </div>
<?php elseif ($queueHealth['queue_stalled']): ?>
    <div class="banner banner-warn">
        <?= icon('alert') ?>
        <div>
            <strong>The print queue is stalled</strong>
            <p><?php if ($queueHealth['progress_age'] === null): ?>No photo has reached the printer yet.<?php else: ?>Nothing has printed for <?= (int) floor(((int) $queueHealth['progress_age']) / 60) ?> minutes and the printer is idle.<?php endif; ?> Check the worker status below.</p>
        </div>
    </div>
<?php elseif ($queueHealth['watchdog_last_run'] === null): ?>
    <div class="banner banner-info">
        <?= icon('mail') ?>
        <div>
            <strong>Queue alert watchdog has not run yet</strong>
            <p>Add the watchdog cron job so a stopped worker or stuck accepted order sends an alert.</p>
        </div>
    </div>
<?php elseif ($queueHealth['watchdog_stale']): ?>
    <div class="banner banner-warn">
        <?= icon('mail') ?>
        <div>
            <strong>The queue alert watchdog has stopped</strong>
            <p>Last check <?= e(time_ago((string) $queueHealth['watchdog_last_run'])) ?>. Queue problems may not be emailed until it runs again.</p>
        </div>
    </div>
<?php endif; ?>

<div class="device-card">
    <div class="device-top">
        <span class="device-label">Epson Connect</span>
        <?php if ($epson->isConnected()): ?>
            <span class="device-state"><span class="status-dot"></span> Linked</span>
        <?php else: ?>
            <span class="device-state is-off">Not linked</span>
        <?php endif; ?>
    </div>
    <div class="device-name">
        <span class="device-icon"><?= icon('printer') ?></span>
        <div>
            <h3><?= e($epson->isConnected() ? $epson->deviceName() : EPSON_PRINTER_MODEL) ?></h3>
            <p>
                <?php if ($epson->isConnected()): ?>
                    Serial <?= e(setting('epson_device_serial', '') !== '' ? (string) setting('epson_device_serial', '') : 'not reported') ?> · Connect v2
                <?php else: ?>
                    Authorise the printer to send jobs automatically
                <?php endif; ?>
            </p>
        </div>
    </div>
    <div class="device-meta">
        <span class="device-chip"><?= icon('image') ?> Photo mode</span>
        <span class="device-chip"><?= icon('droplet') ?> 4-colour EcoTank</span>
        <?php if (setting('accepting_orders', '1') === '1'): ?>
            <span class="device-chip"><?= icon('check') ?> Queue open</span>
        <?php else: ?>
            <span class="device-chip"><?= icon('clock') ?> Queue paused</span>
        <?php endif; ?>
    </div>
    <div class="device-actions">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="printer_refresh">
            <button class="btn btn-sm btn-primary" type="submit"><?= icon('refresh') ?> Refresh printer state</button>
        </form>
        <a class="btn btn-sm" href="<?= e(url('admin/printer-settings.php')) ?>"><?= icon('printer') ?> Printer settings</a>
    </div>
</div>

<p class="muted" style="font-size:.8rem;margin:10px 2px 18px">
    Epson Connect · <?= !empty($printerReading['never_checked']) ? 'not checked yet' : 'checked ' . e(time_ago((string) $printerReading['checked_at'])) ?>
    <?= !empty($printerReading['stale']) ? ' · last known reading — press Refresh to ask Epson' : '' ?>
</p>

<div class="stats-grid is-stacked">
    <?php stat_card('Paper', $paperValue, 'file', $paperTone, $paperHint); ?>
    <?php stat_card('Printer (Epson)', (string) $printerReading['label'], 'printer', 'brand', (string) $printerReading['detail']); ?>
</div>

<?php if ($attention['count'] > 0): ?>
<section class="panel">
    <div class="panel-head">
        <div>
            <h3>Needs attention</h3>
            <p>Failed prints waiting for a retry, oldest first</p>
        </div>
        <a class="panel-link" href="<?= e(url('admin/orders.php?status=failed')) ?>">Failed orders <?= icon('arrow-right') ?></a>
    </div>
    <ul class="queue-list">
        <?php foreach ($attention['orders'] as $failedOrder): ?>
            <?php
            $failedCount = (int) $failedOrder['failed_photos'];
            $failedTotal = (int) $failedOrder['photo_count'];
            ?>
            <li class="queue-item">
                <span class="doc-text">
                    <span class="doc-name"><?= e($failedOrder['reference']) ?> · <?= e($failedOrder['customer']) ?></span>
                    <span class="doc-meta">
                        <?= $failedCount ?> of <?= $failedTotal ?> photo<?= $failedTotal === 1 ? '' : 's' ?> failed ·
                        updated <?= e(time_ago((string) $failedOrder['updated_at'])) ?>
                    </span>
                    <?php if (!empty($failedOrder['error_message'])): ?>
                        <span class="doc-meta"><?= e(mb_substr((string) $failedOrder['error_message'], 0, 120)) ?></span>
                    <?php endif; ?>
                </span>
                <span class="queue-side"><?= status_badge($failedOrder['status']) ?></span>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/order.php?id=' . (int) $failedOrder['id'])) ?>">Open</a>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<div class="stats-grid" style="margin-top:20px">
    <?php
    stat_card('Waiting in queue', (string) $counts['accepted'], 'clock', 'amber', $counts['pending'] > 0 ? $counts['pending'] . ' awaiting acceptance' : 'No new orders');
    stat_card('Printing now', (string) $counts['printing'], 'printer', 'violet', $printingHint);
    stat_card('Photos printed today', (string) $photosToday, 'check', 'accent', $printedToday . ' ' . ($printedToday === 1 ? 'order' : 'orders'));
    stat_card('Customers', (string) $customerCount, 'users', 'brand');
    ?>
</div>

<div class="columns">
    <div class="stack">
        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Live photo queue</h3>
                    <p>Oldest jobs first</p>
                </div>
                <a class="panel-link" href="<?= e(url('admin/orders.php')) ?>">All orders <?= icon('arrow-right') ?></a>
            </div>

            <?php if ($queue === []): ?>
                <div class="queue-empty">The queue is empty. New photo orders will appear here.</div>
            <?php else: ?>
                <ul class="queue-list">
                    <?php foreach ($queue as $order): ?>
                        <?php $count = (int) $order['photo_count']; ?>
                        <li class="queue-item">
                            <?php if (!empty($order['photo_id'])): ?>
                                <img class="thumb" src="<?= e(url('admin/photo.php?photo=' . (int) $order['photo_id'] . '&thumb=1')) ?>"
                                     alt="" loading="lazy" width="42" height="42">
                            <?php endif; ?>
                            <span class="doc-text">
                                <span class="doc-name"><?= e($order['photo_name'] ?: 'Photos') ?><?= $count > 1 ? ' +' . ($count - 1) : '' ?></span>
                                <span class="doc-meta">
                                    <?= e($order['reference']) ?> · <?= e($order['customer']) ?> ·
                                    <?= e(paper_size_label($order['paper_size'])) ?> · <?= $count ?> photo<?= $count === 1 ? '' : 's' ?>
                                </span>
                            </span>
                            <span class="queue-side">
                                <?= status_badge($order['status']) ?>
                                <span><?= e(time_ago($order['created_at'])) ?></span>
                            </span>
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/order.php?id=' . (int) $order['id'])) ?>">Open</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Latest orders</h3>
                    <p>Every new photo submission</p>
                </div>
                <a class="panel-link" href="<?= e(url('admin/orders.php')) ?>">Manage <?= icon('arrow-right') ?></a>
            </div>

            <?php if ($recent === []): ?>
                <div class="queue-empty">No photo orders have been submitted yet.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Photos</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th class="nowrap">Age</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent as $order): ?>
                            <?php $count = (int) $order['photo_count']; ?>
                            <tr>
                                <td>
                                    <div class="doc-cell">
                                        <?php if (!empty($order['photo_id'])): ?>
                                            <img class="thumb" src="<?= e(url('admin/photo.php?photo=' . (int) $order['photo_id'] . '&thumb=1')) ?>"
                                                 alt="" loading="lazy" width="42" height="42">
                                        <?php endif; ?>
                                        <span class="doc-text">
                                            <span class="doc-name"><?= e($order['photo_name'] ?: 'Photos') ?><?= $count > 1 ? ' +' . ($count - 1) : '' ?></span>
                                            <span class="doc-meta"><?= e($order['reference']) ?> · <?= $count ?> photo<?= $count === 1 ? '' : 's' ?></span>
                                        </span>
                                    </div>
                                </td>
                                <td class="nowrap"><?= e($order['customer']) ?></td>
                                <td><?= status_badge($order['status']) ?></td>
                                <td class="nowrap muted"><?= e(time_ago($order['created_at'])) ?></td>
                                <td class="cell-actions">
                                    <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/order.php?id=' . (int) $order['id'])) ?>">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="stack">
        <section class="panel">
            <div class="panel-head"><h3>Quick links</h3></div>
            <div class="panel-body pad-sm">
                <ul class="check-list" style="gap:12px">
                    <li><?= icon('layers') ?><span><a href="<?= e(url('admin/orders.php?status=accepted')) ?>">Automatic print queue</a></span></li>
                    <li><?= icon('clock') ?><span><a href="<?= e(url('admin/orders.php?status=pending')) ?>">New orders awaiting acceptance</a></span></li>
                    <li><?= icon('users') ?><span><a href="<?= e(url('admin/customers.php')) ?>">Customer directory</a></span></li>
                    <li><?= icon('gear') ?><span><a href="<?= e(url('admin/site-settings.php')) ?>">Site settings</a> · <a href="<?= e(url('admin/printer-settings.php')) ?>">printer settings</a></span></li>
                    <li><?= icon('tiro') ?><span><a href="<?= e(url('admin/service-stats.php')) ?>">Queue monitoring, printer &amp; service health</a></span></li>
                </ul>
            </div>
        </section>
    </div>
</div>

<?php app_end(); ?>

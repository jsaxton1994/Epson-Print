<?php
/**
 * Customer dashboard.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$user = require_login();
if ($user['role'] === 'admin') {
    redirect('admin/index.php');
}

$stats = db_one(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status IN ('pending', 'accepted') THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN status = 'printing' THEN 1 ELSE 0 END), 0) AS printing,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) AS completed
     FROM orders WHERE user_id = ?",
    [$user['id']]
) ?? ['total' => 0, 'pending' => 0, 'printing' => 0, 'completed' => 0];

$photosPrinted = (int) db_val(
    "SELECT COUNT(*) FROM order_photos p JOIN orders o ON o.id = p.order_id
     WHERE o.user_id = ? AND p.status = 'completed'" . order_photo_only('p'),
    [$user['id']]
);

$recent = db_all(
    "SELECT o.*,
            (SELECT COUNT(*) FROM order_photos p WHERE p.order_id = o.id" . order_photo_only('p') . ") AS photo_count,
            (SELECT p.id FROM order_photos p WHERE p.order_id = o.id ORDER BY p.position ASC, p.id ASC LIMIT 1) AS photo_id,
            (SELECT p.file_name FROM order_photos p WHERE p.order_id = o.id ORDER BY p.position ASC, p.id ASC LIMIT 1) AS photo_name
     FROM orders o
     WHERE o.user_id = ?
     ORDER BY o.id DESC
     LIMIT 5",
    [$user['id']]
);

$active = (int) $stats['pending'] + (int) $stats['printing'];
$epson  = EpsonClient::fromSettings();

app_start('customer', 'dashboard', 'Dashboard', 'Welcome back, ' . $user['name'] . '. Here is your photo printing at a glance.');
?>

<div class="stats-grid">
    <?php
    stat_card('Total orders', (string) $stats['total'], 'layers', 'brand');
    stat_card('In queue', (string) $stats['pending'], 'clock', 'amber');
    stat_card('Printing now', (string) $stats['printing'], 'printer', 'violet');
    stat_card('Photos printed', (string) $photosPrinted, 'check', 'accent');
    ?>
</div>

<?php if ($active === 0): ?>
    <div class="banner banner-info">
        <?= icon('sparkles') ?>
        <div>
            <strong>Nothing waiting — the printer is all yours.</strong>
            <p>Select up to <?= (int) MAX_PHOTOS_PER_ORDER ?> photos in one order — they upload <?= (int) MAX_PHOTOS_PER_BATCH ?> at a time and queue straight away. Every print is free, 4 × 6 included.</p>
            <div class="banner-actions">
                <a class="btn btn-primary btn-sm" href="<?= e(url('customer/new-order.php')) ?>"><?= icon('upload') ?> Print photos</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="columns">
    <section class="panel">
        <div class="panel-head">
            <div>
                <h3>Recent prints</h3>
                <p>Your five latest photo orders</p>
            </div>
            <a class="panel-link" href="<?= e(url('customer/orders.php')) ?>">View all <?= icon('arrow-right') ?></a>
        </div>

        <?php if ($recent === []): ?>
            <?php empty_state(
                'image',
                'No prints yet',
                'Your photo orders will appear here with a live status for each one.',
                '<a class="btn btn-primary btn-sm" href="' . e(url('customer/new-order.php')) . '">' . icon('upload') . ' Upload photos</a>'
            ); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Photos</th>
                        <th>Status</th>
                        <th class="nowrap">Submitted</th>
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
                                        <img class="thumb" src="<?= e(url('customer/photo.php?photo=' . (int) $order['photo_id'] . '&thumb=1')) ?>"
                                             alt="" loading="lazy" width="42" height="42">
                                    <?php endif; ?>
                                    <span class="doc-text">
                                        <span class="doc-name"><?= e($order['photo_name'] ?: 'Photos') ?><?= $count > 1 ? ' +' . ($count - 1) : '' ?></span>
                                        <span class="doc-meta"><?= e($order['reference']) ?> · <?= e(paper_size_label($order['paper_size'])) ?> · <?= $count ?> photo<?= $count === 1 ? '' : 's' ?></span>
                                    </span>
                                </div>
                            </td>
                            <td><?= status_badge($order['status']) ?></td>
                            <td class="nowrap muted"><?= e(time_ago($order['created_at'])) ?></td>
                            <td class="cell-actions">
                                <a class="btn btn-ghost btn-sm" href="<?= e(url('customer/order.php?id=' . (int) $order['id'])) ?>">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <div class="stack">
        <div class="device-card">
            <div class="device-top">
                <?php $printerStatus = epson_status($epson); ?>
                <span class="device-label">Your photo queue</span>
                <span class="device-state<?= $printerStatus['key'] === 'online' ? '' : ' is-off' ?>"><span class="status-dot"></span> <?= e($printerStatus['label']) ?></span>
            </div>
            <div class="device-name">
                <span class="device-icon"><?= icon('printer') ?></span>
                <div>
                    <h3><?= e($epson->isConnected() ? $epson->deviceName() : EPSON_PRINTER_MODEL) ?></h3>
                    <p>Free photo printing for every order</p>
                </div>
            </div>
            <div class="device-meta">
                <span class="device-chip"><?= icon('image') ?> Up to <?= (int) MAX_PHOTOS_PER_ORDER ?> photos per order</span>
                <span class="device-chip"><?= icon('layers') ?> 4 × 6, 5 × 7 or A4</span>
            </div>
            <div class="device-actions">
                <a class="btn btn-sm" href="<?= e(url('customer/new-order.php')) ?>"><?= icon('plus') ?> New print</a>
            </div>
        </div>

        <section class="panel">
            <div class="panel-head"><h3>Good to know</h3></div>
            <div class="panel-body">
                <ul class="check-list" style="gap:14px">
                    <li><?= icon('check') ?><span>4 × 6 in prints are borderless by default — the classic photo size.</span></li>
                    <li><?= icon('check') ?><span>Every photo prints once. Ask the print desk if you ever need a reprint.</span></li>
                    <li><?= icon('check') ?><span>Need a photo removed? Email <a href="mailto:<?= e(setting('support_email', 'hello@printfree.test')) ?>"><?= e(setting('support_email', 'hello@printfree.test')) ?></a>.</span></li>
                </ul>
            </div>
        </section>
    </div>
</div>

<?php app_end(); ?>

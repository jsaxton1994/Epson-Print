<?php
/**
 * Admin dashboard: photo queue, printer health and the numbers that matter.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$admin = require_admin();
$epson = EpsonClient::fromSettings();

$counts = db_one(
    "SELECT COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN status = 'printing' THEN 1 ELSE 0 END), 0) AS printing,
            COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed,
            COUNT(*) AS total
     FROM orders"
) ?? ['pending' => 0, 'printing' => 0, 'failed' => 0, 'total' => 0];

$printedToday = (int) db_val(
    "SELECT COUNT(*) FROM orders WHERE status = 'completed' AND printed_at >= ?",
    [date('Y-m-d') . ' 00:00:00']
);

$customerCount = (int) db_val("SELECT COUNT(*) FROM users WHERE role = 'customer'");
$photosToday   = (int) db_val(
    "SELECT COUNT(*) FROM order_photos WHERE status = 'completed' AND printed_at >= ?",
    [date('Y-m-d') . ' 00:00:00']
);

$photoSelect = "
    (SELECT COUNT(*) FROM order_photos p WHERE p.order_id = o.id) AS photo_count,
    (SELECT p.id FROM order_photos p WHERE p.order_id = o.id ORDER BY p.position ASC, p.id ASC LIMIT 1) AS photo_id,
    (SELECT p.file_name FROM order_photos p WHERE p.order_id = o.id ORDER BY p.position ASC, p.id ASC LIMIT 1) AS photo_name";

$queue = db_all(
    "SELECT o.*, u.name AS customer, {$photoSelect}
     FROM orders o JOIN users u ON u.id = o.user_id
     WHERE o.status IN ('pending', 'printing', 'failed')
     ORDER BY o.status = 'printing' DESC, o.id ASC
     LIMIT 6"
);

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
            <p>Add your Client ID, Client Secret and API Key, then link the ET-2926. Until then you can still manage photo orders and mark them printed manually.</p>
            <div class="banner-actions">
                <a class="btn btn-primary btn-sm" href="<?= e(url('admin/settings.php')) ?>"><?= icon('gear') ?> Open settings</a>
            </div>
        </div>
    </div>
<?php elseif (!$epson->isConnected()): ?>
    <div class="banner banner-info">
        <?= icon('printer') ?>
        <div>
            <strong>Printer credentials saved — one link to go</strong>
            <p>Authorise <?= e(EPSON_PRINTER_MODEL) ?> from Settings so photo jobs can be sent without manual steps.</p>
            <div class="banner-actions">
                <a class="btn btn-primary btn-sm" href="<?= e(url('admin/settings.php')) ?>"><?= icon('wifi') ?> Connect the printer</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <?php
    stat_card('Waiting in queue', (string) $counts['pending'], 'clock', 'amber', $counts['pending'] > 0 ? 'Needs printing' : 'All clear');
    stat_card('Printing now', (string) $counts['printing'], 'printer', 'violet');
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
                    <p><?= $epson->isConnected() ? 'Ready for live photo jobs' : 'Authorise to print automatically' ?></p>
                </div>
            </div>
            <div class="device-meta">
                <?php if ($epson->isConnected()): ?>
                    <span class="device-chip"><?= icon('check') ?> Photo mode</span>
                    <span class="device-chip"><?= icon('wifi') ?> Cloud linked</span>
                <?php else: ?>
                    <span class="device-chip"><?= icon('gear') ?> Add credentials in Settings</span>
                <?php endif; ?>
            </div>
            <div class="device-actions">
                <a class="btn btn-sm btn-primary" href="<?= e(url('admin/settings.php')) ?>">
                    <?= icon('gear') ?> <?= $epson->isConnected() ? 'Printer settings' : 'Set up printing' ?>
                </a>
            </div>
        </div>

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

        <section class="panel">
            <div class="panel-head"><h3>Quick links</h3></div>
            <div class="panel-body pad-sm">
                <ul class="check-list" style="gap:12px">
                    <li><?= icon('layers') ?><span><a href="<?= e(url('admin/orders.php?status=pending')) ?>">Orders waiting to print</a></span></li>
                    <li><?= icon('users') ?><span><a href="<?= e(url('admin/customers.php')) ?>">Customer directory</a></span></li>
                    <li><?= icon('gear') ?><span><a href="<?= e(url('admin/settings.php')) ?>">Service &amp; printer settings</a></span></li>
                </ul>
            </div>
        </section>
    </div>
</div>

<?php app_end(); ?>

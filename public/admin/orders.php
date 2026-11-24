<?php
/**
 * Admin photo order manager: filter, search, print and update.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$admin = require_admin();
$epson = EpsonClient::fromSettings();

$status  = input('status', 'all');
$allowed = ['all', 'pending', 'printing', 'completed', 'cancelled', 'failed'];
$status  = in_array($status, $allowed, true) ? $status : 'all';
$search  = mb_substr(input('q'), 0, 80);

$where  = [];
$params = [];

if ($status !== 'all') {
    $where[]  = 'o.status = ?';
    $params[] = $status;
}

if ($search !== '') {
    $where[]  = "(o.reference LIKE ? OR u.name LIKE ? OR u.email LIKE ?
                 OR EXISTS (SELECT 1 FROM order_photos p WHERE p.order_id = o.id AND p.file_name LIKE ?))";
    array_push($params, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%', '%' . $search . '%');
}

$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

$total = (int) db_val(
    'SELECT COUNT(*) FROM orders o JOIN users u ON u.id = o.user_id' . $whereSql,
    $params
);

$page   = paginate($total, 12);
$orders = db_all(
    'SELECT o.*, u.name AS customer, u.email AS customer_email,
            (SELECT COUNT(*) FROM order_photos p WHERE p.order_id = o.id) AS photo_count,
            (SELECT p.id FROM order_photos p WHERE p.order_id = o.id ORDER BY p.position ASC, p.id ASC LIMIT 1) AS photo_id,
            (SELECT p.file_name FROM order_photos p WHERE p.order_id = o.id ORDER BY p.position ASC, p.id ASC LIMIT 1) AS photo_name
     FROM orders o JOIN users u ON u.id = o.user_id'
    . $whereSql
    . ' ORDER BY o.id DESC LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
    $params
);

$tabs = [
    'all'       => 'All',
    'pending'   => 'Queue',
    'printing'  => 'Printing',
    'completed' => 'Printed',
    'failed'    => 'Failed',
    'cancelled' => 'Cancelled',
];

$queryBase = array_filter(['status' => $status === 'all' ? null : $status, 'q' => $search !== '' ? $search : null]);

app_start('admin', 'orders', 'Orders', 'Every photo job, with the controls to move it along.');
?>

<section class="panel">
    <div class="filter-bar">
        <div class="filter-tabs">
            <?php foreach ($tabs as $key => $label): ?>
                <a class="filter-tab <?= $status === $key ? 'is-active' : '' ?>"
                   href="?<?= e(http_build_query(array_filter(['status' => $key === 'all' ? null : $key, 'q' => $search !== '' ? $search : null]))) ?>">
                    <?= e($label) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <span class="filter-count"><?= $total ?> <?= $total === 1 ? 'order' : 'orders' ?></span>

        <form class="filter-search" method="get" action="">
            <?php if ($status !== 'all'): ?>
                <input type="hidden" name="status" value="<?= e($status) ?>">
            <?php endif; ?>
            <?= icon('search') ?>
            <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search customer or photo" aria-label="Search orders">
        </form>
    </div>

    <?php if ($orders === []): ?>
        <?php empty_state('layers', 'No orders match', 'Try another status filter or clear the search box.'); ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Photos</th>
                    <th>Customer</th>
                    <th>Status</th>
                    <th class="nowrap">Print</th>
                    <th class="nowrap">Age</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
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
                        <td>
                            <div class="doc-text">
                                <span class="doc-name" style="font-size:.85rem"><?= e($order['customer']) ?></span>
                                <span class="doc-meta"><?= e($order['customer_email']) ?></span>
                            </div>
                        </td>
                        <td><?= status_badge($order['status']) ?></td>
                        <td class="nowrap muted"><?= e(paper_size_label($order['paper_size'])) ?> · <?= $order['color_mode'] === 'mono' ? 'B&W' : 'Colour' ?></td>
                        <td class="nowrap muted"><?= e(time_ago($order['created_at'])) ?></td>
                        <td class="cell-actions">
                            <div class="table-actions">
                                <?php if (in_array($order['status'], ['pending', 'failed'], true) && $epson->isConnected()): ?>
                                    <form method="post" action="<?= e(url('admin/order.php')) ?>" data-confirm="Send <?= $count ?> photo<?= $count === 1 ? '' : 's' ?> to the printer now?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                                        <input type="hidden" name="action" value="print">
                                        <button class="btn btn-soft btn-sm" type="submit"><?= icon('printer') ?> Print</button>
                                    </form>
                                <?php endif; ?>
                                <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/order.php?id=' . (int) $order['id'])) ?>">Open</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= pager($page['page'], $page['pages'], $queryBase) ?>
    <?php endif; ?>
</section>

<?php app_end(); ?>

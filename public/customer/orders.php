<?php
/**
 * Customer photo history with filters, search and pagination.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$user = require_login();
if ($user['role'] === 'admin') {
    redirect('admin/index.php');
}

$status  = input('status', 'all');
$allowed = ['all', 'pending', 'accepted', 'printing', 'completed', 'cancelled', 'failed'];
$status  = in_array($status, $allowed, true) ? $status : 'all';
$search  = mb_substr(input('q'), 0, 80);

$where  = ['o.user_id = ?'];
$params = [$user['id']];

if ($status !== 'all') {
    $where[]  = 'o.status = ?';
    $params[] = $status;
}

if ($search !== '') {
    $where[]  = '(o.reference LIKE ? OR EXISTS (SELECT 1 FROM order_photos p WHERE p.order_id = o.id AND p.file_name LIKE ?))';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$whereSql = implode(' AND ', $where);
$total    = (int) db_val('SELECT COUNT(*) FROM orders o WHERE ' . $whereSql, $params);
$page     = paginate($total, 10);

$orders = db_all(
    'SELECT o.*,
            (SELECT COUNT(*) FROM order_photos p WHERE p.order_id = o.id) AS photo_count,
            (SELECT p.id FROM order_photos p WHERE p.order_id = o.id ORDER BY p.position ASC, p.id ASC LIMIT 1) AS photo_id,
            (SELECT p.file_name FROM order_photos p WHERE p.order_id = o.id ORDER BY p.position ASC, p.id ASC LIMIT 1) AS photo_name
     FROM orders o WHERE ' . $whereSql . '
     ORDER BY o.id DESC LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
    $params
);

$queueEstimates = queue_estimates();

$tabs = [
    'all'       => 'All',
    'pending'   => 'Submitted',
    'accepted'  => 'In queue',
    'printing'  => 'Printing',
    'completed' => 'Printed',
    'cancelled' => 'Cancelled',
    'failed'    => 'Failed',
];

$queryBase = array_filter(['status' => $status === 'all' ? null : $status, 'q' => $search !== '' ? $search : null]);

app_start('customer', 'orders', 'My prints', 'Every photo you have sent to the printer.');
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

        <form class="filter-search" method="get" action="">
            <?php if ($status !== 'all'): ?>
                <input type="hidden" name="status" value="<?= e($status) ?>">
            <?php endif; ?>
            <?= icon('search') ?>
            <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search photo or reference" aria-label="Search prints">
        </form>
    </div>

    <?php if ($orders === []): ?>
        <?php empty_state(
            'image',
            $search !== '' || $status !== 'all' ? 'No matching prints' : 'No prints yet',
            $search !== '' || $status !== 'all'
                ? 'Try a different filter or search term.'
                : 'Upload your first photos and they will show up here with a live status.',
            '<a class="btn btn-primary btn-sm" href="' . e(url('customer/new-order.php')) . '">' . icon('upload') . ' Print photos</a>'
        ); ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Photos</th>
                    <th>Status</th>
                    <th class="nowrap">Print</th>
                    <th class="nowrap">Submitted</th>
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
                                    <img class="thumb" src="<?= e(url('customer/photo.php?photo=' . (int) $order['photo_id'] . '&thumb=1')) ?>"
                                         alt="" loading="lazy" width="42" height="42">
                                <?php endif; ?>
                                <span class="doc-text">
                                    <span class="doc-name"><?= e($order['photo_name'] ?: 'Photos') ?><?= $count > 1 ? ' +' . ($count - 1) : '' ?></span>
                                    <span class="doc-meta"><?= e($order['reference']) ?> · <?= $count ?> photo<?= $count === 1 ? '' : 's' ?></span>
                                </span>
                            </div>
                        </td>
                        <td>
                            <?= status_badge($order['status']) ?>
                            <?php $etaSeconds = $queueEstimates[(int) $order['id']] ?? null; ?>
                            <?php if ($etaSeconds !== null && in_array($order['status'], ['accepted', 'printing'], true)): ?>
                                <span class="doc-meta" style="display:block;margin-top:4px">about <?= e(human_duration((int) $etaSeconds)) ?> left</span>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap muted">
                            <?= e(paper_size_label($order['paper_size'])) ?> · <?= $order['color_mode'] === 'mono' ? 'B&W' : 'Colour' ?>
                        </td>
                        <td class="nowrap muted"><?= e(time_ago($order['created_at'])) ?></td>
                        <td class="cell-actions">
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('customer/order.php?id=' . (int) $order['id'])) ?>">View</a>
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

<?php
/**
 * Customer directory.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$admin  = require_admin();
$search = mb_substr(input('q'), 0, 80);

$where    = ["role = 'customer'"];
$params   = [];

if ($search !== '') {
    $where[]  = '(name LIKE ? OR email LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$whereSql = ' WHERE ' . implode(' AND ', $where);
$total    = (int) db_val('SELECT COUNT(*) FROM users' . $whereSql, $params);
$page     = paginate($total, 12);

$customers = db_all(
    'SELECT u.*,
            (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS order_count,
            (SELECT MAX(o.created_at) FROM orders o WHERE o.user_id = u.id) AS last_order
     FROM users u'
    . $whereSql
    . ' ORDER BY u.id DESC LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
    $params
);

app_start('admin', 'customers', 'Customers', 'Everyone with an account, and what they have printed.');
?>

<section class="panel">
    <div class="filter-bar">
        <span class="filter-count"><?= $total ?> <?= $total === 1 ? 'customer' : 'customers' ?></span>
        <form class="filter-search" method="get" action="">
            <?= icon('search') ?>
            <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search name or email" aria-label="Search customers">
        </form>
    </div>

    <?php if ($customers === []): ?>
        <?php empty_state('users', $search !== '' ? 'No customers match' : 'No customers yet', $search !== '' ? 'Try a different name or email.' : 'Customers appear here as soon as they register.'); ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Customer</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th class="nowrap">Orders</th>
                    <th class="nowrap">Last order</th>
                    <th class="nowrap">Joined</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($customers as $customer): ?>
                    <tr>
                        <td>
                            <div class="doc-cell">
                                <span class="avatar avatar-sm"><?= e(initials((string) $customer['name'])) ?></span>
                                <span class="doc-text">
                                    <span class="doc-name"><?= e($customer['name']) ?></span>
                                    <span class="doc-meta"><?= e($customer['email']) ?></span>
                                </span>
                            </div>
                        </td>
                        <td class="nowrap muted"><?= e($customer['phone'] ?: '—') ?></td>
                        <td>
                            <?php if ($customer['status'] === 'active'): ?>
                                <span class="badge badge-completed"><?= icon('check', 'badge-icon') ?> Active</span>
                            <?php else: ?>
                                <span class="badge badge-cancelled"><?= icon('close', 'badge-icon') ?> Blocked</span>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap"><strong><?= (int) $customer['order_count'] ?></strong></td>
                        <td class="nowrap muted"><?= e($customer['last_order'] ? time_ago($customer['last_order']) : '—') ?></td>
                        <td class="nowrap muted"><?= e(format_date($customer['created_at'], 'j M Y')) ?></td>
                        <td class="cell-actions">
                            <div class="table-actions">
                                <form method="post" action="<?= e(url('admin/customer.php?id=' . (int) $customer['id'])) ?>"
                                      data-confirm="<?= $customer['status'] === 'active' ? 'Block this customer? They will be signed out and cannot log in.' : 'Unblock this customer?' ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <button class="btn btn-ghost btn-sm" type="submit">
                                        <?= $customer['status'] === 'active' ? 'Block' : 'Unblock' ?>
                                    </button>
                                </form>
                                <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/customer.php?id=' . (int) $customer['id'])) ?>">View</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= pager($page['page'], $page['pages'], $search !== '' ? ['q' => $search] : []) ?>
    <?php endif; ?>
</section>

<?php app_end(); ?>

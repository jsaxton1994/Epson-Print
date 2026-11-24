<?php
/**
 * Single customer: photo history, account state, block or delete.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$admin      = require_admin();
$customerId = (int) input('id', '0');
$customer   = db_one("SELECT * FROM users WHERE id = ? AND role = 'customer'", [$customerId]);

if ($customer === null) {
    flash('error', 'That customer could not be found.');
    redirect('admin/customers.php');
}

$back = url('admin/customer.php?id=' . $customerId);

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'toggle') {
        $newStatus = $customer['status'] === 'active' ? 'blocked' : 'active';
        db_update('users', ['status' => $newStatus], 'id = ?', [$customerId]);
        flash('success', $newStatus === 'active' ? 'Customer unblocked.' : 'Customer blocked. They can no longer sign in.');
        redirect($back);
    }

    if ($action === 'delete') {
        db_query('DELETE FROM users WHERE id = ?', [$customerId]);
        flash('success', 'Customer, their orders and their photos were deleted.');
        redirect('admin/customers.php');
    }
}

$stats = db_one(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) AS completed,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending
     FROM orders WHERE user_id = ?",
    [$customerId]
) ?? ['total' => 0, 'completed' => 0, 'pending' => 0];

$photosTotal = (int) db_val(
    'SELECT COUNT(*) FROM order_photos p JOIN orders o ON o.id = p.order_id WHERE o.user_id = ?',
    [$customerId]
);
$photosPrinted = (int) db_val(
    "SELECT COUNT(*) FROM order_photos p JOIN orders o ON o.id = p.order_id
     WHERE o.user_id = ? AND p.status = 'completed'",
    [$customerId]
);

$orders = db_all(
    'SELECT o.*,
            (SELECT COUNT(*) FROM order_photos p WHERE p.order_id = o.id) AS photo_count,
            (SELECT p.id FROM order_photos p WHERE p.order_id = o.id ORDER BY p.position ASC, p.id ASC LIMIT 1) AS photo_id,
            (SELECT p.file_name FROM order_photos p WHERE p.order_id = o.id ORDER BY p.position ASC, p.id ASC LIMIT 1) AS photo_name
     FROM orders o WHERE o.user_id = ?
     ORDER BY o.id DESC LIMIT 12',
    [$customerId]
);

app_start('admin', 'customers', $customer['name'], 'Customer since ' . format_date($customer['created_at'], 'j M Y'));
?>

<div class="detail-layout">
    <div class="stack">
        <section class="panel">
            <div class="panel-body">
                <div class="detail-head">
                    <div class="doc-cell">
                        <span class="avatar avatar-lg"><?= e(initials((string) $customer['name'])) ?></span>
                        <span class="doc-text">
                            <span class="doc-name" style="font-size:1.15rem"><?= e($customer['name']) ?></span>
                            <span class="doc-meta"><?= e($customer['email']) ?></span>
                        </span>
                    </div>
                    <?php if ($customer['status'] === 'active'): ?>
                        <span class="badge badge-completed"><?= icon('check', 'badge-icon') ?> Active</span>
                    <?php else: ?>
                        <span class="badge badge-cancelled"><?= icon('close', 'badge-icon') ?> Blocked</span>
                    <?php endif; ?>
                </div>

                <dl class="detail-grid" style="margin-top:24px">
                    <div><dt>Phone</dt><dd><?= e($customer['phone'] ?: '—') ?></dd></div>
                    <div><dt>Joined</dt><dd><?= e(format_date($customer['created_at'])) ?></dd></div>
                    <div><dt>Orders</dt><dd><?= (int) $stats['total'] ?></dd></div>
                    <div><dt>Photos requested</dt><dd><?= $photosTotal ?></dd></div>
                </dl>

                <div class="order-actions">
                    <form method="post" action="<?= e($back) ?>"
                          data-confirm="<?= $customer['status'] === 'active' ? 'Block this customer? They will be signed out immediately.' : 'Unblock this customer?' ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <button class="btn btn-ghost btn-sm" type="submit">
                            <?= icon($customer['status'] === 'active' ? 'close' : 'check') ?>
                            <?= $customer['status'] === 'active' ? 'Block customer' : 'Unblock customer' ?>
                        </button>
                    </form>

                    <form method="post" action="<?= e($back) ?>" data-confirm="Delete this customer with all of their orders and photos? This cannot be undone.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash') ?> Delete customer</button>
                    </form>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Photo history</h3>
                    <p>Latest 12 orders</p>
                </div>
                <a class="panel-link" href="<?= e(url('admin/orders.php?q=' . urlencode((string) $customer['email']))) ?>">Search all <?= icon('arrow-right') ?></a>
            </div>

            <?php if ($orders === []): ?>
                <?php empty_state('image', 'No prints yet', 'This customer has not printed a photo so far.'); ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Photos</th>
                            <th>Status</th>
                            <th class="nowrap">Print</th>
                            <th class="nowrap">When</th>
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
                                <td><?= status_badge($order['status']) ?></td>
                                <td class="nowrap muted"><?= e(paper_size_label($order['paper_size'])) ?> · <?= $order['color_mode'] === 'mono' ? 'B&W' : 'Colour' ?></td>
                                <td class="nowrap muted"><?= e(time_ago($order['created_at'])) ?></td>
                                <td class="cell-actions">
                                    <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/order.php?id=' . (int) $order['id'])) ?>">Open</a>
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
            <div class="panel-head"><h3>Summary</h3></div>
            <div class="panel-body">
                <div class="summary-list">
                    <div class="summary-row"><span>Photos printed</span><strong><?= $photosPrinted ?></strong></div>
                    <div class="summary-row"><span>Photos requested</span><strong><?= $photosTotal ?></strong></div>
                    <div class="summary-row"><span>Completed orders</span><strong><?= (int) $stats['completed'] ?></strong></div>
                    <div class="summary-row"><span>In queue</span><strong><?= (int) $stats['pending'] ?></strong></div>
                    <div class="summary-row"><span>Account</span><strong><?= e(ucfirst((string) $customer['status'])) ?></strong></div>
                </div>
                <a class="btn btn-ghost btn-block btn-sm" href="mailto:<?= e($customer['email']) ?>"><?= icon('mail') ?> Email customer</a>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h3>Privacy</h3></div>
            <div class="panel-body">
                <p class="muted" style="font-size:.85rem;margin:0">
                    Deleting a customer removes their account, orders and stored photos. Blocking keeps everything but stops sign-in and new orders.
                </p>
            </div>
        </section>
    </div>
</div>

<?php app_end(); ?>

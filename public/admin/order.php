<?php
/**
 * Admin photo order detail: send each photo to the Epson ET-2926, track and update.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$admin = require_admin();
$epson = EpsonClient::fromSettings();

$orderId = (int) input('id', '0');
$order   = db_one(
    'SELECT o.*, u.name AS customer, u.email AS customer_email, u.phone AS customer_phone
     FROM orders o JOIN users u ON u.id = o.user_id
     WHERE o.id = ?',
    [$orderId]
);

if ($order === null) {
    flash('error', 'That order no longer exists.');
    redirect('admin/orders.php');
}

$back = url('admin/order.php?id=' . $orderId);

/**
 * Send one photo to Epson and record the job against that photo.
 *
 * @return array{ok:bool,error:string}
 */
$sendPhoto = static function (array $photo) use ($epson, $order, $admin): array {
    try {
        $job = $epson->printPhoto($order, $photo);

        db_update('order_photos', [
            'status'        => 'printing',
            'epson_job_id'  => (string) $job['jobId'],
            'error_message' => null,
            'printed_at'    => null,
        ], 'id = ?', [$photo['id']]);

        return ['ok' => true, 'error' => ''];
    } catch (EpsonException $e) {
        db_update('order_photos', [
            'status'        => 'failed',
            'error_message' => $e->getMessage(),
        ], 'id = ?', [$photo['id']]);

        return ['ok' => false, 'error' => $photo['file_name'] . ': ' . $e->getMessage()];
    }
};

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'print' || $action === 'print_photo') {
        if (!$epson->isConnected()) {
            flash('error', 'Connect the printer in Settings before sending jobs.');
            redirect($back);
        }

        $all = order_photos($orderId);
        $toSend = [];

        if ($action === 'print_photo') {
            $photoId = (int) input('photo_id', '0');
            foreach ($all as $photo) {
                if ((int) $photo['id'] === $photoId) {
                    $toSend[] = $photo;
                    break;
                }
            }
        } elseif ($order['status'] === 'completed') {
            // Reprinting a finished order sends every photo again.
            $toSend = $all;
        } else {
            foreach ($all as $photo) {
                if (in_array($photo['status'], ['pending', 'failed'], true)) {
                    $toSend[] = $photo;
                }
            }
        }

        if ($toSend === []) {
            flash('info', 'Every photo in this order is already printed.');
            redirect($back);
        }

        $sent = 0;
        $errors = [];
        foreach ($toSend as $photo) {
            $result = $sendPhoto($photo);
            if ($result['ok']) {
                $sent++;
            } else {
                $errors[] = $result['error'];
            }
        }

        sync_order_status($orderId, (string) $admin['name']);

        if ($sent > 0) {
            flash('success', $sent . ' photo' . ($sent === 1 ? '' : 's') . ' sent to the printer. They print in queue order.');
        }
        if ($errors !== []) {
            flash('error', 'Could not send ' . count($errors) . ' photo' . (count($errors) === 1 ? '' : 's') . '. ' . implode(' ', array_slice($errors, 0, 2)));
        }

        redirect($back);
    }

    if ($action === 'refresh') {
        $jobs = array_values(array_filter(
            order_photos($orderId),
            static fn (array $photo): bool => !empty($photo['epson_job_id']) && $photo['status'] === 'printing'
        ));

        if ($jobs === []) {
            flash('info', 'There are no Epson jobs waiting for a status update.');
            redirect($back);
        }

        $checked = 0;
        foreach ($jobs as $photo) {
            try {
                $result = $epson->interpretJobStatus($epson->jobStatus((string) $photo['epson_job_id']));
                $checked++;

                if ($result['status'] !== $photo['status']) {
                    db_update('order_photos', [
                        'status'        => $result['status'],
                        'printed_at'    => $result['status'] === 'completed' ? now() : null,
                        'error_message' => $result['status'] === 'failed' ? $result['detail'] : null,
                    ], 'id = ?', [$photo['id']]);
                }
            } catch (EpsonException $e) {
                flash('error', 'Could not reach Epson: ' . $e->getMessage());
            }
        }

        sync_order_status($orderId, (string) $admin['name']);
        flash('success', 'Checked ' . $checked . ' Epson job' . ($checked === 1 ? '' : 's') . '.');
        redirect($back);
    }

    if ($action === 'complete') {
        complete_order_photos($orderId, (string) $admin['name']);
        flash('success', 'Every photo in this order is marked as printed.');
        redirect($back);
    }

    if ($action === 'cancel') {
        if ($order['status'] === 'completed') {
            flash('error', 'Printed orders cannot be cancelled.');
        } else {
            update_order_status($orderId, 'cancelled', 'Cancelled by ' . $admin['name'] . '.', (string) $admin['name']);
            flash('success', 'Order cancelled.');
        }
        redirect($back);
    }

    if ($action === 'note') {
        $note = mb_substr(input('admin_note'), 0, 500);
        db_update('orders', ['admin_note' => $note !== '' ? $note : null, 'updated_at' => now()], 'id = ?', [$orderId]);
        flash('success', 'Note saved. The customer can see it on their order.');
        redirect($back);
    }

    if ($action === 'delete') {
        db_query('DELETE FROM orders WHERE id = ?', [$orderId]);
        flash('success', 'Order and photos deleted.');
        redirect('admin/orders.php');
    }
}

$photos      = order_photos($orderId);
$count       = count($photos);
$events      = order_events($orderId);
$quality     = quality_options()[$order['print_quality']] ?? ucfirst((string) $order['print_quality']);
$totalBytes  = array_sum(array_map(static fn (array $p): int => (int) $p['file_size'], $photos));
$pendingSend = array_values(array_filter($photos, static fn (array $p): bool => in_array($p['status'], ['pending', 'failed'], true)));
$customer    = db_one(
    'SELECT id, name, email, phone, created_at,
            (SELECT COUNT(*) FROM orders o WHERE o.user_id = users.id) AS order_count
     FROM users WHERE id = ?',
    [$order['user_id']]
);

app_start('admin', 'orders', 'Order ' . $order['reference'], $count . ' photo' . ($count === 1 ? '' : 's') . ' · submitted ' . format_date($order['created_at']) . ' by ' . $order['customer']);
?>

<div class="detail-layout">
    <div class="stack">
        <?php if ($order['status'] === 'failed' && !empty($order['error_message'])): ?>
            <div class="banner banner-warn">
                <?= icon('alert') ?>
                <div>
                    <strong>Some photos were rejected by the printer</strong>
                    <p><?= e($order['error_message']) ?></p>
                    <div class="banner-actions">
                        <a class="btn btn-soft btn-sm" href="<?= e(url('admin/settings.php')) ?>"><?= icon('gear') ?> Check Epson settings</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-body">
                <div class="detail-head">
                    <div>
                        <h2><?= $count ?> photo<?= $count === 1 ? '' : 's' ?></h2>
                        <div class="detail-sub">
                            <span class="mono"><?= e($order['reference']) ?></span>
                            <?= status_badge($order['status']) ?>
                            <span><?= e(time_ago($order['created_at'])) ?></span>
                        </div>
                    </div>
                    <div class="table-actions">
                        <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/orders.php')) ?>">All orders</a>
                    </div>
                </div>

                <div class="photo-gallery mt-3">
                    <?php foreach ($photos as $index => $photo): ?>
                        <figure>
                            <a href="<?= e(url('admin/photo.php?photo=' . (int) $photo['id'])) ?>" target="_blank" rel="noopener">
                                <img src="<?= e(url('admin/photo.php?photo=' . (int) $photo['id'] . '&thumb=1')) ?>"
                                     alt="<?= e($photo['file_name']) ?>" loading="lazy">
                            </a>
                            <figcaption>
                                <span class="pg-name"><?= ($index + 1) . '. ' . e($photo['file_name']) ?></span>
                                <?= status_badge($photo['status']) ?>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>

                <dl class="detail-grid" style="margin-top:24px">
                    <div><dt>Customer</dt><dd><a href="<?= e(url('admin/customer.php?id=' . (int) $order['user_id'])) ?>"><?= e($order['customer']) ?></a></dd></div>
                    <div><dt>Email</dt><dd><?= e($order['customer_email']) ?></dd></div>
                    <div><dt>Photos</dt><dd><?= $count ?></dd></div>
                    <div><dt>Print size</dt><dd><?= e(paper_size_label($order['paper_size'])) ?></dd></div>
                    <div><dt>Colour</dt><dd><?= $order['color_mode'] === 'mono' ? 'Black &amp; white' : 'Colour' ?></dd></div>
                    <div><dt>Border</dt><dd><?= (int) $order['borderless'] === 1 ? 'Borderless' : 'White border' ?></dd></div>
                    <div><dt>Quality</dt><dd><?= e(explode(' — ', $quality)[0]) ?></dd></div>
                    <div><dt>Total size</dt><dd><?= e(human_size($totalBytes)) ?></dd></div>
                    <div><dt>Copies</dt><dd>1 of each photo</dd></div>
                    <div><dt>Submitted</dt><dd><?= e(format_date($order['created_at'])) ?></dd></div>
                    <div><dt>Printed at</dt><dd><?= e(format_date($order['printed_at'])) ?></dd></div>
                    <div><dt>Last update</dt><dd><?= e(format_date($order['updated_at'])) ?></dd></div>
                </dl>

                <?php if (!empty($order['notes'])): ?>
                    <div class="detail-note">
                        <strong>Customer note</strong>
                        <?= e($order['notes']) ?>
                    </div>
                <?php endif; ?>

                <div class="order-actions">
                    <?php if ($epson->isConnected() && $order['status'] !== 'cancelled' && ($pendingSend !== [] || $order['status'] === 'completed')): ?>
                        <form method="post" action="<?= e($back) ?>" data-confirm="Send <?= count($pendingSend ?: $photos) ?> photo<?= count($pendingSend ?: $photos) === 1 ? '' : 's' ?> to <?= e(EPSON_PRINTER_MODEL) ?> now?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="print">
                            <button class="btn btn-primary btn-sm" type="submit">
                                <?= icon('printer') ?>
                                <?php if ($order['status'] === 'completed'): ?>
                                    Print again
                                <?php else: ?>
                                    Print <?= count($pendingSend) ?> photo<?= count($pendingSend) === 1 ? '' : 's' ?>
                                <?php endif; ?>
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($order['status'] === 'printing'): ?>
                        <form method="post" action="<?= e($back) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="refresh">
                            <button class="btn btn-soft btn-sm" type="submit"><?= icon('refresh') ?> Check status</button>
                        </form>
                    <?php endif; ?>

                    <?php if (in_array($order['status'], ['pending', 'printing', 'failed'], true)): ?>
                        <form method="post" action="<?= e($back) ?>" data-confirm="Mark every photo in this order as printed? Do this only after the prints are out of the printer.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="complete">
                            <button class="btn btn-ghost btn-sm" type="submit"><?= icon('check') ?> Mark printed</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($order['status'] !== 'cancelled' && $order['status'] !== 'completed'): ?>
                        <form method="post" action="<?= e($back) ?>" data-confirm="Cancel this order?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cancel">
                            <button class="btn btn-danger btn-sm" type="submit"><?= icon('close') ?> Cancel</button>
                        </form>
                    <?php endif; ?>

                    <form method="post" action="<?= e($back) ?>" data-confirm="Delete this order and its photos permanently? This cannot be undone.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash') ?> Delete</button>
                    </form>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Photos in this order</h3>
                    <p>Each photo prints once, as its own Epson job</p>
                </div>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Photo</th>
                        <th class="nowrap">Size</th>
                        <th>Status</th>
                        <th class="nowrap">Epson job</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($photos as $index => $photo): ?>
                        <tr>
                            <td>
                                <div class="doc-cell">
                                    <img class="thumb" src="<?= e(url('admin/photo.php?photo=' . (int) $photo['id'] . '&thumb=1')) ?>"
                                         alt="" loading="lazy" width="42" height="42">
                                    <span class="doc-text">
                                        <span class="doc-name"><?= ($index + 1) . '. ' . e($photo['file_name']) ?></span>
                                        <span class="doc-meta">
                                            <?= (int) $photo['image_width'] ?> × <?= (int) $photo['image_height'] ?> px ·
                                            <?= e(human_size((int) $photo['file_size'])) ?>
                                        </span>
                                    </span>
                                </div>
                                <?php if (!empty($photo['error_message'])): ?>
                                    <p class="photo-error"><?= e($photo['error_message']) ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap muted"><?= (int) $photo['image_width'] > 0 ? (int) $photo['image_width'] . ' × ' . (int) $photo['image_height'] : '—' ?></td>
                            <td><?= status_badge($photo['status']) ?></td>
                            <td class="mono muted nowrap"><?= e($photo['epson_job_id'] ?: '—') ?></td>
                            <td class="cell-actions">
                                <div class="table-actions">
                                    <?php if ($epson->isConnected() && $order['status'] !== 'cancelled'): ?>
                                        <form method="post" action="<?= e($back) ?>" data-confirm="Send this photo to <?= e(EPSON_PRINTER_MODEL) ?> now?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="print_photo">
                                            <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                                            <button class="btn btn-ghost btn-sm" type="submit"><?= icon('printer') ?> Print</button>
                                        </form>
                                    <?php endif; ?>
                                    <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/photo.php?photo=' . (int) $photo['id'] . '&download=1')) ?>"><?= icon('download') ?></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Note to the customer</h3>
                    <p>Shown on their order page</p>
                </div>
            </div>
            <div class="panel-body">
                <form method="post" action="<?= e($back) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="note">
                    <div class="field mb-0">
                        <label for="admin_note" class="sr-only">Note to the customer</label>
                        <textarea class="textarea" id="admin_note" name="admin_note" maxlength="500"
                                  placeholder="e.g. Cropped the second photo to fit 4 × 6 so the edges are trimmed."><?= e($order['admin_note']) ?></textarea>
                        <p class="help">Up to 500 characters.</p>
                    </div>
                    <button class="btn btn-soft btn-sm mt-2" type="submit">Save note</button>
                </form>
            </div>
        </section>
    </div>

    <div class="stack">
        <section class="panel">
            <div class="panel-head"><h3>Timeline</h3></div>
            <div class="panel-body">
                <ul class="timeline">
                    <?php $last = count($events) - 1; ?>
                    <?php foreach ($events as $index => $event): ?>
                        <li class="timeline-item <?= $index === $last ? 'is-current' : 'is-done' ?>">
                            <span class="timeline-dot"></span>
                            <div class="timeline-body">
                                <p><?= e($event['message']) ?></p>
                                <span class="timeline-meta">
                                    <?= e(status_meta($event['status'])[0]) ?>
                                    · <?= e(format_date($event['created_at'])) ?>
                                    <?= $event['actor'] ? ' · ' . e($event['actor']) : '' ?>
                                </span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>

        <div class="device-card">
            <div class="device-top">
                <span class="device-label">Destination printer</span>
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
                    <p><?= $epson->isConnected() ? 'Epson Connect API v2 · photo mode' : 'Connect it in Settings' ?></p>
                </div>
            </div>
            <div class="device-actions">
                <a class="btn btn-sm" href="<?= e(url('admin/settings.php')) ?>"><?= icon('gear') ?> Printer settings</a>
            </div>
        </div>

        <?php if ($customer !== null): ?>
            <section class="panel">
                <div class="panel-head"><h3>Customer</h3></div>
                <div class="panel-body">
                    <div class="doc-cell" style="margin-bottom:16px">
                        <span class="avatar"><?= e(initials((string) $customer['name'])) ?></span>
                        <span class="doc-text">
                            <span class="doc-name"><?= e($customer['name']) ?></span>
                            <span class="doc-meta"><?= e($customer['email']) ?></span>
                        </span>
                    </div>
                    <div class="summary-list">
                        <div class="summary-row"><span>Orders</span><strong><?= (int) $customer['order_count'] ?></strong></div>
                        <div class="summary-row"><span>Phone</span><strong><?= e($customer['phone'] ?: '—') ?></strong></div>
                        <div class="summary-row"><span>Member since</span><strong><?= e(format_date($customer['created_at'], 'M Y')) ?></strong></div>
                    </div>
                    <a class="btn btn-ghost btn-block btn-sm" href="<?= e(url('admin/customer.php?id=' . (int) $customer['id'])) ?>">Open customer</a>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php app_end(); ?>

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

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'accept') {
        if (!in_array($order['status'], ['pending', 'failed'], true)) {
            flash('error', 'Only submitted or failed orders can be accepted for printing.');
            redirect($back);
        }

        if (order_photo_count($orderId) === 0) {
            flash('error', 'This order has no photos, so there is nothing to print. Delete it, or ask the customer to add photos while it is still in the queue.');
            redirect($back);
        }

        accept_order_for_printing($orderId, (string) $admin['name']);
        flash('success', 'Order accepted and queued. The print-queue cron will send it to the printer automatically.');
        if (!$epson->isConnected()) {
            flash('info', 'The printer is not linked yet, so the order will wait in the queue until it is.');
        }
        redirect($back);
    }

    if ($action === 'retry_failed') {
        if ($order['status'] === 'cancelled') {
            flash('error', 'This order was cancelled, so its photos cannot be queued.');
            redirect($back);
        }

        $requeued = db_update(
            'order_photos',
            [
                'status'        => 'pending',
                'epson_job_id'  => null,
                'printed_at'    => null,
                'error_message' => null,
            ] + (print_started_column_ready() ? ['print_started_at' => null] : []),
            "order_id = ? AND status = 'failed'",
            [$orderId]
        );

        if ($requeued > 0) {
            sync_order_status($orderId, (string) $admin['name']);
        }

        flash($requeued > 0 ? 'success' : 'info', $requeued > 0
            ? $requeued . ' failed photo' . ($requeued === 1 ? '' : 's') . ' put back in the queue; the queue prints them in position order.'
            : 'This order has no failed photos to retry.');

        redirect($back);
    }

    if (in_array($action, ['print', 'print_photo', 'reprint'], true)) {
        if (!$epson->isConnected()) {
            flash('error', 'Connect the printer in Printer Settings before sending jobs.');
            redirect($back);
        }

        if (order_photo_count($orderId) === 0) {
            flash('error', 'This order has no photos to print.');
            redirect($back);
        }

        $toSend = [];

        if ($action === 'reprint') {
            if ($order['status'] !== 'completed') {
                flash('error', 'Only completed orders can be reprinted.');
                redirect($back);
            }

            // Put the whole order back in the queue and send it in batches.
            db_query(
                "UPDATE order_photos
                 SET status = 'pending', epson_job_id = NULL, printed_at = NULL, error_message = NULL"
                    . (print_started_column_ready() ? ', print_started_at = NULL' : '') . "
                 WHERE order_id = ?",
                [$orderId]
            );
            update_order_status($orderId, 'pending', 'Reprint requested by ' . $admin['name'] . '.', (string) $admin['name']);
            $toSend = order_photos($orderId);
        } elseif ($action === 'print_photo') {
            $photoId = (int) input('photo_id', '0');
            foreach (order_photos($orderId) as $photo) {
                if ((int) $photo['id'] === $photoId) {
                    $toSend[] = $photo;
                    break;
                }
            }
        } else {
            foreach (order_photos($orderId) as $photo) {
                if (in_array($photo['status'], ['pending', 'failed'], true)) {
                    $toSend[] = $photo;
                }
            }
        }

        if ($toSend === []) {
            flash('info', 'Every photo in this order is already printed.');
            redirect($back);
        }

        // One sheet at a time: nothing is sent while a photo — this order's or
        // another's — is still on the printer.
        $busyPhoto = db_one(
            "SELECT p.order_id, o.reference FROM order_photos p
             JOIN orders o ON o.id = p.order_id
             WHERE p.status = 'printing'
             ORDER BY p.id ASC
             LIMIT 1"
        );

        if ($busyPhoto !== null) {
            $busy = (int) $busyPhoto['order_id'] === $orderId
                ? 'A photo from this order is still printing'
                : 'Order ' . $busyPhoto['reference'] . ' is still printing';
            flash('error', $busy . '. Wait for it to finish, then press Print again.');
            redirect($back);
        }

        // One photo per click. Several jobs at once would sit on the printer
        // together and could print out of order or mixed with another order.
        @set_time_limit((2 * EPSON_TIMEOUT) + 5);

        $sent    = 0;
        $failed  = 0;
        $skipped = 0;
        $errors  = [];

        // A single-photo reprint may target a completed or cancelled photo;
        // never target one that is already printing.
        $allowedStatuses = $action === 'print_photo'
            ? ['pending', 'failed', 'completed', 'cancelled']
            : ['pending', 'failed'];

        $result = send_photo_to_printer($epson, $order, $toSend[0], $allowedStatuses);

        if (!empty($result['skipped'])) {
            $skipped = 1;
        } elseif ($result['ok']) {
            $sent = 1;
        } else {
            $failed = 1;
            $errors[] = $result['error'];
        }

        $remaining = max(0, count($toSend) - $sent);

        sync_order_status($orderId, (string) $admin['name']);

        if ($sent > 0) {
            flash('success', 'One photo sent to the printer. Press Print again once it has finished so the sheets stay in order.');
        }
        if ($skipped > 0) {
            // A pause is the most common reason: say why it was not sent.
            flash('info', $result['error'] !== ''
                ? $result['error']
                : $skipped . ' photo' . ($skipped === 1 ? ' was' : 's were') . ' already being sent by another process and skipped.');
        }
        if ($errors !== []) {
            flash('error', 'Could not send ' . $failed . ' photo' . ($failed === 1 ? '' : 's') . '. ' . implode(' ', array_slice($errors, 0, 2)));
        }
        if ($remaining > 0) {
            flash('info', $remaining . ' photo' . ($remaining === 1 ? '' : 's') . ' still waiting. Press Print again after the printer finishes this one.');
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

        @set_time_limit(STATUS_BATCH_SECONDS + EPSON_TIMEOUT + 5);

        $batch     = array_slice($jobs, 0, STATUS_BATCH_MAX);
        $checked   = 0;
        $handled   = 0;
        $startedAt = microtime(true);

        foreach ($batch as $index => $photo) {
            if ($index > 0 && (microtime(true) - $startedAt) >= STATUS_BATCH_SECONDS) {
                break;
            }

            $handled++;

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

        $remaining = max(0, count($jobs) - $handled);

        sync_order_status($orderId, (string) $admin['name']);
        flash('success', 'Checked ' . $checked . ' Epson job' . ($checked === 1 ? '' : 's') . '.');
        if ($remaining > 0) {
            flash('info', $remaining . ' job' . ($remaining === 1 ? '' : 's') . ' still waiting. Press Check status again for the next batch.');
        }
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

    if ($action === 'release_photo') {
        $photoId = (int) input('photo_id', '0');
        $photo   = db_one(
            'SELECT id, status FROM order_photos WHERE id = ? AND order_id = ?',
            [$photoId, $orderId]
        );

        if ($photo === null || $photo['status'] !== 'printing') {
            flash('error', 'That photo is not waiting on the printer any more.');
            redirect($back);
        }

        db_update('order_photos', [
            'status'        => 'failed',
            'printed_at'    => null,
            'error_message' => 'Released by ' . $admin['name']
                . ' while waiting on the printer. Print it again once the printer is ready.',
        ], 'id = ?', [$photoId]);

        sync_order_status($orderId, (string) $admin['name']);
        db_update('orders', ['updated_at' => now()], 'id = ?', [$orderId]);

        flash('success', 'Photo released. It is marked failed so you can print it again.');
        redirect($back);
    }

    if ($action === 'remove_photos') {
        $raw = $_POST['photo_ids'] ?? [];
        $ids = [];

        foreach (is_array($raw) ? $raw : [$raw] as $value) {
            if (is_scalar($value) && (int) $value > 0) {
                $ids[(int) $value] = true;
            }
        }

        $ids = array_keys($ids);

        if ($ids === []) {
            flash('error', 'Tick at least one photo to remove it from the order.');
            redirect($back);
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $selected = db_all(
            'SELECT id, file_name FROM order_photos WHERE order_id = ? AND id IN (' . $placeholders . ')',
            array_merge([$orderId], $ids)
        );

        if ($selected === []) {
            flash('error', 'Those photos are not part of this order any more.');
            redirect($back);
        }

        db_query(
            'DELETE FROM order_photos WHERE order_id = ? AND id IN (' . $placeholders . ')',
            array_merge([$orderId], $ids)
        );

        $removed   = count($selected);
        $remaining = order_photo_count($orderId);
        $removedLabel = $removed === 1 ? 'Photo' : $removed . ' photos';

        if ($remaining === 0) {
            db_query('DELETE FROM orders WHERE id = ?', [$orderId]);
            flash('success', $removedLabel . ' removed. Nothing was left in the order, so it was deleted as well.');
            redirect('admin/orders.php');
        }

        // Recompute the order from what is left, then record who removed what.
        sync_order_status($orderId, (string) $admin['name']);
        $currentStatus = (string) db_val('SELECT status FROM orders WHERE id = ?', [$orderId]);
        log_order_event(
            $orderId,
            $currentStatus,
            'Removed ' . ($removed === 1 ? '"' . $selected[0]['file_name'] . '"' : $removed . ' photos')
                . ' from the order; ' . $remaining . ' photo' . ($remaining === 1 ? '' : 's') . ' left.',
            (string) $admin['name']
        );

        // Removing photos changes what is left to print, so keep the queue
        // watchdog honest about the order still making progress.
        db_update('orders', ['updated_at' => now()], 'id = ?', [$orderId]);

        flash('success', $removedLabel . ' removed. ' . $remaining . ' photo' . ($remaining === 1 ? '' : 's') . ' left in this order.');
        redirect($back);
    }

    if ($action === 'quality') {
        if (in_array($order['status'], ['completed', 'cancelled'], true)) {
            flash('error', 'The print quality cannot be changed once an order is printed or cancelled.');
            redirect($back);
        }

        $qualityOptions = quality_options();
        $qualityKey     = input('print_quality');

        if (!array_key_exists($qualityKey, $qualityOptions)) {
            flash('error', 'Pick one of the available print qualities.');
            redirect($back);
        }

        if ($qualityKey === $order['print_quality']) {
            flash('info', 'That is already the print quality for this order.');
            redirect($back);
        }

        $qualityName = static fn (string $key): string => explode(' — ', $qualityOptions[$key] ?? ucfirst($key))[0];

        db_update('orders', ['print_quality' => $qualityKey, 'updated_at' => now()], 'id = ?', [$orderId]);

        log_order_event(
            $orderId,
            (string) $order['status'],
            'Print quality changed from ' . $qualityName((string) $order['print_quality'])
                . ' to ' . $qualityName($qualityKey) . ' by ' . $admin['name'] . '.',
            (string) $admin['name']
        );

        flash('success', 'Print quality updated. Photos already sent to the printer keep their old setting; anything sent from now uses the new one.');
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
$printStarted = order_photo_print_started($orderId);
$events      = order_events($orderId);
$qualityOptions = quality_options();
$quality     = $qualityOptions[$order['print_quality']] ?? ucfirst((string) $order['print_quality']);
$canEditQuality = in_array($order['status'], ['pending', 'accepted', 'printing', 'failed'], true);
$totalBytes  = array_sum(array_map(static fn (array $p): int => (int) $p['file_size'], $photos));
$pendingSend = array_values(array_filter($photos, static fn (array $p): bool => in_array($p['status'], ['pending', 'failed'], true)));
$failedPhotos = count(array_filter($photos, static fn (array $p): bool => $p['status'] === 'failed'));
$reprint     = $order['status'] === 'completed';
$printTotal  = $reprint ? $count : count($pendingSend);
$canAccept   = $count > 0 && in_array($order['status'], ['pending', 'failed'], true);
$canPrint    = $count > 0
    && $epson->isConnected()
    && $order['status'] !== 'cancelled'
    && (($pendingSend !== [] && in_array($order['status'], ['accepted', 'printing'], true))
        || $order['status'] === 'completed');
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
                        <a class="btn btn-soft btn-sm" href="<?= e(url('admin/printer-settings.php')) ?>"><?= icon('printer') ?> Check printer settings</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($count === 0): ?>
            <div class="banner banner-warn">
                <?= icon('alert') ?>
                <div>
                    <strong>This order has no photos</strong>
                    <p>There is nothing to print until photos are added. If it is still in the queue the customer can add some, otherwise delete the order.</p>
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
                    <div>
                        <dt>Quality</dt>
                        <dd>
                            <?php if ($canEditQuality): ?>
                                <form method="post" action="<?= e($back) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="quality">
                                    <label class="sr-only" for="print_quality">Print quality</label>
                                    <select class="select" id="print_quality" name="print_quality">
                                        <?php foreach ($qualityOptions as $value => $label): ?>
                                            <option value="<?= e($value) ?>" <?= $order['print_quality'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-soft btn-sm" type="submit">Save</button>
                                </form>
                                <?php if ($order['status'] === 'printing'): ?>
                                    <span class="muted" style="font-size:.75rem">Photos already sent keep their quality.</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <?= e(explode(' — ', $quality)[0]) ?>
                            <?php endif; ?>
                        </dd>
                    </div>
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
                    <?php if ($canAccept): ?>
                        <form method="post" action="<?= e($back) ?>" data-confirm="Accept this order and put it in the automatic print queue?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="accept">
                            <button class="btn btn-primary btn-sm" type="submit">
                                <?= icon('check') ?> <?= $order['status'] === 'failed' ? 'Retry &amp; queue' : 'Accept &amp; queue' ?>
                            </button>
                        </form>
                        <span class="muted" style="font-size:.78rem;align-self:center">The cron queue starts printing after acceptance.</span>
                    <?php endif; ?>

                    <?php if ($canPrint): ?>
                        <form method="post" action="<?= e($back) ?>" data-confirm="<?= $reprint
                            ? 'Reprint this order one photo at a time, starting now?'
                            : 'Send the next photo to ' . e(EPSON_PRINTER_MODEL) . ' now?' ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= $reprint ? 'reprint' : 'print' ?>">
                            <button class="btn btn-soft btn-sm" type="submit">
                                <?= icon('printer') ?>
                                <?php if ($reprint): ?>
                                    Print next photo
                                <?php else: ?>
                                    Send next photo (<?= $printTotal ?> left)
                                <?php endif; ?>
                            </button>
                        </form>
                        <span class="muted" style="font-size:.78rem;align-self:center">One photo per click; the next sends after the printer finishes.</span>
                    <?php endif; ?>

                    <?php if ($failedPhotos > 0 && $order['status'] !== 'cancelled'): ?>
                        <form method="post" action="<?= e($back) ?>" data-confirm="Put <?= $failedPhotos ?> failed photo<?= $failedPhotos === 1 ? '' : 's' ?> back in the print queue? Nothing is sent until the printer is free.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="retry_failed">
                            <button class="btn btn-soft btn-sm" type="submit"><?= icon('refresh') ?> Retry <?= $failedPhotos ?> failed photo<?= $failedPhotos === 1 ? '' : 's' ?></button>
                        </form>
                    <?php endif; ?>

                    <?php if ($order['status'] === 'accepted'): ?>
                        <span class="muted" style="font-size:.78rem;align-self:center">Waiting for the automatic print queue.</span>
                    <?php endif; ?>

                    <?php if ($order['status'] === 'printing'): ?>
                        <form method="post" action="<?= e($back) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="refresh">
                            <button class="btn btn-soft btn-sm" type="submit"><?= icon('refresh') ?> Check status</button>
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
                    <p>Each photo prints once, as its own Epson job. Tick photos to take them out of the order.</p>
                </div>
                <?php if ($count > 0): ?>
                    <form method="post" action="<?= e($back) ?>" id="remove-photos" class="table-actions"
                          data-confirm="Remove the selected photos from this order? The stored photos are deleted permanently, and any already sent to the printer may still come out.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove_photos">
                        <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash') ?> Remove selected</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if ($count === 0): ?>
                <div class="table-empty">No photos in this order yet.</div>
            <?php else: ?>
            <?php
            // The next photo to print, exactly as the printer will receive it.
            $previewPhoto = null;
            foreach ($photos as $candidate) {
                if (in_array($candidate['status'], ['pending', 'printing'], true)) {
                    $previewPhoto = $candidate;
                    break;
                }
            }
            ?>
            <?php if ($previewPhoto !== null): ?>
                <?php $previewUrl = url('admin/print-preview.php?photo=' . (int) $previewPhoto['id']); ?>
                <div class="print-preview">
                    <a href="<?= e($previewUrl) ?>" target="_blank" rel="noopener">
                        <img src="<?= e($previewUrl . '&w=520') ?>" alt="Print preview for <?= e($previewPhoto['file_name']) ?>" loading="lazy">
                    </a>
                    <div>
                        <strong>Print preview: <?= e($previewPhoto['file_name']) ?></strong>
                        <p>
                            The uploaded photo, sent to the printer unchanged on <?= e(paper_size_label($order['paper_size'])) ?> paper — no padding, blur or recompression.
                            <a class="print-preview-link" href="<?= e($previewUrl) ?>" target="_blank" rel="noopener">Open full size <?= icon('arrow-right') ?></a>
                        </p>
                    </div>
                </div>
            <?php endif; ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th class="cell-check">
                            <input type="checkbox" data-check-all="order-photos" aria-label="Select every photo in this order">
                        </th>
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
                            <td class="cell-check">
                                <input type="checkbox" name="photo_ids[]" value="<?= (int) $photo['id'] ?>"
                                       form="remove-photos" data-check-item="order-photos"
                                       aria-label="Select <?= e($photo['file_name']) ?> for removal">
                            </td>
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
                            <td>
                                <?= status_badge($photo['status']) ?>
                                <?php $printingFor = $photo['status'] === 'printing'
                                    ? photo_printing_minutes($printStarted[(int) $photo['id']] ?? null)
                                    : null; ?>
                                <?php if ($printingFor !== null): ?>
                                    <span class="muted nowrap" style="font-size:.75rem">· <?= $printingFor ?> min</span>
                                <?php endif; ?>
                            </td>
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
                                    <?php if ($photo['status'] === 'printing'): ?>
                                        <form method="post" action="<?= e($back) ?>" data-confirm="Release this print? Check the printer first — the photo is marked failed so it can be sent again, which risks a duplicate sheet if the first one is still coming.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="release_photo">
                                            <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                                            <button class="btn btn-ghost btn-sm" type="submit"><?= icon('refresh') ?> Release</button>
                                        </form>
                                    <?php endif; ?>
                                    <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/print-preview.php?photo=' . (int) $photo['id'])) ?>" target="_blank" rel="noopener"><?= icon('image') ?> Preview</a>
                                    <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/photo.php?photo=' . (int) $photo['id'] . '&download=1')) ?>"><?= icon('download') ?> Download</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
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
                    <p><?= $epson->isConnected() ? 'Epson Connect API v2 · photo mode' : 'Connect it in Printer Settings' ?></p>
                </div>
            </div>
            <div class="device-actions">
                <a class="btn btn-sm" href="<?= e(url('admin/printer-settings.php')) ?>"><?= icon('printer') ?> Printer settings</a>
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

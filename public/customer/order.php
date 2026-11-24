<?php
/**
 * Single photo order: every photo, its print settings, timeline and cancellation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$user = require_login();
if ($user['role'] === 'admin') {
    redirect('admin/index.php');
}

$orderId = (int) input('id', '0');
$order   = db_one('SELECT * FROM orders WHERE id = ? AND user_id = ?', [$orderId, $user['id']]);

if ($order === null) {
    flash('error', 'That order could not be found.');
    redirect('customer/orders.php');
}

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'cancel') {
        if ($order['status'] === 'pending') {
            update_order_status((int) $order['id'], 'cancelled', 'Cancelled by the customer.', $user['name']);
            flash('success', 'Order cancelled. Your photos are still in your history.');
        } else {
            flash('error', 'This order can no longer be cancelled.');
        }
        redirect('customer/order.php?id=' . $orderId);
    }
}

$photos   = order_photos((int) $order['id']);
$count    = count($photos);
$events   = order_events((int) $order['id']);
$epson    = EpsonClient::fromSettings();
$quality  = quality_options()[$order['print_quality']] ?? ucfirst((string) $order['print_quality']);
$totalKb  = array_sum(array_map(static fn (array $p): int => (int) $p['file_size'], $photos));

$uploadLimits    = upload_limits();
$remainingPhotos = max(0, MAX_PHOTOS_PER_ORDER - $count);
$addBatchLimit   = min(MAX_PHOTOS_PER_BATCH, $remainingPhotos, $uploadLimits['max_files']);
$storageCap      = MAX_ORDER_STORAGE_MB > 0 ? MAX_ORDER_STORAGE_MB * 1024 * 1024 : 0;
$storageLeft     = $storageCap > 0 ? max(0, $storageCap - $totalKb) : null;
$fileLimitMb     = round($uploadLimits['file_bytes'] / 1048576, 1);
$canAddPhotos    = $order['status'] === 'pending'
    && $remainingPhotos > 0
    && $addBatchLimit > 0
    && ($storageLeft === null || $storageLeft > 0);

// The browser should block a batch the server would reject.
$batchTotalLimit = $uploadLimits['post_bytes'];
if ($storageLeft !== null && ($batchTotalLimit === 0 || $storageLeft < $batchTotalLimit)) {
    $batchTotalLimit = $storageLeft;
}

app_start(
    'customer',
    'orders',
    'Order ' . $order['reference'],
    $count . ' photo' . ($count === 1 ? '' : 's') . ' · submitted ' . format_date($order['created_at'])
);

$uploadedNow = (int) input('uploaded', '0');
if ($uploadedNow > 0): ?>
    <div class="banner banner-info">
        <?= icon('sparkles') ?>
        <div>
            <strong>Upload complete</strong>
            <p><?= $uploadedNow ?> photo<?= $uploadedNow === 1 ? '' : 's' ?> added. This order now has <?= $count ?> of <?= (int) MAX_PHOTOS_PER_ORDER ?> photos.</p>
        </div>
    </div>
<?php endif; ?>

<div class="detail-layout">
    <div class="stack">
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
                        <a class="btn btn-ghost btn-sm" href="<?= e(url('customer/orders.php')) ?>">All prints</a>
                    </div>
                </div>

                <?php if ($order['status'] === 'failed' && !empty($order['error_message'])): ?>
                    <div class="alert alert-error mt-3">
                        <?= icon('alert') ?>
                        <div>
                            <strong>The printer reported a problem.</strong>
                            <p><?= e($order['error_message']) ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="photo-gallery mt-3">
                    <?php foreach ($photos as $index => $photo): ?>
                        <figure>
                            <a href="<?= e(url('customer/photo.php?photo=' . (int) $photo['id'])) ?>" target="_blank" rel="noopener">
                                <img src="<?= e(url('customer/photo.php?photo=' . (int) $photo['id'] . '&thumb=1')) ?>"
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
                    <div><dt>Photos</dt><dd><?= $count ?> of <?= (int) MAX_PHOTOS_PER_ORDER ?></dd></div>
                    <div><dt>Print size</dt><dd><?= e(paper_size_label($order['paper_size'])) ?></dd></div>
                    <div><dt>Colour</dt><dd><?= $order['color_mode'] === 'mono' ? 'Black &amp; white' : 'Colour' ?></dd></div>
                    <div><dt>Border</dt><dd><?= (int) $order['borderless'] === 1 ? 'Borderless' : 'White border' ?></dd></div>
                    <div><dt>Quality</dt><dd><?= e(explode(' — ', $quality)[0]) ?></dd></div>
                    <div><dt>Total size</dt><dd><?= e(human_size($totalKb)) ?></dd></div>
                    <div><dt>Copies</dt><dd>1 of each photo</dd></div>
                    <div><dt>Last update</dt><dd><?= e(format_date($order['updated_at'])) ?></dd></div>
                </dl>

                <?php if (!empty($order['notes'])): ?>
                    <div class="detail-note">
                        <strong>Your note</strong>
                        <?= e($order['notes']) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($order['admin_note'])): ?>
                    <div class="detail-note">
                        <strong>Note from the print desk</strong>
                        <?= e($order['admin_note']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($order['status'] === 'pending'): ?>
                    <div class="order-actions">
                        <form method="post" action="<?= e(url('customer/order.php?id=' . $orderId)) ?>" data-confirm="Cancel this print? Your photos will stay in your history.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cancel">
                            <button class="btn btn-danger btn-sm" type="submit"><?= icon('close') ?> Cancel order</button>
                        </form>
                        <span class="muted" style="font-size:.8rem;align-self:center">You can cancel while the order is still in the queue.</span>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($order['status'] === 'pending' && $remainingPhotos > 0): ?>
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h3>Add more photos</h3>
                        <p><?= $count ?> of <?= (int) MAX_PHOTOS_PER_ORDER ?> photos in this order · uploaded <?= (int) $addBatchLimit ?> at a time</p>
                    </div>
                </div>
                <div class="panel-body">
                    <?php if ($canAddPhotos): ?>
                        <form method="post" action="<?= e(url('customer/add-photos.php?id=' . $orderId)) ?>"
                              enctype="multipart/form-data" data-submit-once
                              data-batch-upload data-batch-size="<?= (int) $addBatchLimit ?>"
                              data-order-id="<?= (int) $orderId ?>"
                              data-order-url="<?= e(url('customer/order.php?id=' . $orderId)) ?>"
                              data-add-url="<?= e(url('customer/add-photos.php')) ?>">
                            <?= csrf_field() ?>

                            <label class="dropzone dropzone-photo" data-dropzone data-multi
                                   data-max-bytes="<?= (int) $uploadLimits['file_bytes'] ?>"
                                   data-max-files="<?= (int) $remainingPhotos ?>"
                                   data-batch-size="<?= (int) $addBatchLimit ?>"
                                   data-max-total-bytes="<?= (int) $batchTotalLimit ?>">
                                <input type="file" name="photos[]" accept="image/jpeg,image/png,.jpg,.jpeg,.png" multiple required>
                                <span class="dropzone-icon"><?= icon('image') ?></span>
                                <span class="dropzone-title">Drop more photos here</span>
                                <span class="dropzone-hint" data-file-hint>
                                    or click to browse — JPG or PNG, up to <?= e($fileLimitMb) ?> MB each. Select up to <?= (int) $remainingPhotos ?>; they upload <?= (int) $addBatchLimit ?> at a time.
                                </span>
                                <span class="dropzone-grid" data-photo-grid></span>
                                <span class="dropzone-list" data-photo-list></span>
                                <span class="dropzone-file" data-file-name></span>
                            </label>

                            <p class="help">
                                <?= $remainingPhotos ?> photo<?= $remainingPhotos === 1 ? '' : 's' ?> left in this order.
                                A large selection is uploaded automatically in batches of <?= (int) $addBatchLimit ?>.
                                <?php if ($storageCap > 0): ?>
                                    Storage used: <?= e(human_size($totalKb)) ?> of <?= e(human_size($storageCap)) ?>.
                                <?php endif; ?>
                            </p>

                            <p class="help" data-upload-progress hidden aria-live="polite"></p>

                            <button class="btn btn-primary" type="submit"><?= icon('plus') ?> Add photos</button>
                        </form>
                    <?php else: ?>
                        <p class="help mb-0">
                            <?= $storageLeft !== null && $storageLeft <= 0
                                ? 'This order has reached its ' . human_size(MAX_ORDER_STORAGE_MB * 1024 * 1024) . ' storage limit. Start a new order for more photos.'
                                : 'This server cannot accept more photos right now.' ?>
                        </p>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-head"><h3>Photo details</h3></div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Photo</th>
                        <th class="nowrap">Size</th>
                        <th class="nowrap">File</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($photos as $index => $photo): ?>
                        <tr>
                            <td>
                                <div class="doc-cell">
                                    <img class="thumb" src="<?= e(url('customer/photo.php?photo=' . (int) $photo['id'] . '&thumb=1')) ?>"
                                         alt="" loading="lazy" width="42" height="42">
                                    <span class="doc-text">
                                        <span class="doc-name"><?= ($index + 1) . '. ' . e($photo['file_name']) ?></span>
                                        <span class="doc-meta"><?= (int) $photo['image_width'] ?> × <?= (int) $photo['image_height'] ?> px</span>
                                    </span>
                                </div>
                            </td>
                            <td class="nowrap muted"><?= e(human_size((int) $photo['file_size'])) ?></td>
                            <td class="nowrap muted"><?= order_photo_mime($photo) === 'image/png' ? 'PNG' : 'JPG' ?></td>
                            <td><?= status_badge($photo['status']) ?></td>
                            <td class="cell-actions">
                                <a class="btn btn-ghost btn-sm" href="<?= e(url('customer/photo.php?photo=' . (int) $photo['id'] . '&download=1')) ?>"><?= icon('download') ?> Save</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h3>What was asked for</h3></div>
            <div class="panel-body">
                <ul class="check-list">
                    <li><?= icon('check') ?><span><strong><?= e(paper_size_label($order['paper_size'])) ?></strong> photo print, <?= (int) $order['borderless'] === 1 ? 'borderless' : 'with a white border' ?></span></li>
                    <li><?= icon('check') ?><span><?= $order['color_mode'] === 'mono' ? 'Black and white' : 'Full colour' ?>, one copy of each of the <?= $count ?> photo<?= $count === 1 ? '' : 's' ?></span></li>
                    <li><?= icon('check') ?><span><?= e(explode(' — ', $quality)[0]) ?> quality on Epson photo paper</span></li>
                </ul>
            </div>
        </section>
    </div>

    <div class="stack">
        <section class="panel">
            <div class="panel-head"><h3>Progress</h3></div>
            <div class="panel-body">
                <ul class="timeline">
                    <?php $last = count($events) - 1; ?>
                    <?php foreach ($events as $index => $event): ?>
                        <li class="timeline-item <?= $index === $last ? 'is-current' : 'is-done' ?>">
                            <span class="timeline-dot"></span>
                            <div class="timeline-body">
                                <p><?= e($event['message']) ?></p>
                                <span class="timeline-meta"><?= e(status_meta($event['status'])[0]) ?> · <?= e(format_date($event['created_at'])) ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>

        <div class="device-card">
            <div class="device-top">
                <span class="device-label">Printing on</span>
                <span class="device-state"><span class="status-dot"></span> <?= $epson->isConnected() ? 'Online' : 'Ready' ?></span>
            </div>
            <div class="device-name">
                <span class="device-icon"><?= icon('printer') ?></span>
                <div>
                    <h3><?= e($epson->isConnected() ? $epson->deviceName() : EPSON_PRINTER_MODEL) ?></h3>
                    <p>EcoTank · 4 × 6 · Photo paper</p>
                </div>
            </div>
            <div class="device-meta">
                <span class="device-chip"><?= icon('layers') ?> <?= (int) MAX_PHOTOS_PER_ORDER ?> photos per order</span>
            </div>
        </div>
    </div>
</div>

<?php app_end(); ?>

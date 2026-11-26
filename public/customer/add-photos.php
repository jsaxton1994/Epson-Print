<?php
/**
 * Add another batch of photos to an existing order. Orders hold up to
 * MAX_PHOTOS_PER_ORDER photos, added MAX_PHOTOS_PER_BATCH at a time.
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

$back = 'customer/order.php?id=' . $orderId;

if (!is_post()) {
    redirect($back);
}

if (post_request_too_large()) {
    $serverLimit = ini_bytes((string) ini_get('post_max_size'));
    abort_upload(
        'That batch is larger than this server accepts in one request (' . human_size($serverLimit)
        . '). Add fewer or smaller photos.',
        $back
    );
}

verify_csrf();

$count        = order_photo_count((int) $order['id']);
$remaining    = max(0, MAX_PHOTOS_PER_ORDER - $count);
$storageBytes = order_storage_bytes((int) $order['id']);
$storageCap   = order_storage_limit_bytes();
$storageLeft  = $storageCap !== null ? max(0, $storageCap - $storageBytes) : null;
$limits       = upload_limits();
$maxFiles     = min(MAX_PHOTOS_PER_BATCH, $remaining, $limits['max_files']);

if ($order['status'] !== 'pending') {
    abort_upload('Photos can only be added while the order is still waiting in the queue.', $back);
}

if ($remaining <= 0) {
    abort_upload('This order already has the maximum of ' . MAX_PHOTOS_PER_ORDER . ' photos. Start a new order for more.', $back);
}

if ($storageLeft !== null && $storageLeft <= 0) {
    abort_upload('This order has reached its ' . human_size(order_storage_limit_bytes() ?? 0) . ' storage limit. Start a new order for more photos.', $back);
}

if ($maxFiles <= 0) {
    abort_upload('This server does not currently accept photo uploads.', $back);
}

try {
    $files = validate_photo_uploads(
        normalize_uploaded_files($_FILES['photos'] ?? []),
        $maxFiles,
        $storageLeft
    );

    $nextPosition = (int) db_val(
        'SELECT COALESCE(MAX(position), 0) FROM order_photos WHERE order_id = ?',
        [$order['id']]
    ) + 1;

    $inserted = [];

    // One photo at a time, so a batch never holds every file in memory.
    foreach ($files as $index => $file) {
        $inserted[] = insert_photo_upload((int) $order['id'], $file, $nextPosition + $index);
    }

    $added = count($inserted);
    $total = $count + $added;

    db_update('orders', ['updated_at' => now()], 'id = ?', [$order['id']]);
    log_order_event(
        (int) $order['id'],
        'pending',
        $added . ' more photo' . ($added === 1 ? '' : 's') . ' added by ' . $user['name']
            . ' (' . $total . ' of ' . MAX_PHOTOS_PER_ORDER . ').',
        $user['name']
    );

    if (is_ajax_upload()) {
        // Details of just the photos added by this batch, so the order page can
        // drop them straight into its photo list while the rest uploads.
        $addedPhotos = [];

        if ($inserted !== []) {
            $placeholders = implode(', ', array_fill(0, count($inserted), '?'));
            $rows = db_all(
                'SELECT ' . order_photo_columns() . ' FROM order_photos
                 WHERE id IN (' . $placeholders . ') ORDER BY position ASC, id ASC',
                $inserted
            );

            foreach ($rows as $row) {
                $addedPhotos[] = [
                    'id'       => (int) $row['id'],
                    'name'     => (string) $row['file_name'],
                    'size'     => (int) $row['file_size'],
                    'width'    => (int) $row['image_width'],
                    'height'   => (int) $row['image_height'],
                    'file'     => order_photo_mime($row) === 'image/png' ? 'PNG' : 'JPG',
                    'position' => (int) $row['position'],
                    'thumb'    => url('customer/photo.php?photo=' . (int) $row['id'] . '&thumb=1'),
                    'view'     => url('customer/photo.php?photo=' . (int) $row['id']),
                    'download' => url('customer/photo.php?photo=' . (int) $row['id'] . '&download=1'),
                    'badge'    => status_badge((string) $row['status']),
                ];
            }
        }

        upload_json([
            'ok'       => true,
            'order_id' => (int) $order['id'],
            'added'    => $added,
            'total'    => $total,
            'photos'   => $addedPhotos,
        ]);
    }

    flash('success', 'Added ' . $added . ' photo' . ($added === 1 ? '' : 's') . '. This order now has '
        . $total . ' of ' . MAX_PHOTOS_PER_ORDER . '.');
} catch (Throwable $e) {
    // Undo a partially inserted batch so it can be retried cleanly.
    if (!empty($inserted)) {
        $placeholders = implode(', ', array_fill(0, count($inserted), '?'));
        db_query('DELETE FROM order_photos WHERE id IN (' . $placeholders . ')', $inserted);
    }

    error_log('[PrintFree] add photos failed: ' . get_class($e) . ': ' . $e->getMessage());
    abort_upload($e instanceof RuntimeException
        ? $e->getMessage()
        : 'The server could not save your photos. Please try again.', $back);
}

redirect($back);

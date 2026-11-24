<?php
/**
 * Serve any photo to an administrator for previews or download.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$admin = require_admin();

$photoId = (int) input('photo', '0');
$photo   = db_one(
    'SELECT id, order_id, file_name, mime_type, file_size, image_width, image_height,
            position, status, epson_job_id, error_message, printed_at, created_at
     FROM order_photos WHERE id = ?',
    [$photoId]
);

if ($photo === null) {
    flash('error', 'That photo could not be found.');
    redirect('admin/orders.php');
}

$orderId = (int) $photo['order_id'];

serve_order_photo(
    $photo,
    url('admin/order.php?id=' . $orderId),
    isset($_GET['download']),
    isset($_GET['thumb'])
);

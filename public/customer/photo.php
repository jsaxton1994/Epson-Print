<?php
/**
 * Serve one of a customer's own photos inline for previews or as a download.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$user = require_login();
if ($user['role'] === 'admin') {
    redirect('admin/index.php');
}

$photoId = (int) input('photo', '0');
$photo   = db_one(
    'SELECT p.id, p.order_id, p.file_name, p.mime_type, p.file_size, p.image_width,
            p.image_height, p.position, p.status, p.epson_job_id, p.error_message,
            p.printed_at, p.created_at
     FROM order_photos p JOIN orders o ON o.id = p.order_id
     WHERE p.id = ? AND o.user_id = ?',
    [$photoId, $user['id']]
);

if ($photo === null) {
    flash('error', 'That photo could not be found.');
    redirect('customer/orders.php');
}

$orderId = (int) $photo['order_id'];

serve_order_photo(
    $photo,
    url('customer/order.php?id=' . $orderId),
    isset($_GET['download']),
    isset($_GET['thumb'])
);

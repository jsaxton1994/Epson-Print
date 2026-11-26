<?php
/**
 * The photo that will be sent to the printer, for admins to check first.
 *
 * Nothing is altered: this is the stored upload, which is exactly what Epson
 * receives. `w` returns a smaller version for the inline preview on the order
 * page only.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

require_admin();

$photoId = (int) input('photo', '0');

$photo = db_one(
    'SELECT id, file_name, mime_type FROM order_photos WHERE id = ?',
    [$photoId]
);

if ($photo === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Photo not found.');
}

$bytes = photo_binary($photo);

if ($bytes === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Photo data is missing.');
}

$mime  = order_photo_mime($photo);
$width = (int) input('w', '0');

if ($width > 0 && function_exists('imagecreatefromstring') && function_exists('imagescale')) {
    $width = max(160, min(1600, $width));
    $image = @imagecreatefromstring($bytes);

    if ($image !== false) {
        $scaled = imagescale($image, $width, -1);
        imagedestroy($image);

        if ($scaled !== false) {
            ob_start();
            imagejpeg($scaled, null, 86);
            $bytes = ob_get_clean();
            $mime  = 'image/jpeg';
            imagedestroy($scaled);
        }
    }
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($bytes));
header('Content-Disposition: inline; filename="print-preview.jpg"');
header('Cache-Control: private, max-age=120');

echo $bytes;

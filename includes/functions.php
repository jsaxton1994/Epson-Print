<?php
/**
 * Shared helpers: URLs, formatting, settings, uploads, orders and pagination.
 */

declare(strict_types=1);

/* -------------------------------------------------------------------------
 |  Output & URLs
 * ---------------------------------------------------------------------- */

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Web path of public/, e.g. '' when public/ is the document root (Wasmer,
 * Laravel-style hosts) or '/printfree/public' when the project root is.
 *
 * Internal links stay root-relative so the site works over HTTP or HTTPS and
 * inside a subdirectory without mixed-content surprises. Only the Epson
 * redirect URI needs a full URL, and that uses absolute_url().
 */
function public_base_uri(): string
{
    static $base = null;

    if ($base !== null) {
        return $base;
    }

    if (PHP_SAPI === 'cli' || empty($_SERVER['SCRIPT_NAME'])) {
        return $base = '';
    }

    $scriptName = str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']);
    $scriptFile = isset($_SERVER['SCRIPT_FILENAME']) ? realpath($_SERVER['SCRIPT_FILENAME']) : false;
    $publicDir  = realpath(__DIR__ . '/../public');

    // A script inside public/ tells us where that folder is mounted, whether
    // the host serves public/ as its root or the project root above it.
    if ($publicDir !== false && $scriptFile !== false
        && str_starts_with($scriptFile, $publicDir . DIRECTORY_SEPARATOR)) {
        $relative = str_replace('\\', '/', substr($scriptFile, strlen($publicDir) + 1));
        if ($relative !== '' && str_ends_with($scriptName, '/' . $relative)) {
            return $base = rtrim(substr($scriptName, 0, -strlen('/' . $relative)), '/');
        }
    }

    // Root-level entry points (index.php) sit one level above public/.
    $projectDir = realpath(__DIR__ . '/..');
    if ($projectDir !== false && $scriptFile !== false
        && str_starts_with($scriptFile, $projectDir . DIRECTORY_SEPARATOR)) {
        $relative = str_replace('\\', '/', substr($scriptFile, strlen($projectDir) + 1));
        if ($relative !== '' && str_ends_with($scriptName, '/' . $relative)) {
            $prefix = rtrim(substr($scriptName, 0, -strlen('/' . $relative)), '/');

            return $base = $prefix . '/public';
        }
    }

    return $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
}

/**
 * Build an internal URL. Paths are relative to public/, which is the web root.
 * A legacy 'public/' prefix is accepted and removed.
 */
function url(string $path = ''): string
{
    $base = public_base_uri();
    $path = ltrim($path, '/');

    if (str_starts_with($path, 'public/')) {
        $path = substr($path, 7);
    }

    if ($path === '') {
        return $base === '' ? '/' : $base . '/';
    }

    return $base . '/' . $path;
}

function absolute_url(string $path = ''): string
{
    if (APP_URL !== '') {
        return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return ($https ? 'https' : 'http') . '://' . $host . url($path);
}

function asset(string $path): string
{
    return url(ltrim($path, '/'));
}

function redirect(string $path): never
{
    header('Location: ' . (preg_match('#^https?://#i', $path) ? $path : url($path)));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

/* -------------------------------------------------------------------------
 |  Settings
 * ---------------------------------------------------------------------- */

function settings_all(bool $refresh = false): array
{
    static $cache = null;

    if ($cache === null || $refresh) {
        $cache = [];
        foreach (db_all('SELECT setting_key, setting_value FROM settings') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }

    return $cache;
}

function setting(string $key, ?string $default = null): ?string
{
    $value = settings_all()[$key] ?? null;

    return ($value === null || $value === '') ? $default : $value;
}

function set_setting(string $key, ?string $value): void
{
    $exists = (int) db_val('SELECT COUNT(*) FROM settings WHERE setting_key = ?', [$key]);

    if ($exists > 0) {
        db_query('UPDATE settings SET setting_value = ? WHERE setting_key = ?', [$value, $key]);
    } else {
        db_query('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)', [$key, $value]);
    }

    settings_all(true);
}

function site_name(): string
{
    return setting('site_name', APP_NAME) ?? APP_NAME;
}

/* -------------------------------------------------------------------------
 |  Dates & formatting
 * ---------------------------------------------------------------------- */

function now(): string
{
    return date('Y-m-d H:i:s');
}

function format_date(?string $value, string $format = 'j M Y, H:i'): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? '—' : date($format, $timestamp);
}

function time_ago(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '—';
    }

    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        $mins = (int) floor($diff / 60);
        return $mins . ' min ago';
    }
    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 604800) {
        $days = (int) floor($diff / 86400);
        return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }

    return date('j M Y', $timestamp);
}

function database_size_bytes(): int
{
    if (db_driver() === 'sqlite') {
        return is_file(DB_SQLITE_PATH) ? (int) filesize(DB_SQLITE_PATH) : 0;
    }

    try {
        return (int) db_val(
            'SELECT COALESCE(SUM(data_length + index_length), 0)
             FROM information_schema.TABLES WHERE table_schema = DATABASE()'
        );
    } catch (Throwable $e) {
        return 0;
    }
}

function human_size(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 KB';
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

    return round($bytes / (1024 ** $power), $power === 0 ? 0 : 1) . ' ' . $units[$power];
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach ($parts as $part) {
        if ($part !== '') {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }
        if (strlen($letters) === 2) {
            break;
        }
    }

    return $letters !== '' ? $letters : 'PF';
}

/* -------------------------------------------------------------------------
 |  Order vocabulary
 * ---------------------------------------------------------------------- */

function paper_size_options(): array
{
    return [
        '4x6' => '4 × 6 in — 102 × 152 mm',
        '5x7' => '5 × 7 in — 127 × 178 mm',
        'A4'  => 'A4 — 210 × 297 mm',
    ];
}

function paper_size_label(string $key): string
{
    $label = paper_size_options()[$key] ?? $key;

    return explode(' — ', $label)[0];
}

function quality_options(): array
{
    return [
        'normal' => 'Standard — crisp, everyday prints',
        'high'   => 'High — maximum detail and colour',
    ];
}

function color_options(): array
{
    return ['color' => 'Colour', 'mono' => 'Black & white'];
}

function status_meta(string $status): array
{
    return match ($status) {
        'pending'   => ['In queue', 'badge-pending', 'clock'],
        'printing'  => ['Printing', 'badge-printing', 'printer'],
        'completed' => ['Printed', 'badge-completed', 'check'],
        'cancelled' => ['Cancelled', 'badge-cancelled', 'close'],
        'failed'    => ['Failed', 'badge-failed', 'alert'],
        default     => [ucfirst($status), 'badge-neutral', 'file'],
    };
}

function status_badge(string $status): string
{
    [$label, $class, $iconName] = status_meta($status);

    return '<span class="badge ' . $class . '">' . icon($iconName, 'badge-icon') . e($label) . '</span>';
}

/**
 * Columns to select for photo listings — everything except the image bytes.
 */
function order_photo_columns(): string
{
    return 'id, order_id, file_name, mime_type, file_size, image_width, image_height,
            position, status, epson_job_id, error_message, printed_at, created_at';
}

/**
 * Every photo in an order, in the order the customer picked them.
 */
function order_photos(int $orderId): array
{
    return db_all(
        'SELECT ' . order_photo_columns() . '
         FROM order_photos WHERE order_id = ? ORDER BY position ASC, id ASC',
        [$orderId]
    );
}

function order_photo_count(int $orderId): int
{
    return (int) db_val('SELECT COUNT(*) FROM order_photos WHERE order_id = ?', [$orderId]);
}

/**
 * Total bytes of full-size photo data stored for one order.
 */
function order_storage_bytes(int $orderId): int
{
    return (int) db_val(
        'SELECT COALESCE(SUM(file_size), 0) FROM order_photos WHERE order_id = ?',
        [$orderId]
    );
}

/**
 * First photo of an order — used for thumbnails and the order summary.
 */
function primary_photo(int $orderId): ?array
{
    return db_one(
        'SELECT ' . order_photo_columns() . '
         FROM order_photos WHERE order_id = ? ORDER BY position ASC, id ASC LIMIT 1',
        [$orderId]
    );
}

function order_photo_mime(array $photo): string
{
    $mime = strtolower(trim((string) ($photo['mime_type'] ?? '')));

    if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
        return $mime;
    }

    $extension = strtolower(pathinfo((string) ($photo['file_name'] ?? ''), PATHINFO_EXTENSION));

    return $extension === 'png' ? 'image/png' : 'image/jpeg';
}

/**
 * Photo bytes from the database. Fetches them when the caller only has the
 * photo row (listings deliberately leave the BLOB out).
 */
function photo_binary(array $photo): ?string
{
    $data = $photo['image_data'] ?? null;

    if ($data === null && !empty($photo['id'])) {
        $data = db_val('SELECT image_data FROM order_photos WHERE id = ?', [$photo['id']]);
    }

    if (is_resource($data)) {
        $data = stream_get_contents($data);
    }

    return is_string($data) && $data !== '' ? $data : null;
}

/**
 * Generated thumbnail bytes for a photo, if one was stored.
 */
function photo_thumbnail(array $photo): ?string
{
    $data = $photo['thumb_data'] ?? null;

    if ($data === null && !empty($photo['id'])) {
        $data = db_val('SELECT thumb_data FROM order_photos WHERE id = ?', [$photo['id']]);
    }

    if (is_resource($data)) {
        $data = stream_get_contents($data);
    }

    return is_string($data) && $data !== '' ? $data : null;
}

/**
 * Build a small JPEG preview from photo bytes. Returns null when GD is
 * unavailable, the image is too large to decode safely, or it is unreadable
 * — the full photo is then served instead.
 */
function make_photo_thumbnail(string $data, int $maxEdge = 480): ?string
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg') || !function_exists('imagedestroy')) {
        return null;
    }

    $info = @getimagesizefromstring($data);
    if (!is_array($info)) {
        return null;
    }

    $width  = (int) $info[0];
    $height = (int) $info[1];

    // 30 MP is already ~120 MB decoded; skip anything that could exhaust memory.
    if ($width < 1 || $height < 1 || ($width * $height) > 30_000_000) {
        return null;
    }

    $image = @imagecreatefromstring($data);
    if ($image === false) {
        return null;
    }

    $scale = min(1.0, $maxEdge / max($width, $height));
    $thumbWidth  = max(1, (int) round($width * $scale));
    $thumbHeight = max(1, (int) round($height * $scale));

    $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
    if ($thumb === false) {
        imagedestroy($image);

        return null;
    }

    // Flatten transparent PNGs onto white photo paper.
    imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
    imagecopyresampled($thumb, $image, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);

    ob_start();
    $ok = imagejpeg($thumb, null, 82);
    $jpeg = ob_get_clean();

    imagedestroy($thumb);
    imagedestroy($image);

    return $ok && is_string($jpeg) && $jpeg !== '' ? $jpeg : null;
}

/**
 * Stream a stored photo, or its generated thumbnail, to its owner or an
 * admin. Inline for previews, attachment when the download flag is set.
 */
function serve_order_photo(array $photo, string $fallbackUrl, bool $download = false, bool $thumbnail = false): never
{
    $bytes     = null;
    $isThumb   = false;
    $name      = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $photo['file_name']) ?: 'photo.jpg';

    if ($thumbnail) {
        $bytes   = photo_thumbnail($photo);
        $isThumb = $bytes !== null;
    }

    if ($bytes === null) {
        $bytes = photo_binary($photo);
    }

    if ($bytes === null) {
        flash('error', 'That photo is no longer available in the database.');
        redirect($fallbackUrl);
    }

    if ($isThumb) {
        $name = preg_replace('/\.[a-z]+$/i', '', $name) . '-thumb.jpg';
    } elseif (!preg_match('/\.(jpe?g|png)$/i', $name)) {
        $name .= order_photo_mime($photo) === 'image/png' ? '.png' : '.jpg';
    }

    header('Content-Type: ' . ($isThumb ? 'image/jpeg' : order_photo_mime($photo)));
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $name . '"');
    header('Content-Length: ' . (string) strlen($bytes));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=' . ($isThumb ? '86400' : '600'));

    echo $bytes;
    exit;
}

/**
 * Turn PHP's $_FILES['photos'] shape into a list of individual files.
 *
 * @return array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function normalize_uploaded_files(array $field): array
{
    if (!isset($field['name'])) {
        return [];
    }

    if (!is_array($field['name'])) {
        return [$field];
    }

    $files = [];
    foreach (array_keys($field['name']) as $index) {
        $files[] = [
            'name'     => $field['name'][$index] ?? '',
            'type'     => $field['type'][$index] ?? '',
            'tmp_name' => $field['tmp_name'][$index] ?? '',
            'error'    => $field['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size'     => $field['size'][$index] ?? 0,
        ];
    }

    return $files;
}

/**
 * Parse a php.ini size value ("8M", "512K", "1G") into bytes. 0 means the
 * setting is unlimited or not configured.
 */
function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $unit   = strtolower(substr($value, -1));
    $number = (float) $value;

    return (int) match ($unit) {
        'g' => $number * 1024 ** 3,
        'm' => $number * 1024 ** 2,
        'k' => $number * 1024,
        default => $number,
    };
}

/**
 * Effective upload limits after PHP's ini settings are applied. Hosts such as
 * Wasmer or shared hosting often allow less than MAX_UPLOAD_MB per file or per
 * request, so the forms and validation use these numbers instead of guessing.
 *
 * @return array{file_bytes:int, post_bytes:int, max_files:int}
 */
function upload_limits(): array
{
    $fileBytes = MAX_UPLOAD_MB * 1024 * 1024;
    $iniFile   = ini_bytes((string) ini_get('upload_max_filesize'));
    $iniPost   = ini_bytes((string) ini_get('post_max_size'));

    if ($iniFile > 0) {
        $fileBytes = min($fileBytes, $iniFile);
    }
    if ($iniPost > 0) {
        $fileBytes = min($fileBytes, $iniPost);
    }

    $maxFiles = MAX_PHOTOS_PER_BATCH;
    $iniFiles = (int) ini_get('max_file_uploads');
    if ($iniFiles > 0) {
        $maxFiles = min($maxFiles, $iniFiles);
    }

    return [
        'file_bytes' => max(1, $fileBytes),
        'post_bytes' => max(0, $iniPost),
        'max_files'  => max(1, $maxFiles),
    ];
}

/**
 * True when PHP discarded a POST because it was larger than post_max_size.
 * In that case $_POST and $_FILES are empty, so this must be checked before
 * CSRF validation to show a useful message instead of a generic failure.
 */
function post_request_too_large(): bool
{
    if (!is_post()) {
        return false;
    }

    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $max    = ini_bytes((string) ini_get('post_max_size'));

    return $length > 0 && $max > 0 && $length > $max;
}

/**
 * Check how many photos arrived and that the selection is allowed. Keeps the
 * files on disk untouched — each one is read and stored individually.
 *
 * @param array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}> $files
 * @return array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function validate_photo_uploads(array $files, ?int $maxFiles = null, ?int $maxTotalBytes = null): array
{
    $files = array_values(array_filter(
        $files,
        static fn (array $file): bool => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
    ));

    if ($files === []) {
        throw new RuntimeException('Please choose at least one photo to upload.');
    }

    $limits   = upload_limits();
    $maxFiles = max(1, min($maxFiles ?? MAX_PHOTOS_PER_BATCH, $limits['max_files']));

    if (count($files) > $maxFiles) {
        throw new RuntimeException(
            'You can add up to ' . $maxFiles . ' photo' . ($maxFiles === 1 ? '' : 's') . ' in one batch.'
        );
    }

    $maxBytes = $limits['file_bytes'];

    foreach ($files as $file) {
        $size = (int) ($file['size'] ?? 0);

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(upload_error_message((int) $file['error']));
        }

        if ($size <= 0) {
            throw new RuntimeException('One of those files looks empty. Please choose another photo.');
        }

        if ($size > $maxBytes) {
            throw new RuntimeException('Please keep photos under ' . human_size($maxBytes) . ' each on this server.');
        }

        $extension = strtolower(pathinfo(trim((string) ($file['name'] ?? '')), PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            throw new RuntimeException('Only JPG and PNG photos can be printed.');
        }
    }

    $totalBytes = array_sum(array_map(static fn (array $file): int => (int) ($file['size'] ?? 0), $files));

    if ($maxTotalBytes !== null && $maxTotalBytes > 0 && $totalBytes > $maxTotalBytes) {
        throw new RuntimeException(
            'That batch is ' . human_size($totalBytes) . ', but only ' . human_size($maxTotalBytes)
            . ' of space is available here. Choose fewer photos or smaller files.'
        );
    }

    if ($limits['post_bytes'] > 0 && $totalBytes > $limits['post_bytes']) {
        throw new RuntimeException(
            'That batch is ' . human_size($totalBytes) . ', larger than this server accepts in one upload ('
            . human_size($limits['post_bytes']) . '). Add photos in smaller batches.'
        );
    }

    return $files;
}

function upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE   => 'That photo is larger than the server allows.',
        UPLOAD_ERR_FORM_SIZE  => 'That photo is larger than the server allows.',
        UPLOAD_ERR_PARTIAL    => 'The upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE    => 'Please choose a photo to upload.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server could not store the upload. Please try again later.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not store the upload. Please try again later.',
        UPLOAD_ERR_EXTENSION  => 'The upload was blocked by the server.',
        default               => 'The upload failed. Please try again.',
    };
}

/**
 * Read, validate and insert one uploaded photo, thumbnail included.
 *
 * Called once per file so only a single photo (at most MAX_UPLOAD_MB) is held
 * in memory at a time, rather than the whole order.
 */
function insert_photo_upload(int $orderId, array $file, int $position): int
{
    $photo = read_photo_upload($file, $position);

    insert_order_photo($orderId, $photo);
    $photoId = (int) db()->lastInsertId();

    // Release the full-size bytes before the next file is read.
    unset($photo['data'], $photo['thumb']);

    return $photoId;
}

/**
 * Validate and read one JPG or PNG photo, plus its thumbnail.
 */
function read_photo_upload(array $file, int $position): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Please choose a photo to upload.');
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_message((int) $file['error']));
    }

    $maxBytes = upload_limits()['file_bytes'];
    $size     = (int) ($file['size'] ?? 0);

    if ($size <= 0) {
        throw new RuntimeException('One of those files looks empty. Please choose another photo.');
    }
    if ($size > $maxBytes) {
        throw new RuntimeException('Please keep photos under ' . human_size($maxBytes) . ' each on this server.');
    }

    $original  = trim((string) ($file['name'] ?? 'photo.jpg'));
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));

    if (!in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
        throw new RuntimeException('Only JPG and PNG photos can be printed.');
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false || !isset($info['mime'])) {
        throw new RuntimeException('That file is not a readable photo. Please upload a JPG or PNG.');
    }

    if (!in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
        throw new RuntimeException('Only JPG and PNG photos can be printed.');
    }

    $data = @file_get_contents($file['tmp_name']);

    if ($data === false || $data === '') {
        throw new RuntimeException('The server could not read your photo. Please try again.');
    }

    return [
        'data'     => $data,
        'thumb'    => make_photo_thumbnail($data),
        'mime'     => (string) $info['mime'],
        'name'     => mb_substr($original, 0, 255),
        'size'     => strlen($data),
        'width'    => (int) $info[0],
        'height'   => (int) $info[1],
        'position' => $position,
    ];
}

/**
 * Store one photo row, full bytes and thumbnail included, in the database.
 *
 * The blobs are bound as LOBs so MySQL treats them as binary rather than
 * text and SQLite stores them as real BLOBs.
 */
function insert_order_photo(int $orderId, array $photo): void
{
    $stmt = db()->prepare(
        'INSERT INTO order_photos
            (order_id, file_name, mime_type, image_data, thumb_data, file_size,
             image_width, image_height, position, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $stmt->bindValue(1, $orderId, PDO::PARAM_INT);
    $stmt->bindValue(2, (string) $photo['name'], PDO::PARAM_STR);
    $stmt->bindValue(3, (string) $photo['mime'], PDO::PARAM_STR);
    $stmt->bindValue(4, (string) $photo['data'], PDO::PARAM_LOB);
    $stmt->bindValue(5, $photo['thumb'] ?? null, $photo['thumb'] === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    $stmt->bindValue(6, (int) $photo['size'], PDO::PARAM_INT);
    $stmt->bindValue(7, (int) $photo['width'], PDO::PARAM_INT);
    $stmt->bindValue(8, (int) $photo['height'], PDO::PARAM_INT);
    $stmt->bindValue(9, (int) $photo['position'], PDO::PARAM_INT);
    $stmt->bindValue(10, 'pending', PDO::PARAM_STR);
    $stmt->bindValue(11, now(), PDO::PARAM_STR);
    $stmt->execute();
}

/**
 * Recompute an order's status from its photos and log the change.
 *
 * all photos completed  -> completed
 * any photo failed      -> failed
 * any sent/printed      -> printing
 * otherwise             -> pending
 */
function sync_order_status(int $orderId, ?string $actor = null): string
{
    $order = db_one('SELECT status FROM orders WHERE id = ?', [$orderId]);
    if ($order === null) {
        return 'pending';
    }

    $photos = order_photos($orderId);
    $total  = count($photos);

    if ($total === 0 || $order['status'] === 'cancelled') {
        return (string) $order['status'];
    }

    $counts    = array_count_values(array_map(static fn (array $p): string => (string) $p['status'], $photos));
    $completed = (int) ($counts['completed'] ?? 0);
    $failed    = (int) ($counts['failed'] ?? 0);
    $sent      = $completed + (int) ($counts['printing'] ?? 0);

    if ($completed === $total) {
        $status  = 'completed';
        $message = 'All ' . $total . ' photo' . ($total === 1 ? '' : 's') . ' printed.';
    } elseif ($failed > 0) {
        $status  = 'failed';
        $message = $failed . ' of ' . $total . ' photos could not be printed.';
    } elseif ($sent > 0) {
        $status  = 'printing';
        $message = $sent . ' of ' . $total . ' photos sent to the printer.';
    } else {
        $status  = 'pending';
        $message = 'Order waiting in the queue.';
    }

    if ($status === $order['status']) {
        return $status;
    }

    $data = ['status' => $status, 'updated_at' => now()];

    if ($status === 'completed') {
        $data['printed_at'] = now();
        $data['error_message'] = null;
    }

    if ($status === 'pending') {
        $data['error_message'] = null;
    }

    if ($status === 'failed') {
        $errors = [];
        foreach ($photos as $photo) {
            if (!empty($photo['error_message'])) {
                $errors[] = (string) $photo['error_message'];
            }
        }
        $data['error_message'] = mb_substr(implode(' ', array_unique($errors)), 0, 500)
            ?: 'One or more photos could not be printed.';
    }

    db_update('orders', $data, 'id = ?', [$orderId]);
    log_order_event($orderId, $status, $message, $actor);

    return $status;
}

/**
 * Mark every photo in an order as printed by hand.
 */
function complete_order_photos(int $orderId, string $actor): void
{
    db_query(
        "UPDATE order_photos SET status = 'completed', printed_at = ?, error_message = NULL WHERE order_id = ?",
        [now(), $orderId]
    );

    update_order_status($orderId, 'completed', 'Marked as printed by ' . $actor . '.', $actor);
}

function generate_reference(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    do {
        $reference = 'PF-';
        for ($i = 0; $i < 6; $i++) {
            $reference .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $taken = (int) db_val('SELECT COUNT(*) FROM orders WHERE reference = ?', [$reference]);
    } while ($taken > 0);

    return $reference;
}

function log_order_event(int $orderId, string $status, string $message, ?string $actor = null): void
{
    db_insert('order_events', [
        'order_id'   => $orderId,
        'status'     => $status,
        'message'    => mb_substr($message, 0, 255),
        'actor'      => $actor !== null ? mb_substr($actor, 0, 120) : null,
        'created_at' => now(),
    ]);
}

function update_order_status(int $orderId, string $status, string $message, ?string $actor = null): void
{
    $data = ['status' => $status, 'updated_at' => now()];

    if ($status === 'completed') {
        $data['printed_at'] = now();
    }
    if ($status === 'pending') {
        $data['error_message'] = null;
    }

    db_update('orders', $data, 'id = ?', [$orderId]);
    log_order_event($orderId, $status, $message, $actor);
}

function order_events(int $orderId): array
{
    return db_all('SELECT * FROM order_events WHERE order_id = ? ORDER BY id ASC', [$orderId]);
}

/* -------------------------------------------------------------------------
 |  Pagination
 * ---------------------------------------------------------------------- */

function paginate(int $total, int $perPage = 10): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = max(1, min($pages, (int) ($_GET['page'] ?? 1)));

    return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage, 'per_page' => $perPage];
}

function pager(int $page, int $pages, array $query = []): string
{
    if ($pages <= 1) {
        return '';
    }

    $link = static function (int $target, string $label, string $class = '') use ($query): string {
        $params = array_merge($query, ['page' => $target]);
        $href   = '?' . http_build_query($params);

        return '<a class="pager-link ' . $class . '" href="' . e($href) . '">' . $label . '</a>';
    };

    $html = '<nav class="pager" aria-label="Pagination">';

    if ($page > 1) {
        $html .= $link($page - 1, icon('arrow-left', 'pager-icon') . ' Prev');
    }

    $start = max(1, $page - 2);
    $end   = min($pages, $page + 2);

    if ($start > 1) {
        $html .= $link(1, '1');
        if ($start > 2) {
            $html .= '<span class="pager-gap">…</span>';
        }
    }

    for ($i = $start; $i <= $end; $i++) {
        $html .= $i === $page
            ? '<span class="pager-link is-active" aria-current="page">' . $i . '</span>'
            : $link($i, (string) $i);
    }

    if ($end < $pages) {
        if ($end < $pages - 1) {
            $html .= '<span class="pager-gap">…</span>';
        }
        $html .= $link($pages, (string) $pages);
    }

    if ($page < $pages) {
        $html .= $link($page + 1, 'Next ' . icon('arrow-right', 'pager-icon'));
    }

    return $html . '</nav>';
}

<?php
/**
 * Live printer status for the admin dashboard.
 *
 * Returns what the Epson printer is doing right now (printing photo 4 of 12,
 * out of paper, idle, unreachable) as JSON so the dashboard can poll it without
 * a full page reload.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$user = current_user();

if ($user === null || $user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Administrators only.']);
    exit;
}

try {
    $status = printer_live_status(EpsonClient::fromSettings());
} catch (Throwable $e) {
    $status = [
        'state'  => 'unreachable',
        'label'  => 'Status unavailable',
        'detail' => $e->getMessage(),
        'order_id' => null,
        'order_url' => null,
        'order_reference' => null,
    ];
}

echo json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

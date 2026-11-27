<?php
/**
 * OAuth2 redirect target for Epson device authorisation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$admin = require_admin();

$storedState = (string) ($_SESSION['epson_state'] ?? '');
$issuedAt    = (int) ($_SESSION['epson_state_time'] ?? 0);
$state       = (string) ($_GET['state'] ?? '');
unset($_SESSION['epson_state'], $_SESSION['epson_state_time']);

// Epson's redirect may or may not echo the state value. When it is absent we
// still bind the callback to this admin session and require a fresh handshake.
if ($storedState === '' || ($state !== '' && !hash_equals($storedState, $state)) || (time() - $issuedAt) > 900) {
    flash('error', 'That Epson sign-in link has expired. Start the connection again from Settings.');
    redirect('admin/printer-settings.php');
}

if (!empty($_GET['error'])) {
    $reason = is_string($_GET['error']) ? $_GET['error'] : 'authorisation failed';
    flash('error', 'Epson returned an error: ' . $reason . '.');
    redirect('admin/printer-settings.php');
}

$code = (string) ($_GET['code'] ?? '');

if ($code === '') {
    flash('error', 'Epson did not send an authorisation code. Please try again.');
    redirect('admin/printer-settings.php');
}

$epson = EpsonClient::fromSettings();

try {
    $epson->exchangeCode($code);
    $fresh = EpsonClient::fromSettings();
    flash('success', 'Printer linked: ' . $fresh->deviceName() . '. You can send orders now.');
} catch (EpsonException $e) {
    flash('error', 'Could not finish linking the printer: ' . $e->getMessage());
}

redirect('admin/printer-settings.php');

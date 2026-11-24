<?php
/**
 * Sign out (POST only, CSRF protected).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if (!is_post()) {
    redirect('public/index.php');
}

verify_csrf();
logout_user();

flash('success', 'You have been signed out.');
redirect('public/login.php');

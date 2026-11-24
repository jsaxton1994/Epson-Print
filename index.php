<?php
/**
 * Root entry point — sends visitors to the public website.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

redirect(current_user() === null ? 'public/index.php' : (current_user()['role'] === 'admin' ? 'admin/index.php' : 'customer/index.php'));

<?php
/**
 * Single entry point for every page: config, database, helpers, session.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

date_default_timezone_set(APP_TIMEZONE);

error_reporting(E_ALL);
ini_set('display_errors', (APP_DEBUG || getenv('PRINTFREE_DEBUG')) ? '1' : '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/epson.php';
require_once __DIR__ . '/layout.php';

settings_prune_removed_features();

start_app_session();

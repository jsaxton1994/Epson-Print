<?php
/**
 * PrintFree — application configuration.
 *
 * Everything here is safe to edit by hand. The site installs its own
 * tables and demo data on first load, so you can upload the folder and go.
 */

declare(strict_types=1);

/* -------------------------------------------------------------------------
 |  Site
 * ---------------------------------------------------------------------- */

const APP_NAME    = 'PrintFree';
const APP_TAGLINE = 'Free printing, powered by Epson Connect';

// Shown centred in the site footer. Bump on every push so the live site
// makes it obvious which build is deployed (1.0.0 → 1.0.1 → 1.0.2 …).
const APP_VERSION = '1.3.28';
const APP_TIMEZONE = 'UTC';

// Set to true only while developing: it prints PHP errors into the page.
const APP_DEBUG = false;

// Leave blank to detect the address automatically. Set it if the site lives
// behind a proxy or you want absolute links, e.g. 'https://print.example.com'.
const APP_URL = '';

/* -------------------------------------------------------------------------
 |  Database
 |
 |  Non-secret connection details for the live cPanel MySQL database.
 |  The password belongs in includes/config.local.php, which is gitignored
 |  and overrides these defaults. Copy config.local.example.php to create it.
 |  This file therefore stays safe to publish.
 |
 |  MySQL is the only supported database. If the password file is missing or
 |  MySQL is unreachable, the site returns a 503 page instead of falling back.
 * ---------------------------------------------------------------------- */

$dbConfig = [
    'host'   => 'localhost',
    'port'   => 3306,
    'name'   => 'snapprin_snapprints',
    'user'   => 'snapprin_snapprints',
    'pass'   => '',
];

$configLocal = __DIR__ . '/config.local.php';
if (is_file($configLocal)) {
    $overrides = require $configLocal;
    if (is_array($overrides)) {
        $dbConfig = array_replace($dbConfig, array_intersect_key($overrides, $dbConfig));
    }
}

define('DB_DRIVER', 'mysql');
define('DB_HOST', (string) $dbConfig['host']);
define('DB_PORT', (int) $dbConfig['port']);
define('DB_NAME', (string) $dbConfig['name']);
define('DB_USER', (string) $dbConfig['user']);
define('DB_PASS', (string) $dbConfig['pass']);

/* -------------------------------------------------------------------------
 |  Uploads
 * ---------------------------------------------------------------------- */

const MAX_UPLOAD_MB = 10;

// An order can hold up to 100 photos. The browser may select them all at once;
// they are uploaded in batches of at most 10 so one request stays inside PHP's
// post size and memory limits.
const MAX_PHOTOS_PER_ORDER = 100;
const MAX_PHOTOS_PER_BATCH = 10;

// Stop a single order from growing without bound. 0 disables the cap.
const MAX_ORDER_STORAGE_MB = 5000;

// Printing and status checks work through a slice per request, then ask the
// admin to continue, so a 100-photo order cannot time out one request.
const PRINT_BATCH_MAX = 10;
const PRINT_BATCH_SECONDS = 20;
const STATUS_BATCH_MAX = 25;
const STATUS_BATCH_SECONDS = 20;

// Strict print sequencing. The worker sends one photo, waits for the printer
// to report it finished, then sends the next — so sheets come out in order and
// a second order cannot start until the first is complete.
const PRINT_SEQUENTIAL_SECONDS = 150;
const PRINT_POLL_SECONDS = 8;

// How long the dashboard reuses a printer status before asking Epson again.
const PRINT_STATUS_CACHE_SECONDS = 20;

// Queue monitoring. The watchdog runs separately from the print worker so it
// can still alert when the worker itself has stopped.
// A photo still reported as printing after this long is treated as stuck: the
// worker fails it so the order can be retried instead of waiting for ever.
// The longest normal job (A4 photo, high quality) finishes well inside this.
const PRINT_JOB_STUCK_MINUTES = 45;

const PRINT_QUEUE_STALE_MINUTES = 10;
const PRINT_QUEUE_ORDER_STALL_MINUTES = 20;
const PRINT_QUEUE_WATCHDOG_STALE_MINUTES = 15;
const PRINT_QUEUE_ALERT_COOLDOWN_MINUTES = 60;


/* -------------------------------------------------------------------------
 |  Epson Connect API v2
 |
 |  Credentials are entered in Admin → Settings so they never sit in code.
 |  These are the fixed Epson endpoints from the v2 documentation.
 * ---------------------------------------------------------------------- */

const EPSON_AUTH_URL = 'https://auth.epsonconnect.com';
const EPSON_API_URL  = 'https://api.epsonconnect.com/api/2';
const EPSON_TIMEOUT  = 45;
const EPSON_PRINTER_MODEL = 'Epson ET-2926';

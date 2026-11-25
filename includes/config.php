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
const APP_VERSION = '1.0.5';
const APP_TIMEZONE = 'UTC';

// Set to true only while developing: it prints PHP errors into the page.
const APP_DEBUG = false;

// Leave blank to detect the address automatically. Set it if the site lives
// behind a proxy or you want absolute links, e.g. 'https://print.example.com'.
const APP_URL = '';

/* -------------------------------------------------------------------------
 |  Database
 |
 |  Set DB_HOST, DB_NAME, DB_USERNAME, DB_PORT and DB_PASSWORD in the
 |  environment (Railway, Render, Heroku, Docker, etc.) to use MySQL.
 |  When DB_HOST is missing or the server cannot be reached, the site falls
 |  back to SQLite automatically — no credentials, no setup, a single file.
 |  Tables install themselves either way.
 * ---------------------------------------------------------------------- */

/**
 * Read a database setting from the environment, ignoring blank values.
 */
function db_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value === false) {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
    }

    if (!is_string($value) || trim($value) === '') {
        return $default;
    }

    return trim($value);
}

$dbHost   = db_env('DB_HOST');
$dbDriver = strtolower(db_env('DB_DRIVER', $dbHost !== '' ? 'mysql' : 'sqlite'));
$dbPort   = db_env('DB_PORT', '3306');

// 'mysql' is attempted first when configured; db.php falls back to SQLite if
// it is unreachable.
define('DB_DRIVER', $dbDriver === 'mysql' ? 'mysql' : 'sqlite');

define('DB_HOST', $dbHost);
define('DB_PORT', ctype_digit($dbPort) ? (int) $dbPort : 3306);
define('DB_NAME', db_env('DB_NAME', 'printfree'));
define('DB_USER', db_env('DB_USERNAME', db_env('DB_USER', 'root')));
define('DB_PASS', db_env('DB_PASSWORD', db_env('DB_PASS', '')));

const DB_SQLITE_PATH = __DIR__ . '/../storage/database.sqlite';

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

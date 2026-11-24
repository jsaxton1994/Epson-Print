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

// Epson's printable-file rules allow 10 files per print submission, and each
// photo is uploaded to its own print job.
const MAX_PHOTOS_PER_ORDER = 10;


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

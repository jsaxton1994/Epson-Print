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
const APP_VERSION = '1.0.11';
const APP_TIMEZONE = 'UTC';

// Set to true only while developing: it prints PHP errors into the page.
const APP_DEBUG = false;

// Leave blank to detect the address automatically. Set it if the site lives
// behind a proxy or you want absolute links, e.g. 'https://print.example.com'.
const APP_URL = '';

/* -------------------------------------------------------------------------
 |  Database
 |
 |  Live MySQL database from cPanel. Edit these values directly.
 |  If MySQL cannot be reached, db.php falls back to the local SQLite file
 |  at DB_SQLITE_PATH so the site stays available.
 * ---------------------------------------------------------------------- */

const DB_DRIVER = 'mysql';
const DB_HOST   = 'localhost';
const DB_PORT   = 3306;
const DB_NAME   = 'snapprin_snapprints';
const DB_USER   = 'snapprin_snapprints';
const DB_PASS   = '(O4#K*s?gnK]n6U;';

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

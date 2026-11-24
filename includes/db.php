<?php
/**
 * Database connection and small query helpers.
 *
 * Works with either SQLite (default, zero setup) or MySQL. All queries use
 * prepared statements; dates are written by PHP so both drivers agree.
 */

declare(strict_types=1);

function db(): PDO
{
    return db_connection()['pdo'];
}

/** The driver actually in use — 'sqlite' when MySQL was unreachable. */
function db_driver(): string
{
    return db_connection()['driver'];
}

/** True when MySQL was configured but the site fell back to SQLite. */
function db_using_fallback(): bool
{
    return db_connection()['fallback'];
}

/**
 * Connect once per request. MySQL is tried when configured; if it cannot be
 * reached the request continues on SQLite instead of failing.
 *
 * @return array{pdo: PDO, driver: string, fallback: bool}
 */
function db_connection(): array
{
    static $state = null;

    if ($state !== null) {
        return $state;
    }

    $driver   = DB_DRIVER === 'mysql' ? 'mysql' : 'sqlite';
    $fallback = false;
    $pdo      = null;

    if ($driver === 'mysql') {
        try {
            $pdo = db_connect_mysql();
        } catch (Throwable $e) {
            error_log(sprintf(
                '[PrintFree] MySQL at %s:%d is unavailable (%s). Using SQLite instead.',
                DB_HOST,
                DB_PORT,
                $e->getMessage()
            ));
            $driver   = 'sqlite';
            $fallback = true;
        }
    }

    if (!$pdo instanceof PDO) {
        try {
            $pdo = db_connect_sqlite();
        } catch (Throwable $e) {
            error_log('[PrintFree] database connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('The database is not reachable. Set the DB_* environment variables or check includes/config.php.');
        }
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    // Set the state before installing so the installer can see the real driver.
    $state = ['pdo' => $pdo, 'driver' => $driver, 'fallback' => $fallback];

    ensure_database_ready($pdo, $driver);

    return $state;
}

function db_connect_sqlite(): PDO
{
    $path = DB_SQLITE_PATH;
    $dir  = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the database folder: ' . $dir);
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}

function db_connect_mysql(): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

    try {
        return new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } catch (PDOException $e) {
        // A fresh MySQL server has no database yet — create it once.
        if (!str_contains($e->getMessage(), '1049') && !str_contains($e->getMessage(), 'Unknown database')) {
            throw $e;
        }

        $serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT);
        $server    = new PDO($serverDsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $name = str_replace('`', '', DB_NAME);
        $server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        return new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }
}

/**
 * Create the tables and demo data the first time the site runs.
 */
function ensure_database_ready(PDO $pdo, string $driver): void
{
    $exists = false;
    try {
        if ($driver === 'sqlite') {
            $exists = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
        } else {
            $exists = (bool) $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
        }
    } catch (Throwable $e) {
        $exists = false;
    }

    if (!$exists) {
        require_once __DIR__ . '/installer.php';
        install_database($pdo, $driver);
    }
}

/* -------------------------------------------------------------------------
 |  Query helpers
 * ---------------------------------------------------------------------- */

function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute(array_values($params));

    return $stmt;
}

function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();

    return $row === false ? null : $row;
}

function db_val(string $sql, array $params = [], mixed $default = null): mixed
{
    $value = db_query($sql, $params)->fetchColumn();

    return $value === false ? $default : $value;
}

function db_insert(string $table, array $data): int
{
    $columns = array_keys($data);
    $sql     = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES ('
        . implode(', ', array_fill(0, count($columns), '?')) . ')';

    db_query($sql, array_values($data));

    return (int) db()->lastInsertId();
}

function db_update(string $table, array $data, string $where, array $whereParams = []): int
{
    $set = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($data)));
    $sql = 'UPDATE ' . $table . ' SET ' . $set . ' WHERE ' . $where;

    return db_query($sql, array_merge(array_values($data), array_values($whereParams)))->rowCount();
}

<?php
/**
 * Database connection and small query helpers.
 *
 * MySQL is the only supported driver. When the password file is missing or
 * MySQL cannot be reached, the request stops with a 503 page instead of
 * silently switching to a different database.
 */

declare(strict_types=1);

function db(): PDO
{
    return db_connection()['pdo'];
}

function db_driver(): string
{
    return 'mysql';
}

/**
 * Connect once per request and make sure the MySQL schema exists.
 *
 * @return array{pdo: PDO, driver: string}
 */
function db_connection(): array
{
    static $state = null;

    if ($state !== null) {
        return $state;
    }

    try {
        if (DB_PASS === '') {
            throw new RuntimeException(
                'MySQL password is not configured. Create includes/config.local.php with the database password.'
            );
        }

        $pdo = db_connect_mysql();

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        ensure_database_ready($pdo);
    } catch (Throwable $e) {
        error_log('[PrintFree] MySQL is unavailable: ' . $e->getMessage());
        db_unavailable_response();
    }

    $state = ['pdo' => $pdo, 'driver' => 'mysql'];

    return $state;
}

function db_connect_mysql(): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
}

/**
 * Create the tables and demo data the first time the site runs.
 */
function ensure_database_ready(PDO $pdo): void
{
    try {
        $exists = (bool) $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    } catch (Throwable $e) {
        $exists = false;
    }

    if (!$exists) {
        require_once __DIR__ . '/installer.php';
        install_database($pdo);
    }
}

/**
 * Stop the request with a public 503 page. Details stay in the error log.
 */
function db_unavailable_response(): never
{
    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 300');
        header('Cache-Control: no-store, max-age=0');
        header('Content-Type: text/html; charset=utf-8');
    }

    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Temporarily unavailable — PrintFree</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background: #f5f2ec;
            color: #23201c;
            font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        main {
            max-width: 560px;
            padding: 40px 32px;
            border: 1px solid #ded7cb;
            border-radius: 18px;
            background: #fffdf9;
            box-shadow: 0 12px 40px rgb(35 32 28 / 8%);
            text-align: center;
        }
        .mark {
            width: 52px;
            height: 52px;
            margin: 0 auto 20px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: #f0e7d8;
            font-size: 24px;
        }
        h1 { margin: 0 0 12px; font-size: 1.6rem; line-height: 1.25; }
        p { margin: 0 0 10px; color: #5c554b; }
        .small { font-size: .9rem; }
        @media (prefers-color-scheme: dark) {
            body { background: #151311; color: #f3eee5; }
            main { background: #1d1a17; border-color: #37312a; box-shadow: none; }
            .mark { background: #2b251f; }
            p { color: #b9b0a4; }
        }
    </style>
</head>
<body>
    <main>
        <div class="mark" aria-hidden="true">⏳</div>
        <h1>We’ll be back shortly</h1>
        <p>The print service is temporarily unavailable because its database is not connected.</p>
        <p class="small">Please try again in a few minutes. No orders have been lost.</p>
    </main>
</body>
</html>
    <?php
    exit;
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

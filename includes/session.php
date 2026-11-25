<?php
/**
 * Sessions, CSRF protection, flash messages and authentication guards.
 */

declare(strict_types=1);

/**
 * Keep sessions in the shared database instead of each instance's /tmp.
 * Wasmer (and any multi-instance host) routes requests to different workers,
 * so file-based sessions lose the login and CSRF token on the next request.
 * The `sessions` table lives in the MySQL database and is created on demand,
 * so existing installs pick it up without a migration.
 */
final class DatabaseSessionHandler implements SessionHandlerInterface
{
    private bool $tableReady = false;

    public function open(string $path, string $name): bool
    {
        $this->ensureTable();

        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        if (!$this->validId($id)) {
            return '';
        }

        try {
            $data = db_val('SELECT data FROM sessions WHERE id = ?', [$id]);
            if (!is_string($data) || $data === '') {
                return '';
            }

            $decoded = base64_decode($data, true);

            return $decoded === false ? '' : $decoded;
        } catch (Throwable $e) {
            error_log('[PrintFree] session read failed: ' . $e->getMessage());

            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        if (!$this->validId($id)) {
            return false;
        }

        try {
            $this->ensureTable();
            db_query(
                'INSERT INTO sessions (id, data, last_activity) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE data = VALUES(data), last_activity = VALUES(last_activity)',
                [$id, base64_encode($data), time()]
            );

            return true;
        } catch (Throwable $e) {
            error_log('[PrintFree] session write failed: ' . $e->getMessage());

            return false;
        }
    }

    public function destroy(string $id): bool
    {
        if (!$this->validId($id)) {
            return true;
        }

        try {
            db_query('DELETE FROM sessions WHERE id = ?', [$id]);

            return true;
        } catch (Throwable $e) {
            error_log('[PrintFree] session destroy failed: ' . $e->getMessage());

            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            return db_query('DELETE FROM sessions WHERE last_activity < ?', [time() - $max_lifetime])->rowCount();
        } catch (Throwable $e) {
            error_log('[PrintFree] session gc failed: ' . $e->getMessage());

            return false;
        }
    }

    private function validId(string $id): bool
    {
        return $id !== '' && strlen($id) <= 128;
    }

    private function ensureTable(): void
    {
        if ($this->tableReady) {
            return;
        }

        try {
            db()->exec(
                'CREATE TABLE IF NOT EXISTS sessions (
                    id VARCHAR(128) NOT NULL,
                    data LONGTEXT NOT NULL,
                    last_activity INT UNSIGNED NOT NULL,
                    PRIMARY KEY (id),
                    KEY sessions_activity_idx (last_activity)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            $this->tableReady = true;
        } catch (Throwable $e) {
            error_log('[PrintFree] session table unavailable: ' . $e->getMessage());
        }
    }
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name('printfree_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);

    session_set_save_handler(new DatabaseSessionHandler(), true);
    session_start();

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Reject a POST that is not a same-site form submission.
 */
function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('This form expired. Go back, refresh the page and try again.');
    }
}

function rotate_csrf(): void
{
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* -------------------------------------------------------------------------
 |  Flash messages
 * ---------------------------------------------------------------------- */

function flash(string $type, string $message): void
{
    $_SESSION['flashes'] ??= [];
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

function consume_flashes(): array
{
    $flashes = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);

    return $flashes;
}

/* -------------------------------------------------------------------------
 |  Authentication
 * ---------------------------------------------------------------------- */

function current_user(bool $refresh = false): ?array
{
    static $user = null;

    if ($refresh) {
        $user = null;
    }
    if ($user !== null) {
        return $user;
    }

    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    $row = db_one('SELECT * FROM users WHERE id = ?', [$id]);

    if ($row === null || $row['status'] !== 'active') {
        unset($_SESSION['user_id']);

        return null;
    }

    return $user = $row;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    rotate_csrf();
    unset($_SESSION['login_attempts']);
}

function logout_user(): void
{
    unset($_SESSION['user_id']);
    session_regenerate_id(true);
    rotate_csrf();
}

function login_locked_out(): bool
{
    $attempts = $_SESSION['login_attempts'] ?? null;

    return is_array($attempts) && ($attempts['count'] ?? 0) >= 5 && time() < (int) ($attempts['until'] ?? 0);
}

function record_login_attempt(): void
{
    $attempts = $_SESSION['login_attempts'] ?? ['count' => 0, 'until' => 0];
    $attempts['count'] = (int) $attempts['count'] + 1;

    if ($attempts['count'] >= 5) {
        $attempts['until'] = time() + 600;
    }

    $_SESSION['login_attempts'] = $attempts;
}

/**
 * Verify credentials. Returns the user row or null.
 */
function attempt_login(string $email, string $password): ?array
{
    $user = db_one('SELECT * FROM users WHERE email = ?', [mb_strtolower($email)]);

    // Always run a hash check so a missing account and a wrong password take
    // roughly the same time.
    $hash  = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    $valid = password_verify($password, $hash);

    if (!$user || !$valid) {
        record_login_attempt();

        return null;
    }

    if ($user['status'] !== 'active') {
        return null;
    }

    return $user;
}

function require_login(): array
{
    $user = current_user();

    if ($user === null) {
        flash('error', 'Please sign in to continue.');
        redirect('public/login.php');
    }

    return $user;
}

function require_admin(): array
{
    $user = require_login();

    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('This area is for administrators only.');
    }

    return $user;
}

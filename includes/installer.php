<?php
/**
 * First-run installer: creates the tables, default settings and demo accounts.
 *
 * The schema is embedded here so the project needs no separate schema
 * directory. It only runs when the `users` table is missing. To start over,
 * drop the MySQL tables.
 */

declare(strict_types=1);

function install_database(PDO $pdo): void
{
    $sql = schema_mysql();

    // Strip comment lines first so the statement after a header comment is
    // never skipped, then run each statement.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

    foreach (explode(';', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }

    seed_demo_data($pdo);
}

function schema_mysql(): string
{
    return <<<'SQL'
CREATE TABLE users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(120) NOT NULL,
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    phone         VARCHAR(40)  DEFAULT NULL,
    role          VARCHAR(20)  NOT NULL DEFAULT 'customer',
    status        VARCHAR(20)  NOT NULL DEFAULT 'active',
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference     VARCHAR(20)  NOT NULL,
    user_id       INT UNSIGNED NOT NULL,
    color_mode    VARCHAR(10)  NOT NULL DEFAULT 'color',
    borderless    TINYINT(1)   NOT NULL DEFAULT 1,
    paper_size    VARCHAR(10)  NOT NULL DEFAULT '4x6',
    print_quality VARCHAR(10)  NOT NULL DEFAULT 'high',
    notes         TEXT         DEFAULT NULL,
    admin_note    TEXT         DEFAULT NULL,
    status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
    error_message TEXT         DEFAULT NULL,
    printed_at    DATETIME     DEFAULT NULL,
    accepted_at   DATETIME     DEFAULT NULL,
    created_at    DATETIME     NOT NULL,
    updated_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY orders_reference_unique (reference),
    KEY orders_user_idx (user_id),
    KEY orders_status_idx (status),
    CONSTRAINT orders_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_photos (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id      INT UNSIGNED NOT NULL,
    file_name     VARCHAR(255) NOT NULL,
    mime_type     VARCHAR(30)  NOT NULL DEFAULT 'image/jpeg',
    image_data    MEDIUMBLOB   NOT NULL,
    thumb_data    MEDIUMBLOB   DEFAULT NULL,
    file_size     INT UNSIGNED NOT NULL DEFAULT 0,
    image_width   INT UNSIGNED NOT NULL DEFAULT 0,
    image_height  INT UNSIGNED NOT NULL DEFAULT 0,
    position      INT UNSIGNED NOT NULL DEFAULT 1,
    kind          VARCHAR(20)  NOT NULL DEFAULT 'photo',
    status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
    epson_job_id  VARCHAR(64)  DEFAULT NULL,
    error_message TEXT         DEFAULT NULL,
    printed_at    DATETIME     DEFAULT NULL,
    print_started_at DATETIME  DEFAULT NULL,
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY photos_order_idx (order_id),
    CONSTRAINT photos_order_fk FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_events (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id   INT UNSIGNED NOT NULL,
    status     VARCHAR(20)  NOT NULL,
    message    VARCHAR(255) NOT NULL,
    actor      VARCHAR(120) DEFAULT NULL,
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY events_order_idx (order_id),
    CONSTRAINT events_order_fk FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    setting_key   VARCHAR(64) NOT NULL,
    setting_value TEXT        DEFAULT NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sessions (
    id            VARCHAR(128) NOT NULL,
    data          LONGTEXT     NOT NULL,
    last_activity INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY sessions_activity_idx (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
}

function seed_demo_data(PDO $pdo): void
{
    $now = date('Y-m-d H:i:s');

    $defaults = [
        'site_name'             => APP_NAME,
        'support_email'         => 'hello@printfree.test',
        'queue_alert_email'     => '',
        'accepting_orders'      => '1',
        'default_paper_size'    => '4x6',
        'default_print_quality' => 'high',
        'order_details_sheet_enabled' => '1',
        'epson_client_id'       => '',
        'epson_client_secret'   => '',
        'epson_api_key'         => '',
        'epson_redirect_uri'    => '',
        'epson_refresh_token'   => '',
        'epson_access_token'    => '',
        'epson_token_expires'   => '',
        'epson_device_name'     => '',
        'epson_device_serial'   => '',
        'epson_connected_at'    => '',
    ];

    $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($defaults as $key => $value) {
        $stmt->execute([$key, $value]);
    }

    $userStmt = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, phone, role, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $userStmt->execute([
        'PrintFree Customer',
        'customer@me.com',
        password_hash('123', PASSWORD_DEFAULT),
        '07700 900123',
        'customer',
        'active',
        $now,
    ]);

    $userStmt->execute([
        'PrintFree Admin',
        'admin@me.com',
        password_hash('123', PASSWORD_DEFAULT),
        null,
        'admin',
        'active',
        $now,
    ]);
}

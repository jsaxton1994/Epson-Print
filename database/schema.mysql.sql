-- PrintFree — MySQL / MariaDB schema (installed automatically; kept here for reference)

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
    status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
    epson_job_id  VARCHAR(64)  DEFAULT NULL,
    error_message TEXT         DEFAULT NULL,
    printed_at    DATETIME     DEFAULT NULL,
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

-- PrintFree — SQLite schema (installed automatically; kept here for reference)

CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT    NOT NULL,
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    phone         TEXT    DEFAULT NULL,
    role          TEXT    NOT NULL DEFAULT 'customer',
    status        TEXT    NOT NULL DEFAULT 'active',
    created_at    TEXT    NOT NULL
);

CREATE TABLE orders (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    reference     TEXT    NOT NULL UNIQUE,
    user_id       INTEGER NOT NULL,
    color_mode    TEXT    NOT NULL DEFAULT 'color',
    borderless    INTEGER NOT NULL DEFAULT 1,
    paper_size    TEXT    NOT NULL DEFAULT '4x6',
    print_quality TEXT    NOT NULL DEFAULT 'high',
    notes         TEXT    DEFAULT NULL,
    admin_note    TEXT    DEFAULT NULL,
    status        TEXT    NOT NULL DEFAULT 'pending',
    error_message TEXT    DEFAULT NULL,
    printed_at    TEXT    DEFAULT NULL,
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE INDEX idx_orders_user   ON orders (user_id);
CREATE INDEX idx_orders_status ON orders (status);

CREATE TABLE order_photos (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id      INTEGER NOT NULL,
    file_name     TEXT    NOT NULL,
    mime_type     TEXT    NOT NULL DEFAULT 'image/jpeg',
    image_data    BLOB    NOT NULL,
    thumb_data    BLOB    DEFAULT NULL,
    file_size     INTEGER NOT NULL DEFAULT 0,
    image_width   INTEGER NOT NULL DEFAULT 0,
    image_height  INTEGER NOT NULL DEFAULT 0,
    position      INTEGER NOT NULL DEFAULT 1,
    status        TEXT    NOT NULL DEFAULT 'pending',
    epson_job_id  TEXT    DEFAULT NULL,
    error_message TEXT    DEFAULT NULL,
    printed_at    TEXT    DEFAULT NULL,
    created_at    TEXT    NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
);

CREATE INDEX idx_photos_order ON order_photos (order_id);

CREATE TABLE order_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL,
    status     TEXT    NOT NULL,
    message    TEXT    NOT NULL,
    actor      TEXT    DEFAULT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
);

CREATE INDEX idx_events_order ON order_events (order_id);

CREATE TABLE settings (
    setting_key   TEXT PRIMARY KEY,
    setting_value TEXT DEFAULT NULL
);

-- Sessions live in the database so every app instance shares sign-in state.
CREATE TABLE sessions (
    id            TEXT PRIMARY KEY,
    data          TEXT    NOT NULL,
    last_activity INTEGER NOT NULL
);

CREATE INDEX idx_sessions_activity ON sessions (last_activity);

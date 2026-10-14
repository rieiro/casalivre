PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    cpf TEXT NOT NULL,
    address TEXT NOT NULL,
    cep TEXT NOT NULL DEFAULT '',
    street TEXT NOT NULL DEFAULT '',
    address_number TEXT NOT NULL DEFAULT '',
    address_complement TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    activation_token TEXT,
    activation_link TEXT,
    activation_expires_at TEXT,
    activated_at TEXT,
    status TEXT NOT NULL DEFAULT 'active',
    reset_token TEXT,
    reset_link TEXT,
    reset_expires_at TEXT,
    inactivated_at TEXT,
    inactivated_ip TEXT,
    deleted_at TEXT,
    failed_logins INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    audit_hold TEXT
);

CREATE TABLE IF NOT EXISTS properties (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title TEXT NOT NULL,
    description TEXT NOT NULL,
    summary TEXT NOT NULL,
    city TEXT NOT NULL,
    address TEXT NOT NULL,
    cep TEXT NOT NULL DEFAULT '',
    street TEXT NOT NULL DEFAULT '',
    address_number TEXT NOT NULL DEFAULT '',
    address_complement TEXT NOT NULL DEFAULT '',
    lat REAL NOT NULL,
    lng REAL NOT NULL,
    bedrooms INTEGER NOT NULL,
    beds INTEGER NOT NULL,
    rooms TEXT NOT NULL DEFAULT '[]',
    max_guests INTEGER NOT NULL,
    nightly_price INTEGER NOT NULL,
    checkin_time TEXT NOT NULL DEFAULT '15:00',
    checkout_time TEXT NOT NULL DEFAULT '11:00',
    min_nights INTEGER NOT NULL DEFAULT 1,
    calendar_months INTEGER NOT NULL DEFAULT 6,
    cleaning_fee INTEGER NOT NULL DEFAULT 0,
    pool INTEGER NOT NULL DEFAULT 0,
    bbq INTEGER NOT NULL DEFAULT 0,
    garage INTEGER NOT NULL DEFAULT 0,
    amenities TEXT NOT NULL DEFAULT '{}',
    public_hash TEXT,
    external_ical_url TEXT,
    ical_hash TEXT,
    status TEXT NOT NULL DEFAULT 'active',
    inactivated_at TEXT,
    audit_hold TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE UNIQUE INDEX IF NOT EXISTS properties_ical_hash ON properties(ical_hash);
CREATE UNIQUE INDEX IF NOT EXISTS properties_public_hash ON properties(public_hash);

CREATE TABLE IF NOT EXISTS photos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    property_id INTEGER NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
    path TEXT NOT NULL,
    is_primary INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    audit_hold TEXT
);

CREATE TABLE IF NOT EXISTS calendar_days (
    property_id INTEGER NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
    day TEXT NOT NULL,
    blocked INTEGER NOT NULL DEFAULT 0,
    price INTEGER,
    min_nights INTEGER,
    origin TEXT NOT NULL DEFAULT '',
    audit_hold TEXT,
    PRIMARY KEY (property_id, day)
);

CREATE TABLE IF NOT EXISTS calendar_feeds (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    property_id INTEGER NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
    url TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    last_status TEXT,
    last_synced_at TEXT,
    UNIQUE (property_id, url)
);

CREATE TABLE IF NOT EXISTS reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    property_id INTEGER NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
    author TEXT NOT NULL,
    score INTEGER NOT NULL CHECK (score BETWEEN 1 AND 10),
    comment TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_properties_city ON properties(city);
CREATE INDEX IF NOT EXISTS idx_properties_user ON properties(user_id);
CREATE INDEX IF NOT EXISTS idx_properties_status ON properties(status);
CREATE INDEX IF NOT EXISTS idx_photos_property ON photos(property_id);
CREATE INDEX IF NOT EXISTS idx_photos_cover ON photos(property_id, is_primary, sort_order, id);
CREATE INDEX IF NOT EXISTS idx_reviews_property ON reviews(property_id);

-- Fila de e-mails (src/MailQueue.php; envio pelo scripts/mail-worker.php)
CREATE TABLE IF NOT EXISTS mail_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    recipient TEXT NOT NULL,
    subject TEXT NOT NULL,
    body TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    next_attempt_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_mail_queue_ready ON mail_queue(status, next_attempt_at);

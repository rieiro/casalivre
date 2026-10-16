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
    activation_expires_at TEXT,
    activated_at TEXT,
    status TEXT NOT NULL DEFAULT 'active',
    reset_token TEXT,
    reset_expires_at TEXT,
    inactivated_at TEXT,
    inactivated_ip TEXT,
    deleted_at TEXT,
    failed_logins INTEGER NOT NULL DEFAULT 0,
    session_version INTEGER NOT NULL DEFAULT 0,
    is_admin INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    audit_hold TEXT
);

-- Grupos de imóveis (imobiliária ou rede; Sprint 3, PR 6). slug [a-z0-9-] único; theme só da lista do manifesto.
CREATE TABLE IF NOT EXISTS groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    theme TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS properties (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
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
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    rating_sum INTEGER NOT NULL DEFAULT 0 CHECK (rating_sum >= 0),
    rating_count INTEGER NOT NULL DEFAULT 0 CHECK (rating_count >= 0),
    -- Grupo do imóvel: só muda com o aceite do dono (convite em group_property_invites).
    group_id INTEGER REFERENCES groups(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS properties_ical_hash ON properties(ical_hash);
CREATE UNIQUE INDEX IF NOT EXISTS properties_public_hash ON properties(public_hash);

CREATE TABLE IF NOT EXISTS photos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    property_id INTEGER NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
    path TEXT NOT NULL,
    is_primary INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    audit_hold TEXT,
    -- processing (original fora do public/, aguardando o image-worker) | ready | failed
    status TEXT NOT NULL DEFAULT 'ready',
    -- uuid dos arquivos WebP públicos (/uploads/fotos/<uuid>-<largura>.webp)
    uuid TEXT,
    -- nome do original em storage/fotos/originais (apagado quando fica pronta ou falha)
    original TEXT
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

CREATE INDEX IF NOT EXISTS idx_users_activation_token ON users(activation_token) WHERE activation_token IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_users_reset_token ON users(reset_token) WHERE reset_token IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_properties_city ON properties(city);
CREATE INDEX IF NOT EXISTS idx_properties_user ON properties(user_id);
CREATE INDEX IF NOT EXISTS idx_properties_status ON properties(status);
CREATE INDEX IF NOT EXISTS idx_properties_group ON properties(group_id);
CREATE INDEX IF NOT EXISTS idx_photos_cover ON photos(property_id, is_primary, sort_order, id);
CREATE INDEX IF NOT EXISTS idx_reviews_property ON reviews(property_id);

-- Papéis no grupo: gestor (convida/remove imóveis) ou membro (só vê). Membros e domínios só pelo admin.
CREATE TABLE IF NOT EXISTS group_members (
    group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role TEXT NOT NULL CHECK (role IN ('gestor', 'membro')),
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (group_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_group_members_user ON group_members(user_id);

-- Lista fixa de domínios por grupo (minúsculas, sem porta); um domínio pertence a um só grupo.
-- Posse provada por DNS TXT (_casalivre.<domínio> = casalivre-verificacao=<verify_token>); o resolvedor só usa verified_at preenchido.
-- Reverificação pelo cron (scripts/verify-domains.php): last_check_at (NULL = checagem pedida) e fail_count (TXT ausente seguidas).
CREATE TABLE IF NOT EXISTS group_domains (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    domain TEXT NOT NULL UNIQUE,
    verify_token TEXT NOT NULL DEFAULT '',
    verified_at TEXT,
    last_check_at TEXT,
    fail_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_group_domains_group ON group_domains(group_id);

-- Convite do gestor para vincular um imóvel ao grupo; o vínculo (properties.group_id) só nasce no aceite do dono.
CREATE TABLE IF NOT EXISTS group_property_invites (
    group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    property_id INTEGER NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
    invited_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (group_id, property_id)
);
CREATE INDEX IF NOT EXISTS idx_group_invites_property ON group_property_invites(property_id);

-- Fila de e-mails (src/MailQueue.php; envio pelo scripts/mail-worker.php)
CREATE TABLE IF NOT EXISTS mail_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    recipient TEXT NOT NULL,
    cooldown_key TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NOT NULL DEFAULT '',
    next_attempt_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_mail_queue_ready ON mail_queue(status, next_attempt_at);
CREATE INDEX IF NOT EXISTS idx_mail_queue_cooldown ON mail_queue(cooldown_key, created_at);

-- Fila de imagens (src/ImageQueue.php; processamento pelo scripts/image-worker.php)
CREATE TABLE IF NOT EXISTS image_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    photo_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NOT NULL DEFAULT '',
    next_attempt_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_image_queue_ready ON image_queue(status, next_attempt_at);
CREATE INDEX IF NOT EXISTS idx_photos_status ON photos(property_id, status);

-- Busca (ADR 001)
CREATE INDEX IF NOT EXISTS idx_prop_status_city ON properties(status, city);
CREATE INDEX IF NOT EXISTS idx_prop_latlng ON properties(lat, lng);

-- Antes só via migrate() (src/Db.php)
CREATE TABLE IF NOT EXISTS settings (skey TEXT PRIMARY KEY, value TEXT NOT NULL, description TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS languages (code TEXT PRIMARY KEY, label TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS translations (lang TEXT NOT NULL, tkey TEXT NOT NULL, text TEXT NOT NULL, PRIMARY KEY (lang, tkey));
CREATE TABLE IF NOT EXISTS cities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL, uf TEXT NOT NULL, label TEXT NOT NULL,
    lat REAL NOT NULL, lng REAL NOT NULL,
    name_fold TEXT NOT NULL, label_fold TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_cities_name_fold ON cities(name_fold);
CREATE INDEX IF NOT EXISTS idx_cities_label_fold ON cities(label_fold);
CREATE TABLE IF NOT EXISTS activity_actions (code INTEGER PRIMARY KEY, description TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS login_rate_limit (ip TEXT PRIMARY KEY, failures INTEGER NOT NULL DEFAULT 0, locked_until TEXT);
CREATE TABLE IF NOT EXISTS ceps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cep TEXT NOT NULL UNIQUE,
    street TEXT NOT NULL DEFAULT '', neighborhood TEXT NOT NULL DEFAULT '',
    city TEXT NOT NULL DEFAULT '', uf TEXT NOT NULL DEFAULT ''
);

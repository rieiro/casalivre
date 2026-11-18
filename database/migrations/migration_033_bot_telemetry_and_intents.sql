-- Migration 033: Bot action logs, booking intents, and schema 33
BEGIN;

CREATE TABLE IF NOT EXISTS bot_action_logs (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    created_at    timestamptz NOT NULL DEFAULT statement_timestamp(),
    actor_type    text        NOT NULL CHECK (actor_type = 'bot'),
    bot_name      text        NOT NULL CHECK (length(bot_name) BETWEEN 1 AND 64),
    channel       text        NOT NULL CHECK (channel IN ('html', 'markdown', 'rest', 'mcp')),
    action_name   text        NOT NULL CHECK (length(action_name) BETWEEN 1 AND 64),
    property_id   integer     NULL,
    search_params jsonb       NULL CHECK (search_params IS NULL OR jsonb_typeof(search_params) = 'object'),
    status_code   smallint    NOT NULL,
    latency_ms    integer     NOT NULL CHECK (latency_ms >= 0 AND latency_ms <= 600000),
    ip_hash       bytea       NULL CHECK (ip_hash IS NULL OR octet_length(ip_hash) = 32)
);

CREATE INDEX IF NOT EXISTS bot_action_logs_created ON bot_action_logs (created_at);

CREATE TABLE IF NOT EXISTS booking_intents (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    token            text        NOT NULL UNIQUE CHECK (token ~ '^[a-f0-9]{32}$'),
    property_id      integer     NOT NULL,
    checkin          date        NOT NULL,
    checkout         date        NOT NULL,
    guests           smallint    NOT NULL CHECK (guests BETWEEN 1 AND 100),
    nights           integer     NOT NULL CHECK (nights >= 1),
    nightly_price    integer     NOT NULL CHECK (nightly_price >= 0),
    cleaning_fee     integer     NOT NULL CHECK (cleaning_fee >= 0),
    total_price      integer     NOT NULL CHECK (total_price >= 0),
    plan             text        NOT NULL CHECK (plan IN ('pix_a_vista', 'sinal_50', 'cartao_12x')),
    amount_due_now   integer     NOT NULL CHECK (amount_due_now >= 0),
    balance_due      integer     NOT NULL CHECK (balance_due >= 0),
    installments     smallint    NOT NULL CHECK (installments IN (1, 12)),
    status           text        NOT NULL DEFAULT 'held' CHECK (status IN ('held', 'expired', 'cancelled')),
    expires_at       timestamptz NOT NULL,
    created_at       timestamptz NOT NULL DEFAULT statement_timestamp(),
    CHECK (checkout > checkin),
    CHECK (amount_due_now + balance_due = total_price)
);

CREATE INDEX IF NOT EXISTS booking_intents_open
    ON booking_intents (property_id, expires_at)
    WHERE status = 'held';

INSERT INTO schema_meta (skey, value)
VALUES ('schema_version', '33')
ON CONFLICT (skey) DO UPDATE SET value = '33';

COMMIT;

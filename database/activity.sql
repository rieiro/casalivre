CREATE TABLE IF NOT EXISTS activity_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    action_code INTEGER NOT NULL,
    action TEXT NOT NULL,
    actor_id INTEGER,
    ip TEXT,
    user_agent TEXT,
    params TEXT NOT NULL,
    valor_anterior TEXT,
    valor_atual TEXT
);

CREATE INDEX IF NOT EXISTS idx_activity_code ON activity_log(action_code);
CREATE INDEX IF NOT EXISTS idx_activity_actor ON activity_log(actor_id);
CREATE INDEX IF NOT EXISTS idx_activity_created ON activity_log(created_at);

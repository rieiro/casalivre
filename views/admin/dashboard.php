<?php
/**
 * @var array<string, mixed> $kpis
 * @var array<string, mixed> $env
 * @var array<string, bool> $services
 * @var list<array<string, mixed>> $recent_activity
 * @var array<string, mixed> $settings
 * @var array<string, mixed> $user
 * @var string $csrf
 */
?>

<?php if (!empty($settings['maintenance_mode'])): ?>
    <div class="admin-alert-banner admin-alert-warn">
        <span class="admin-kpi-icon">⚠️</span>
        <div>
            <strong>MAINTENANCE MODE IS CURRENTLY ACTIVE:</strong>
            <span>Public marketplace traffic is suspended. Visitors receive an unavailability notice while you work.</span>
        </div>
    </div>
<?php endif; ?>

<!-- SERVICES & INFRASTRUCTURE STATUS -->
<div class="admin-card">
    <div class="admin-card-title">
        <span>⚡ Services & Infrastructure Status</span>
        <span class="admin-card-tag">Void Linux (musl) &bull; Runit Supervision</span>
    </div>

    <!-- Daemon Status Pills -->
    <div class="admin-daemon-list">
        <?php foreach ($services as $name => $running): ?>
            <div class="admin-daemon-badge <?= $running ? 'admin-daemon-online' : 'admin-daemon-stopped' ?>">
                <span class="admin-dot <?= $running ? 'admin-dot-ok' : 'admin-dot-danger' ?>"></span>
                <span class="admin-daemon-name"><?= e($name) ?></span>
                <span class="<?= $running ? 'admin-daemon-state-online' : 'admin-daemon-state-stopped' ?>">
                    <?= $running ? 'ONLINE' : 'STOPPED' ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- System Diagnostic Summary -->
    <div class="admin-sysinfo-grid">
        <div class="admin-sysinfo-item">
            <span class="admin-kpi-icon">🐘</span>
            <div>
                <div class="admin-sysinfo-label">PHP <?= e($env['php_version'] ?? PHP_VERSION) ?></div>
                <div class="admin-sysinfo-val">OPcache: <?= !empty($env['opcache_enabled']) ? 'Active' : 'Disabled' ?></div>
            </div>
        </div>

        <div class="admin-sysinfo-item">
            <span class="admin-kpi-icon">🐘</span>
            <div>
                <div class="admin-sysinfo-label">PostgreSQL <?= e($env['postgres_version'] ?? '18') ?></div>
                <div class="admin-sysinfo-val">Engine: Connected (Pool)</div>
            </div>
        </div>

        <div class="admin-sysinfo-item">
            <span class="admin-kpi-icon">🖼️</span>
            <div>
                <div class="admin-sysinfo-label">WebP Media Engine</div>
                <div class="admin-sysinfo-val">Libwebp: GD / WebP Ready</div>
            </div>
        </div>

        <div class="admin-sysinfo-item">
            <span class="admin-kpi-icon">💾</span>
            <div class="admin-sysinfo-flex">
                <div class="admin-sysinfo-label">Disk Storage: <?= e((string)($env['disk_free_gb'] ?? 0)) ?> GB free</div>
                <div class="admin-progress-track">
                    <div class="admin-progress-bar"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- KPI AT A GLANCE (Osclass inspired metrics) -->
<div class="admin-kpi-grid">
    <!-- PROPERTIES -->
    <div class="admin-kpi-card">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Registered Properties</span>
            <span class="admin-kpi-icon">🏠</span>
        </div>
        <span class="admin-kpi-value"><?= e((string)($kpis['properties']['total'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <span class="admin-pill admin-pill-ok admin-pill-sm">
                <?= e((string)($kpis['properties']['active'] ?? 0)) ?> active
            </span>
            <span>&bull; <?= e((string)($kpis['properties']['inactive'] ?? 0)) ?> inactive</span>
        </span>
    </div>

    <!-- USERS -->
    <div class="admin-kpi-card">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Users & Accounts</span>
            <span class="admin-kpi-icon">👥</span>
        </div>
        <span class="admin-kpi-value"><?= e((string)($kpis['users']['total'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <span class="admin-pill admin-pill-neutral admin-pill-sm">
                <?= e((string)($kpis['users']['admins'] ?? 0)) ?> admins
            </span>
            <?php if (($kpis['users']['locked'] ?? 0) > 0): ?>
                <span class="admin-pill admin-pill-danger admin-pill-sm">
                    <?= e((string)($kpis['users']['locked'] ?? 0)) ?> locked
                </span>
            <?php else: ?>
                <span>&bull; 0 locked</span>
            <?php endif; ?>
        </span>
    </div>

    <!-- CALENDARS -->
    <div class="admin-kpi-card">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">iCal Calendar Feeds</span>
            <span class="admin-kpi-icon">📅</span>
        </div>
        <span class="admin-kpi-value"><?= e((string)($kpis['feeds']['total'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <span class="admin-pill admin-pill-ok admin-pill-sm">
                <?= e((string)($kpis['feeds']['ok'] ?? 0)) ?> synced
            </span>
            <?php if (($kpis['feeds']['errors'] ?? 0) > 0): ?>
                <span class="admin-pill admin-pill-danger admin-pill-sm">
                    <?= e((string)($kpis['feeds']['errors'] ?? 0)) ?> err
                </span>
            <?php endif; ?>
        </span>
    </div>

    <!-- IMAGE QUEUE -->
    <div class="admin-kpi-card">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Image Queue</span>
            <span class="admin-kpi-icon">🖼️</span>
        </div>
        <span class="admin-kpi-value"><?= e((string)($kpis['queues']['image_queue']['pending'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <span>WebP tasks &bull;</span>
            <?php if (($kpis['queues']['image_queue']['failed'] ?? 0) > 0): ?>
                <span class="admin-pill admin-pill-danger admin-pill-sm">
                    <?= e((string)($kpis['queues']['image_queue']['failed'] ?? 0)) ?> failed
                </span>
            <?php else: ?>
                <span class="admin-pill admin-pill-ok admin-pill-sm">0 failed</span>
            <?php endif; ?>
        </span>
    </div>

    <!-- EMAIL QUEUE -->
    <div class="admin-kpi-card">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Email Queue</span>
            <span class="admin-kpi-icon">✉️</span>
        </div>
        <span class="admin-kpi-value"><?= e((string)($kpis['queues']['mail_queue']['pending'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <span>SMTP queue &bull;</span>
            <?php if (($kpis['queues']['mail_queue']['failed'] ?? 0) > 0): ?>
                <span class="admin-pill admin-pill-danger admin-pill-sm">
                    <?= e((string)($kpis['queues']['mail_queue']['failed'] ?? 0)) ?> failed
                </span>
            <?php else: ?>
                <span class="admin-pill admin-pill-ok admin-pill-sm">0 failed</span>
            <?php endif; ?>
        </span>
    </div>

    <!-- QUOTES & RESERVATIONS -->
    <div class="admin-kpi-card">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Quotes & Reservations</span>
            <span class="admin-kpi-icon">🏷️</span>
        </div>
        <span class="admin-kpi-value"><?= e((string)($kpis['quotes']['total'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <span class="admin-pill admin-pill-ok admin-pill-sm">
                <?= e((string)($kpis['quotes']['confirmed'] ?? 0)) ?> confirmed
            </span>
            <span>&bull; <?= e((string)($kpis['quotes']['pending'] ?? 0)) ?> hold</span>
        </span>
    </div>
</div>

<!-- 2-COLUMN SPLIT: RECENT ACTIVITY & ADMIN TOOLS -->
<div class="admin-grid-split">
    <!-- LEFT: RECENT ACTIVITY AUDIT STREAM -->
    <div>
        <div class="admin-card">
            <div class="admin-card-title">
                <span>📜 Recent System Activity</span>
                <a href="/admin/auditoria" class="admin-btn admin-btn-secondary admin-btn-sm">
                    View Complete Audit Trail &rarr;
                </a>
            </div>

            <?php if (empty($recent_activity)): ?>
                <div class="admin-empty-state">
                    <div class="admin-empty-icon">📭</div>
                    <div class="admin-empty-desc">No recent system activity recorded in this window.</div>
                </div>
            <?php else: ?>
                <div class="admin-table-container">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Time (UTC)</th>
                                <th>Action Event</th>
                                <th>Class</th>
                                <th>Details / Payload</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_activity as $act): ?>
                                <tr>
                                    <td>
                                        <div class="admin-sysinfo-val"><?= e(substr((string)($act['created_at'] ?? ''), 11, 8)) ?></div>
                                        <div class="admin-hint"><?= e(substr((string)($act['created_at'] ?? ''), 0, 10)) ?></div>
                                    </td>
                                    <td>
                                        <div class="admin-label">
                                            <?= e($act['action_label'] ?? ('#' . ($act['code'] ?? ''))) ?>
                                        </div>
                                        <div class="admin-hint">Event #<?= e((string)($act['code'] ?? '')) ?></div>
                                    </td>
                                    <td>
                                        <?php
                                            $rc = (string)($act['retention_class'] ?? 'other');
                                            $pillClass = match($rc) {
                                                'security' => 'admin-pill-danger',
                                                'admin' => 'admin-pill-warn',
                                                default => 'admin-pill-neutral'
                                            };
                                        ?>
                                        <span class="admin-pill <?= $pillClass ?> admin-pill-sm">
                                            <?= e($rc) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                            $pl = $act['payload'] ?? '';
                                            if (is_string($pl) && $pl !== '') {
                                                $decoded = json_decode($pl, true);
                                                if (is_array($decoded)) {
                                                    foreach ($decoded as $k => $v) {
                                                        if (is_scalar($v) && $v !== '') {
                                                            echo '<span class="admin-code">' . e($k . ': ' . $v) . '</span> ';
                                                        }
                                                    }
                                                } else {
                                                    echo '<span class="admin-code">' . e(substr($pl, 0, 70)) . '</span>';
                                                }
                                            } else {
                                                echo '<span class="admin-hint">-</span>';
                                            }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT: GENERAL ADMIN NOTES & QUICK ACTIONS (Osclass Omega Widget style) -->
    <div>
        <!-- GENERAL NOTES WIDGET -->
        <div class="admin-card">
            <div class="admin-card-title">
                <span>📝 General Admin Notes</span>
                <span class="admin-card-tag">Shared Board</span>
            </div>
            <form method="post" action="/admin/notas">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p class="admin-hint">
                    Persistent notes, deployment logs and maintenance reminders visible to all platform administrators.
                </p>
                <textarea name="admin_notes_general" rows="7" class="admin-textarea" placeholder="Write reminders, deployment details, or operational instructions here..."><?= e((string)($settings['admin_notes_general'] ?? '')) ?></textarea>
                <button type="submit" class="admin-btn admin-btn-primary admin-btn-block">
                    <span>💾</span>
                    <span>Save Notes</span>
                </button>
            </form>
        </div>

        <!-- QUICK ACTIONS PANEL -->
        <div class="admin-card">
            <div class="admin-card-title">
                <span>⚡ Quick Management</span>
            </div>
            <div class="admin-quick-action-list">
                <a href="/admin/configuracoes" class="admin-btn admin-btn-secondary admin-quick-action-link">
                    <span>⚙️ Parameters & Limits</span>
                    <span class="admin-quick-action-arrow">&rarr;</span>
                </a>
                <a href="/admin/seguranca" class="admin-btn admin-btn-secondary admin-quick-action-link">
                    <span>🛡️ Security & IP Control</span>
                    <span class="admin-quick-action-arrow">&rarr;</span>
                </a>
                <a href="/admin/filas" class="admin-btn admin-btn-secondary admin-quick-action-link">
                    <span>⚡ Queue & Daemons</span>
                    <span class="admin-quick-action-arrow">&rarr;</span>
                </a>
                <a href="/admin/auditoria" class="admin-btn admin-btn-secondary admin-quick-action-link">
                    <span>📜 Audit Trail Records</span>
                    <span class="admin-quick-action-arrow">&rarr;</span>
                </a>
                <a href="/admin/traducoes" class="admin-btn admin-btn-secondary admin-quick-action-link">
                    <span>🌐 Translation Matrix</span>
                    <span class="admin-quick-action-arrow">&rarr;</span>
                </a>
                <a href="/admin/grupos" class="admin-btn admin-btn-secondary admin-quick-action-link">
                    <span>🏢 Groups & Networks</span>
                    <span class="admin-quick-action-arrow">&rarr;</span>
                </a>
                <a href="/admin/tema" class="admin-btn admin-btn-secondary admin-quick-action-link">
                    <span>🎨 Visual Theme Customizer</span>
                    <span class="admin-quick-action-arrow">&rarr;</span>
                </a>
            </div>
        </div>
    </div>
</div>

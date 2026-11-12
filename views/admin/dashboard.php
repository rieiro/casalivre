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
    <div class="admin-alert-banner admin-alert-warn" style="margin-bottom: 1.5rem;">
        <span style="font-size: 1.25rem;">⚠️</span>
        <div>
            <strong>MAINTENANCE MODE IS CURRENTLY ACTIVE:</strong>
            <span style="color: #b45309;">Public marketplace traffic is suspended. Visitors receive an unavailability notice while you work.</span>
        </div>
    </div>
<?php endif; ?>

<!-- SERVICES & INFRASTRUCTURE STATUS -->
<div class="admin-card" style="margin-bottom: 1.5rem;">
    <div class="admin-card-title">
        <div style="display: flex; align-items: center; gap: 0.6rem;">
            <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 6px; background: #eff6ff; color: #2563eb; font-size: 0.95rem;">⚡</span>
            <span>Services & Infrastructure Status</span>
        </div>
        <span style="font-size: 0.8rem; font-weight: 500; color: var(--admin-text-muted); background: #f8fafc; padding: 0.25rem 0.65rem; border-radius: 9999px; border: 1px solid var(--admin-border);">
            Void Linux (musl) &bull; Runit Supervision
        </span>
    </div>

    <!-- Daemon Status Pills -->
    <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; margin-bottom: 1.25rem;">
        <?php foreach ($services as $name => $running): ?>
            <div style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.45rem 0.85rem; background: #ffffff; border: 1px solid <?= $running ? '#bbf7d0' : '#fecaca' ?>; border-radius: var(--admin-radius-sm); font-size: 0.85rem; box-shadow: var(--admin-shadow-sm);">
                <span class="admin-dot <?= $running ? 'admin-dot-ok' : 'admin-dot-danger' ?>"></span>
                <span style="font-weight: 600; font-family: ui-monospace, monospace; color: #1e293b;"><?= e($name) ?></span>
                <span style="font-size: 0.72rem; font-weight: 700; padding: 0.1rem 0.4rem; border-radius: 4px; background: <?= $running ? '#dcfce7' : '#fee2e2' ?>; color: <?= $running ? '#166534' : '#991b1b' ?>;">
                    <?= $running ? 'ONLINE' : 'STOPPED' ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- System Diagnostic Summary -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; font-size: 0.825rem; color: var(--admin-text-muted); border-top: 1px solid var(--admin-border-subtle); padding-top: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <span style="font-size: 1rem;">🐘</span>
            <div>
                <div style="font-weight: 600; color: var(--admin-text-main);">PHP <?= e($env['php_version'] ?? PHP_VERSION) ?></div>
                <div style="font-size: 0.75rem;">OPcache: <strong style="color: <?= !empty($env['opcache_enabled']) ? '#16a34a' : '#d97706' ?>;"><?= !empty($env['opcache_enabled']) ? 'Active' : 'Disabled' ?></strong></div>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <span style="font-size: 1rem;">🐘</span>
            <div>
                <div style="font-weight: 600; color: var(--admin-text-main);">PostgreSQL <?= e($env['postgres_version'] ?? '18') ?></div>
                <div style="font-size: 0.75rem;">Engine: <strong style="color: #16a34a;">Connected (Pool)</strong></div>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <span style="font-size: 1rem;">🖼️</span>
            <div>
                <div style="font-weight: 600; color: var(--admin-text-main);">WebP Media Engine</div>
                <div style="font-size: 0.75rem;">Libwebp: <strong style="color: #16a34a;">GD / WebP Ready</strong></div>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <span style="font-size: 1rem;">💾</span>
            <div style="flex-grow: 1;">
                <div style="font-weight: 600; color: var(--admin-text-main); display: flex; justify-content: space-between;">
                    <span>Storage Volume</span>
                    <span style="font-weight: 700;"><?= e((string)($env['disk_free_gb'] ?? 0)) ?> GB free</span>
                </div>
                <div style="background: #e2e8f0; border-radius: 9999px; height: 6px; width: 100%; margin-top: 0.25rem; overflow: hidden;">
                    <div style="background: linear-gradient(90deg, #3b82f6, #2563eb); height: 100%; width: <?= max(5, min(100, (int)($env['disk_used_percent'] ?? 0))) ?>%;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- KPI AT A GLANCE (Osclass inspired metrics) -->
<div class="admin-kpi-grid">
    <!-- PROPERTIES -->
    <div class="admin-kpi-card" style="--accent: #2563eb;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <span class="admin-kpi-label">Registered Properties</span>
            <span style="font-size: 1.25rem;">🏠</span>
        </div>
        <span class="admin-kpi-value"><?= e((string)($kpis['properties']['total'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <span class="admin-pill admin-pill-ok" style="padding: 0.15rem 0.45rem; font-size: 0.7rem;">
                <?= e((string)($kpis['properties']['active'] ?? 0)) ?> active
            </span>
            <span style="color: var(--admin-text-muted);">&bull; <?= e((string)($kpis['properties']['inactive'] ?? 0)) ?> inactive</span>
        </span>
    </div>

    <!-- USERS -->
    <div class="admin-kpi-card" style="--accent: #6366f1;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <span class="admin-kpi-label">Users & Accounts</span>
            <span style="font-size: 1.25rem;">👥</span>
        </div>
        <span class="admin-kpi-value"><?= e((string)($kpis['users']['total'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <span class="admin-pill admin-pill-neutral" style="padding: 0.15rem 0.45rem; font-size: 0.7rem;">
                <?= e((string)($kpis['users']['admins'] ?? 0)) ?> admins
            </span>
            <?php if (($kpis['users']['locked'] ?? 0) > 0): ?>
                <span class="admin-pill admin-pill-danger" style="padding: 0.15rem 0.45rem; font-size: 0.7rem;">
                    <?= e((string)($kpis['users']['locked'] ?? 0)) ?> locked
                </span>
            <?php else: ?>
                <span style="color: var(--admin-text-muted);">&bull; 0 locked</span>
            <?php endif; ?>
        </span>
    </div>

    <!-- CALENDARS -->
    <div class="admin-kpi-card" style="--accent: #f59e0b;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <span class="admin-kpi-label">iCal Calendar Feeds</span>
            <span style="font-size: 1.25rem;">📅</span>
        </div>
        <span class="admin-kpi-value"><?= e((string)($kpis['feeds']['total'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <span class="admin-pill admin-pill-ok" style="padding: 0.15rem 0.45rem; font-size: 0.7rem;">
                <?= e((string)($kpis['feeds']['ok'] ?? 0)) ?> synced
            </span>
            <?php if (($kpis['feeds']['errors'] ?? 0) > 0): ?>
                <span class="admin-pill admin-pill-danger" style="padding: 0.15rem 0.45rem; font-size: 0.7rem;">
                    <?= e((string)($kpis['feeds']['errors'] ?? 0)) ?> err
                </span>
            <?php endif; ?>
        </span>
    </div>

    <!-- IMAGE QUEUE -->
    <div class="admin-kpi-card" style="--accent: #8b5cf6;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <span class="admin-kpi-label">Image Queue</span>
            <span style="font-size: 1.25rem;">🖼️</span>
        </div>
        <span class="admin-kpi-value"><?= e((string)($kpis['queues']['image_queue']['pending'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <span style="color: var(--admin-text-muted);">WebP tasks &bull;</span>
            <?php if (($kpis['queues']['image_queue']['failed'] ?? 0) > 0): ?>
                <span class="admin-pill admin-pill-danger" style="padding: 0.15rem 0.45rem; font-size: 0.7rem;">
                    <?= e((string)($kpis['queues']['image_queue']['failed'] ?? 0)) ?> failed
                </span>
            <?php else: ?>
                <span class="admin-pill admin-pill-ok" style="padding: 0.15rem 0.45rem; font-size: 0.7rem;">0 failed</span>
            <?php endif; ?>
        </span>
    </div>

    <!-- EMAIL QUEUE -->
    <div class="admin-kpi-card" style="--accent: #10b981;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <span class="admin-kpi-label">Email Queue</span>
            <span style="font-size: 1.25rem;">✉️</span>
        </div>
        <span class="admin-kpi-value"><?= e((string)($kpis['queues']['mail_queue']['pending'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <span style="color: var(--admin-text-muted);">SMTP queue &bull;</span>
            <?php if (($kpis['queues']['mail_queue']['failed'] ?? 0) > 0): ?>
                <span class="admin-pill admin-pill-danger" style="padding: 0.15rem 0.45rem; font-size: 0.7rem;">
                    <?= e((string)($kpis['queues']['mail_queue']['failed'] ?? 0)) ?> failed
                </span>
            <?php else: ?>
                <span class="admin-pill admin-pill-ok" style="padding: 0.15rem 0.45rem; font-size: 0.7rem;">0 failed</span>
            <?php endif; ?>
        </span>
    </div>

    <!-- QUOTES & RESERVATIONS -->
    <div class="admin-kpi-card" style="--accent: #ec4899;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <span class="admin-kpi-label">Quotes & Reservations</span>
            <span style="font-size: 1.25rem;">🏷️</span>
        </div>
        <span class="admin-kpi-value"><?= e((string)($kpis['quotes']['total'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <span class="admin-pill admin-pill-ok" style="padding: 0.15rem 0.45rem; font-size: 0.7rem;">
                <?= e((string)($kpis['quotes']['confirmed'] ?? 0)) ?> confirmed
            </span>
            <span style="color: var(--admin-text-muted);">&bull; <?= e((string)($kpis['quotes']['pending'] ?? 0)) ?> hold</span>
        </span>
    </div>
</div>

<!-- 2-COLUMN SPLIT: RECENT ACTIVITY & ADMIN TOOLS -->
<div class="admin-grid-split">
    <!-- LEFT: RECENT ACTIVITY AUDIT STREAM -->
    <div>
        <div class="admin-card">
            <div class="admin-card-title">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span>📜</span>
                    <span>Recent System Activity</span>
                </div>
                <a href="/admin/auditoria" class="admin-btn admin-btn-secondary admin-btn-sm" style="text-decoration: none;">
                    View Complete Audit Trail &rarr;
                </a>
            </div>

            <?php if (empty($recent_activity)): ?>
                <div style="text-align: center; padding: 2.5rem 1rem; color: var(--admin-text-muted);">
                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">📭</div>
                    <div>No recent system activity recorded in this window.</div>
                </div>
            <?php else: ?>
                <div class="admin-table-container">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th style="width: 130px;">Time (UTC)</th>
                                <th>Action Event</th>
                                <th style="width: 90px;">Class</th>
                                <th>Details / Payload</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_activity as $act): ?>
                                <tr>
                                    <td style="white-space: nowrap; font-size: 0.78rem; color: var(--admin-text-muted); font-family: ui-monospace, monospace;">
                                        <?= e(substr((string)($act['created_at'] ?? ''), 11, 8)) ?>
                                        <div style="font-size: 0.7rem; color: #94a3b8;"><?= e(substr((string)($act['created_at'] ?? ''), 0, 10)) ?></div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: #0f172a; font-size: 0.85rem;">
                                            <?= e($act['action_label'] ?? ('#' . ($act['code'] ?? ''))) ?>
                                        </div>
                                        <div style="font-size: 0.72rem; color: var(--admin-text-muted);">Event #<?= e((string)($act['code'] ?? '')) ?></div>
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
                                        <span class="admin-pill <?= $pillClass ?>" style="font-size: 0.7rem; padding: 0.15rem 0.45rem;">
                                            <?= e($rc) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                            $pl = $act['payload'] ?? '';
                                            if (is_string($pl) && $pl !== '') {
                                                $decoded = json_decode($pl, true);
                                                if (is_array($decoded)) {
                                                    echo '<div style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">';
                                                    foreach ($decoded as $k => $v) {
                                                        if (is_scalar($v) && $v !== '') {
                                                            echo '<span class="admin-code" style="margin-right: 0.2rem; font-size: 0.75rem;">' . e($k . ': ' . $v) . '</span> ';
                                                        }
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="admin-code" style="font-size: 0.75rem;">' . e(substr($pl, 0, 70)) . '</span>';
                                                }
                                            } else {
                                                echo '<span style="color: var(--admin-text-muted); font-size: 0.8rem;">-</span>';
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
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span>📝</span>
                    <span>General Admin Notes</span>
                </div>
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Shared Board</span>
            </div>
            <form method="post" action="/admin/notas">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p style="font-size: 0.8rem; color: var(--admin-text-muted); margin-top: 0; margin-bottom: 0.65rem;">
                    Persistent notes, deployment logs and maintenance reminders visible to all platform administrators.
                </p>
                <textarea name="admin_notes_general" rows="7" class="admin-textarea" placeholder="Write reminders, deployment details, or operational instructions here..." style="resize: vertical; font-size: 0.85rem; line-height: 1.5; margin-bottom: 0.75rem; font-family: inherit;"><?= e((string)($settings['admin_notes_general'] ?? '')) ?></textarea>
                <button type="submit" class="admin-btn admin-btn-primary" style="width: 100%; justify-content: center;">
                    <span>💾</span>
                    <span>Save Notes</span>
                </button>
            </form>
        </div>

        <!-- QUICK ACTIONS PANEL -->
        <div class="admin-card">
            <div class="admin-card-title">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span>⚡</span>
                    <span>Quick Management</span>
                </div>
            </div>
            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                <a href="/admin/configuracoes" class="admin-btn admin-btn-secondary" style="justify-content: space-between;">
                    <span style="display: flex; align-items: center; gap: 0.6rem;">
                        <span>⚙️</span>
                        <span>Parameters & Limits</span>
                    </span>
                    <span style="color: var(--admin-text-muted); font-size: 0.75rem;">&rarr;</span>
                </a>
                <a href="/admin/seguranca" class="admin-btn admin-btn-secondary" style="justify-content: space-between;">
                    <span style="display: flex; align-items: center; gap: 0.6rem;">
                        <span>🛡️</span>
                        <span>Security & IP Control</span>
                    </span>
                    <span style="color: var(--admin-text-muted); font-size: 0.75rem;">&rarr;</span>
                </a>
                <a href="/admin/filas" class="admin-btn admin-btn-secondary" style="justify-content: space-between;">
                    <span style="display: flex; align-items: center; gap: 0.6rem;">
                        <span>⚡</span>
                        <span>Queue & Daemons</span>
                    </span>
                    <span style="color: var(--admin-text-muted); font-size: 0.75rem;">&rarr;</span>
                </a>
                <a href="/admin/auditoria" class="admin-btn admin-btn-secondary" style="justify-content: space-between;">
                    <span style="display: flex; align-items: center; gap: 0.6rem;">
                        <span>📜</span>
                        <span>Audit Trail Records</span>
                    </span>
                    <span style="color: var(--admin-text-muted); font-size: 0.75rem;">&rarr;</span>
                </a>
                <a href="/admin/traducoes" class="admin-btn admin-btn-secondary" style="justify-content: space-between;">
                    <span style="display: flex; align-items: center; gap: 0.6rem;">
                        <span>🌐</span>
                        <span>Translation Matrix</span>
                    </span>
                    <span style="color: var(--admin-text-muted); font-size: 0.75rem;">&rarr;</span>
                </a>
                <a href="/admin/grupos" class="admin-btn admin-btn-secondary" style="justify-content: space-between;">
                    <span style="display: flex; align-items: center; gap: 0.6rem;">
                        <span>🏢</span>
                        <span>Groups & Networks</span>
                    </span>
                    <span style="color: var(--admin-text-muted); font-size: 0.75rem;">&rarr;</span>
                </a>
                <a href="/admin/tema" class="admin-btn admin-btn-secondary" style="justify-content: space-between;">
                    <span style="display: flex; align-items: center; gap: 0.6rem;">
                        <span>🎨</span>
                        <span>Visual Theme Customizer</span>
                    </span>
                    <span style="color: var(--admin-text-muted); font-size: 0.75rem;">&rarr;</span>
                </a>
            </div>
        </div>
    </div>
</div>

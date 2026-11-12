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
        <strong>⚠️ MAINTENANCE MODE ACTIVE:</strong> Public site access is restricted. Unauthenticated visitors see an unavailability notice.
    </div>
<?php endif; ?>

<div class="admin-card" style="margin-bottom: 1.5rem;">
    <div class="admin-card-title">
        <span>Services & Infrastructure Status</span>
        <span style="font-size: 0.8rem; font-weight: normal; color: var(--admin-text-muted);">Void Linux (musl) &bull; Runit Supervision</span>
    </div>
    <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; margin-bottom: 1rem;">
        <?php foreach ($services as $name => $running): ?>
            <div style="display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.4rem 0.75rem; background: #f8fafc; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.825rem;">
                <span class="admin-dot <?= $running ? 'admin-dot-ok' : 'admin-dot-danger' ?>"></span>
                <span style="font-weight: 500;"><?= e($name) ?></span>
                <span style="font-size: 0.75rem; font-weight: 600; color: <?= $running ? 'var(--admin-success)' : 'var(--admin-danger)' ?>;">
                    <?= $running ? 'ONLINE' : 'STOPPED' ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
    <div style="display: flex; flex-wrap: wrap; gap: 1.5rem; font-size: 0.825rem; color: var(--admin-text-muted); border-top: 1px solid var(--admin-border); padding-top: 0.75rem;">
        <div><strong>PHP:</strong> <?= e($env['php_version'] ?? PHP_VERSION) ?> (OPcache: <?= !empty($env['opcache_enabled']) ? 'Enabled' : 'Disabled' ?>)</div>
        <div><strong>PostgreSQL:</strong> <?= e($env['postgres_version'] ?? '18') ?></div>
        <div><strong>WebP Support:</strong> <?= !empty($env['webp_support']) ? 'Available (GD)' : 'Unavailable' ?></div>
        <div><strong>Disk Storage:</strong> <?= e((string)($env['disk_free_gb'] ?? 0)) ?> GB free of <?= e((string)($env['disk_total_gb'] ?? 0)) ?> GB (<?= e((string)($env['disk_used_percent'] ?? 0)) ?>% used)</div>
    </div>
</div>

<div class="admin-kpi-grid">
    <div class="admin-kpi-card">
        <span class="admin-kpi-label">Registered Properties</span>
        <span class="admin-kpi-value"><?= e((string)($kpis['properties']['total'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <span style="color: var(--admin-success); font-weight: 600;"><?= e((string)($kpis['properties']['active'] ?? 0)) ?></span> active &bull;
            <span><?= e((string)($kpis['properties']['inactive'] ?? 0)) ?></span> inactive
        </span>
    </div>

    <div class="admin-kpi-card">
        <span class="admin-kpi-label">Users & Accounts</span>
        <span class="admin-kpi-value"><?= e((string)($kpis['users']['total'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <strong><?= e((string)($kpis['users']['admins'] ?? 0)) ?></strong> administrators &bull;
            <span style="color: <?= ($kpis['users']['locked'] ?? 0) > 0 ? 'var(--admin-danger)' : 'inherit' ?>;">
                <?= e((string)($kpis['users']['locked'] ?? 0)) ?> locked
            </span>
        </span>
    </div>

    <div class="admin-kpi-card">
        <span class="admin-kpi-label">iCal Calendar Feeds</span>
        <span class="admin-kpi-value"><?= e((string)($kpis['feeds']['total'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <span style="color: var(--admin-success); font-weight: 600;"><?= e((string)($kpis['feeds']['ok'] ?? 0)) ?></span> synced &bull;
            <span style="color: <?= ($kpis['feeds']['errors'] ?? 0) > 0 ? 'var(--admin-danger)' : 'inherit' ?>;">
                <?= e((string)($kpis['feeds']['errors'] ?? 0)) ?> errors
            </span> &bull;
            <span><?= e((string)($kpis['feeds']['stopped'] ?? 0)) ?> stopped</span>
        </span>
    </div>

    <div class="admin-kpi-card">
        <span class="admin-kpi-label">Image Queue</span>
        <span class="admin-kpi-value"><?= e((string)($kpis['queues']['image_queue']['pending'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            pending WebP conversion &bull;
            <span style="color: <?= ($kpis['queues']['image_queue']['failed'] ?? 0) > 0 ? 'var(--admin-danger)' : 'inherit' ?>;">
                <?= e((string)($kpis['queues']['image_queue']['failed'] ?? 0)) ?> failed
            </span>
        </span>
    </div>

    <div class="admin-kpi-card">
        <span class="admin-kpi-label">Email Queue</span>
        <span class="admin-kpi-value"><?= e((string)($kpis['queues']['mail_queue']['pending'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            pending SMTP delivery &bull;
            <span style="color: <?= ($kpis['queues']['mail_queue']['failed'] ?? 0) > 0 ? 'var(--admin-danger)' : 'inherit' ?>;">
                <?= e((string)($kpis['queues']['mail_queue']['failed'] ?? 0)) ?> failed
            </span>
        </span>
    </div>

    <div class="admin-kpi-card">
        <span class="admin-kpi-label">Quotes & Reservations</span>
        <span class="admin-kpi-value"><?= e((string)($kpis['quotes']['total'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <span><?= e((string)($kpis['quotes']['pending'] ?? 0)) ?></span> pending &bull;
            <span style="color: var(--admin-success); font-weight: 600;"><?= e((string)($kpis['quotes']['confirmed'] ?? 0)) ?></span> confirmed
        </span>
    </div>
</div>

<div class="admin-grid-split">
    <div>
        <div class="admin-card">
            <div class="admin-card-title">
                <span>Recent System Activity</span>
                <a href="/admin/auditoria" style="font-size: 0.85rem; color: #2563eb; text-decoration: none;">View Complete Audit Trail &rarr;</a>
            </div>
            <?php if (empty($recent_activity)): ?>
                <p style="color: var(--admin-text-muted); font-size: 0.9rem;">No recent activity recorded.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Timestamp (UTC)</th>
                                <th>Action</th>
                                <th>Class</th>
                                <th>Details / Payload</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_activity as $act): ?>
                                <tr>
                                    <td style="white-space: nowrap; font-size: 0.8rem; color: var(--admin-text-muted);">
                                        <?= e(substr((string)($act['created_at'] ?? ''), 0, 19)) ?>
                                    </td>
                                    <td>
                                        <strong><?= e($act['action_label'] ?? ('#' . ($act['code'] ?? ''))) ?></strong>
                                        <div style="font-size: 0.75rem; color: var(--admin-text-muted);">Code: <?= e((string)($act['code'] ?? '')) ?></div>
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
                                        <span class="admin-pill <?= $pillClass ?>"><?= e($rc) ?></span>
                                    </td>
                                    <td>
                                        <?php
                                            $pl = $act['payload'] ?? '';
                                            if (is_string($pl) && $pl !== '') {
                                                $decoded = json_decode($pl, true);
                                                if (is_array($decoded)) {
                                                    echo '<div style="max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">';
                                                    foreach ($decoded as $k => $v) {
                                                        if (is_scalar($v) && $v !== '') {
                                                            echo '<span class="admin-code" style="margin-right: 0.25rem;">' . e($k . ': ' . $v) . '</span> ';
                                                        }
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="admin-code">' . e(substr($pl, 0, 80)) . '</span>';
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

    <div>
        <div class="admin-card">
            <div class="admin-card-title">General Admin Notes</div>
            <form method="post" action="/admin/notas">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p style="font-size: 0.825rem; color: var(--admin-text-muted); margin-top: 0;">
                    Shared notes visible to all platform administrators.
                </p>
                <textarea name="admin_notes_general" rows="8" style="width: 100%; box-sizing: border-box; border: 1px solid var(--admin-border); border-radius: 0.375rem; padding: 0.75rem; font-family: inherit; font-size: 0.875rem; resize: vertical; margin-bottom: 0.75rem;"><?= e((string)($settings['admin_notes_general'] ?? '')) ?></textarea>
                <button type="submit" class="admin-btn admin-btn-primary" style="width: 100%; justify-content: center;">Save Notes</button>
            </form>
        </div>

        <div class="admin-card">
            <div class="admin-card-title">Quick Actions</div>
            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                <a href="/admin/configuracoes" class="admin-btn admin-btn-secondary" style="justify-content: flex-start;">
                    <span>⚙️</span> Technical Parameters & Limits
                </a>
                <a href="/admin/seguranca" class="admin-btn admin-btn-secondary" style="justify-content: flex-start;">
                    <span>🛡️</span> Security & IP Control Center
                </a>
                <a href="/admin/filas" class="admin-btn admin-btn-secondary" style="justify-content: flex-start;">
                    <span>⚡</span> Background Queue Supervision
                </a>
                <a href="/admin/auditoria" class="admin-btn admin-btn-secondary" style="justify-content: flex-start;">
                    <span>📜</span> Complete Audit Trail
                </a>
                <a href="/admin/grupos" class="admin-btn admin-btn-secondary" style="justify-content: flex-start;">
                    <span>🏢</span> Manage Groups & Networks
                </a>
                <a href="/admin/tema" class="admin-btn admin-btn-secondary" style="justify-content: flex-start;">
                    <span>🎨</span> Customize Visual Theme
                </a>
                <a href="/admin/traducoes" class="admin-btn admin-btn-secondary" style="justify-content: flex-start;">
                    <span>🌐</span> Multi-Language Translations
                </a>
            </div>
        </div>
    </div>
</div>

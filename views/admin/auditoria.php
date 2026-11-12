<?php
/**
 * @var list<array<string, mixed>> $logs
 * @var array<string, int> $metrics
 * @var array<int, string> $actions
 * @var int|null $current_code
 * @var string|null $current_class
 * @var int $current_limit
 * @var array<string, mixed> $user
 * @var string $csrf
 */
?>

<!-- 4 TOP AUDIT METRIC CARDS -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
    <div class="admin-kpi-card" style="--accent: #2563eb;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <span class="admin-kpi-label">Total Logged Events</span>
            <span style="font-size: 1.25rem;">📊</span>
        </div>
        <span class="admin-kpi-value"><?= e((string)($metrics['total'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">Recent audited sample window</span>
    </div>

    <div class="admin-kpi-card" style="--accent: #ef4444;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <span class="admin-kpi-label">Security & Access</span>
            <span style="font-size: 1.25rem;">🛡️</span>
        </div>
        <span class="admin-kpi-value" style="color: #ef4444;"><?= e((string)($metrics['security'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">Logins, failed attempts & lockouts</span>
    </div>

    <div class="admin-kpi-card" style="--accent: #f59e0b;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <span class="admin-kpi-label">Admin Actions</span>
            <span style="font-size: 1.25rem;">⚡</span>
        </div>
        <span class="admin-kpi-value" style="color: #f59e0b;"><?= e((string)($metrics['admin'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">Back-office mutations & inspections</span>
    </div>

    <div class="admin-kpi-card" style="--accent: #3b82f6;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <span class="admin-kpi-label">Other Operations</span>
            <span style="font-size: 1.25rem;">🏠</span>
        </div>
        <span class="admin-kpi-value" style="color: #3b82f6;"><?= e((string)($metrics['other'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">Properties, galleries & guest quotes</span>
    </div>
</div>

<!-- FILTER & EXPORT CONTROLS (Osclass toolbar style) -->
<div class="admin-card" style="margin-bottom: 1.5rem;">
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem;">
        <form method="get" action="/admin/auditoria" style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end;">
            <div>
                <label style="display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-text-muted); margin-bottom: 0.35rem;">Filter by Action</label>
                <select name="code" class="admin-select" style="padding: 0.45rem 0.65rem; font-size: 0.85rem; min-width: 200px;">
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $codeNum => $desc): ?>
                        <option value="<?= e((string)$codeNum) ?>" <?= $current_code === $codeNum ? 'selected' : '' ?>>
                            #<?= e((string)$codeNum) ?> - <?= e($desc) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-text-muted); margin-bottom: 0.35rem;">Retention Class</label>
                <select name="class" class="admin-select" style="padding: 0.45rem 0.65rem; font-size: 0.85rem;">
                    <option value="">All Classes</option>
                    <option value="security" <?= $current_class === 'security' ? 'selected' : '' ?>>Security (security)</option>
                    <option value="admin" <?= $current_class === 'admin' ? 'selected' : '' ?>>Admin (admin)</option>
                    <option value="other" <?= $current_class === 'other' ? 'selected' : '' ?>>Other (other)</option>
                </select>
            </div>

            <div>
                <label style="display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-text-muted); margin-bottom: 0.35rem;">Row Limit</label>
                <select name="limit" class="admin-select" style="padding: 0.45rem 0.65rem; font-size: 0.85rem;">
                    <option value="50" <?= $current_limit === 50 ? 'selected' : '' ?>>50 rows</option>
                    <option value="100" <?= $current_limit === 100 ? 'selected' : '' ?>>100 rows</option>
                    <option value="250" <?= $current_limit === 250 ? 'selected' : '' ?>>250 rows</option>
                    <option value="500" <?= $current_limit === 500 ? 'selected' : '' ?>>500 rows</option>
                </select>
            </div>

            <button type="submit" class="admin-btn admin-btn-secondary" style="padding: 0.45rem 1rem; font-size: 0.85rem;">
                <span>🔍</span>
                <span>Filter</span>
            </button>
        </form>

        <div>
            <?php
                $csvQuery = array_filter([
                    'code' => $current_code,
                    'class' => $current_class,
                    'limit' => $current_limit,
                ]);
                $csvUrl = '/admin/auditoria/exportar.csv' . (!empty($csvQuery) ? '?' . http_build_query($csvQuery) : '');
            ?>
            <a href="<?= e($csvUrl) ?>" class="admin-btn admin-btn-primary" style="background: linear-gradient(180deg, #10b981 0%, #059669 100%);">
                <span>📥</span>
                <span>Export CSV Report (Excel)</span>
            </a>
        </div>
    </div>
</div>

<!-- AUDIT LOGS TABLE -->
<div class="admin-card">
    <div class="admin-card-title">
        <div style="display: flex; align-items: center; gap: 0.6rem;">
            <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 6px; background: #eff6ff; color: #2563eb; font-size: 0.95rem;">📜</span>
            <span>Audit Trail Records</span>
        </div>
        <span style="font-size: 0.8rem; font-weight: 500; color: var(--admin-text-muted); background: #f8fafc; padding: 0.25rem 0.65rem; border-radius: 9999px; border: 1px solid var(--admin-border);">
            audit.activity_log &bull; Immutable Ledger
        </span>
    </div>

    <?php if (empty($logs)): ?>
        <div style="text-align: center; padding: 2.5rem 1rem; color: var(--admin-text-muted);">
            <div style="font-size: 2rem; margin-bottom: 0.5rem;">🔍</div>
            <div style="font-weight: 600; color: var(--admin-text-main); margin-bottom: 0.25rem;">No Records Found</div>
            <div style="font-size: 0.85rem;">No audit records matching selected filter criteria in database.</div>
        </div>
    <?php else: ?>
        <div class="admin-table-container">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width: 140px;">Timestamp (UTC)</th>
                        <th>Action</th>
                        <th style="width: 90px;">Class</th>
                        <th>Actor</th>
                        <th>Details / Payload</th>
                        <th>State Diff</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $row): ?>
                        <tr>
                            <td style="white-space: nowrap; font-size: 0.8rem; color: var(--admin-text-muted); font-family: ui-monospace, monospace;">
                                <div><?= e(substr((string)($row['created_at'] ?? ''), 11, 8)) ?></div>
                                <div style="font-size: 0.7rem; color: #94a3b8;"><?= e(substr((string)($row['created_at'] ?? ''), 0, 10)) ?></div>
                                <div style="font-size: 0.65rem; color: #cbd5e1;" title="UUIDv7"><?= e(substr((string)($row['id'] ?? ''), 0, 8)) ?>...</div>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: #0f172a; font-size: 0.85rem;">
                                    <?= e($row['action_label'] ?? ('#' . ($row['code'] ?? ''))) ?>
                                </div>
                                <div style="font-size: 0.72rem; color: var(--admin-text-muted);">Code: <?= e((string)($row['code'] ?? '')) ?></div>
                            </td>
                            <td>
                                <?php
                                    $c = (string)($row['retention_class'] ?? 'other');
                                    $badgeClass = match($c) {
                                        'security' => 'admin-pill-danger',
                                        'admin' => 'admin-pill-warn',
                                        default => 'admin-pill-neutral'
                                    };
                                ?>
                                <span class="admin-pill <?= $badgeClass ?>" style="font-size: 0.7rem; padding: 0.15rem 0.45rem;">
                                    <?= e($c) ?>
                                </span>
                            </td>
                            <td style="font-size: 0.85rem;">
                                <span class="admin-pill admin-pill-neutral" style="font-size: 0.72rem;">
                                    <?= e((string)($row['actor_kind'] ?? 'system')) ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                    $pl = (string)($row['payload'] ?? '');
                                    if ($pl !== '') {
                                        $dec = json_decode($pl, true);
                                        if (is_array($dec)) {
                                            echo '<div style="max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">';
                                            foreach ($dec as $k => $v) {
                                                if (is_scalar($v) && $v !== '') {
                                                    echo '<span class="admin-code" style="margin-right: 0.2rem; font-size: 0.75rem;">' . e($k . ': ' . $v) . '</span> ';
                                                }
                                            }
                                            echo '</div>';
                                        } else {
                                            echo '<span class="admin-code" style="font-size: 0.75rem;">' . e(substr($pl, 0, 60)) . '</span>';
                                        }
                                    } else {
                                        echo '<span style="color: var(--admin-text-muted); font-size: 0.8rem;">-</span>';
                                    }
                                ?>
                            </td>
                            <td>
                                <?php
                                    $before = (string)($row['before_value'] ?? '');
                                    $after = (string)($row['after_value'] ?? '');
                                ?>
                                <?php if ($before !== '' || $after !== ''): ?>
                                    <details style="font-size: 0.8rem;">
                                        <summary style="cursor: pointer; color: #2563eb; font-weight: 600;">View Diff</summary>
                                        <div style="margin-top: 0.5rem; background: #f8fafc; padding: 0.5rem; border-radius: var(--admin-radius-sm); border: 1px solid var(--admin-border);">
                                            <?php if ($before !== ''): ?>
                                                <div style="color: #b91c1c; margin-bottom: 0.25rem;"><strong>Before:</strong> <?= e(substr($before, 0, 120)) ?></div>
                                            <?php endif; ?>
                                            <?php if ($after !== ''): ?>
                                                <div style="color: #15803d;"><strong>After:</strong> <?= e(substr($after, 0, 120)) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                <?php else: ?>
                                    <span style="color: var(--admin-text-muted); font-size: 0.8rem;">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

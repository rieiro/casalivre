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
<div class="admin-kpi-grid">
    <div class="admin-kpi-card">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Total Logged Events</span>
            <span class="admin-kpi-icon">📊</span>
        </div>
        <span class="admin-kpi-value"><?= e((string)($metrics['total'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">Recent audited sample window</span>
    </div>

    <div class="admin-kpi-card">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Security & Access</span>
            <span class="admin-kpi-icon">🛡️</span>
        </div>
        <span class="admin-kpi-value"><?= e((string)($metrics['security'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">Logins, failed attempts & lockouts</span>
    </div>

    <div class="admin-kpi-card">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Admin Actions</span>
            <span class="admin-kpi-icon">⚡</span>
        </div>
        <span class="admin-kpi-value"><?= e((string)($metrics['admin'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">Back-office mutations & inspections</span>
    </div>

    <div class="admin-kpi-card">
        <div class="admin-kpi-header">
            <span class="admin-kpi-label">Other Operations</span>
            <span class="admin-kpi-icon">🏠</span>
        </div>
        <span class="admin-kpi-value"><?= e((string)($metrics['other'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">Properties, galleries & guest quotes</span>
    </div>
</div>

<!-- FILTER & EXPORT CONTROLS (Osclass toolbar style) -->
<div class="admin-card">
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem;">
        <form method="get" action="/admin/auditoria" style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end;">
            <div>
                <label class="admin-label">Filter by Action</label>
                <select name="code" class="admin-select">
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $codeNum => $desc): ?>
                        <option value="<?= e((string)$codeNum) ?>" <?= $current_code === $codeNum ? 'selected' : '' ?>>
                            #<?= e((string)$codeNum) ?> - <?= e($desc) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="admin-label">Retention Class</label>
                <select name="class" class="admin-select">
                    <option value="">All Classes</option>
                    <option value="security" <?= $current_class === 'security' ? 'selected' : '' ?>>Security (security)</option>
                    <option value="admin" <?= $current_class === 'admin' ? 'selected' : '' ?>>Admin (admin)</option>
                    <option value="other" <?= $current_class === 'other' ? 'selected' : '' ?>>Other (other)</option>
                </select>
            </div>

            <div>
                <label class="admin-label">Row Limit</label>
                <select name="limit" class="admin-select">
                    <option value="50" <?= $current_limit === 50 ? 'selected' : '' ?>>50 rows</option>
                    <option value="100" <?= $current_limit === 100 ? 'selected' : '' ?>>100 rows</option>
                    <option value="250" <?= $current_limit === 250 ? 'selected' : '' ?>>250 rows</option>
                    <option value="500" <?= $current_limit === 500 ? 'selected' : '' ?>>500 rows</option>
                </select>
            </div>

            <button type="submit" class="admin-btn admin-btn-secondary">
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
            <a href="<?= e($csvUrl) ?>" class="admin-btn admin-btn-primary">
                <span>📥</span>
                <span>Export CSV Report (Excel)</span>
            </a>
        </div>
    </div>
</div>

<!-- AUDIT LOGS TABLE -->
<div class="admin-card">
    <div class="admin-card-title">
        <span>📜 Audit Trail Records</span>
        <span class="admin-card-tag">audit.activity_log &bull; Immutable Ledger</span>
    </div>

    <?php if (empty($logs)): ?>
        <div class="admin-empty-state">
            <div class="admin-empty-icon">🔍</div>
            <div class="admin-empty-title">No Records Found</div>
            <div class="admin-empty-desc">No audit records matching selected filter criteria in database.</div>
        </div>
    <?php else: ?>
        <div class="admin-table-container">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Timestamp (UTC)</th>
                        <th>Action</th>
                        <th>Class</th>
                        <th>Actor</th>
                        <th>Details / Payload</th>
                        <th>State Diff</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $row): ?>
                        <tr>
                            <td>
                                <div class="admin-sysinfo-val"><?= e(substr((string)($row['created_at'] ?? ''), 11, 8)) ?></div>
                                <div class="admin-hint"><?= e(substr((string)($row['created_at'] ?? ''), 0, 10)) ?></div>
                                <div class="admin-hint" title="UUIDv7"><?= e(substr((string)($row['id'] ?? ''), 0, 8)) ?>...</div>
                            </td>
                            <td>
                                <div class="admin-label">
                                    <?= e($row['action_label'] ?? ('#' . ($row['code'] ?? ''))) ?>
                                </div>
                                <div class="admin-hint">Code: <?= e((string)($row['code'] ?? '')) ?></div>
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
                                <span class="admin-pill <?= $badgeClass ?> admin-pill-sm">
                                    <?= e($c) ?>
                                </span>
                            </td>
                            <td>
                                <span class="admin-pill admin-pill-neutral admin-pill-sm">
                                    <?= e((string)($row['actor_kind'] ?? 'system')) ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                    $pl = (string)($row['payload'] ?? '');
                                    if ($pl !== '') {
                                        $dec = json_decode($pl, true);
                                        if (is_array($dec)) {
                                            foreach ($dec as $k => $v) {
                                                if (is_scalar($v) && $v !== '') {
                                                    echo '<span class="admin-code">' . e($k . ': ' . $v) . '</span> ';
                                                }
                                            }
                                        } else {
                                            echo '<span class="admin-code">' . e(substr($pl, 0, 60)) . '</span>';
                                        }
                                    } else {
                                        echo '<span class="admin-hint">-</span>';
                                    }
                                ?>
                            </td>
                            <td>
                                <?php
                                    $before = (string)($row['before_value'] ?? '');
                                    $after = (string)($row['after_value'] ?? '');
                                ?>
                                <?php if ($before !== '' || $after !== ''): ?>
                                    <details class="admin-hint">
                                        <summary>View Diff</summary>
                                        <div class="admin-form-box">
                                            <?php if ($before !== ''): ?>
                                                <div class="admin-hint"><strong>Before:</strong> <?= e(substr($before, 0, 120)) ?></div>
                                            <?php endif; ?>
                                            <?php if ($after !== ''): ?>
                                                <div class="admin-hint"><strong>After:</strong> <?= e(substr($after, 0, 120)) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                <?php else: ?>
                                    <span class="admin-hint">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

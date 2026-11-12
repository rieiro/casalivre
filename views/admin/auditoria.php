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

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <div class="admin-kpi-card">
        <span class="admin-kpi-label">Total de Eventos</span>
        <span class="admin-kpi-value"><?= e((string)($metrics['total'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">Amostra recente auditada</span>
    </div>

    <div class="admin-kpi-card">
        <span class="admin-kpi-label">Segurança & Acessos</span>
        <span class="admin-kpi-value" style="color: #ef4444;"><?= e((string)($metrics['security'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">Tentativas, logins e bloqueios</span>
    </div>

    <div class="admin-kpi-card">
        <span class="admin-kpi-label">Ações Administrativas</span>
        <span class="admin-kpi-value" style="color: #f59e0b;"><?= e((string)($metrics['admin'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">Back-office e auditorias</span>
    </div>

    <div class="admin-kpi-card">
        <span class="admin-kpi-label">Outras Operações</span>
        <span class="admin-kpi-value" style="color: #3b82f6;"><?= e((string)($metrics['other'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">Imóveis, fotos e cotações</span>
    </div>
</div>

<div class="admin-card" style="margin-bottom: 1.5rem;">
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem;">
        <form method="get" action="/admin/auditoria" style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end;">
            <div>
                <label style="display: block; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--admin-text-muted); margin-bottom: 0.25rem;">Filtrar por Ação</label>
                <select name="code" style="padding: 0.45rem 0.65rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.85rem;">
                    <option value="">Todas as Ações</option>
                    <?php foreach ($actions as $codeNum => $desc): ?>
                        <option value="<?= e((string)$codeNum) ?>" <?= $current_code === $codeNum ? 'selected' : '' ?>>
                            #<?= e((string)$codeNum) ?> - <?= e($desc) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="display: block; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--admin-text-muted); margin-bottom: 0.25rem;">Classe</label>
                <select name="class" style="padding: 0.45rem 0.65rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.85rem;">
                    <option value="">Todas as Classes</option>
                    <option value="security" <?= $current_class === 'security' ? 'selected' : '' ?>>Segurança (security)</option>
                    <option value="admin" <?= $current_class === 'admin' ? 'selected' : '' ?>>Admin (admin)</option>
                    <option value="other" <?= $current_class === 'other' ? 'selected' : '' ?>>Outras (other)</option>
                </select>
            </div>

            <div>
                <label style="display: block; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--admin-text-muted); margin-bottom: 0.25rem;">Limite</label>
                <select name="limit" style="padding: 0.45rem 0.65rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.85rem;">
                    <option value="50" <?= $current_limit === 50 ? 'selected' : '' ?>>50 linhas</option>
                    <option value="100" <?= $current_limit === 100 ? 'selected' : '' ?>>100 linhas</option>
                    <option value="250" <?= $current_limit === 250 ? 'selected' : '' ?>>250 linhas</option>
                    <option value="500" <?= $current_limit === 500 ? 'selected' : '' ?>>500 linhas</option>
                </select>
            </div>

            <button type="submit" class="admin-btn admin-btn-secondary" style="padding: 0.45rem 0.85rem; font-size: 0.85rem;">
                Filtrar
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
            <a href="<?= e($csvUrl) ?>" class="admin-btn" style="background: #10b981; color: #ffffff; text-decoration: none; padding: 0.5rem 1rem;">
                📥 Exportar Relatório CSV (Excel)
            </a>
        </div>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card-title">
        <span>Registros da Trilha de Auditoria</span>
        <span style="font-size: 0.8rem; font-weight: normal; color: var(--admin-text-muted);">audit.activity_log &bull; Imutável</span>
    </div>

    <?php if (empty($logs)): ?>
        <p style="color: var(--admin-text-muted); font-size: 0.9rem;">Nenhum evento localizado com os filtros selecionados.</p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Data / Hora (UTC)</th>
                        <th>Ação</th>
                        <th>Classe</th>
                        <th>Ator</th>
                        <th>Detalhes / Payload</th>
                        <th>Diff de Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $row): ?>
                        <tr>
                            <td style="white-space: nowrap; font-size: 0.8rem; color: var(--admin-text-muted);">
                                <?= e(substr((string)($row['created_at'] ?? ''), 0, 19)) ?>
                                <div style="font-size: 0.7rem; color: #94a3b8;" title="UUIDv7"><?= e(substr((string)($row['id'] ?? ''), 0, 8)) ?>...</div>
                            </td>
                            <td>
                                <strong><?= e($row['action_label'] ?? ('#' . ($row['code'] ?? ''))) ?></strong>
                                <div style="font-size: 0.75rem; color: var(--admin-text-muted);">Código: <?= e((string)($row['code'] ?? '')) ?></div>
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
                                <span class="admin-pill <?= $badgeClass ?>"><?= e($c) ?></span>
                            </td>
                            <td style="font-size: 0.85rem;">
                                <?= e((string)($row['actor_kind'] ?? 'system')) ?>
                            </td>
                            <td>
                                <?php
                                    $pl = (string)($row['payload'] ?? '');
                                    if ($pl !== '') {
                                        $dec = json_decode($pl, true);
                                        if (is_array($dec)) {
                                            echo '<div style="max-width: 280px; overflow: hidden; text-overflow: ellipsis;">';
                                            foreach ($dec as $k => $v) {
                                                if (is_scalar($v) && $v !== '') {
                                                    echo '<span class="admin-code" style="margin-right: 0.2rem;">' . e($k . ': ' . $v) . '</span> ';
                                                }
                                            }
                                            echo '</div>';
                                        } else {
                                            echo '<span class="admin-code">' . e(substr($pl, 0, 60)) . '</span>';
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
                                        <summary style="cursor: pointer; color: #2563eb; font-weight: 500;">Ver Diff</summary>
                                        <div style="margin-top: 0.5rem; background: #f8fafc; padding: 0.5rem; border-radius: 0.25rem; border: 1px solid var(--admin-border);">
                                            <?php if ($before !== ''): ?>
                                                <div style="color: #b91c1c; margin-bottom: 0.25rem;"><strong>Antes:</strong> <?= e(substr($before, 0, 120)) ?></div>
                                            <?php endif; ?>
                                            <?php if ($after !== ''): ?>
                                                <div style="color: #15803d;"><strong>Depois:</strong> <?= e(substr($after, 0, 120)) ?></div>
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

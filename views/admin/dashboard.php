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
        <strong>⚠️ Modo de Manutenção ATIVO:</strong> A plataforma está restrita a administradores. Novos acessos públicos verão mensagem de indisponibilidade.
    </div>
<?php endif; ?>

<div class="admin-card" style="margin-bottom: 1.5rem;">
    <div class="admin-card-title">
        <span>Estado dos Serviços & Infraestrutura</span>
        <span style="font-size: 0.8rem; font-weight: normal; color: var(--admin-text-muted);">Void Linux (musl) &bull; Runit</span>
    </div>
    <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; margin-bottom: 1rem;">
        <?php foreach ($services as $name => $running): ?>
            <div style="display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.4rem 0.75rem; background: #f8fafc; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.825rem;">
                <span class="admin-dot <?= $running ? 'admin-dot-ok' : 'admin-dot-danger' ?>"></span>
                <span style="font-weight: 500;"><?= e($name) ?></span>
                <span style="font-size: 0.75rem; color: <?= $running ? 'var(--admin-success)' : 'var(--admin-danger)' ?>;">
                    <?= $running ? 'ONLINE' : 'STOPPED' ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
    <div style="display: flex; flex-wrap: wrap; gap: 1.5rem; font-size: 0.825rem; color: var(--admin-text-muted); border-top: 1px solid var(--admin-border); padding-top: 0.75rem;">
        <div><strong>PHP:</strong> <?= e($env['php_version'] ?? PHP_VERSION) ?> (OPcache: <?= !empty($env['opcache_enabled']) ? 'Ativo' : 'Inativo' ?>)</div>
        <div><strong>PostgreSQL:</strong> <?= e($env['postgres_version'] ?? '18') ?></div>
        <div><strong>Suporte WebP:</strong> <?= !empty($env['webp_support']) ? 'Disponível (GD)' : 'Indisponível' ?></div>
        <div><strong>Disco:</strong> <?= e((string)($env['disk_free_gb'] ?? 0)) ?> GB livres de <?= e((string)($env['disk_total_gb'] ?? 0)) ?> GB (<?= e((string)($env['disk_used_percent'] ?? 0)) ?>% em uso)</div>
    </div>
</div>

<div class="admin-kpi-grid">
    <div class="admin-kpi-card">
        <span class="admin-kpi-label">Imóveis Cadastrados</span>
        <span class="admin-kpi-value"><?= e((string)($kpis['properties']['total'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <span style="color: var(--admin-success); font-weight: 600;"><?= e((string)($kpis['properties']['active'] ?? 0)) ?></span> ativos &bull;
            <span><?= e((string)($kpis['properties']['inactive'] ?? 0)) ?></span> inativos
        </span>
    </div>

    <div class="admin-kpi-card">
        <span class="admin-kpi-label">Usuários & Contas</span>
        <span class="admin-kpi-value"><?= e((string)($kpis['users']['total'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <strong><?= e((string)($kpis['users']['admins'] ?? 0)) ?></strong> administradores &bull;
            <span style="color: <?= ($kpis['users']['locked'] ?? 0) > 0 ? 'var(--admin-danger)' : 'inherit' ?>;">
                <?= e((string)($kpis['users']['locked'] ?? 0)) ?> bloqueados
            </span>
        </span>
    </div>

    <div class="admin-kpi-card">
        <span class="admin-kpi-label">Feeds iCal</span>
        <span class="admin-kpi-value"><?= e((string)($kpis['feeds']['total'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <span style="color: var(--admin-success); font-weight: 600;"><?= e((string)($kpis['feeds']['ok'] ?? 0)) ?></span> OK &bull;
            <span style="color: <?= ($kpis['feeds']['errors'] ?? 0) > 0 ? 'var(--admin-danger)' : 'inherit' ?>;">
                <?= e((string)($kpis['feeds']['errors'] ?? 0)) ?> erros
            </span> &bull;
            <span><?= e((string)($kpis['feeds']['stopped'] ?? 0)) ?> parados</span>
        </span>
    </div>

    <div class="admin-kpi-card">
        <span class="admin-kpi-label">Fila de Imagens</span>
        <span class="admin-kpi-value"><?= e((string)($kpis['queues']['image_queue']['pending'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            pendentes de conversão WebP &bull;
            <span style="color: <?= ($kpis['queues']['image_queue']['failed'] ?? 0) > 0 ? 'var(--admin-danger)' : 'inherit' ?>;">
                <?= e((string)($kpis['queues']['image_queue']['failed'] ?? 0)) ?> falhas
            </span>
        </span>
    </div>

    <div class="admin-kpi-card">
        <span class="admin-kpi-label">Fila de E-mails</span>
        <span class="admin-kpi-value"><?= e((string)($kpis['queues']['mail_queue']['pending'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            pendentes de envio SMTP &bull;
            <span style="color: <?= ($kpis['queues']['mail_queue']['failed'] ?? 0) > 0 ? 'var(--admin-danger)' : 'inherit' ?>;">
                <?= e((string)($kpis['queues']['mail_queue']['failed'] ?? 0)) ?> falhas
            </span>
        </span>
    </div>

    <div class="admin-kpi-card">
        <span class="admin-kpi-label">Cotações & Reservas</span>
        <span class="admin-kpi-value"><?= e((string)($kpis['quotes']['total'] ?? 0)) ?></span>
        <span class="admin-kpi-sub">
            <span><?= e((string)($kpis['quotes']['pending'] ?? 0)) ?></span> pendentes &bull;
            <span style="color: var(--admin-success); font-weight: 600;"><?= e((string)($kpis['quotes']['confirmed'] ?? 0)) ?></span> confirmadas
        </span>
    </div>
</div>

<div class="admin-grid-split">
    <div>
        <div class="admin-card">
            <div class="admin-card-title">
                <span>Atividades Recentes do Sistema</span>
                <a href="/admin/auditoria" style="font-size: 0.85rem; color: #2563eb; text-decoration: none;">Ver Trilha Completa &rarr;</a>
            </div>
            <?php if (empty($recent_activity)): ?>
                <p style="color: var(--admin-text-muted); font-size: 0.9rem;">Nenhuma atividade recente registrada.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Horário (UTC)</th>
                                <th>Ação / Código</th>
                                <th>Classe</th>
                                <th>Detalhes / Payload</th>
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
                                        <div style="font-size: 0.75rem; color: var(--admin-text-muted);">Código: <?= e((string)($act['code'] ?? '')) ?></div>
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
            <div class="admin-card-title">Anotações Gerais</div>
            <form method="post" action="/admin/notas">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p style="font-size: 0.825rem; color: var(--admin-text-muted); margin-top: 0;">
                    Notas internas compartilhadas entre os administradores da plataforma.
                </p>
                <textarea name="admin_notes_general" rows="8" style="width: 100%; box-sizing: border-box; border: 1px solid var(--admin-border); border-radius: 0.375rem; padding: 0.75rem; font-family: inherit; font-size: 0.875rem; resize: vertical; margin-bottom: 0.75rem;"><?= e((string)($settings['admin_notes_general'] ?? '')) ?></textarea>
                <button type="submit" class="admin-btn admin-btn-primary" style="width: 100%; justify-content: center;">Salvar Anotações</button>
            </form>
        </div>

        <div class="admin-card">
            <div class="admin-card-title">Atalhos Rápidos</div>
            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                <a href="/admin/configuracoes" class="admin-btn admin-btn-secondary" style="justify-content: flex-start;">
                    <span>⚙️</span> Configurações Técnicas
                </a>
                <a href="/admin/seguranca" class="admin-btn admin-btn-secondary" style="justify-content: flex-start;">
                    <span>🛡️</span> Central de Segurança & IPs
                </a>
                <a href="/admin/filas" class="admin-btn admin-btn-secondary" style="justify-content: flex-start;">
                    <span>⚡</span> Supervisão de Filas
                </a>
                <a href="/admin/auditoria" class="admin-btn admin-btn-secondary" style="justify-content: flex-start;">
                    <span>📜</span> Trilha de Auditoria Completa
                </a>
                <a href="/admin/grupos" class="admin-btn admin-btn-secondary" style="justify-content: flex-start;">
                    <span>🏢</span> Gerenciar Grupos & Redes
                </a>
                <a href="/admin/tema" class="admin-btn admin-btn-secondary" style="justify-content: flex-start;">
                    <span>🎨</span> Personalizar Tema
                </a>
            </div>
        </div>
    </div>
</div>

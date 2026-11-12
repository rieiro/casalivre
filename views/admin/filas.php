<?php
/**
 * @var array<string, mixed> $image_queue
 * @var array<string, mixed> $mail_queue
 * @var array<string, bool> $services
 * @var array<string, mixed> $user
 * @var string $csrf
 * @var string|null $ok
 * @var string|null $error
 */
?>

<div class="admin-card" style="margin-bottom: 1.5rem;">
    <div class="admin-card-title">
        <span>Supervisão de Daemons em Background</span>
        <span style="font-size: 0.8rem; font-weight: normal; color: var(--admin-text-muted);">Runit /var/service</span>
    </div>
    <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: center;">
        <?php foreach ($services as $name => $running): ?>
            <div style="display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.45rem 0.85rem; background: #f8fafc; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.85rem;">
                <span class="admin-dot <?= $running ? 'admin-dot-ok' : 'admin-dot-danger' ?>"></span>
                <span style="font-weight: 500;"><?= e($name) ?></span>
                <span style="font-size: 0.75rem; color: <?= $running ? 'var(--admin-success)' : 'var(--admin-danger)' ?>;">
                    <?= $running ? 'ATIVO' : 'PARADO' ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="admin-grid-split">
    <div>
        <!-- FILA DE IMAGENS -->
        <div class="admin-card">
            <div class="admin-card-title">
                <span>🖼️ Fila de Otimização de Imagens (WebP)</span>
                <div>
                    <form method="post" action="/admin/filas/reprocessar-imagens" style="display: inline;">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button type="submit" class="admin-btn admin-btn-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.8rem;">
                            🔄 Reprocessar Falhas
                        </button>
                    </form>
                </div>
            </div>

            <div style="display: flex; gap: 1rem; margin-bottom: 1.25rem;">
                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: 0.375rem; padding: 0.75rem 1rem; flex: 1;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--admin-text-muted); font-weight: 600;">Total</div>
                    <div style="font-size: 1.5rem; font-weight: 700;"><?= e((string)($image_queue['stats']['total'] ?? 0)) ?></div>
                </div>
                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: 0.375rem; padding: 0.75rem 1rem; flex: 1;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--admin-text-muted); font-weight: 600;">Pendentes</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #2563eb;"><?= e((string)($image_queue['stats']['pending'] ?? 0)) ?></div>
                </div>
                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: 0.375rem; padding: 0.75rem 1rem; flex: 1;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--admin-text-muted); font-weight: 600;">Falhas</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #ef4444;"><?= e((string)($image_queue['stats']['failed'] ?? 0)) ?></div>
                </div>
            </div>

            <h4 style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-text-muted); margin-bottom: 0.5rem;">
                Tarefas Recentes
            </h4>
            <?php if (empty($image_queue['recent'])): ?>
                <p style="color: var(--admin-text-muted); font-size: 0.85rem;">Nenhuma tarefa recente na fila de imagens.</p>
            <?php else: ?>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Foto ID</th>
                            <th>Status</th>
                            <th>Tentativas</th>
                            <th>Último Erro</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($image_queue['recent'] as $item): ?>
                            <tr>
                                <td>#<?= e((string)($item['id'] ?? '')) ?></td>
                                <td><?= e((string)($item['photo_id'] ?? '')) ?></td>
                                <td>
                                    <?php
                                        $st = (string)($item['status'] ?? '');
                                        $pill = match($st) {
                                            'pending' => 'admin-pill-neutral',
                                            'processing' => 'admin-pill-warn',
                                            'failed' => 'admin-pill-danger',
                                            default => 'admin-pill-ok',
                                        };
                                    ?>
                                    <span class="admin-pill <?= $pill ?>"><?= e($st) ?></span>
                                </td>
                                <td><?= e((string)($item['attempts'] ?? 0)) ?></td>
                                <td style="font-size: 0.8rem; color: #ef4444;"><?= e(substr((string)($item['last_error'] ?? '-'), 0, 40)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- FILA DE EMAILS -->
        <div class="admin-card">
            <div class="admin-card-title">
                <span>✉️ Fila de Disparo de E-mails (SMTP)</span>
                <div>
                    <form method="post" action="/admin/filas/reprocessar-emails" style="display: inline;">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button type="submit" class="admin-btn admin-btn-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.8rem;">
                            🔄 Reprocessar Falhas
                        </button>
                    </form>
                </div>
            </div>

            <div style="display: flex; gap: 1rem; margin-bottom: 1.25rem;">
                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: 0.375rem; padding: 0.75rem 1rem; flex: 1;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--admin-text-muted); font-weight: 600;">Total</div>
                    <div style="font-size: 1.5rem; font-weight: 700;"><?= e((string)($mail_queue['stats']['total'] ?? 0)) ?></div>
                </div>
                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: 0.375rem; padding: 0.75rem 1rem; flex: 1;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--admin-text-muted); font-weight: 600;">Pendentes</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #2563eb;"><?= e((string)($mail_queue['stats']['pending'] ?? 0)) ?></div>
                </div>
                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: 0.375rem; padding: 0.75rem 1rem; flex: 1;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--admin-text-muted); font-weight: 600;">Falhas</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #ef4444;"><?= e((string)($mail_queue['stats']['failed'] ?? 0)) ?></div>
                </div>
            </div>

            <h4 style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-text-muted); margin-bottom: 0.5rem;">
                Disparos Recentes
            </h4>
            <?php if (empty($mail_queue['recent'])): ?>
                <p style="color: var(--admin-text-muted); font-size: 0.85rem;">Nenhum e-mail recente na fila.</p>
            <?php else: ?>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Tipo</th>
                            <th>Destinatário</th>
                            <th>Status</th>
                            <th>Tentativas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mail_queue['recent'] as $item): ?>
                            <tr>
                                <td>#<?= e((string)($item['id'] ?? '')) ?></td>
                                <td><span class="admin-code"><?= e((string)($item['type'] ?? '')) ?></span></td>
                                <td style="font-size: 0.8rem;"><?= e((string)($item['recipient'] ?? '')) ?></td>
                                <td>
                                    <?php
                                        $mst = (string)($item['status'] ?? '');
                                        $mpill = match($mst) {
                                            'pending' => 'admin-pill-neutral',
                                            'failed' => 'admin-pill-danger',
                                            default => 'admin-pill-ok',
                                        };
                                    ?>
                                    <span class="admin-pill <?= $mpill ?>"><?= e($mst) ?></span>
                                </td>
                                <td><?= e((string)($item['attempts'] ?? 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <div class="admin-card">
            <div class="admin-card-title">Execução Manual & Workers</div>
            <p style="font-size: 0.85rem; color: var(--admin-text-muted); line-height: 1.5;">
                Os workers operam continuamente através do daemon supervisor <code>casalivre-queue-worker</code>.
            </p>
            <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: 0.375rem; padding: 0.75rem; font-size: 0.8rem; font-family: monospace; margin-bottom: 1rem;">
                sv status casalivre-queue-worker<br>
                php scripts/queue-worker.php all --once
            </div>
            <p style="font-size: 0.8rem; color: var(--admin-text-muted);">
                Use os botões de reprocessamento caso falhas transitórias de conexão tenham esgotado as tentativas.
            </p>
        </div>
    </div>
</div>

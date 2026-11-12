<?php
/**
 * @var list<array<string, mixed>> $ips
 * @var array<string, mixed> $user
 * @var string $csrf
 * @var string|null $ok
 * @var string|null $error
 */
?>

<div class="admin-grid-split">
    <div>
        <div class="admin-card">
            <div class="admin-card-title">
                <span>Endereços IP Monitorados & Bloqueados</span>
                <span style="font-size: 0.8rem; font-weight: normal; color: var(--admin-text-muted);">Tabela login_rate_limit</span>
            </div>

            <?php if (empty($ips)): ?>
                <p style="color: var(--admin-text-muted); font-size: 0.9rem;">Nenhum endereço IP com histórico de falhas ou bloqueio ativo.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Endereço IP</th>
                                <th>Falhas</th>
                                <th>Bloqueado Até</th>
                                <th>Estado</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ips as $item): ?>
                                <tr>
                                    <td>
                                        <strong class="admin-code"><?= e($item['ip'] ?? '') ?></strong>
                                    </td>
                                    <td>
                                        <span style="font-weight: 600;"><?= e((string)($item['failures'] ?? 0)) ?></span>
                                    </td>
                                    <td style="font-size: 0.8rem; color: var(--admin-text-muted);">
                                        <?= e($item['locked_until'] ? substr((string)$item['locked_until'], 0, 19) : 'Não bloqueado') ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($item['is_locked'])): ?>
                                            <span class="admin-pill admin-pill-danger">BLOQUEADO</span>
                                        <?php else: ?>
                                            <span class="admin-pill admin-pill-neutral">OBSERVADO</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" action="/admin/seguranca/desbloquear" style="display: inline;">
                                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="ip" value="<?= e($item['ip'] ?? '') ?>">
                                            <button type="submit" class="admin-btn admin-btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">
                                                Desbloquear
                                            </button>
                                        </form>
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
            <div class="admin-card-title">Bloqueio Manual de IP</div>
            <form method="post" action="/admin/seguranca/bloquear">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

                <div style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                        Endereço IP (IPv4 ou IPv6)
                    </label>
                    <input type="text" name="ip" placeholder="Ex: 198.51.100.25" required style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                </div>

                <div style="margin-bottom: 1.25rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                        Duração da Suspensão
                    </label>
                    <select name="minutes" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                        <option value="15">15 minutos</option>
                        <option value="60" selected>1 hora</option>
                        <option value="1440">24 horas (1 dia)</option>
                        <option value="10080">7 dias (1 semana)</option>
                        <option value="43200">30 dias (1 mês)</option>
                        <option value="525600">1 ano (permanente)</option>
                    </select>
                </div>

                <button type="submit" class="admin-btn admin-btn-primary" style="width: 100%; justify-content: center; background: #dc2626;">
                    ⛔ Aplicar Bloqueio Imediato
                </button>
            </form>
        </div>

        <div class="admin-card">
            <div class="admin-card-title">Políticas Ativas</div>
            <ul style="font-size: 0.825rem; color: var(--admin-text-muted); padding-left: 1.25rem; margin: 0; line-height: 1.6;">
                <li>Endereços com falhas sucessivas recebem bloqueio automático temporário.</li>
                <li>Desbloquear um IP zera seu contador de falhas e limpa o tempo de retenção.</li>
                <li>Tentativas em IPs bloqueados são abortadas antes de consultar a tabela de usuários.</li>
            </ul>
        </div>
    </div>
</div>

<?php
/**
 * @var array<string, mixed> $settings
 * @var array<string, mixed> $user
 * @var string $csrf
 * @var string|null $ok
 * @var string|null $error
 */
?>

<div class="admin-card">
    <div class="admin-card-title">
        <span>Parâmetros Técnicos da Plataforma</span>
        <span style="font-size: 0.8rem; font-weight: normal; color: var(--admin-text-muted);">Modificações aplicadas em tempo de execução via matriz de configuração</span>
    </div>

    <form method="post" action="/admin/configuracoes">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <h3 style="font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-text-muted); border-bottom: 1px solid var(--admin-border); padding-bottom: 0.5rem; margin-top: 1.5rem;">
            🛡️ Segurança & Autenticação
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Tentativas Máximas por IP
                </label>
                <input type="number" name="login_ip_max_failures" value="<?= e((string)($settings['login_ip_max_failures'] ?? 5)) ?>" min="1" max="100" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Bloqueia novas tentativas do IP após N falhas seguidas.</span>
            </div>

            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Duração do Bloqueio de IP (minutos)
                </label>
                <input type="number" name="login_ip_lock_minutes" value="<?= e((string)($settings['login_ip_lock_minutes'] ?? 15)) ?>" min="1" max="1440" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Tempo em minutos que o endereço IP permanece suspenso.</span>
            </div>

            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Tempo de Sessão do Usuário (minutos)
                </label>
                <input type="number" name="session_minutes" value="<?= e((string)($settings['session_minutes'] ?? 60)) ?>" min="5" max="10080" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Inatividade máxima antes de expirar a sessão autenticada.</span>
            </div>

            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Tamanho Mínimo da Senha
                </label>
                <input type="number" name="password_min_length" value="<?= e((string)($settings['password_min_length'] ?? 8)) ?>" min="6" max="64" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Mínimo de caracteres exigidos em novos cadastros.</span>
            </div>
        </div>

        <h3 style="font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-text-muted); border-bottom: 1px solid var(--admin-border); padding-bottom: 0.5rem; margin-top: 1.5rem;">
            📅 Sincronização de Calendários (iCal)
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Intervalo de Sincronização (minutos)
                </label>
                <input type="number" name="calendar_sync_interval_min" value="<?= e((string)($settings['calendar_sync_interval_min'] ?? 30)) ?>" min="5" max="1440" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Tempo entre sincronizações automáticas periódicas.</span>
            </div>

            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Tolerância de Falhas Consecutivas
                </label>
                <input type="number" name="calendar_sync_fail_stop" value="<?= e((string)($settings['calendar_sync_fail_stop'] ?? 16)) ?>" min="1" max="100" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Desativa o feed após N erros consecutivos.</span>
            </div>

            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Timeout de Conexão iCal (segundos)
                </label>
                <input type="number" name="calendar_fetch_timeout_sec" value="<?= e((string)($settings['calendar_fetch_timeout_sec'] ?? 20)) ?>" min="3" max="60" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Tempo limite para download de URLs externas de feed.</span>
            </div>

            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Janela de Calendário Global (meses)
                </label>
                <input type="number" name="calendar_months" value="<?= e((string)($settings['calendar_months'] ?? 12)) ?>" min="1" max="36" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Limite máximo de dias gerados para o futuro.</span>
            </div>
        </div>

        <h3 style="font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-text-muted); border-bottom: 1px solid var(--admin-border); padding-bottom: 0.5rem; margin-top: 1.5rem;">
            🖼️ Imagens, WebP & Cotações
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Qualidade de Compressão WebP (50 a 95)
                </label>
                <input type="number" name="photo_webp_quality" value="<?= e((string)($settings['photo_webp_quality'] ?? 80)) ?>" min="50" max="95" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Equilíbrio entre fidelidade visual e tamanho de arquivo.</span>
            </div>

            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Máximo de Fotos por Imóvel
                </label>
                <input type="number" name="max_photos_per_property" value="<?= e((string)($settings['max_photos_per_property'] ?? 40)) ?>" min="1" max="100" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Teto permitido na galeria de cada propriedade.</span>
            </div>

            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Retenção de Cotação Pendente (minutos)
                </label>
                <input type="number" name="quote_hold_minutes" value="<?= e((string)($settings['quote_hold_minutes'] ?? 30)) ?>" min="5" max="1440" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Minutos de bloqueio temporário das datas durante o pagamento.</span>
            </div>

            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Resultados por Página na Busca
                </label>
                <input type="number" name="results_per_page" value="<?= e((string)($settings['results_per_page'] ?? 20)) ?>" min="5" max="100" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Paginação da listagem pública.</span>
            </div>
        </div>

        <h3 style="font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-text-muted); border-bottom: 1px solid var(--admin-border); padding-bottom: 0.5rem; margin-top: 1.5rem;">
            ⚙️ Operações & Manutenção
        </h3>
        <div style="margin-bottom: 1.5rem;">
            <label style="display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; font-weight: 500; cursor: pointer;">
                <input type="checkbox" name="maintenance_mode" value="1" <?= !empty($settings['maintenance_mode']) ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                <span>Ativar Modo de Manutenção (acesso público suspenso para manutenção programada)</span>
            </label>
        </div>

        <div style="border-top: 1px solid var(--admin-border); padding-top: 1.25rem; display: flex; justify-content: flex-end;">
            <button type="submit" class="admin-btn admin-btn-primary" style="padding: 0.65rem 1.5rem;">
                Salvar Todas as Configurações
            </button>
        </div>
    </form>
</div>

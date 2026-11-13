<?php
$properties = $properties ?? [];
$quotes_count = $quotes_count ?? 0;
$pending_count = $pending_count ?? 0;
$use_api = $use_api ?? false;
$csrf = $csrf ?? '';
?>
<section class="dash-page">
    <!-- Header Hero -->
    <div class="dash-header">
        <div class="dash-header-top">
            <div class="dash-header-info">
                <span class="dash-badge-eyebrow">PORTAL DO ANFITRIÃO</span>
                <h1 class="dash-header-title"><?= e(t('dash.title')) ?></h1>
                <p class="dash-header-desc">
                    Gerencie seus imóveis anunciados, controle calendários de reservas, acesse links de pagamento Pix e configure suas propriedades com rapidez e elegância.
                </p>
            </div>
            <div class="dash-header-actions">
                <a class="btn-dash-primary" href="/imoveis/novo">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span><?= e(t('dash.add')) ?></span>
                </a>
                <a class="btn-dash-secondary" href="/reservas">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span>Reservas &amp; Pix</span>
                    <?php if (!empty($pending_count)): ?>
                        <span class="dash-pill-pending"><?= (int) $pending_count ?></span>
                    <?php endif; ?>
                </a>
                <a class="btn-dash-ghost" href="/conta">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span><?= e(t('dash.edit_profile')) ?></span>
                </a>
                <a class="btn-dash-ghost" href="/grupos">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span>Grupos</span>
                </a>
            </div>
        </div>

        <!-- Barra de Métricas / KPIs -->
        <div class="dash-stats-bar">
            <div class="dash-stat-item">
                <span class="dash-stat-icon" aria-hidden="true">🏡</span>
                <div class="dash-stat-data">
                    <span class="dash-stat-num" id="stat-props-count"><?= (int) count($properties) ?></span>
                    <span class="dash-stat-label">Imóveis Ativos</span>
                </div>
            </div>
            <a class="dash-stat-item is-clickable" href="/reservas">
                <span class="dash-stat-icon" aria-hidden="true">📅</span>
                <div class="dash-stat-data">
                    <span class="dash-stat-num"><?= (int) $quotes_count ?></span>
                    <span class="dash-stat-label">Reservas / Cotações</span>
                </div>
            </a>
            <div class="dash-stat-item">
                <span class="dash-stat-icon" aria-hidden="true">⚡</span>
                <div class="dash-stat-data">
                    <span class="dash-stat-num" style="color: #059669; font-size: 1.15rem;">Pix Direto</span>
                    <span class="dash-stat-label">Sem taxas nem intermediários</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Lista de Imóveis -->
    <div id="painel-list">
        <?php if (empty($use_api)): ?>
            <?php if (empty($properties)): ?>
                <div class="dash-empty-box">
                    <div class="dash-empty-icon" aria-hidden="true">🏡</div>
                    <h2 class="dash-empty-title"><?= e(t('dash.empty')) ?></h2>
                    <p class="dash-empty-desc">
                        Cadastre sua primeira acomodação para começar a receber reservas diretas via Pix e sincronizar com plataformas externas.
                    </p>
                    <a class="btn-dash-primary" href="/imoveis/novo">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <span><?= e(t('dash.add')) ?></span>
                    </a>
                </div>
            <?php else: ?>
                <div class="dash-grid">
                    <?php foreach ($properties as $item): ?>
                        <?php
                        $ref = property_ref($item);
                        $photoSrc = !empty($item['photo']) ? photo_url($item['photo']) : photo_url(null);
                        $price = (int) ($item['nightly_price'] ?? 0);
                        $guests = (int) ($item['max_guests'] ?? 0);
                        $city = (string) ($item['city'] ?? '');
                        $title = (string) ($item['title'] ?? '');
                        ?>
                        <article class="dash-card">
                            <div class="dash-card-media">
                                <img src="<?= e($photoSrc) ?>" alt="Foto de <?= e($title) ?>" loading="lazy">
                                <span class="dash-card-badge">✓ Ativo</span>
                                <?php if ($price > 0): ?>
                                    <span class="dash-card-price">R$ <?= number_format($price, 0, ',', '.') ?> <small>/ noite</small></span>
                                <?php endif; ?>
                            </div>
                            <div class="dash-card-body">
                                <h2 class="dash-card-title">
                                    <a href="/imoveis/<?= e($ref) ?>"><?= e($title) ?></a>
                                </h2>
                                <p class="dash-card-meta">
                                    <span>📍 <?= e($city) ?></span>
                                    <?php if ($guests > 0): ?>
                                        <span>· 👥 <?= $guests ?> hóspedes</span>
                                    <?php endif; ?>
                                </p>
                                <?php if (!empty($item['created_at'])): ?>
                                    <p class="dash-card-date">
                                        <?= e(t('dash.registered')) ?> <?= e(format_date((string) $item['created_at'])) ?>
                                    </p>
                                <?php endif; ?>

                                <div class="dash-card-actions">
                                    <a class="btn-dash-action is-cal" href="/imoveis/<?= e($ref) ?>/calendario">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                        <span><?= e(t('dash.calendar')) ?></span>
                                    </a>
                                    <a class="btn-dash-action is-edit" href="/imoveis/<?= e($ref) ?>/editar">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        <span><?= e(t('dash.edit')) ?></span>
                                    </a>
                                    <a class="btn-dash-action is-view" href="/imoveis/<?= e($ref) ?>" title="Ver anúncio público">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <span>Ver</span>
                                    </a>
                                    <form method="post" action="/imoveis/<?= e($ref) ?>/excluir" data-confirm-submit="<?= e(t('dash.remove_confirm')) ?>" style="margin: 0;">
                                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                        <button class="btn-dash-action is-delete" type="submit" title="<?= e(t('dash.remove')) ?>">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div id="painel-loading" class="dash-loading">
                <div class="dash-loading-spinner" aria-hidden="true"></div>
                <p class="dash-loading-text">Carregando seus imóveis…</p>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php if (!empty($use_api)): ?>
<?php page_data([
    'confirm' => t('dash.remove_confirm'),
    'empty' => t('dash.empty'),
    'edit' => t('dash.edit'),
    'calendar' => t('dash.calendar'),
    'remove' => t('dash.remove'),
    'view' => 'Ver',
    'add' => t('dash.add'),
    'active' => '✓ Ativo',
    'per_night' => '/ noite',
    'guests' => 'hóspedes',
]); ?>
<script src="<?= e(asset_url('/assets/pages/dashboard.js')) ?>"></script>
<?php endif; ?>

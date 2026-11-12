<?php
$quotes = $quotes ?? [];
$photos = $photos ?? [];
$filter = $filter ?? null;
$counts = $counts ?? [
    'total' => count($quotes),
    'pending' => count(array_filter($quotes, fn($q) => ($q['status'] ?? '') !== 'confirmed')),
    'confirmed' => count(array_filter($quotes, fn($q) => ($q['status'] ?? '') === 'confirmed')),
];
$confirmed = $confirmed ?? false;
$error = $error ?? null;
?>
<section class="res-page">
    <!-- Header Hero -->
    <div class="res-header">
        <a class="res-back-link" href="/painel">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
            <span>Voltar ao Painel</span>
        </a>
        <h1 class="res-header-title">Reservas &amp; Confirmação Pix</h1>
        <p class="res-header-desc">
            Painel exclusivo do anfitrião para conferência de depósitos Pix recebidos, liberação imediata de datas na agenda e sincronização automática com plataformas externas via iCal.
        </p>

        <!-- Filtros em Pills -->
        <nav class="res-filter-tabs" aria-label="Filtro de reservas">
            <a class="res-filter-pill<?= $filter === null ? ' is-active' : '' ?>" href="/reservas">
                <span>Todas</span>
                <span class="res-pill-count"><?= (int) ($counts['total'] ?? 0) ?></span>
            </a>
            <a class="res-filter-pill<?= $filter === 'pending' ? ' is-active' : '' ?>" href="/reservas?status=pending">
                <span>⏳ Pendentes de Pix</span>
                <span class="res-pill-count"><?= (int) ($counts['pending'] ?? 0) ?></span>
            </a>
            <a class="res-filter-pill<?= $filter === 'confirmed' ? ' is-active' : '' ?>" href="/reservas?status=confirmed">
                <span>✓ Confirmadas</span>
                <span class="res-pill-count"><?= (int) ($counts['confirmed'] ?? 0) ?></span>
            </a>
        </nav>
    </div>

    <!-- Alerta de Sucesso -->
    <?php if ($confirmed): ?>
        <div class="res-alert-success" role="alert">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11.5 14.5 15.5 9.5"/></svg>
            <div>
                <strong>✓ Pagamento Pix Confirmado com Sucesso!</strong>
                <p style="margin: 0.25rem 0 0; opacity: 0.9;">
                    A reserva foi confirmada oficialmente. As datas foram bloqueadas na sua agenda do CasaLivre e o feed iCal oficial foi atualizado para sincronização externa.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Alerta de Erro -->
    <?php if (!empty($error)): ?>
        <div class="res-alert-error" role="alert">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div>
                <strong>Atenção:</strong> <?= e($error) ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Lista de Cards ou Estado Vazio -->
    <?php if (empty($quotes)): ?>
        <div class="res-empty-box">
            <div class="res-empty-icon" aria-hidden="true">📅</div>
            <h2 class="res-empty-title"><?= e(t('res.empty')) ?></h2>
            <p class="res-empty-desc">
                <?php if ($filter === 'pending'): ?>
                    Não há nenhuma reserva aguardando depósito Pix no momento.
                <?php elseif ($filter === 'confirmed'): ?>
                    Nenhuma reserva confirmada encontrada neste filtro.
                <?php else: ?>
                    Você ainda não possui cotações ou reservas registradas. Gere um link de pré-reserva direto pelo calendário de qualquer imóvel.
                <?php endif; ?>
            </p>
            <a class="button primary" href="/painel">Ver Meus Imóveis ↗</a>
        </div>
    <?php else: ?>
        <div class="res-card-list">
            <?php foreach ($quotes as $q): ?>
                <?php
                $isConfirmed = ($q['status'] ?? '') === 'confirmed';
                $isExpired = !$isConfirmed && strtotime((string) $q['expires_at']) < time();
                $propId = (int) ($q['property_id'] ?? 0);
                $propPhoto = $photos[$propId] ?? null;
                $propTitle = (string) ($q['property_title'] ?? 'Imóvel');
                $propHash = (string) ($q['property_hash'] ?? '');
                $quoteHash = (string) ($q['quote_hash'] ?? '');
                $nights = (int) ($q['nights'] ?? 1);
                $nightlyPrice = (int) ($q['nightly_price'] ?? 0);
                $cleaningFee = (int) ($q['cleaning_fee'] ?? 0);
                $taxes = (int) ($q['taxes'] ?? 0);
                $specialOffer = (isset($q['special_offer']) && $q['special_offer'] !== null) ? (int) $q['special_offer'] : null;
                $totalPrice = (int) ($q['total'] ?? $q['total_price'] ?? 0);
                $pixKey = (string) ($q['pix_key'] ?? '');

                // WhatsApp URL
                $appUrl = app_url();
                $paymentUrl = $appUrl . '/pagamento/' . $quoteHash;
                $checkinFmt = format_date((string) $q['checkin']);
                $checkoutFmt = format_date((string) $q['checkout']);
                $totalFmt = money($totalPrice);
                $waText = urlencode("Olá! Segue o link exclusivo para confirmação e pagamento da sua reserva no CasaLivre ($propTitle):\n$paymentUrl\n\nPeríodo: $checkinFmt a $checkoutFmt ($nights noites)\nValor total: $totalFmt (Chave Pix: $pixKey)");
                $waUrl = "https://wa.me/?text=" . $waText;
                ?>
                <article class="res-card">
                    <!-- Top Ribbon -->
                    <div class="res-card-head">
                        <div class="res-prop-info">
                            <?php if (!empty($propPhoto)): ?>
                                <img class="res-prop-thumb" src="<?= e($propPhoto) ?>" alt="<?= e($propTitle) ?>" loading="lazy">
                            <?php else: ?>
                                <div class="res-prop-thumb-fallback" aria-hidden="true">🏡</div>
                            <?php endif; ?>
                            <div>
                                <h2 class="res-prop-title">
                                    <a href="/imoveis/<?= e($propHash) ?>"><?= e($propTitle) ?> ↗</a>
                                </h2>
                                <div class="res-prop-code">Imóvel: #<?= e($propHash) ?> · Cotação: CL-<?= strtoupper(substr($quoteHash, 0, 8)) ?></div>
                            </div>
                        </div>

                        <!-- Status Badge -->
                        <div>
                            <?php if ($isConfirmed): ?>
                                <span class="res-status-badge is-confirmed">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                                    <span>✓ <?= e(t('res.confirmed')) ?> &amp; Sincronizada</span>
                                </span>
                            <?php elseif ($isExpired): ?>
                                <span class="res-status-badge is-expired">
                                    <span>⌛ Retenção Expirada (Datas Liberadas)</span>
                                </span>
                            <?php else: ?>
                                <span class="res-status-badge is-pending">
                                    <span class="res-pulse-dot" aria-hidden="true"></span>
                                    <span>⏳ Aguardando Depósito Pix (Retenção 30m)</span>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Main Dates & Price Grid -->
                    <div class="res-card-body">
                        <!-- Dates -->
                        <div class="res-dates">
                            <div class="res-date-col">
                                <span class="res-date-label">Check-in</span>
                                <span class="res-date-val"><?= e(format_date((string) $q['checkin'])) ?></span>
                            </div>
                            <div class="res-date-arrow" aria-hidden="true">➔</div>
                            <div class="res-date-col">
                                <span class="res-date-label">Check-out</span>
                                <span class="res-date-val"><?= e(format_date((string) $q['checkout'])) ?></span>
                            </div>
                            <span class="res-nights-pill"><?= $nights ?> <?= $nights === 1 ? 'noite' : 'noites' ?></span>
                        </div>

                        <!-- Price Box -->
                        <div class="res-price">
                            <span class="res-price-label">Valor Total da Reserva</span>
                            <span class="res-price-val"><?= money($totalPrice) ?></span>
                            <div class="res-breakdown-tags">
                                <span class="res-tag">Diária: <?= money($nightlyPrice) ?></span>
                                <span class="res-tag">Limpeza: <?= money($cleaningFee) ?></span>
                                <?php if ($taxes > 0): ?>
                                    <span class="res-tag">Taxas: <?= money($taxes) ?></span>
                                <?php endif; ?>
                                <?php if ($specialOffer !== null): ?>
                                    <span class="res-tag">Oferta: <?= money($specialOffer) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Metadata Bar -->
                    <div class="res-card-meta">
                        <div><strong>Chave Pix:</strong> <?= e($pixKey) ?></div>
                        <div><strong>Código Completo:</strong> <?= e($quoteHash) ?></div>
                        <div><strong>Criado em:</strong> <?= !empty($q['created_at']) ? e(format_datetime((string) $q['created_at'])) : date('d/m/Y H:i') ?></div>
                    </div>

                    <!-- Actions Bar -->
                    <div class="res-card-actions">
                        <?php if (!$isConfirmed): ?>
                            <form method="post" action="/reservas/<?= e($quoteHash) ?>/confirmar" style="margin: 0;">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <button type="submit" class="btn-res-confirm">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                                    <span>✓ <?= e(t('res.confirm')) ?> Recebido</span>
                                </button>
                            </form>
                            <a class="btn-res-link" href="/pagamento/<?= e($quoteHash) ?>" target="_blank" rel="noopener">
                                <span>Abrir Link do Hóspede ↗</span>
                            </a>
                            <a class="btn-res-whatsapp" href="<?= e($waUrl) ?>" target="_blank" rel="noopener">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766 0-3.18-2.587-5.771-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.299.045-.677.063-1.092-.069-.252-.08-.575-.187-.988-.365-1.739-.751-2.874-2.502-2.961-2.617-.087-.116-.708-.94-.708-1.793s.448-1.273.607-1.446c.159-.173.346-.217.462-.217l.332.007c.106.005.249-.04.39.298.144.347.491 1.2.534 1.287.043.087.072.188.014.304-.058.116-.087.188-.173.289l-.26.304c-.087.086-.177.18-.076.354.101.174.449.741.964 1.201.662.591 1.221.774 1.394.86.173.086.275.072.376-.044.101-.116.433-.506.549-.68.116-.173.231-.145.39-.086s1.011.477 1.184.564.289.13.332.202c.045.072.045.419-.099.824z"/></svg>
                                <span>Enviar no WhatsApp</span>
                            </a>
                        <?php else: ?>
                            <a class="btn-res-calendar" href="/imoveis/<?= e($propHash) ?>/calendario">
                                <span>📅 Ver Calendário do Imóvel ↗</span>
                            </a>
                            <a class="btn-res-voucher" href="/pagamento/<?= e($quoteHash) ?>" target="_blank" rel="noopener">
                                <span>📄 Ver Comprovante do Hóspede ↗</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

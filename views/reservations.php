<section class="wrap">
    <p><a href="/painel">← Voltar ao Painel</a></p>
    <div class="toolbar">
        <div>
            <h1><?= e(t('res.title')) ?></h1>
            <p class="muted">Verifique o recebimento do depósito Pix no seu extrato bancário e confirme a reserva para bloquear as datas no CasaLivre e sincronizar com o Airbnb via iCal.</p>
        </div>
        <div class="actions">
            <a class="button<?= $filter === null ? ' primary' : '' ?>" href="/reservas">Todas</a>
            <a class="button<?= $filter === 'pending' ? ' primary' : '' ?>" href="/reservas?status=pending">Pendentes de Pix</a>
            <a class="button<?= $filter === 'confirmed' ? ' primary' : '' ?>" href="/reservas?status=confirmed">Confirmadas</a>
        </div>
    </div>

    <?php if ($confirmed): ?>
        <div class="panel notice mt-16">
            <p><strong>✓ Reserva confirmada com sucesso!</strong> O pagamento foi registrado, as datas foram bloqueadas na agenda e o feed iCal foi atualizado para sincronização externa.</p>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <p class="alert error mt-16"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if (empty($quotes)): ?>
        <div class="panel mt-16">
            <p class="muted"><?= e(t('res.empty')) ?></p>
        </div>
    <?php else: ?>
        <div class="grid mt-16">
            <?php foreach ($quotes as $q): ?>
                <?php
                $isConfirmed = ($q['status'] ?? '') === 'confirmed';
                $isExpired = !$isConfirmed && strtotime((string) $q['expires_at']) < time();
                ?>
                <article class="card">
                    <div class="body">
                        <div class="toolbar">
                            <h2><?= e($q['property_title'] ?? 'Imóvel') ?></h2>
                            <?php if ($isConfirmed): ?>
                                <span class="chip is-confirmed">✓ <?= e(t('res.confirmed')) ?></span>
                            <?php elseif ($isExpired): ?>
                                <span class="chip is-pending muted">⏳ Retenção Expirada</span>
                            <?php else: ?>
                                <span class="chip is-pending">⏳ Aguardando Depósito Pix</span>
                            <?php endif; ?>
                        </div>

                        <p class="mt-8">
                            <strong>Período:</strong> <?= e(format_date((string) $q['checkin'])) ?> a <?= e(format_date((string) $q['checkout'])) ?> (<?= (int) $q['nights'] ?> noites)
                        </p>
                        <p>
                            <strong>Valor Total:</strong> <?= money((int) $q['total_price']) ?>
                            <span class="muted">(Diária: <?= money((int) $q['nightly_price']) ?> · Limpeza: <?= money((int) $q['cleaning_fee']) ?><?php if (!empty($q['taxes'])): ?> · Taxas: <?= money((int) $q['taxes']) ?><?php endif; ?><?php if (!empty($q['special_offer'])): ?> · Oferta: <?= money((int) $q['special_offer']) ?><?php endif; ?>)</span>
                        </p>
                        <p class="muted">
                            <strong>Chave Pix:</strong> <?= e($q['pix_key']) ?> · <strong>Código:</strong> <?= e($q['quote_hash']) ?> · <strong>Criado em:</strong> <?= e(format_datetime((string) $q['created_at'])) ?>
                        </p>

                        <div class="cover-actions mt-16">
                            <?php if (!$isConfirmed): ?>
                                <form method="post" action="/reservas/<?= e($q['quote_hash']) ?>/confirmar">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <button type="submit" class="primary">✓ <?= e(t('res.confirm')) ?></button>
                                </form>
                                <a class="button" href="/pagamento/<?= e($q['quote_hash']) ?>" target="_blank">Abrir Link do Hóspede ↗</a>
                            <?php else: ?>
                                <a class="button" href="/imoveis/<?= e($q['property_hash']) ?>/calendario">Ver Calendário do Imóvel ↗</a>
                                <a class="button" href="/pagamento/<?= e($q['quote_hash']) ?>" target="_blank">Comprovante do Hóspede ↗</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

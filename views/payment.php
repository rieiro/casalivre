<section class="wrap payment-wrap">
    <?php if (!empty($confirmed)): ?>
        <div class="panel notice">
            <h1>✓ Pagamento Confirmado e Reserva Garantida!</h1>
            <p>Sua estadia no <strong><?= e($property["title"]) ?></strong> está confirmada com sucesso.</p>
            <p><strong>Período:</strong> <?= e(format_date((string) $quote["checkin"])) ?> até <?= e(format_date((string) $quote["checkout"])) ?> (<?= (int) $quote["nights"] ?> noites)</p>
            <p>As datas já foram bloqueadas no calendário do CasaLivre e sincronizadas via iCal para garantir que o imóvel não seja reservado em nenhuma outra plataforma.</p>
            <div class="mt-16">
                <a class="button primary" href="<?= e($whatsapp_confirm_url) ?>" target="_blank">Conversar com o Anfitrião no WhatsApp ↗</a>
                <a class="button" href="/imoveis/<?= e(property_ref($property)) ?>">Ver Detalhes do Imóvel</a>
            </div>
        </div>
    <?php else: ?>
        <div class="toolbar">
            <h1>Pré-Reserva Exclusiva</h1>
            <div class="timer-box">
                <span class="muted">Tempo de reserva: </span>
                <strong id="hold-timer" data-expires="<?= strtotime((string) $quote["expires_at"]) ?>">30:00</strong>
            </div>
        </div>
        <p class="muted">Você está a um passo de garantir sua hospedagem em <?= e($property["city"]) ?>.</p>

        <div class="grid mt-16">
            <article class="card">
                <?php if (!empty($property["photo"])): ?>
                    <img src="<?= e(photo_url($property["photo"])) ?>" alt="Foto de <?= e($property["title"]) ?>" loading="lazy">
                <?php else: ?>
                    <img src="<?= e(photo_url(null)) ?>" alt="Sem foto" loading="lazy">
                <?php endif; ?>
                <div class="body">
                    <h2><?= e($property["title"]) ?></h2>
                    <p class="muted"><?= e($property["address"]) ?> · <?= e($property["city"]) ?></p>
                    <p><?= (int) $property["max_guests"] ?> hóspedes · <?= (int) $property["bedrooms"] ?> quartos · <?= (int) $property["beds"] ?> camas</p>
                    <p class="muted">Check-in a partir das <?= e(format_time((string) $property["checkin_time"])) ?> · Check-out até <?= e(format_time((string) $property["checkout_time"])) ?></p>
                </div>
            </article>

            <div class="panel">
                <h2>Resumo da Estadia</h2>
                <div class="breakdown mt-12">
                    <p><strong>Check-in:</strong> <?= e(format_date((string) $quote["checkin"])) ?></p>
                    <p><strong>Check-out:</strong> <?= e(format_date((string) $quote["checkout"])) ?></p>
                    <p><strong>Duração:</strong> <?= (int) $quote["nights"] ?> <?= (int) $quote["nights"] === 1 ? "noite" : "noites" ?></p>
                    <hr>
                    <p>Subtotal diárias: <?= money((int) $quote["subtotal"]) ?></p>
                    <p>Taxa de limpeza: <?= money((int) $quote["cleaning"]) ?></p>
                    <?php if (!empty($quote["taxes"])): ?>
                        <p>Taxas: <?= money((int) $quote["taxes"]) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($quote["special_offer"])): ?>
                        <p class="notice">Oferta Especial: <?= money((int) $quote["special_offer"]) ?> aplicado</p>
                    <?php endif; ?>
                    <p class="price mt-12"><strong>Total a pagar:</strong> <?= money((int) $quote["total"]) ?></p>
                </div>

                <div class="pix-section mt-16">
                    <h3>Pagamento via Pix</h3>
                    <p class="muted">Pague pelo app do seu banco com o Pix Copia e Cola:</p>
                    
                    <label>Chave Pix: <strong><?= e($quote["pix_key"]) ?></strong> (Rafael Rieiro)</label>
                    
                    <div class="mt-8">
                        <textarea id="pix-payload" rows="3" readonly><?= e($pix_payload) ?></textarea>
                    </div>

                    <div class="cover-actions mt-8">
                        <button type="button" class="primary" id="btn-copy-pix">📋 Copiar Código Pix</button>
                    </div>
                    <p class="muted mt-8" id="pix-copy-status"></p>

                    <div class="mt-16">
                        <a class="button primary btn-whatsapp" href="<?= e($whatsapp_confirm_url) ?>" target="_blank">
                            📲 Confirmar Pagamento no WhatsApp
                        </a>
                    </div>

                    <form class="mt-16" method="post" action="/pagamento/<?= e($quote["quote_hash"]) ?>/confirmar">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button type="submit" class="button">✓ Já realizei o pagamento (Garantir Reserva)</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
<script src="<?= e(asset_url('/assets/pages/payment.js')) ?>"></script>

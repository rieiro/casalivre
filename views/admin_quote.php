<section class="wrap">
    <p><a href="/admin">← Voltar ao Painel Admin</a></p>
    <h1>Gerar Link de Pagamento Imediato</h1>
    <p class="muted"><strong>Imóvel:</strong> <?= e($property["title"]) ?> (<?= e($property["city"]) ?>)</p>

    <?php if (!empty($error)): ?>
        <p class="alert error"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if (!empty($quote)): ?>
        <div class="panel notice mt-16">
            <h2>✓ Link Gerado com Sucesso!</h2>
            <p>O link abaixo é exclusivo para esta reserva e fica retido por 30 minutos.</p>
            
            <div class="mt-12">
                <label>Link de Pagamento
                    <input type="text" id="quote-link" readonly value="<?= e($payment_url) ?>">
                </label>
            </div>

            <div class="mt-12">
                <p><strong>Período:</strong> <?= e($quote["checkin"]) ?> a <?= e($quote["checkout"]) ?> (<?= (int) $quote["nights"] ?> noites)</p>
                <p><strong>Subtotal Diárias:</strong> <?= money((int) $quote["subtotal"]) ?></p>
                <p><strong>Taxa de Limpeza:</strong> <?= money((int) $quote["cleaning"]) ?></p>
                <?php if (!empty($quote["taxes"])): ?>
                    <p><strong>Taxas/Impostos:</strong> <?= money((int) $quote["taxes"]) ?></p>
                <?php endif; ?>
                <?php if (!empty($quote["special_offer"])): ?>
                    <p><strong>Oferta Especial Aplicada:</strong> <?= money((int) $quote["special_offer"]) ?> (substituiu o cálculo padrão)</p>
                <?php endif; ?>
                <p class="price"><strong>Total da Reserva:</strong> <?= money((int) $quote["total"]) ?></p>
            </div>

            <div class="cover-actions mt-16">
                <button type="button" class="primary" id="btn-copy-wa" data-msg="<?= e($whatsapp_msg) ?>">📲 Copiar Mensagem para WhatsApp</button>
                <a href="<?= e($payment_url) ?>" target="_blank" class="button">Abrir Página do Hóspede ↗</a>
            </div>
            <p class="muted mt-12" id="copy-status"></p>
        </div>
    <?php endif; ?>

    <form class="panel mt-16" method="post" action="/admin/imoveis/<?= e(property_ref($property)) ?>/cotacao">
        <h2>Parâmetros da Estadia</h2>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        
        <div class="grid">
            <label>Data de Check-in
                <input type="date" name="checkin" required value="<?= e($posted["checkin"] ?? date("Y-m-d", strtotime("+1 day"))) ?>" min="<?= date("Y-m-d") ?>">
            </label>
            <label>Data de Check-out
                <input type="date" name="checkout" required value="<?= e($posted["checkout"] ?? date("Y-m-d", strtotime("+3 day"))) ?>" min="<?= date("Y-m-d", strtotime("+1 day")) ?>">
            </label>
        </div>

        <div class="grid mt-12">
            <label>Taxas / Impostos (%) <span class="muted">(opcional)</span>
                <input type="number" name="tax_percent" min="0" max="100" value="<?= e($posted["tax_percent"] ?? "0") ?>">
            </label>
            <label>Preço de Oferta Especial (R$) <span class="muted">(opcional - sobrepõe todo o cálculo)</span>
                <input type="number" name="special_offer" min="1" step="1" placeholder="Ex: 500" value="<?= e($posted["special_offer"] ?? "") ?>">
            </label>
        </div>

        <div class="mt-12">
            <label>Chave Pix para Recebimento
                <input type="text" name="pix_key" required value="<?= e($posted["pix_key"] ?? "rafaelrieiro@gmail.com") ?>">
            </label>
        </div>

        <button type="submit" class="primary mt-16">⚡ Calcular e Gerar Link Compartilhável</button>
    </form>
</section>

<script src="<?= e(asset_url('/assets/pages/quote-admin.js')) ?>"></script>

<?php
/**
 * View: Payment Intent Landing Page (Architecture §6)
 *
 * Variables passed:
 *   $intent - row from booking_intents
 *   $house  - public property fields (title, city, uf)
 *   $planLabel - guest-facing description
 */
?>
<section class="wrap payment-intent-page">
    <div class="card" style="max-width: 600px; margin: 40px auto; padding: 24px;">
        <span class="badge" style="background: #e0f2fe; color: #0369a1; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; text-transform: uppercase;">
            Intenção de Reserva
        </span>

        <h1 style="font-size: 24px; margin: 16px 0 8px 0;"><?= htmlspecialchars((string) ($house['title'] ?? 'Imóvel'), ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="muted" style="color: #64748b; margin-bottom: 20px;">
            <?= htmlspecialchars((string) ($house['city'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        </p>

        <div style="border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; padding: 16px 0; margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span>Datas:</span>
                <strong><?= htmlspecialchars((string) $intent['checkin'], ENT_QUOTES, 'UTF-8') ?> até <?= htmlspecialchars((string) $intent['checkout'], ENT_QUOTES, 'UTF-8') ?> (<?= (int) $intent['nights'] ?> noites)</strong>
            </div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span>Hóspedes:</span>
                <strong><?= (int) $intent['guests'] ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span>Plano selecionado:</span>
                <strong><?= htmlspecialchars((string) $intent['plan'], ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <p style="font-size: 14px; color: #475569; margin-top: 6px;"><?= htmlspecialchars((string) $planLabel, ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <div style="background: #f8fafc; padding: 16px; border-radius: 8px; margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span>Total da reserva:</span>
                <strong>R$ <?= number_format((float) $intent['total_price'], 0, ',', '.') ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px; color: #0f766e; font-size: 18px;">
                <span>A pagar agora:</span>
                <strong>R$ <?= number_format((float) $intent['amount_due_now'], 0, ',', '.') ?></strong>
            </div>
            <?php if ((int) $intent['balance_due'] > 0): ?>
                <div style="display: flex; justify-content: space-between; color: #64748b; font-size: 14px;">
                    <span>Saldo restante:</span>
                    <span>R$ <?= number_format((float) $intent['balance_due'], 0, ',', '.') ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="alert info" style="background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 14px; border-radius: 6px; font-size: 14px;">
            Esta reserva está pré-reservada por 15 minutos. A captura final de pagamento estará disponível em breve diretamente pelo anfitrião.
        </div>

        <div style="margin-top: 24px; text-align: center;">
            <a href="/" class="btn" style="display: inline-block; padding: 10px 20px; text-decoration: none;">Voltar ao início</a>
        </div>
    </div>
</section>

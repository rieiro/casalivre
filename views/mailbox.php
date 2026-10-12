<section class="wrap">
    <h1>Caixa local</h1>
    <p class="muted">E-mails de ativação gerados pelo sistema. Clique no link para ativar a conta.</p>
    <?php foreach ($mails as $mail): ?>
        <article class="panel" style="margin-bottom:16px">
            <p class="muted">Mensagem #<?= e($mail['id']) ?></p>
            <?= $mail['html'] ?>
        </article>
    <?php endforeach; ?>
    <?php if ($mails === []): ?><p>Nenhuma mensagem.</p><?php endif; ?>
</section>

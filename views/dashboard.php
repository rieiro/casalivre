<section class="wrap">
    <div class="toolbar">
        <h1><?= e(t('dash.title')) ?></h1>
        <a class="primary" href="/imoveis/novo"><?= e(t('dash.add')) ?></a>
    </div>
    <p class="muted"><a href="/conta"><?= e(t('dash.edit_profile')) ?></a></p>
    <div id="painel-list">
        <?php if (empty($use_api)): ?>
            <?php if ($properties === []): ?><p class="muted"><?= e(t('dash.empty')) ?></p><?php endif; ?>
            <div class="grid">
                <?php foreach ($properties as $item): ?>
                    <article class="card">
                        <?php if (!empty($item['photo'])): ?>
                            <img src="<?= e(photo_url($item['photo'])) ?>" alt="Foto de <?= e($item['title']) ?>" loading="lazy">
                        <?php else: ?>
                            <img src="/assets/placeholder.svg" alt="Sem foto" loading="lazy">
                        <?php endif; ?>
                        <div class="body">
                            <h2><?= e($item['title']) ?></h2>
                            <p class="muted"><?= e($item['city']) ?> · <?= (int) $item['max_guests'] ?> hóspedes</p>
                            <p class="muted"><?= e(t('dash.registered')) ?> <?= e(format_date((string) $item['created_at'])) ?></p>
                            <p>
                                <a href="/imoveis/<?= e(property_ref($item)) ?>/editar"><?= e(t('dash.edit')) ?></a>
                                · <a href="/imoveis/<?= e(property_ref($item)) ?>/calendario"><?= e(t('dash.calendar')) ?></a>
                            </p>
                            <form method="post" action="/imoveis/<?= e(property_ref($item)) ?>/excluir" data-confirm-submit="<?= e(t('dash.remove_confirm')) ?>">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <button class="danger" type="submit"><?= e(t('dash.remove')) ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted" id="painel-loading">Carregando…</p>
        <?php endif; ?>
    </div>
</section>
<?php if (!empty($use_api)): ?>
<?php page_data(['csrf' => $csrf, 'confirm' => t('dash.remove_confirm'), 'empty' => t('dash.empty'), 'edit' => t('dash.edit'), 'calendar' => t('dash.calendar'), 'remove' => t('dash.remove')]); ?>
<script src="/assets/pages/dashboard.js?v=1"></script>
<?php endif; ?>
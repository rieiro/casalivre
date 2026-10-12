<section class="wrap">
    <div class="toolbar">
        <h1><?= e(t('dash.title')) ?></h1>
        <a class="primary" href="/imoveis/novo"><?= e(t('dash.add')) ?></a>
    </div>
    <p class="muted"><a href="/conta"><?= e(t('dash.edit_profile')) ?></a></p>
    <?php if ($properties === []): ?><p class="muted"><?= e(t('dash.empty')) ?></p><?php endif; ?>
    <div class="grid">
        <?php foreach ($properties as $item): ?>
            <article class="card">
                <?php if (!empty($item['photo'])): ?>
                    <img src="<?= e($item['photo']) ?>" alt="Foto de <?= e($item['title']) ?>">
                <?php else: ?>
                    <img src="/assets/placeholder.svg" alt="Sem foto">
                <?php endif; ?>
                <div class="body">
                    <h2><?= e($item['title']) ?></h2>
                    <p class="muted"><?= e($item['city']) ?> · <?= (int) $item['max_guests'] ?> hóspedes</p>
                    <p class="muted"><?= e(t('dash.registered')) ?> <?= e(format_date((string) $item['created_at'])) ?></p>
                    <p>
                        <a href="/imoveis/<?= e(property_ref($item)) ?>/editar"><?= e(t('dash.edit')) ?></a>
                        · <a href="/imoveis/<?= e(property_ref($item)) ?>/calendario"><?= e(t('dash.calendar')) ?></a>
                    </p>
                    <form method="post" action="/imoveis/<?= e(property_ref($item)) ?>/excluir" onsubmit="return confirm(<?= json_encode(t('dash.remove_confirm'), JSON_UNESCAPED_UNICODE) ?>)">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button class="danger" type="submit"><?= e(t('dash.remove')) ?></button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

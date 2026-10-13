<section class="wrap" id="property-detail">
    <?php if (!empty($use_api)): ?>
        <p class="muted">Carregando…</p>
    <?php else: ?>
        <p class="muted"><a href="<?= e(safe_search_return((string) ($_GET['volta'] ?? ''))) ?>"><?= e(t('home.back_search')) ?></a></p>
        <h1><?= e($property['title']) ?></h1>
        <p><?= e($property['city']) ?> · até <?= (int) $property['max_guests'] ?> hóspedes · <?= (int) $property['bedrooms'] ?> quartos · <?= (int) $property['beds'] ?> camas</p>
        <?php if ($score !== null): ?><p>Nota dos hóspedes: <?= e((string) $score) ?>/10</p><?php endif; ?>
        <div class="gallery">
            <?php foreach ($photos as $photo): ?>
                <img src="<?= e(photo_url($photo['path'])) ?>" alt="Foto de <?= e($property['title']) ?>" loading="lazy">
            <?php endforeach; ?>
        </div>
        <p><?= e($property['description']) ?></p>
        <p class="price"><?= $quote ? money((int) $quote['nightly']) . ' a diária no período (' . (int) $quote['nights'] . ' noites, total ' . money((int) $quote['total']) . ')' . (!empty($quote['cleaning']) ? ', ' . t('form.cleaning') . ' ' . money((int) $quote['cleaning']) : '') : 'Diária base ' . money((int) $property['nightly_price']) . ((int) ($property['cleaning_fee'] ?? 0) > 0 ? ', ' . t('form.cleaning') . ' ' . money((int) $property['cleaning_fee']) : '') ?></p>
        <p class="muted"><?= e(t('form.checkin')) ?> <?= e(format_time((string) $property['checkin_time'])) ?> · <?= e(t('form.checkout')) ?> <?= e(format_time((string) $property['checkout_time'])) ?> · <?= e(t('stay.min')) ?> <?= (int) $property['min_nights'] ?> <?= e(t('stay.night_one')) ?></p>
        <form class="panel" method="post" action="/imoveis/<?= e(property_ref($property)) ?>/avaliar">
            <h2>Avaliar estadia</h2>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="volta" value="<?= e(safe_search_return((string) ($_GET['volta'] ?? ''))) ?>">
            <label>Nome <input name="author" required></label>
            <label>Nota (1 a 10) <input type="number" name="score" min="1" max="10" required></label>
            <label>Comentário <textarea name="comment" rows="3"></textarea></label>
            <button class="primary" type="submit">Publicar avaliação</button>
        </form>
    <?php endif; ?>
</section>
<?php if (!empty($use_api)): ?>
<?php page_data(['hash' => $hash ?? '']); ?>
<script src="/assets/pages/property.js?v=2"></script>
<?php endif; ?>
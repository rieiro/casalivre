<?php $base = (string) ($vitrine_base ?? ''); // prefixo da vitrine (/g/<slug>) ou '' na plataforma ?>
<div class="results">
    <?php foreach ($results as $item): ?>
        <article class="result" data-property-id="<?= (int) $item['id'] ?>">
            <a href="<?= e($base) ?>/imoveis/<?= e(property_ref($item)) ?>?<?= e(http_build_query(['checkin' => $filters['checkin'] ?? '', 'checkout' => $filters['checkout'] ?? '', 'volta' => (string) ($_SERVER['REQUEST_URI'] ?? '/')])) ?>">
                <?php if (!empty($item['photo'])): ?>
                    <img src="<?= e(photo_url(photo_variant($item['photo'], 'card'))) ?>"<?= photo_srcset_attr($item['photo'], '(max-width: 600px) 100vw, 400px') ?> alt="Foto principal de <?= e($item['title']) ?>" loading="lazy">
                <?php else: ?>
                    <img src="<?= e(photo_url(null)) ?>" alt="<?= e(t('home.no_photo')) ?>" loading="lazy">
                <?php endif; ?>
            </a>
            <div class="body">
                <h2 class="card-title"><?= e($item['title']) ?></h2>
                <p class="muted"><?= e($item['city']) ?></p>
                <p class="summary muted"><?= e($item['summary']) ?></p>
                <p class="guests"><?= (int) $item['max_guests'] ?> <?= e(t('home.guests_short')) ?> · <?= (int) $item['bedrooms'] ?> <?= e(t('home.bedrooms')) ?> · <?= (int) $item['beds'] ?> <?= e(t('home.beds')) ?></p>
            </div>
            <div class="offer">
                <?php if ($item['score'] !== null): ?><p class="score-badge"><?= e(number_format((float) $item['score'], 1, current_locale() === 'en' ? '.' : ',', '')) ?></p><?php endif; ?>
                <?php if (!empty($item['quote']['nights'])): ?>
                    <p class="price"><?= money((int) $item['quote']['total']) ?></p>
                    <p class="muted"><?= (int) $item['quote']['nights'] ?> <?= e(t('stay.nights')) ?></p>
                    <?php if (!empty($item['quote']['cleaning'])): ?><p class="muted"><?= e(t('form.cleaning')) ?> <?= money((int) $item['quote']['cleaning']) ?></p><?php endif; ?>
                <?php else: ?>
                    <p class="price"><?= money((int) $item['quote']['nightly']) ?></p>
                    <p class="muted"><?= e(t('home.night')) ?></p>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</div>

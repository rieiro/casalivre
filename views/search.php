<?php
$base = (string) ($vitrine_base ?? ''); // prefixo da vitrine (/g/<slug>) ou '' na plataforma
$facets = $facets ?? [];
$query = search_query($filters);
$dateOrder = current_locale() === 'en' ? 'mdy' : 'dmy';
$checkinValue = $filters['checkin'] !== '' ? (format_date(parse_date($filters['checkin']) ?? '') ?: $filters['checkin']) : '';
$checkoutValue = $filters['checkout'] !== '' ? (format_date(parse_date($filters['checkout']) ?? '') ?: $filters['checkout']) : '';
$popular = [];
foreach (Amenities::keys() as $key) {
    $count = (int) ($facets[$key] ?? 0);
    if ($count > 0 || in_array($key, $filters['amenidades'], true)) {
        $popular[] = [$key, $count];
    }
}
usort($popular, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
$popular = array_slice($popular, 0, 10);
?>
<section class="wrap search-page">
    <form class="search search-query" method="get" action="<?= e($base) ?>/busca">
        <label class="field"><?= e(t('home.place')) ?>
            <input name="localidade" data-city-hints value="<?= e($filters['localidade']) ?>" required autocomplete="off">
        </label>
        <label class="field"><?= e(t('home.checkin')) ?>
            <input name="checkin" data-stay-date="checkin" data-date-order="<?= e($dateOrder) ?>" value="<?= e($checkinValue) ?>" autocomplete="off">
        </label>
        <label class="field"><?= e(t('home.checkout')) ?>
            <input name="checkout" data-stay-date="checkout" data-date-order="<?= e($dateOrder) ?>" value="<?= e($checkoutValue) ?>" autocomplete="off">
        </label>
        <label class="field"><?= e(t('home.guests')) ?>
            <input type="number" min="1" name="hospedes" value="<?= $filters['hospedes'] ?: '' ?>">
        </label>
        <input type="hidden" name="raio" value="<?= (int) $filters['raio'] ?>">
        <input type="hidden" name="preco_min" value="<?= (int) $filters['preco_min'] ?: '' ?>">
        <input type="hidden" name="preco_max" value="<?= (int) $filters['preco_max'] ?: '' ?>">
        <input type="hidden" name="score_min" value="<?= (float) $filters['score_min'] ?: '' ?>">
        <?php foreach ($filters['amenidades'] as $amenity): ?>
            <input type="hidden" name="amenidade[]" value="<?= e($amenity) ?>">
        <?php endforeach; ?>
        <button type="submit"><?= e(t('home.search')) ?></button>
    </form>
    <div class="search-layout">
        <div class="search-side">
            <a class="map-teaser" href="<?= e($base) ?>/busca/mapa?<?= e($query) ?>">
                <span class="map-tiles is-empty"></span>
                <span class="map-pin" aria-hidden="true"></span>
                <span class="map-open"><?= e(t('home.show_map')) ?></span>
            </a>
            <form class="filters-panel" method="get" action="<?= e($base) ?>/busca">
                <h2><?= e(t('home.filter_by')) ?></h2>
                <input type="hidden" name="localidade" value="<?= e($filters['localidade']) ?>">
                <input type="hidden" name="checkin" value="<?= e($filters['checkin']) ?>">
                <input type="hidden" name="checkout" value="<?= e($filters['checkout']) ?>">
                <input type="hidden" name="hospedes" value="<?= (int) $filters['hospedes'] ?: '' ?>">
                <label class="field"><?= e(t('home.radius')) ?>
                    <select name="raio">
                        <option value="0"><?= e(t('home.radius_city')) ?></option>
                        <?php foreach ([5, 10, 25] as $km): ?>
                            <option value="<?= $km ?>" <?= (int) $filters['raio'] === $km ? 'selected' : '' ?>><?= $km ?> km</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field"><?= e(t('home.price_min')) ?>
                    <input type="number" name="preco_min" min="0" value="<?= $filters['preco_min'] ?: '' ?>">
                </label>
                <label class="field"><?= e(t('home.price_max')) ?>
                    <input type="number" name="preco_max" min="0" value="<?= $filters['preco_max'] ?: '' ?>">
                </label>
                <label class="field"><?= e(t('home.score')) ?>
                    <select name="score_min">
                        <option value="0"><?= e(t('home.score_any')) ?></option>
                        <?php foreach ([6, 7, 8, 9] as $score): ?>
                            <option value="<?= $score ?>" <?= (float) $filters['score_min'] === (float) $score ? 'selected' : '' ?>><?= $score ?>+</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ($popular): ?>
                    <fieldset class="amenity-set">
                        <legend><?= e(t('home.popular')) ?></legend>
                        <div class="filter-list">
                            <?php foreach ($popular as [$key, $count]): ?>
                                <label>
                                    <input type="checkbox" name="amenidade[]" value="<?= e($key) ?>" <?= in_array($key, $filters['amenidades'], true) ? 'checked' : '' ?>>
                                    <span><?= e(t('amenity.' . $key)) ?></span>
                                    <b><?= (int) $count ?></b>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endif; ?>
                <button class="primary" type="submit"><?= e(t('home.search')) ?></button>
            </form>
        </div>
        <div id="search-results"<?= $base !== '' ? ' data-base="' . e($base) . '"' : '' ?><?= isset($vitrine_api) ? ' data-api="' . e($vitrine_api) . '"' : '' ?>>
            <?php if (!$searched): ?>
                <p class="muted"><?= e(t('home.empty')) ?></p>
            <?php elseif (!empty($use_api)): ?>
                <p class="muted" id="search-loading">Carregando resultados…</p>
            <?php else: ?>
                <?php
                $resultTotal = count($results);
                $perPage = max(1, class_exists('Settings') ? (Settings::int('results_per_page') ?: 20) : 20);
                $pageCount = max(1, (int) ceil($resultTotal / $perPage));
                $page = min($pageCount, max(1, (int) ($_GET['pagina'] ?? 1)));
                $results = array_slice($results, ($page - 1) * $perPage, $perPage);
                ?>
                <h1><?= e($filters['localidade'] !== '' ? $filters['localidade'] . ': ' : '') ?><?= $resultTotal ?> <?= e(t('home.found')) ?></h1>
                <?php include __DIR__ . '/result_cards.php'; ?>
                <?php if ($pageCount > 1): ?>
                    <nav class="pager" aria-label="<?= e(t('home.pages')) ?>">
                        <?php if ($page > 1): ?><a href="<?= e($base) ?>/busca?<?= e($query) ?>&pagina=<?= $page - 1 ?>"><?= e(t('home.prev_page')) ?></a><?php endif; ?>
                        <?php
                        $from = max(1, $page - 2);
                        $to = min($pageCount, $page + 2);
                        for ($i = $from; $i <= $to; $i++):
                        ?>
                            <?php if ($i === $page): ?><span class="is-on"><?= $i ?></span><?php else: ?><a href="<?= e($base) ?>/busca?<?= e($query) ?>&pagina=<?= $i ?>"><?= $i ?></a><?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $pageCount): ?><a href="<?= e($base) ?>/busca?<?= e($query) ?>&pagina=<?= $page + 1 ?>"><?= e(t('home.next_page')) ?></a><?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php if (!empty($use_api) && $searched): ?>
<script src="<?= e(asset_url('/assets/pages/search.js')) ?>"></script>
<?php endif; ?>

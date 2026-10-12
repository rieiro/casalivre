<!DOCTYPE html>
<html lang="<?= e($locale ?? 'pt-BR') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'CasaLivre') ?></title>
    <link rel="stylesheet" href="/assets/app.css?v=17">
    <link rel="stylesheet" href="/assets/studio.css?v=2">
</head>
<body class="studio">
<?php
$filters = $filters ?? [
    'localidade' => '', 'checkin' => '', 'checkout' => '', 'hospedes' => 0,
    'raio' => 0, 'preco_min' => 0, 'preco_max' => 0, 'score_min' => 0, 'amenidades' => [],
];
$order = (string) ($_GET['ordem'] ?? 'relevancia');
if (!in_array($order, ['relevancia', 'preco', 'nota'], true)) {
    $order = 'relevancia';
}
$studioOrder = $order;
$studioLink = static function (array $next) use ($studioOrder): string {
    $built = search_query($next);
    return '/busca-novo?' . $built . ($studioOrder !== 'relevancia' ? '&ordem=' . rawurlencode($studioOrder) : '');
};
$cats = [
    ['sea_view', 'Praia', 'wave'],
    ['pool', 'Piscina', 'pool'],
    [null, 'Serra', 'peak'],
    [null, 'Cidade', 'city'],
    ['garden', 'Campo', 'leaf'],
    ['pets', 'Pet friendly', 'paw'],
    ['sea_view', 'Beira-mar', 'beach'],
    [null, 'Festa ok', 'spark'],
];
$activeFilters = count($filters['amenidades']);
foreach (['raio', 'preco_min', 'preco_max', 'score_min'] as $key) {
    if ((float) ($filters[$key] ?? 0) > 0) {
        $activeFilters++;
    }
}
$sortLabel = ['relevancia' => 'Relevância', 'preco' => 'Menor diária', 'nota' => 'Melhor nota'][$studioOrder];
$guestText = (int) ($filters['hospedes'] ?? 0) > 0 ? (int) $filters['hospedes'] . ' hóspedes' : '';
?>
<header class="vm-header">
    <div class="vm-bar">
        <a class="vm-logo" href="/busca-novo">
            <span>v</span>
            <strong>viva maré<small>aluguel de temporada</small></strong>
        </a>
        <form class="vm-search" method="get" action="/busca-novo">
            <label>
                <small><?= e(t('studio.where')) ?></small>
                <input name="localidade" data-city-hints value="<?= e($filters['localidade'] ?? '') ?>" autocomplete="off" placeholder="Florianópolis, SC">
            </label>
            <label>
                <small><?= e(t('home.checkin')) ?></small>
                <input name="checkin" data-stay-date="checkin" data-date-order="<?= ($locale ?? '') === 'en' ? 'mdy' : 'dmy' ?>" value="<?= e(($filters['checkin'] ?? '') !== '' ? (format_date(parse_date($filters['checkin']) ?? '') ?: $filters['checkin']) : '') ?>" autocomplete="off" placeholder="12 dez">
            </label>
            <label>
                <small><?= e(t('home.checkout')) ?></small>
                <input name="checkout" data-stay-date="checkout" data-date-order="<?= ($locale ?? '') === 'en' ? 'mdy' : 'dmy' ?>" value="<?= e(($filters['checkout'] ?? '') !== '' ? (format_date(parse_date($filters['checkout']) ?? '') ?: $filters['checkout']) : '') ?>" autocomplete="off" placeholder="19 dez">
            </label>
            <label>
                <small><?= e(t('studio.who')) ?></small>
                <input type="number" min="1" name="hospedes" value="<?= $guestText !== '' ? (int) $filters['hospedes'] : '' ?>" placeholder="4 hóspedes">
            </label>
            <input type="hidden" name="raio" value="<?= (int) ($filters['raio'] ?? 0) ?>">
            <input type="hidden" name="preco_min" value="<?= (int) ($filters['preco_min'] ?? 0) ?: '' ?>">
            <input type="hidden" name="preco_max" value="<?= (int) ($filters['preco_max'] ?? 0) ?: '' ?>">
            <input type="hidden" name="score_min" value="<?= (float) ($filters['score_min'] ?? 0) ?: '' ?>">
            <input type="hidden" name="ordem" value="<?= e($studioOrder) ?>">
            <?php foreach (($filters['amenidades'] ?? []) as $amenity): ?>
                <input type="hidden" name="amenidade[]" value="<?= e((string) $amenity) ?>">
            <?php endforeach; ?>
            <button type="submit"><?= e(t('home.search')) ?></button>
        </form>
        <div class="vm-actions">
            <a class="vm-anunciar" href="<?= !empty($user) ? '/painel' : '/cadastro' ?>"><?= e(t(!empty($user) ? 'nav.properties' : 'nav.advertise')) ?></a>
            <?php if (!empty($user)): ?>
                <a class="vm-avatar" href="/conta"><?= e(function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr((string) $user['name'], 0, 1)) : strtoupper(substr((string) $user['name'], 0, 1))) ?></a>
            <?php else: ?>
                <a class="vm-avatar" href="/entrar">M</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="vm-row">
        <nav class="vm-cats">
            <?php foreach ($cats as [$key, $label, $icon]): ?>
                <?php
                $on = $key !== null && in_array($key, $filters['amenidades'] ?? [], true);
                if ($key === null) {
                    $href = '/busca-novo?' . search_query($filters);
                } else {
                    $next = $filters;
                    $next['amenidades'] = $on
                        ? array_values(array_diff($filters['amenidades'], [$key]))
                        : array_values(array_unique(array_merge($filters['amenidades'], [$key])));
                    $href = $studioLink($next);
                }
                ?>
                <a class="vm-cat<?= $on ? ' is-on' : '' ?>" href="<?= e($href) ?>"><?php include __DIR__ . '/studio_icon.php'; ?><span><?= e($label) ?></span></a>
            <?php endforeach; ?>
        </nav>
        <div class="vm-tools">
            <details class="vm-filter">
                <summary><span class="vm-ico"><?php $icon = 'filter'; include __DIR__ . '/studio_icon.php'; ?></span> <?= e(t('studio.filters')) ?> <?php if ($activeFilters > 0): ?><b><?= $activeFilters ?></b><?php endif; ?></summary>
                <form method="get" action="/busca-novo">
                    <input type="hidden" name="localidade" value="<?= e($filters['localidade']) ?>">
                    <input type="hidden" name="checkin" value="<?= e($filters['checkin']) ?>">
                    <input type="hidden" name="checkout" value="<?= e($filters['checkout']) ?>">
                    <input type="hidden" name="hospedes" value="<?= (int) $filters['hospedes'] ?: '' ?>">
                    <input type="hidden" name="ordem" value="<?= e($studioOrder) ?>">
                    <label><?= e(t('home.radius')) ?>
                        <select name="raio">
                            <option value="0"><?= e(t('home.radius_city')) ?></option>
                            <?php foreach ([5, 10, 25] as $km): ?>
                                <option value="<?= $km ?>" <?= (int) $filters['raio'] === $km ? 'selected' : '' ?>><?= $km ?> km</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><?= e(t('home.price_min')) ?> <input type="number" min="0" name="preco_min" value="<?= $filters['preco_min'] ?: '' ?>"></label>
                    <label><?= e(t('home.price_max')) ?> <input type="number" min="0" name="preco_max" value="<?= $filters['preco_max'] ?: '' ?>"></label>
                    <label><?= e(t('home.score')) ?>
                        <select name="score_min">
                            <option value="0"><?= e(t('home.score_any')) ?></option>
                            <?php foreach ([6, 7, 8, 9] as $score): ?>
                                <option value="<?= $score ?>" <?= (float) $filters['score_min'] === (float) $score ? 'selected' : '' ?>><?= $score ?>+</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php foreach (Amenities::keys() as $key): ?>
                        <label class="check"><input type="checkbox" name="amenidade[]" value="<?= e($key) ?>" <?= in_array($key, $filters['amenidades'], true) ? 'checked' : '' ?>> <?= e(t('amenity.' . $key)) ?></label>
                    <?php endforeach; ?>
                    <button type="submit"><?= e(t('home.search')) ?></button>
                </form>
            </details>
            <form class="vm-sort" method="get" action="/busca-novo">
                <input type="hidden" name="localidade" value="<?= e($filters['localidade']) ?>">
                <input type="hidden" name="checkin" value="<?= e($filters['checkin']) ?>">
                <input type="hidden" name="checkout" value="<?= e($filters['checkout']) ?>">
                <input type="hidden" name="hospedes" value="<?= (int) $filters['hospedes'] ?: '' ?>">
                <input type="hidden" name="raio" value="<?= (int) $filters['raio'] ?>">
                <input type="hidden" name="preco_min" value="<?= (int) $filters['preco_min'] ?: '' ?>">
                <input type="hidden" name="preco_max" value="<?= (int) $filters['preco_max'] ?: '' ?>">
                <input type="hidden" name="score_min" value="<?= (float) $filters['score_min'] ?: '' ?>">
                <?php foreach ($filters['amenidades'] as $amenity): ?>
                    <input type="hidden" name="amenidade[]" value="<?= e((string) $amenity) ?>">
                <?php endforeach; ?>
                <label>
                    <?php $icon = 'sort'; include __DIR__ . '/studio_icon.php'; ?>
                    <span><?= e(t('studio.sort')) ?>: <?= e($sortLabel) ?></span>
                    <select name="ordem" onchange="this.form.submit()" aria-label="<?= e(t('studio.sort')) ?>">
                        <option value="relevancia" <?= $studioOrder === 'relevancia' ? 'selected' : '' ?>>Relevância</option>
                        <option value="preco" <?= $studioOrder === 'preco' ? 'selected' : '' ?>>Menor diária</option>
                        <option value="nota" <?= $studioOrder === 'nota' ? 'selected' : '' ?>>Melhor nota</option>
                    </select>
                </label>
            </form>
        </div>
    </div>
</header>
<main><?= $content ?></main>
<script src="/assets/app.js?v=16"></script>
</body>
</html>

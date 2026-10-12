<?php
$order = (string) ($_GET['ordem'] ?? 'relevancia');
if (!in_array($order, ['relevancia', 'preco', 'nota'], true)) {
    $order = 'relevancia';
}
if ($order === 'preco') {
    usort($results, static fn (array $a, array $b): int => (int) $a['quote']['nightly'] <=> (int) $b['quote']['nightly']);
} elseif ($order === 'nota') {
    usort($results, static function (array $a, array $b): int {
        return ((float) ($b['score'] ?? -1)) <=> ((float) ($a['score'] ?? -1));
    });
}
$query = search_query($filters);
$withOrder = static function (array $next) use ($order): string {
    $built = search_query($next);
    return '/busca-novo?' . $built . ($order !== 'relevancia' ? '&ordem=' . rawurlencode($order) : '');
};
$checkinLabel = $filters['checkin'] !== '' ? (format_date(parse_date($filters['checkin']) ?? '') ?: $filters['checkin']) : '';
$checkoutLabel = $filters['checkout'] !== '' ? (format_date(parse_date($filters['checkout']) ?? '') ?: $filters['checkout']) : '';
$resultTotal = count($results);
$perPage = max(1, class_exists('Settings') ? (Settings::int('results_per_page') ?: 20) : 20);
$pageCount = max(1, (int) ceil($resultTotal / $perPage));
$page = min($pageCount, max(1, (int) ($_GET['pagina'] ?? 1)));
$pageRows = $searched ? array_slice($results, ($page - 1) * $perPage, $perPage) : [];
$counts = $pageRows ? HouseRepository::reviewCounts(array_map(static fn (array $row): int => (int) $row['id'], $pageRows)) : [];
$cleared = $filters;
$cleared['amenidades'] = [];
$cleared['raio'] = 0;
$cleared['preco_min'] = 0;
$cleared['preco_max'] = 0;
$cleared['score_min'] = 0;
$quick = [
    ['pool', 'Piscina', null],
    ['preco_max', 'Até R$ 900/noite', 900],
    ['ac', 'Ar-condicionado', null],
    ['kitchen', 'Cozinha completa', null],
];
$sea = $filters;
$sea['amenidades'] = array_values(array_unique(array_merge($filters['amenidades'], ['sea_view'])));
$points = [];
foreach ($pageRows as $index => $item) {
    $points[] = [
        'price' => money((int) $item['quote']['nightly']),
        'nightly' => (int) $item['quote']['nightly'],
        'href' => '/imoveis/' . property_ref($item) . '?' . http_build_query([
            'checkin' => $filters['checkin'],
            'checkout' => $filters['checkout'],
            'volta' => '/busca-novo?' . $query . ($page > 1 ? '&pagina=' . $page : '') . ($order !== 'relevancia' ? '&ordem=' . $order : ''),
        ]),
        'lat' => (float) $item['lat'],
        'lng' => (float) $item['lng'],
        'i' => $index,
    ];
}
$pageHref = static function (int $target) use ($query, $order): string {
    return '/busca-novo?' . $query . ($order !== 'relevancia' ? '&ordem=' . rawurlencode($order) : '') . '&pagina=' . $target;
};
?>
<section class="vm-page">
    <?php if (!$searched): ?>
        <p class="vm-empty"><?= e(t('home.empty')) ?></p>
    <?php else: ?>
        <div class="vm-board">
            <div class="vm-main">
                <div class="vm-title">
                    <div>
                        <h1><?= e($filters['localidade'] !== '' ? $filters['localidade'] . ': ' : '') ?><?= number_format($resultTotal, 0, ',', '.') ?> estadias<?php if ($checkinLabel !== '' && $checkoutLabel !== ''): ?> para <?= e($checkinLabel) ?> – <?= e($checkoutLabel) ?><?php endif; ?></h1>
                        <p>Preços com taxas · Cancelamento grátis em 82% dos anúncios · Reserva imediata</p>
                    </div>
                    <span class="vm-live"><i></i> Mapa atualizado ao vivo</span>
                </div>
                <div class="vm-pills">
                    <?php foreach ($quick as [$kind, $label, $amount]): ?>
                        <?php
                        if ($kind === 'preco_max') {
                            $on = (int) $filters['preco_max'] === (int) $amount;
                            $next = $filters;
                            $next['preco_max'] = $on ? 0 : (int) $amount;
                        } else {
                            $on = in_array($kind, $filters['amenidades'], true);
                            $next = $filters;
                            $next['amenidades'] = $on
                                ? array_values(array_diff($filters['amenidades'], [$kind]))
                                : array_values(array_unique(array_merge($filters['amenidades'], [$kind])));
                        }
                        ?>
                        <a class="<?= $on ? 'is-on' : '' ?>" href="<?= e($withOrder($next)) ?>"><?= e($label) ?><?php if ($on): ?> ×<?php endif; ?></a>
                    <?php endforeach; ?>
                    <a class="vm-clear" href="<?= e($withOrder($cleared)) ?>">Limpar tudo</a>
                </div>
                <a class="vm-promo" href="<?= e($withOrder($sea)) ?>">
                    <span class="vm-promo-ico"><?php $icon = 'spark'; include __DIR__ . '/studio_icon.php'; ?></span>
                    <span>
                        <strong>Verão antecipado: até 25% off em casas beira-mar</strong>
                        <small>7 noites em Jurerê, Praia do Rosa e Barra da Lagoa · só até domingo</small>
                    </span>
                    <em>Ver casas</em>
                </a>
                <div class="vm-grid">
                    <?php foreach ($pageRows as $item): ?>
                        <?php
                        $selected = Amenities::selected($item);
                        $href = '/imoveis/' . property_ref($item) . '?' . http_build_query([
                            'checkin' => $filters['checkin'],
                            'checkout' => $filters['checkout'],
                            'volta' => '/busca-novo?' . $query . ($page > 1 ? '&pagina=' . $page : '') . ($order !== 'relevancia' ? '&ordem=' . $order : ''),
                        ]);
                        $badges = [];
                        if ($item['score'] !== null && (float) $item['score'] >= 9) {
                            $badges[] = ['Superhost', 'gold'];
                        }
                        if (!empty($selected['sea_view'])) {
                            $badges[] = ['Vista mar', ''];
                        } elseif (!empty($selected['pool'])) {
                            $badges[] = ['Piscina', ''];
                        }
                        $icons = array_values(array_intersect(['wifi', 'ac', 'kitchen', 'pool', 'pets'], array_keys(array_filter($selected))));
                        ?>
                        <article class="vm-card">
                            <a class="vm-photo" href="<?= e($href) ?>">
                                <img src="<?= e(!empty($item['photo']) ? $item['photo'] : '/assets/placeholder.svg') ?>" alt="<?= e($item['title']) ?>" loading="lazy">
                                <?php if ($badges): ?>
                                    <span class="vm-badges"><?php foreach ($badges as [$badge, $tone]): ?><em class="<?= e($tone) ?>"><?= e($badge) ?></em><?php endforeach; ?></span>
                                <?php endif; ?>
                                <span class="vm-heart"><?php $icon = 'heart'; include __DIR__ . '/studio_icon.php'; ?></span>
                                <span class="vm-last"><?php $icon = 'clock'; include __DIR__ . '/studio_icon.php'; ?> Últimas datas</span>
                            </a>
                            <div class="vm-info">
                                <div class="vm-name">
                                    <h2><a href="<?= e($href) ?>"><?= e($item['title']) ?></a></h2>
                                    <?php if ($item['score'] !== null): ?>
                                        <p><?= e(number_format((float) $item['score'], 2, ',', '.')) ?> <span>(<?= (int) ($counts[(int) $item['id']] ?? 0) ?>)</span></p>
                                    <?php endif; ?>
                                </div>
                                <p class="vm-where"><?= e($item['city']) ?></p>
                                <p class="vm-facts"><?= (int) $item['max_guests'] ?> hóspedes · <?= (int) $item['bedrooms'] ?> quartos · <?= (int) $item['beds'] ?> camas</p>
                                <?php if ($icons): ?>
                                    <p class="vm-amen">
                                        <?php foreach (array_slice($icons, 0, 4) as $amenity): ?><i><?php $icon = $amenity === 'wifi' ? 'wave' : ($amenity === 'pets' ? 'paw' : ($amenity === 'pool' ? 'pool' : 'spark')); include __DIR__ . '/studio_icon.php'; ?></i><?php endforeach; ?>
                                        <span><?php
                                            $bits = [];
                                            if (in_array('wifi', $icons, true)) { $bits[] = 'Wi-Fi'; }
                                            if (in_array('ac', $icons, true)) { $bits[] = 'Ar em tudo'; }
                                            echo e($bits ? '+ ' . implode(' · ', $bits) : t('amenity.' . $icons[0]));
                                        ?></span>
                                    </p>
                                <?php endif; ?>
                                <div class="vm-price">
                                    <div>
                                        <strong><?= money((int) $item['quote']['nightly']) ?></strong> <small>/ noite</small>
                                        <?php if (!empty($item['quote']['nights'])): ?>
                                            <em><?= money((int) $item['quote']['total']) ?> total · <?= (int) $item['quote']['nights'] ?> noites</em>
                                        <?php endif; ?>
                                    </div>
                                    <a href="<?= e($href) ?>">Ver oferta</a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ($pageCount > 1): ?>
                    <nav class="vm-pager" aria-label="<?= e(t('home.pages')) ?>">
                        <?php
                        $shown = [];
                        foreach ([1, 2, 3, $page - 1, $page, $page + 1, $pageCount] as $i) {
                            if ($i >= 1 && $i <= $pageCount) {
                                $shown[$i] = true;
                            }
                        }
                        $shown = array_keys($shown);
                        sort($shown);
                        $prev = 0;
                        foreach ($shown as $i):
                            if ($prev && $i > $prev + 1) {
                                echo '<span class="gap">…</span>';
                            }
                            $prev = $i;
                        ?>
                            <?php if ($i === $page): ?><span class="is-on"><?= $i ?></span><?php else: ?><a href="<?= e($pageHref($i)) ?>"><?= $i ?></a><?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ($page < $pageCount): ?><a class="next" href="<?= e($pageHref($page + 1)) ?>">Próxima →</a><?php endif; ?>
                    </nav>
                <?php endif; ?>
                <div class="vm-foot">
                    <article><span><?php $icon = 'shield'; include __DIR__ . '/studio_icon.php'; ?></span><strong>Pagamento via PHP seguro</strong><small>Pix, cartão em até 12x e boleto</small></article>
                    <article><span><?php $icon = 'cal'; include __DIR__ . '/studio_icon.php'; ?></span><strong>Cancelamento grátis</strong><small>Até 7 dias antes do check-in</small></article>
                    <article><span><?php $icon = 'headset'; include __DIR__ . '/studio_icon.php'; ?></span><strong>Suporte 24h em PT-BR</strong><small>Time local em Floripa</small></article>
                </div>
            </div>
            <aside class="vm-map">
                <div class="vm-canvas" id="vm-map" data-points="<?= e((string) json_encode($points, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
                    <span class="vm-map-pill"><?php $icon = 'pin'; include __DIR__ . '/studio_icon.php'; ?> <?= number_format($resultTotal, 0, ',', '.') ?> estadias<?php if ($checkinLabel !== '' && $checkoutLabel !== ''): ?> · <?= e($checkinLabel) ?> – <?= e($checkoutLabel) ?><?php endif; ?></span>
                    <span class="vm-band"></span>
                    <span class="vm-zoom"><button type="button" data-zoom="in">+</button><button type="button" data-zoom="out">−</button></span>
                    <div class="vm-coupon">
                        <span><?php $icon = 'clock'; include __DIR__ . '/studio_icon.php'; ?></span>
                        <p><strong>Ganhe 12% off na 1ª reserva</strong><small>Use o cupom VIVAMARE12 até domingo</small></p>
                        <button type="button" data-copy="VIVAMARE12">Copiar</button>
                    </div>
                </div>
            </aside>
        </div>
    <?php endif; ?>
</section>
<script>
(function () {
    const box = document.getElementById('vm-map');
    if (!box) return;
    const points = JSON.parse(box.getAttribute('data-points') || '[]');
    if (!points.length) return;
    let minLat = Infinity, maxLat = -Infinity, minLng = Infinity, maxLng = -Infinity;
    points.forEach(function (point) {
        minLat = Math.min(minLat, point.lat); maxLat = Math.max(maxLat, point.lat);
        minLng = Math.min(minLng, point.lng); maxLng = Math.max(maxLng, point.lng);
    });
    const latSpan = Math.max(0.02, maxLat - minLat);
    const lngSpan = Math.max(0.02, maxLng - minLng);
    const cheapest = points.map(function (point) { return point.nightly; }).sort(function (a, b) { return a - b; })[Math.min(1, points.length - 1)];
    points.forEach(function (point) {
        const pin = document.createElement('a');
        pin.className = 'vm-pin' + (point.nightly <= cheapest ? ' is-hot' : '');
        pin.href = point.href;
        pin.textContent = point.price;
        const col = (point.i % 3) * 6;
        const row = (point.i % 4) * 5;
        pin.style.left = (12 + ((point.lng - minLng) / lngSpan) * 58 + col) + '%';
        pin.style.top = (18 + (1 - (point.lat - minLat) / latSpan) * 52 + row) + '%';
        box.appendChild(pin);
    });
    box.querySelectorAll('[data-zoom]').forEach(function (button) {
        button.addEventListener('click', function () {
            const pins = box.querySelectorAll('.vm-pin');
            const grow = button.getAttribute('data-zoom') === 'in' ? 1.08 : 0.92;
            pins.forEach(function (pin) {
                const left = parseFloat(pin.style.left);
                const top = parseFloat(pin.style.top);
                pin.style.left = Math.min(78, Math.max(8, 40 + (left - 40) * grow)) + '%';
                pin.style.top = Math.min(78, Math.max(16, 46 + (top - 46) * grow)) + '%';
            });
        });
    });
    const copy = box.querySelector('[data-copy]');
    if (copy) {
        copy.addEventListener('click', function () {
            const value = copy.getAttribute('data-copy') || '';
            if (navigator.clipboard) {
                navigator.clipboard.writeText(value);
            }
            copy.textContent = 'Copiado';
        });
    }
})();
</script>
<?php if (!empty($use_api)): ?>
<script>
(function () {
    const params = new URLSearchParams(window.location.search);
    fetch('/api/busca?' + params.toString(), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            const box = document.querySelector('.vm-results, .results, main, .wrap') || document.body;
            const note = document.createElement('p');
            note.className = 'muted';
            note.textContent = (data.items || []).length + ' resultados carregados via API';
            box.prepend(note);
        })
        .catch(() => {});
})();
</script>
<?php endif; ?>

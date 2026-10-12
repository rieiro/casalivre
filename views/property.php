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
                <img src="<?= e($photo['path']) ?>" alt="Foto de <?= e($property['title']) ?>" loading="lazy">
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
<script>
(function () {
    const hash = <?= json_encode($hash ?? '') ?>;
    const csrf = <?= json_encode($csrf) ?>;
    const params = new URLSearchParams(window.location.search);
    const box = document.getElementById('property-detail');
    fetch('/api/imoveis/' + hash + '?' + params.toString(), { credentials: 'same-origin' })
        .then(r => r.ok ? r.json() : Promise.reject())
        .then(data => {
            const photos = (data.photos || []).map(p => '<img src="' + p.path + '" loading="lazy">').join('');
            const quote = data.quote ? (data.quote.nightly + ' / noite (' + data.quote.nights + ' noites)') : (data.nightly_price + ' / noite');
            box.innerHTML = '<h1>' + (data.title || '') + '</h1>'
                + '<p>' + (data.city || '') + ' · até ' + data.max_guests + ' hóspedes · ' + data.bedrooms + ' quartos</p>'
                + (data.score ? '<p>Nota: ' + data.score + '/10</p>' : '')
                + '<div class="gallery">' + photos + '</div>'
                + '<p>' + (data.description || '') + '</p>'
                + '<p class="price">' + quote + '</p>'
                + '<form class="panel" id="review-form">'
                + '<h2>Avaliar estadia</h2>'
                + '<label>Nome <input name="author" required></label>'
                + '<label>Nota (1 a 10) <input type="number" name="score" min="1" max="10" required></label>'
                + '<label>Comentário <textarea name="comment" rows="3"></textarea></label>'
                + '<button class="primary" type="submit">Publicar avaliação</button></form>';
            document.getElementById('review-form').addEventListener('submit', async function (ev) {
                ev.preventDefault();
                const formData = Object.fromEntries(new FormData(ev.target).entries());
                const res = await fetch('/api/imoveis/' + hash + '/avaliar', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify(formData)
                });
                if (res.ok) {
                    alert('Avaliação enviada');
                    window.location.reload();
                } else {
                    alert('Erro ao enviar avaliação');
                }
            });
        })
        .catch(() => {
            box.innerHTML = '<p>Imóvel não encontrado.</p>';
        });
})();
</script>
<?php endif; ?>
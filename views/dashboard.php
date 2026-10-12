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
                            <img src="<?= e($item['photo']) ?>" alt="Foto de <?= e($item['title']) ?>" loading="lazy">
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
                            <form method="post" action="/imoveis/<?= e(property_ref($item)) ?>/excluir" onsubmit="return confirm(<?= json_encode(t('dash.remove_confirm'), JSON_UNESCAPED_UNICODE) ?>)">
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
<script>
(function () {
    const box = document.getElementById('painel-list');
    const csrf = <?= json_encode($csrf) ?>;
    const confirmMsg = <?= json_encode(t('dash.remove_confirm'), JSON_UNESCAPED_UNICODE) ?>;

    function render(items) {
        if (!items.length) {
            box.innerHTML = '<p class="muted"><?= e(t('dash.empty')) ?></p>';
            return;
        }
        let html = '<div class="grid">';
        items.forEach(item => {
            const photo = item.photo || '/assets/placeholder.svg';
            const hash = item.id;
            html += '<article class="card">'
                + '<img src="' + photo + '" alt="" loading="lazy">'
                + '<div class="body">'
                + '<h2>' + (item.title || '') + '</h2>'
                + '<p class="muted">' + (item.city || '') + '</p>'
                + '<p>'
                + '<a href="/imoveis/' + hash + '/editar"><?= e(t('dash.edit')) ?></a> · '
                + '<a href="/imoveis/' + hash + '/calendario"><?= e(t('dash.calendar')) ?></a>'
                + '</p>'
                + '<button class="danger" data-remove="' + hash + '"><?= e(t('dash.remove')) ?></button>'
                + '</div></article>';
        });
        html += '</div>';
        box.innerHTML = html;
        box.querySelectorAll('[data-remove]').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!confirm(confirmMsg)) return;
                const hash = btn.getAttribute('data-remove');
                try {
                    const res = await fetch('/api/casas/' + hash, {
                        method: 'DELETE',
                        credentials: 'same-origin',
                        headers: { 'X-CSRF-Token': csrf }
                    });
                    if (!res.ok) {
                        const j = await res.json().catch(() => ({}));
                        alert(j.error || 'Erro ao remover');
                        return;
                    }
                    load();
                } catch (e) {
                    alert('Falha de conexão');
                }
            });
        });
    }

    async function load() {
        try {
            const res = await fetch('/api/painel', { credentials: 'same-origin' });
            if (res.status === 401) {
                window.location.href = '/entrar';
                return;
            }
            const json = await res.json();
            render(json.items || []);
        } catch (e) {
            box.innerHTML = '<p class="muted">Erro ao carregar o painel.</p>';
        }
    }
    load();
})();
</script>
<?php endif; ?>
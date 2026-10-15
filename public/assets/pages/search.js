// Resultados da busca via API, montados sem innerHTML (textContent/createElement).
(function () {
    const el = CL.el;
    const params = new URLSearchParams(window.location.search);
    const box = document.getElementById('search-results');
    if (!box) return;
    // Vitrine do grupo (/g/<slug>): data-base prefixa os links e data-api traz a busca já filtrada pelo grupo.
    const base = box.dataset.base || '';
    fetch(box.dataset.api || ('/api/busca?' + params.toString()), { credentials: 'same-origin' })
        .then((r) => r.json())
        .then((data) => {
            const items = data.items || [];
            const loc = params.get('localidade') || '';
            const nodes = [el('h1', {}, (loc ? loc + ': ' : '') + items.length + ' encontrados')];
            if (items.length === 0) {
                nodes.push(el('p', { className: 'muted' }, 'Nenhum imóvel encontrado.'));
            } else {
                nodes.push(el('div', { className: 'results' }, items.map((item) => {
                    const q = item.quote || {};
                    const price = q.nights ? (q.total + ' total') : (item.nightly_price + ' / noite');
                    const href = base + '/imoveis/' + encodeURIComponent(String(item.id || '')) + '?' + params.toString();
                    return el('article', { className: 'result' },
                        el('a', { href: href }, CL.photoImg(item.photo, '(max-width: 600px) 100vw, 400px', '')),
                        el('div', { className: 'body' },
                            el('h2', { className: 'card-title' }, item.title || ''),
                            el('p', { className: 'muted' }, item.city || ''),
                            el('p', { className: 'summary muted' }, item.summary || '')),
                        el('div', { className: 'offer' },
                            item.score ? el('p', { className: 'score-badge' }, item.score) : null,
                            el('p', { className: 'price' }, price)));
                })));
            }
            box.replaceChildren(...nodes);
        })
        .catch(() => {
            box.replaceChildren(el('p', { className: 'muted' }, 'Erro ao carregar resultados.'));
        });
})();

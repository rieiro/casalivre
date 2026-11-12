// Resultados da busca via API, montados sem innerHTML (textContent/createElement).
(function () {
    const el = CL.el;
    const box = document.getElementById('search-results');
    if (!box) return;
    const base = box.dataset.base || '';

    function loadResults() {
        const params = new URLSearchParams(window.location.search);
        let api = box.dataset.api || '';
        if (api) {
            const separator = api.includes('?') ? '&' : '?';
            api = api + separator + params.toString();
        } else {
            api = '/api/busca?' + params.toString();
        }

        fetch(api, { credentials: 'same-origin' })
            .then((r) => r.json())
            .then((data) => {
                const items = data.items || [];
                const loc = params.get('localidade') || '';
                const total = (data.total !== undefined && data.total !== null)
                    ? data.total
                    : (data.total_aprox !== undefined && data.total_aprox !== null ? data.total_aprox : items.length);
                const nodes = [el('h1', {}, (loc ? loc + ': ' : '') + total + ' encontrados')];
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

                const page = Number(data.page) || Number(params.get('pagina')) || 1;
                const totalPages = Number(data.total_pages) || (typeof total === 'number' ? Math.max(1, Math.ceil(total / (data.per_page || 20))) : 1);

                if (totalPages > 1) {
                    const pagerLinks = [];
                    const createPageLink = function (p, text) {
                        const pParams = new URLSearchParams(params);
                        pParams.set('pagina', String(p));
                        const href = (base ? base : '') + '/busca?' + pParams.toString();
                        const a = el('a', { href: href }, text);
                        a.addEventListener('click', function (e) {
                            e.preventDefault();
                            history.pushState({}, '', href);
                            loadResults();
                            window.scrollTo({ top: box.offsetTop - 60, behavior: 'smooth' });
                        });
                        return a;
                    };

                    if (page > 1) {
                        pagerLinks.push(createPageLink(page - 1, 'Anterior'));
                    }

                    const from = Math.max(1, page - 2);
                    const to = Math.min(totalPages, page + 2);
                    for (let i = from; i <= to; i++) {
                        if (i === page) {
                            pagerLinks.push(el('span', { className: 'is-on' }, String(i)));
                        } else {
                            pagerLinks.push(createPageLink(i, String(i)));
                        }
                    }

                    if (page < totalPages) {
                        pagerLinks.push(createPageLink(page + 1, 'Próxima'));
                    }

                    nodes.push(el('nav', { className: 'pager', 'aria-label': 'Páginas' }, pagerLinks));
                }

                box.replaceChildren(...nodes);
            })
            .catch(() => {
                box.replaceChildren(el('p', { className: 'muted' }, 'Erro ao carregar resultados.'));
            });
    }

    window.addEventListener('popstate', loadResults);
    loadResults();
})();

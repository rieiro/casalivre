// Detalhe do imóvel via API, montado sem innerHTML (textContent/createElement).
(function () {
    const el = CL.el;
    const page = CL.data();
    const hash = String(page.hash || '');
    const csrf = page.csrf || '';
    const params = new URLSearchParams(window.location.search);
    const box = document.getElementById('property-detail');
    if (!box) return;
    fetch('/api/imoveis/' + encodeURIComponent(hash) + '?' + params.toString(), { credentials: 'same-origin' })
        .then((r) => (r.ok ? r.json() : Promise.reject()))
        .then((data) => {
            const quote = data.quote ? (data.quote.nightly + ' / noite (' + data.quote.nights + ' noites)') : (data.nightly_price + ' / noite');
            const form = el('form', { className: 'panel', id: 'review-form' },
                el('h2', {}, 'Avaliar estadia'),
                el('label', {}, 'Nome ', el('input', { name: 'author', required: true })),
                el('label', {}, 'Nota (1 a 10) ', el('input', { type: 'number', name: 'score', min: '1', max: '10', required: true })),
                el('label', {}, 'Comentário ', el('textarea', { name: 'comment', rows: '3' })),
                el('button', { className: 'primary', type: 'submit' }, 'Publicar avaliação'));
            box.replaceChildren(
                el('h1', {}, data.title || ''),
                el('p', {}, (data.city || '') + ' · até ' + data.max_guests + ' hóspedes · ' + data.bedrooms + ' quartos'),
                data.score ? el('p', {}, 'Nota: ' + data.score + '/10') : null,
                el('div', { className: 'gallery' }, (data.photos || []).map((p) => el('img', { src: p.path, alt: '', loading: 'lazy' }))),
                el('p', {}, data.description || ''),
                el('p', { className: 'price' }, quote),
                form);
            form.addEventListener('submit', async (ev) => {
                ev.preventDefault();
                const formData = Object.fromEntries(new FormData(form).entries());
                const res = await fetch('/api/imoveis/' + encodeURIComponent(hash) + '/avaliar', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify(formData),
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
            box.replaceChildren(el('p', {}, 'Imóvel não encontrado.'));
        });
})();

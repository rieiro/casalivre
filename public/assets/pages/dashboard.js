// Painel do anfitrião via API, montado sem innerHTML (textContent/createElement).
(function () {
    const el = CL.el;
    const page = CL.data();
    const box = document.getElementById('painel-list');
    if (!box) return;

    function render(items) {
        const statCount = document.getElementById('stat-props-count');
        if (statCount) statCount.textContent = String(items.length);

        if (!items.length) {
            const emptyBox = el('div', { className: 'dash-empty-box' },
                el('div', { className: 'dash-empty-icon' }, '🏡'),
                el('h2', { className: 'dash-empty-title' }, page.empty || 'Você ainda não cadastrou imóveis.'),
                el('p', { className: 'dash-empty-desc' }, 'Cadastre sua primeira acomodação para começar a receber reservas diretas via Pix.'),
                el('a', { className: 'btn-dash-primary', href: '/imoveis/novo' }, page.add || 'Adicionar imóvel')
            );
            box.replaceChildren(emptyBox);
            return;
        }

        const cards = items.map((item) => {
            const hash = String(item.id || '');
            const ref = encodeURIComponent(hash);
            const price = Number(item.nightly_price) || 0;
            const guests = Number(item.max_guests) || 0;

            const mediaChildren = [
                CL.photoImg(item.photo, '(max-width: 600px) 100vw, 400px', item.title || ''),
                el('span', { className: 'dash-card-badge' }, page.active || '✓ Ativo')
            ];
            if (price > 0) {
                mediaChildren.push(el('span', { className: 'dash-card-price' }, 'R$ ' + price.toLocaleString('pt-BR') + ' ' + (page.per_night || '/ noite')));
            }
            const media = el('div', { className: 'dash-card-media' }, ...mediaChildren);

            const titleLink = el('a', { href: '/imoveis/' + ref }, item.title || '');
            const title = el('h2', { className: 'dash-card-title' }, titleLink);

            const metaParts = ['📍 ' + (item.city || '')];
            if (guests > 0) {
                metaParts.push(' · 👥 ' + guests + ' ' + (page.guests || 'hóspedes'));
            }
            const meta = el('p', { className: 'dash-card-meta' }, metaParts.join(''));

            const btnCal = el('a', { className: 'btn-dash-action is-cal', href: '/imoveis/' + ref + '/calendario' }, '📅 ' + (page.calendar || 'Calendário'));
            const btnEdit = el('a', { className: 'btn-dash-action is-edit', href: '/imoveis/' + ref + '/editar' }, '✏️ ' + (page.edit || 'Editar'));
            const btnView = el('a', { className: 'btn-dash-action is-view', href: '/imoveis/' + ref }, '👁️ ' + (page.view || 'Ver'));

            const actions = el('div', { className: 'dash-card-actions' }, btnCal, btnEdit, btnView);

            const body = el('div', { className: 'dash-card-body body' }, title, meta, actions);

            return el('article', { className: 'dash-card card' }, media, body);
        });

        box.replaceChildren(el('div', { className: 'dash-grid grid' }, ...cards));
    }

    async function load() {
        try {
            const json = await CL.api('/api/painel', { error: 'Erro ao carregar o painel.' });
            render(json.items || []);
        } catch (e) {
            if (e.status === 401) {
                window.location.href = '/entrar';
                return;
            }
            box.replaceChildren(el('p', { className: 'muted' }, e.message));
        }
    }
    load();
})();

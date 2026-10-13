// Painel do proprietário via API, montado sem innerHTML (textContent/createElement).
(function () {
    const el = CL.el;
    const page = CL.data();
    const box = document.getElementById('painel-list');
    if (!box) return;

    async function remove(hash) {
        if (!confirm(page.confirm || '')) return;
        try {
            const res = await fetch('/api/casas/' + encodeURIComponent(hash), {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': page.csrf || '' },
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
    }

    function render(items) {
        if (!items.length) {
            box.replaceChildren(el('p', { className: 'muted' }, page.empty || ''));
            return;
        }
        box.replaceChildren(el('div', { className: 'grid' }, items.map((item) => {
            const hash = String(item.id || '');
            const ref = encodeURIComponent(hash);
            const button = el('button', { className: 'danger', type: 'button' }, page.remove || '');
            button.addEventListener('click', () => remove(hash));
            return el('article', { className: 'card' },
                el('img', { src: item.photo, alt: '', loading: 'lazy' }),
                el('div', { className: 'body' },
                    el('h2', {}, item.title || ''),
                    el('p', { className: 'muted' }, item.city || ''),
                    el('p', {},
                        el('a', { href: '/imoveis/' + ref + '/editar' }, page.edit || ''), ' · ',
                        el('a', { href: '/imoveis/' + ref + '/calendario' }, page.calendar || '')),
                    button));
        })));
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
            box.replaceChildren(el('p', { className: 'muted' }, 'Erro ao carregar o painel.'));
        }
    }
    load();
})();

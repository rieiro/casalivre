(function () {
    const form = document.getElementById('property-form');
    if (!form) return;
    const csrf = form.querySelector('[name="_csrf"]').value;
    const editing = form.dataset.editing === '1';
    const hash = form.dataset.hash;
    form.addEventListener('submit', async function (ev) {
        // Deixa o submit normal se for o botão de excluir fotos (tem data-delete-photos)
        if (ev.submitter && ev.submitter.hasAttribute('data-delete-photos')) {
            return;
        }
        ev.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        const url = editing ? '/api/casas/' + hash : '/api/casas';
        const method = editing ? 'PUT' : 'POST';
        try {
            const res = await fetch(url, {
                method: method,
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify(data)
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                alert(json.error || 'Erro ao salvar imóvel');
                return;
            }
            const newHash = (json.casa && json.casa.id) || hash;
            if (!editing) {
                window.location.href = '/imoveis/' + newHash + '/editar';
            } else {
                alert('Imóvel atualizado');
                window.location.reload();
            }
        } catch (e) {
            alert('Falha de conexão');
        }
    });
})();

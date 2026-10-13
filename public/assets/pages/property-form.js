(function () {
    const form = document.getElementById('property-form');
    if (!form) return;
    const editing = form.dataset.editing === '1';
    const hash = form.dataset.hash;
    form.addEventListener('submit', async function (ev) {
        // Deixa o submit normal se for o botão de excluir fotos (tem data-delete-photos)
        if (ev.submitter && ev.submitter.hasAttribute('data-delete-photos')) {
            return;
        }
        ev.preventDefault();
        // rooms[i][beds][j][campo] e amenity[chave] viram objetos/listas (issue #20); form-data.js carrega antes.
        if (!window.CasaLivreForm) {
            alert(form.dataset.scriptError || 'Erro ao carregar a página. Recarregue e tente de novo.');
            return;
        }
        const data = window.CasaLivreForm.toObject(form);
        const url = editing ? '/api/casas/' + encodeURIComponent(hash) : '/api/casas';
        try {
            const json = await CL.api(url, { method: editing ? 'PUT' : 'POST', body: data, error: 'Erro ao salvar imóvel' });
            const newHash = (json.casa && json.casa.id) || hash;
            if (!editing) {
                window.location.href = '/imoveis/' + encodeURIComponent(newHash) + '/editar';
            } else {
                alert('Imóvel atualizado');
                window.location.reload();
            }
        } catch (e) {
            alert(e.message);
        }
    });
})();

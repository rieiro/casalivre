// Carrega resultados via API quando use_api
(function () {
    const params = new URLSearchParams(window.location.search);
    fetch('/api/busca?' + params.toString(), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            const list = document.querySelector('.map-results, .results, .wrap') || document.body;
            const note = document.createElement('p');
            note.className = 'muted';
            note.textContent = (data.items || []).length + ' imóveis (via API)';
            list.prepend(note);
        })
        .catch(() => {});
})();

// Prévia do mapa na busca: imagens dos tiles via CSSOM (sem style= inline).
document.querySelectorAll('[data-tiles]').forEach((node) => {
    const urls = (node.dataset.tiles || '').split(' ').filter((u) => /^https:\/\/tile\.openstreetmap\.org\/\d+\/\d+\/\d+\.png$/.test(u));
    node.style.backgroundImage = urls.map((u) => 'url("' + u + '")').join(',');
});

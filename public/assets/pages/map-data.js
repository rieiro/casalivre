// Dados do mapa (puro, sem DOM): GeoJSON dos resultados de /api/busca e textos dos pins.
// Usado por map.js no navegador e pelo teste standalone (node), por isso exporta nos dois mundos.
(function (root) {
    'use strict';

    function validCoord(lat, lng) {
        return typeof lat === 'number' && typeof lng === 'number' && isFinite(lat) && isFinite(lng)
            && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180;
    }

    function brl(value) {
        const n = Math.round(Number(value) || 0);
        return 'R$ ' + String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    /** Texto curto do pin: total do período quando há datas, senão a diária. */
    function priceLabel(item) {
        const q = item.quote || {};
        if (q.nights) {
            return brl(q.total);
        }
        return brl(item.nightly_price) + '/noite';
    }

    /** Frase acessível do preço (popup/aria-label). */
    function priceText(item) {
        const q = item.quote || {};
        if (q.nights) {
            return brl(q.total) + ' total · ' + q.nights + (q.nights === 1 ? ' noite' : ' noites') + ' · taxas inclusas';
        }
        return brl(item.nightly_price) + ' por noite';
    }

    /**
     * FeatureCollection de pontos a partir dos itens de /api/busca. Só usa lat/lng que a API devolve
     * (coordenada pública do #36); itens sem coordenada ficam fora. $query vai no link do imóvel.
     */
    function toGeoJSON(items, query) {
        const qs = query ? String(query) : '';
        const features = [];
        (items || []).forEach(function (item) {
            const id = String(item && item.id || '');
            if (!/^[a-f0-9]{16}$/.test(id) || !validCoord(item.lat, item.lng)) {
                return;
            }
            features.push({
                type: 'Feature',
                geometry: { type: 'Point', coordinates: [item.lng, item.lat] },
                properties: {
                    id: id,
                    title: String(item.title || ''),
                    city: String(item.city || ''),
                    score: item.score === null || item.score === undefined ? '' : String(item.score),
                    photo: item.photo ? String(item.photo) : '',
                    price: priceLabel(item),
                    priceText: priceText(item),
                    href: '/imoveis/' + id + (qs ? '?' + qs : ''),
                },
            });
        });
        return { type: 'FeatureCollection', features: features };
    }

    /** [[minLng, minLat], [maxLng, maxLat]] dos pontos, ou null. */
    function bounds(fc) {
        let b = null;
        fc.features.forEach(function (f) {
            const c = f.geometry.coordinates;
            if (!b) {
                b = [[c[0], c[1]], [c[0], c[1]]];
                return;
            }
            b[0][0] = Math.min(b[0][0], c[0]); b[0][1] = Math.min(b[0][1], c[1]);
            b[1][0] = Math.max(b[1][0], c[0]); b[1][1] = Math.max(b[1][1], c[1]);
        });
        return b;
    }

    const api = { toGeoJSON: toGeoJSON, bounds: bounds, priceLabel: priceLabel, priceText: priceText, brl: brl };
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.CLMapData = api;
    }
})(typeof window !== 'undefined' ? window : globalThis);

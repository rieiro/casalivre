// Mapa da busca: MapLibre GL (build CSP, worker local) + protocolo pmtiles, basemap Protomaps light.
// Pins = resultados de /api/busca em GeoJSON com clusters (coordenada pública do #36). Marcadores HTML
// (focáveis, Enter/Espaço) em vez de símbolos: funcionam também sem o arquivo de tiles.
// Sem requisições externas: estilo, fontes, sprites e tiles saem do próprio site.
(function () {
    'use strict';
    const box = document.getElementById('result-map');
    if (!box) return;
    const statusEl = document.getElementById('map-status');
    const noticeEl = document.getElementById('map-notice');
    const D = window.CLMapData;
    const el = window.CL.el;
    const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const ATTRIBUTION = '<a href="https://www.openstreetmap.org/copyright" rel="noopener">© OpenStreetMap contributors</a> · <a href="https://protomaps.com" rel="noopener">Protomaps</a>';
    const BRASIL = [[-74.0, -33.8], [-28.6, 5.3]];

    function setStatus(text) {
        if (statusEl) statusEl.textContent = text;
    }
    function notice(text, retry) {
        if (!noticeEl) return;
        const nodes = [text];
        if (retry) {
            const btn = el('button', { type: 'button', className: 'link-button' }, 'Tentar novamente');
            btn.addEventListener('click', retry);
            nodes.push(' ', btn);
        }
        noticeEl.replaceChildren(...nodes);
        noticeEl.hidden = false;
    }
    function listFallback(text) {
        box.hidden = true;
        const back = el('a', { href: '/busca?' + (box.dataset.query || '') }, 'Ver a lista de resultados');
        if (noticeEl) {
            noticeEl.replaceChildren(text + ' ', back);
            noticeEl.hidden = false;
        }
        setStatus('');
    }

    if (!window.maplibregl || !window.pmtiles || !D) {
        listFallback('Não foi possível carregar o mapa.');
        return;
    }
    maplibregl.setWorkerUrl(box.dataset.worker);
    const protocol = new pmtiles.Protocol();
    maplibregl.addProtocol('pmtiles', protocol.tile);

    const origin = window.location.origin;
    const tilesUrl = origin + box.dataset.tiles;

    // Estilo sem o basemap (só fundo): usado quando brasil.pmtiles não existe; os pins continuam.
    function bareStyle() {
        return {
            version: 8,
            glyphs: origin + '/assets/map/fonts/{fontstack}/{range}.pbf',
            sources: {},
            layers: [{ id: 'background', type: 'background', paint: { 'background-color': '#e8eef2' } }],
        };
    }

    async function loadStyle() {
        let head;
        try {
            head = await fetch(box.dataset.tiles, { method: 'HEAD', credentials: 'same-origin' });
        } catch (e) {
            head = null;
        }
        if (!head || !head.ok) {
            notice('Mapa de fundo indisponível no momento (arquivo de tiles ausente). Os imóveis aparecem nos marcadores e na lista.');
            return bareStyle();
        }
        const style = await (await fetch(box.dataset.style, { credentials: 'same-origin' })).json();
        style.glyphs = origin + style.glyphs;
        style.sprite = origin + style.sprite;
        style.sources.protomaps.url = 'pmtiles://' + tilesUrl;
        return style;
    }

    async function loadItems() {
        if (!box.dataset.api) return [];
        const res = await fetch(box.dataset.api, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        return data.items || [];
    }

    function center() {
        const c = (box.dataset.center || '').split(',').map(Number);
        return c.length === 2 && c.every(isFinite) ? [c[1], c[0]] : null;
    }

    async function start() {
        setStatus('Carregando mapa…');
        let style;
        let items;
        try {
            [style, items] = await Promise.all([loadStyle(), loadItems()]);
        } catch (e) {
            setStatus('');
            notice('Erro ao carregar os resultados do mapa.', function () { noticeEl.hidden = true; start(); });
            return;
        }
        const fc = D.toGeoJSON(items, box.dataset.query || '');
        let map;
        try {
            const c = center();
            map = new maplibregl.Map({
                container: box,
                style: style,
                center: c || [-51.9, -14.2],
                zoom: c ? 11 : 3.5,
                maxBounds: [[-90, -45], [-15, 15]],
                attributionControl: false,
                fadeDuration: reduceMotion ? 0 : 300,
                cooperativeGestures: false,
            });
        } catch (e) {
            listFallback('Seu navegador não conseguiu abrir o mapa (WebGL indisponível).');
            return;
        }
        map.addControl(new maplibregl.AttributionControl({ compact: false, customAttribution: ATTRIBUTION }), 'bottom-right');
        map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
        map.on('error', function (ev) {
            // Glifos de escritas não incluídas (ex.: CJK) dão 404: sem impacto, não poluem o console.
            const url = ev && ev.error && ev.error.url ? String(ev.error.url) : '';
            if (url.indexOf('/assets/map/fonts/') !== -1) return;
            if (window.console && console.warn) console.warn('mapa:', ev && ev.error ? ev.error.message : ev);
        });

        map.on('load', function () {
            map.addSource('imoveis', { type: 'geojson', data: fc, cluster: true, clusterRadius: 48, clusterMaxZoom: 15 });
            // Camada invisível: o MapLibre calcula os clusters no zoom atual e queryRenderedFeatures devolve só o
            // que está na tela neste zoom (sem tiles-pai de transição); o desenho é feito com marcadores HTML.
            map.addLayer({ id: 'imoveis-hit', type: 'circle', source: 'imoveis', paint: { 'circle-radius': 4, 'circle-opacity': 0, 'circle-stroke-width': 0 } });
            const b = D.bounds(fc);
            if (b) {
                map.fitBounds(b, { padding: 60, maxZoom: 14, animate: false });
            } else if (!center()) {
                map.fitBounds(BRASIL, { animate: false });
            }
            const n = fc.features.length;
            setStatus(n === 0 ? 'Nenhum imóvel encontrado nesta busca.' : n + (n === 1 ? ' imóvel no mapa.' : ' imóveis no mapa.'));
            box.dataset.points = String(n);
            map.on('data', function (ev) { if (ev.sourceId === 'imoveis' && ev.isSourceLoaded) draw(); });
            map.on('moveend', draw);
            map.on('idle', draw); // estado final após animação/carga de tiles (sem marcador de cluster antigo)
            draw();
        });

        const markers = new Map();
        const onMap = new Set();
        let popup = null;

        function easeTo(opts) {
            if (reduceMotion) map.jumpTo(opts); else map.easeTo(opts);
        }

        function clusterMarker(f) {
            const count = f.properties.point_count;
            const btn = el('button', { type: 'button', className: 'map-cluster', 'aria-label': count + ' imóveis aqui; ampliar' }, String(count));
            btn.dataset.count = String(count);
            btn.addEventListener('click', function (ev) {
                ev.stopPropagation(); // o clique não pode chegar ao mapa (fecharia o popup aberto)
                map.getSource('imoveis').getClusterExpansionZoom(f.properties.cluster_id).then(function (z) {
                    easeTo({ center: f.geometry.coordinates, zoom: Math.min(z, 18) });
                });
            });
            return btn;
        }

        function pointMarker(f) {
            const p = f.properties;
            const btn = el('button', { type: 'button', className: 'map-price', 'aria-label': p.title + ', ' + p.priceText, 'aria-haspopup': 'dialog' }, p.price);
            btn.dataset.href = p.href;
            btn.addEventListener('click', function (ev) { ev.stopPropagation(); openPopup(f, btn); });
            return btn;
        }

        function openPopup(f, pin) {
            const p = f.properties;
            if (popup) popup.remove();
            document.querySelectorAll('.map-price.is-selected').forEach(function (n) { n.classList.remove('is-selected'); n.setAttribute('aria-pressed', 'false'); });
            pin.classList.add('is-selected', 'is-visited');
            pin.setAttribute('aria-pressed', 'true');
            const content = el('div', { className: 'map-popup' },
                p.photo ? window.CL.photoImg(p.photo, '240px', 'Foto de ' + p.title) : null,
                el('p', { className: 'map-popup-title' }, p.title),
                p.score ? el('p', { className: 'muted' }, 'Nota ' + p.score) : null,
                el('p', { className: 'price' }, p.priceText),
                el('a', { href: p.href, className: 'button primary' }, 'Ver detalhes'));
            content.addEventListener('keydown', function (ev) {
                if (ev.key === 'Escape' && popup) popup.remove(); // Esc fecha; o foco volta ao pin
            });
            popup = new maplibregl.Popup({ offset: 18, maxWidth: '260px', focusAfterOpen: true })
                .setLngLat(f.geometry.coordinates).setDOMContent(content).addTo(map);
            popup.on('close', function () {
                pin.classList.remove('is-selected');
                pin.setAttribute('aria-pressed', 'false');
                if (document.body.contains(pin)) pin.focus();
            });
        }

        function draw() {
            if (!map.getSource('imoveis') || !map.isSourceLoaded('imoveis')) return;
            const seen = new Set();
            map.queryRenderedFeatures({ layers: ['imoveis-hit'] }).forEach(function (f) {
                const key = f.properties.cluster ? 'c' + f.properties.cluster_id : 'p' + f.properties.id;
                if (seen.has(key)) return;
                seen.add(key);
                if (!markers.has(key)) {
                    const node = f.properties.cluster ? clusterMarker(f) : pointMarker(f);
                    markers.set(key, new maplibregl.Marker({ element: node, anchor: f.properties.cluster ? 'center' : 'bottom' }).setLngLat(f.geometry.coordinates));
                }
                if (!onMap.has(key)) {
                    markers.get(key).addTo(map);
                    onMap.add(key);
                }
            });
            onMap.forEach(function (key) {
                if (!seen.has(key)) {
                    markers.get(key).remove();
                    onMap.delete(key);
                }
            });
        }
    }

    start();
})();

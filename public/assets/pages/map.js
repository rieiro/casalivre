(function () {
    const box = document.getElementById('result-map');
    if (!box) return;
    const points = JSON.parse(box.getAttribute('data-points') || '[]');
    const key = box.getAttribute('data-key') || '';
    if (!points.length) return;
    if (key) {
        const script = document.createElement('script');
        script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) + '&callback=casalivreMap';
        script.async = true;
        window.casalivreMap = function () {
            const map = new google.maps.Map(box, {center: {lat: points[0].lat, lng: points[0].lng}, zoom: 12, mapTypeControl: false});
            const bounds = new google.maps.LatLngBounds();
            points.forEach(function (point) {
                const marker = new google.maps.Marker({position: {lat: point.lat, lng: point.lng}, map: map, title: point.title});
                bounds.extend(marker.getPosition());
                marker.addListener('click', function () { window.location = point.href; });
            });
            if (points.length > 1) map.fitBounds(bounds);
        };
        document.body.appendChild(script);
        return;
    }
    const zoom = 13;
    const world = function (lat, lng) {
        const n = Math.pow(2, zoom);
        const x = (lng + 180) / 360 * n;
        const s = Math.sin(lat * Math.PI / 180);
        const y = (0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI)) * n;
        return {x: x, y: y};
    };
    let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
    const placed = points.map(function (point) {
        const spot = world(point.lat, point.lng);
        minX = Math.min(minX, spot.x); maxX = Math.max(maxX, spot.x);
        minY = Math.min(minY, spot.y); maxY = Math.max(maxY, spot.y);
        return {point: point, spot: spot};
    });
    const x0 = Math.floor(minX) - 1;
    const y0 = Math.floor(minY) - 1;
    const x1 = Math.ceil(maxX) + 1;
    const y1 = Math.ceil(maxY) + 1;
    const cols = Math.min(8, Math.max(1, x1 - x0 + 1));
    const rows = Math.min(6, Math.max(1, y1 - y0 + 1));
    box.style.width = (cols * 256) + 'px';
    box.style.height = (rows * 256) + 'px';
    box.style.maxWidth = '100%';
    for (let y = 0; y < rows; y++) {
        for (let x = 0; x < cols; x++) {
            const img = document.createElement('img');
            img.alt = '';
            img.loading = 'lazy';
            img.src = 'https://tile.openstreetmap.org/' + zoom + '/' + (x0 + x) + '/' + (y0 + y) + '.png';
            img.style.position = 'absolute';
            img.style.left = (x * 256) + 'px';
            img.style.top = (y * 256) + 'px';
            img.width = 256;
            img.height = 256;
            box.appendChild(img);
        }
    }
    placed.forEach(function (item) {
        const pin = document.createElement('a');
        pin.className = 'map-marker';
        pin.href = item.point.href;
        pin.textContent = item.point.title;
        pin.style.left = ((item.spot.x - x0) * 256) + 'px';
        pin.style.top = ((item.spot.y - y0) * 256) + 'px';
        box.appendChild(pin);
    });
})();

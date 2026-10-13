"""E2E CSP de mapas. Modo osm: tiles do OpenStreetMap carregam sem violação de CSP (busca e /mapa).
Modo maps (chave falsa): o script do Google Maps não é bloqueado pela CSP (rede pode falhar)."""
import sys
import urllib.parse

from playwright.sync_api import sync_playwright

base, mode = sys.argv[1:3]
loc = urllib.parse.quote('Florianópolis, SC')
failures = []


def check(cond, msg):
    print(('  ok   ' if cond else '  FAIL ') + msg)
    if not cond:
        failures.append(msg)


with sync_playwright() as pw:
    browser = pw.chromium.launch()
    page = browser.new_page()
    console, responses, requests = [], [], []
    page.on('console', lambda m: console.append(m.text))
    page.on('pageerror', lambda e: console.append('pageerror: ' + str(e)))
    page.on('request', lambda r: requests.append(r.url))
    page.on('response', lambda r: responses.append((r.url, r.status)))
    paths = ['/busca?localidade=' + loc, '/busca/mapa?localidade=' + loc]
    for path in paths:
        console.clear(); responses.clear(); requests.clear()
        page.goto(base + path)
        page.wait_for_load_state('networkidle')
        if path.startswith('/busca/mapa'):
            # A rota (modo API) entrega data-points vazio e o mapa nunca desenha (bug pré-existente). Para exercitar
            # o map.js real sob a CSP real, injeta pontos e recarrega o script do próprio site ('self').
            inject = '''() => {
                document.getElementById('result-map').setAttribute('data-points', JSON.stringify([
                    {title: 'Casa', lat: -27.59, lng: -48.54, href: '/'}]));
                const s = document.createElement('script');
                s.src = '/assets/pages/map.js?e2e=' + Date.now();
                document.body.appendChild(s);
            }'''
            if mode == 'maps':
                # Espera explícita pelo script do Google (a rede externa pode demorar).
                try:
                    with page.expect_request(lambda r: 'maps.googleapis.com/maps/api/js' in r.url, timeout=15000):
                        page.evaluate(inject)
                except Exception:
                    pass
                page.wait_for_timeout(4000)
            else:
                page.evaluate(inject)
            page.wait_for_load_state('networkidle')
        page.wait_for_timeout(1500)
        csp = [m for m in console if 'Content Security Policy' in m]
        for m in csp:
            print('  csp  ' + m[:300])
        if mode == 'osm':
            check(not csp, path + ': nenhuma violação de CSP')
            tiles = [s for u, s in responses if 'tile.openstreetmap.org' in u]
            check(tiles and all(s == 200 for s in tiles), path + ': tiles OSM carregados (%d, status %s)' % (len(tiles), sorted(set(s for s in tiles))))
        else:
            google = [m for m in csp if 'google' in m or 'gstatic' in m]
            check(not google, path + ': nenhuma violação de CSP para hosts Google')
            check(not csp, path + ': nenhuma violação de CSP')
            if path.startswith('/busca/mapa'):
                check(any('maps.googleapis.com/maps/api/js' in u for u in requests), path + ': script do Google Maps requisitado (não bloqueado)')
                print('  info hosts Google requisitados: ' + ', '.join(sorted({urllib.parse.urlsplit(u).netloc for u in requests if 'goog' in u or 'gstatic' in u})))
    browser.close()

print('E2E csp_maps %s: %s' % (mode, 'PASSOU' if not failures else 'FALHOU (%d)' % len(failures)))
sys.exit(1 if failures else 0)

"""E2E do mapa (MapLibre GL + PMTiles) sob a CSP estrita: zero violações de CSP (inclui o worker do MapLibre),
zero requisições a hosts externos (Google incluído), zero erros no console, atribuição "© OpenStreetMap contributors · Protomaps"
visível com link, e tiles do /tiles/brasil.pmtiles via Range (206) quando o arquivo existe; sem o arquivo, o
mapa abre só com os pins e mostra o aviso. Cobre CT-MAPA-010..012 (CT-E2E-092/093).
Uso: csp_maps.py <base_url> [executável do Chrome]"""
import sys
import urllib.parse

from playwright.sync_api import sync_playwright

base = sys.argv[1]
chrome = sys.argv[2] if len(sys.argv) > 2 else None
loc = urllib.parse.quote('Florianópolis, SC')
failures = []


def check(cond, msg):
    print(('  ok   ' if cond else '  FAIL ') + msg)
    if not cond:
        failures.append(msg)


with sync_playwright() as pw:
    args = ['--use-angle=swiftshader', '--enable-unsafe-swiftshader']
    browser = pw.chromium.launch(executable_path=chrome, args=args) if chrome else pw.chromium.launch(args=args)
    page = browser.new_page()
    console, responses, requests = [], [], []
    page.on('console', lambda m: console.append(('console-error: ' if m.type == 'error' else '') + m.text))
    page.on('pageerror', lambda e: console.append('pageerror: ' + str(e)))
    page.on('request', lambda r: requests.append(r.url))
    page.on('response', lambda r: responses.append((r.url, r.status)))
    page.add_init_script("window.__csp = []; document.addEventListener('securitypolicyviolation', e => window.__csp.push(e.violatedDirective + ' ' + e.blockedURI));")
    for path in ['/busca?localidade=' + loc, '/busca/mapa?localidade=' + loc]:
        console.clear(); responses.clear(); requests.clear()
        notice_shown = False
        page.goto(base + path)
        page.wait_for_load_state('networkidle')
        if path.startswith('/busca/mapa'):
            page.wait_for_function("document.getElementById('result-map').dataset.points !== undefined", timeout=20000)
            page.wait_for_timeout(1500)
            attrib = page.locator('.maplibregl-ctrl-attrib')
            check(attrib.is_visible() and 'OpenStreetMap contributors' in attrib.inner_text() and 'Protomaps' in attrib.inner_text(),
                  path + ': atribuição OSM/Protomaps visível')
            check(attrib.locator('a[href="https://www.openstreetmap.org/copyright"]').count() == 1, path + ': link do copyright OSM')
            tiles = [st for u, st in responses if '/tiles/brasil.pmtiles' in u]
            notice = page.locator('#map-notice')
            if tiles and all(st in (200, 206) for st in tiles):
                check(206 in tiles, path + ': tiles via Range (206) (%d respostas)' % len(tiles))
            else:
                notice_shown = notice.is_visible() and 'indispon' in notice.inner_text()
                check(notice_shown, path + ': sem brasil.pmtiles -> aviso e mapa só com pins')
        csp = page.evaluate('window.__csp') + [m for m in console if 'Content Security Policy' in m]
        for m in csp:
            print('  csp  ' + str(m)[:300])
        check(not csp, path + ': nenhuma violação de CSP')
        external = [u for u in requests if not u.startswith(base)]
        check(not external, path + ': nenhuma requisição externa (%s)' % external[:3])
        google = [u for u in requests if 'google' in u or 'gstatic' in u]
        check(not google, path + ': nenhum host Google requisitado')
        errors = [m for m in console if 'pageerror' in m]
        check(not errors, path + ': sem erro de JS (%s)' % errors[:2])
        # Sem o brasil.pmtiles o HEAD de checagem dá 404 de propósito (aviso na tela); fora isso, console limpo.
        cerr = [m for m in console if m.startswith('console-error:') and not (notice_shown and '404' in m)]
        check(not cerr, path + ': zero erros no console (%s)' % cerr[:2])
    browser.close()

print('E2E csp_maps: %s' % ('PASSOU' if not failures else 'FALHOU (%d)' % len(failures)))
sys.exit(1 if failures else 0)

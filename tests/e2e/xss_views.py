"""E2E fix/xss-views: payload XSS salvo via API e renderizado (painel, busca, detalhe) não dispara
dialog e aparece como texto; nenhuma violação de CSP no console das páginas principais."""
import json
import sys
import urllib.parse

from playwright.sync_api import sync_playwright

base, db, email, password, phash, shots = sys.argv[1:7]
TITLE = '<svg onload=alert(1)>'
DESC = '<img src=x onerror="alert(document.domain)"><script>alert(3)</script> fim'
failures = []


def check(cond, msg):
    print(('  ok   ' if cond else '  FAIL ') + msg)
    if not cond:
        failures.append(msg)


with sync_playwright() as pw:
    browser = pw.chromium.launch()
    page = browser.new_page()
    dialogs, console = [], []
    page.on('dialog', lambda d: (dialogs.append(d.message), d.dismiss()))
    page.on('console', lambda m: console.append(m.text))
    page.on('pageerror', lambda e: console.append('pageerror: ' + str(e)))

    page.goto(base + '/entrar')
    page.fill('input[name=email]', email)
    page.fill('input[name=password]', password)
    page.click('form button[type=submit]')
    page.wait_for_url('**/painel**')

    csrf = page.locator('input[name=_csrf]').first.get_attribute('value')
    body = {
        'title': TITLE, 'description': DESC, 'cep': '88015-200', 'address_number': '10', 'address_complement': '',
        'max_guests': '5', 'nightly_price': 'R$ 350,00', 'cleaning_fee': 'R$ 0,00', 'checkin_time': '15:00',
        'checkout_time': '11:00', 'min_nights': '1', 'calendar_months': '6',
        'rooms': [{'beds': [{'type': 'single', 'variant': 'common'}]}], 'amenity': {'wifi': '1'},
    }
    r = page.request.put(base + '/api/casas/' + phash, data=json.dumps(body),
                         headers={'Content-Type': 'application/json', 'X-CSRF-Token': csrf})
    check(r.status == 200, 'payload XSS salvo via API (PUT %d)' % r.status)

    loc = urllib.parse.quote('Florianópolis, SC')
    pages = {
        'painel': ('/painel', '#painel-list h2'),
        'busca': ('/busca?localidade=' + loc, '#search-results .card-title'),
        'detalhe': ('/imoveis/' + phash, '#property-detail h1'),
    }
    for name, (path, sel) in pages.items():
        console.clear()
        page.goto(base + path)
        page.wait_for_selector(sel, timeout=10000)
        page.wait_for_timeout(600)
        check(page.locator(sel).first.text_content() == TITLE, name + ': título aparece como texto literal')
        imgs = page.eval_on_selector_all('img', 'els => els.map(e => e.getAttribute("src"))')
        check(all(not s or not s.startswith('javascript') for s in imgs), name + ': nenhum src javascript: (' + ', '.join(i for i in imgs if i)[:120] + ')')
        check(page.locator('svg[onload], img[onerror]').count() == 0, name + ': nenhum elemento injetado do payload')
        page.screenshot(path=f'{shots}/{name}.png', full_page=True)
    detail_desc = page.locator('#property-detail p', has_text='fim').first.text_content()
    check(detail_desc == DESC, 'detalhe: descrição aparece como texto literal')

    for path in ['/', '/entrar', '/cadastro', '/busca?localidade=' + loc, '/imoveis/' + phash, '/painel',
                 '/imoveis/' + phash + '/editar', '/imoveis/' + phash + '/calendario', '/conta', '/mapa']:
        console.clear()
        page.goto(base + path)
        page.wait_for_load_state('networkidle')
        # Tiles do OpenStreetMap: quebra conhecida da CSP pedida (img-src não libera o host); listada no relatório.
        known = [m for m in console if 'tile.openstreetmap.org' in m]
        if known:
            print('  info quebra conhecida em %s: %d tile(s) OSM bloqueado(s) pela CSP' % (path, len(known)))
        csp = [m for m in console if ('Content Security Policy' in m or 'pageerror' in m) and m not in known]
        check(not csp, 'sem violação CSP/erro JS em ' + path + ('' if not csp else ': ' + csp[0][:200]))

    check(not dialogs, 'nenhum dialog disparado pelo payload (' + '; '.join(dialogs) + ')')
    browser.close()

print('E2E xss: %s' % ('PASSOU' if not failures else 'FALHOU (%d)' % len(failures)))
sys.exit(1 if failures else 0)

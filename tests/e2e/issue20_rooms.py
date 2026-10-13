#!/usr/bin/env python3
"""E2E (navegador headless) da issue #20: editar camas por quarto e comodidades.

Uso: python tests/e2e/issue20_rooms.py <base_url> <sqlite> <email> <senha> <hash> <dir_prints> <before|after>
Requer: pip install playwright && python -m playwright install chromium
Sai com 0 se o comportamento do modo for o esperado (after: salva; before: só registra o erro).
"""
import json
import sqlite3
import sys

from playwright.sync_api import sync_playwright

base, db, email, password, ref, shots, mode = sys.argv[1:8]
fails = []


def check(cond, msg):
    print(("  ok   " if cond else "  FAIL ") + msg)
    if not cond:
        fails.append(msg)


with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page(viewport={"width": 1100, "height": 900}, locale="pt-BR")
    page.goto(base + "/entrar")
    page.fill('input[name="email"]', email)
    page.fill('input[name="password"]', password)
    page.click('form button[type="submit"]')
    page.wait_for_load_state("networkidle")
    page.goto(base + "/imoveis/" + ref + "/editar")
    page.wait_for_selector("[data-rooms]")
    rooms = page.locator("fieldset.rooms")
    rooms.scroll_into_view_if_needed()
    rooms.screenshot(path=f"{shots}/{mode}.png")

    # Edita: adiciona uma cama no quarto 2 e marca/desmarca comodidades.
    page.locator("[data-room]").nth(1).locator("[data-add-bed]").click()
    page.locator("[data-room]").nth(1).locator("[data-bed-type]").last.select_option("double")
    page.check('input[name="amenity[workspace]"]')
    page.uncheck('input[name="amenity[bbq]"]')
    if mode == "after":
        items = page.locator("[data-room-summary] li").all_text_contents()
        check(items == ["Quarto 1: 2 camas", "Quarto 2: 2 camas"], f"resumo atualizado ao vivo: {items}")
        page.locator("section.rooms-summary").screenshot(path=f"{shots}/after-summary.png")

    dialogs = []
    page.on("dialog", lambda d: (dialogs.append(d.message), d.accept()))
    page.locator("[data-save-house]").click()
    page.wait_for_timeout(1500)
    print("  alerta:", dialogs)
    rooms.screenshot(path=f"{shots}/{mode}-after-save.png")
    if mode == "after":
        # Sem form-data.js: erro visível e nada enviado.
        page2 = browser.new_page(locale="pt-BR")
        page2.context.add_cookies(page.context.cookies())
        page2.route("**/assets/form-data.js*", lambda r: r.abort())
        sent = []
        page2.on("request", lambda req: sent.append(req.url) if "/api/casas/" in req.url else None)
        msgs = []
        page2.on("dialog", lambda d: (msgs.append(d.message), d.accept()))
        page2.goto(base + "/imoveis/" + ref + "/editar")
        page2.check('input[name="amenity[ethernet]"]')
        page2.locator("[data-save-house]").click()
        page2.wait_for_timeout(800)
        check(bool(msgs) and "Recarregue" in msgs[0] and not sent, f"sem conversor: erro visível e nada enviado ({msgs[:1]}, {len(sent)} req)")
    browser.close()

con = sqlite3.connect(db)
row = con.execute("SELECT rooms, bedrooms, beds, amenities FROM properties WHERE public_hash = ?", (ref,)).fetchone()
saved_rooms = json.loads(row[0] or "[]")
amen = json.loads(row[3] or "{}")
beds_per_room = [len(r.get("beds", [])) for r in saved_rooms]
print("  banco:", beds_per_room, row[1], row[2], sorted(k for k, v in amen.items() if v))

if mode == "after":
    check(dialogs[:1] == ["Imóvel atualizado"], "salvar mostra 'Imóvel atualizado'")
    check(beds_per_room == [2, 2] and row[1] == 2 and row[2] == 4, "quartos/camas salvos (2+2)")
    check(saved_rooms[1]["beds"][1].get("type") == "double", "tipo da cama nova salvo")
    check(bool(amen.get("workspace")) and not amen.get("bbq") and bool(amen.get("pool")), "comodidades salvas (workspace on, bbq off)")
else:
    check(bool(dialogs) and dialogs[0] != "Imóvel atualizado", f"bug reproduzido: {dialogs[:1]}")

print("PASSOU" if not fails else f"FALHOU: {len(fails)}")
sys.exit(1 if fails else 0)

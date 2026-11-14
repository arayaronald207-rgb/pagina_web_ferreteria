"""Prueba local con Chrome y Playwright. Usa PHP y SQLite en memoria, nunca MySQL.

Ejecutar: python pruebas/probar_interfaz.py
Requiere el paquete Python playwright y Chrome instalado; no descarga navegadores.
Las capturas y la copia aislada se guardan en una carpeta temporal.
"""

import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import time
from urllib.parse import parse_qs, urlparse
from urllib.request import urlopen

from playwright.sync_api import sync_playwright


ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php') or r'C:\xampp2\php\php.exe'
CHROME = os.environ.get('CRUCE_CHROME', r'C:\Program Files\Google\Chrome\Application\chrome.exe')
ARTIFACTS = Path(tempfile.mkdtemp(prefix='el-cruce-visual-'))
ERRORS = []


def check(condition, message):
    if not condition:
        raise AssertionError(message)


def layout(page):
    return page.evaluate('''() => {
        const width = document.documentElement.clientWidth;
        const outside = [...document.querySelectorAll('main *, .cabecera__interior *, footer *')]
            .filter(e => !e.closest('iframe, .solo-lectores, .boton-lista__texto'))
            .filter(e => { const r = e.getBoundingClientRect();
                return r.width && r.height && (r.left < -1 || r.right > width + 1); })
            .map(e => e.className || e.tagName);
        return {width, scroll: document.documentElement.scrollWidth, outside};
    }''')


def key(page, name, shift=False):
    page.keyboard.press(('Shift+' if shift else '') + name)


def menu(page, width):
    button = page.locator('.boton-menu')
    panel = page.locator('#panel-navegacion')
    if width > 1120:
        check(not button.is_visible(), 'Menú móvil visible en escritorio')
        page.locator('.navegacion__categorias summary').click()
        check(page.locator('.navegacion__categorias').get_attribute('open') is not None, 'No abre categorías')
        key(page, 'Escape')
        check(page.locator('.navegacion__categorias').get_attribute('open') is None, 'Escape no cierra categorías')
        return
    button.click()
    check(button.get_attribute('aria-expanded') == 'true', 'No abre menú')
    check(panel.get_attribute('aria-modal') == 'true', 'Falta semántica de diálogo')
    check(page.locator('main').evaluate('(e) => e.inert'), 'Fondo sigue activo')
    check(page.locator('.menu-panel__cerrar').evaluate('(e) => e === document.activeElement'), 'Foco inicial incorrecto')
    key(page, 'Tab', shift=True)
    check(page.locator('.navegacion__consulta').evaluate('(e) => e === document.activeElement'), 'Foco sale por inicio')
    key(page, 'Tab')
    check(page.locator('.menu-panel__cerrar').evaluate('(e) => e === document.activeElement'), 'Foco sale por final')
    key(page, 'Escape')
    check(button.get_attribute('aria-expanded') == 'false', 'Escape no cierra menú')
    check(button.evaluate('(e) => e === document.activeElement'), 'No devuelve foco')
    check(not page.locator('main').evaluate('(e) => e.inert'), 'Fondo quedó inerte')
    button.click()
    page.mouse.click(width - 10, 250)
    check(button.get_attribute('aria-expanded') == 'false', 'El fondo no cierra menú')
    button.click()
    page.locator('.menu-panel__cerrar').click()
    check(button.get_attribute('aria-expanded') == 'false', 'Botón de cierre no funciona')
    panel.wait_for(state='hidden')


def run(browser, base):
    context = browser.new_context()
    # Se prueban recursos locales; los servicios externos no forman parte de la fixture.
    context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(base) else route.abort())
    page = context.new_page()
    page.add_init_script('''window.animacionesPortada = 0;
        const animate = Element.prototype.animate;
        Element.prototype.animate = function(...args) {
            if (this.hasAttribute('data-revelar')) window.animacionesPortada++;
            return animate.apply(this, args);
        };''')
    page.on('pageerror', lambda error: ERRORS.append(str(error)))
    page.on('console', lambda msg: ERRORS.append(msg.text) if msg.type == 'error' and 'ERR_FAILED' not in msg.text else None)
    page.goto(base + 'index.php')
    page.locator('.salto-contenido').focus()
    check(page.locator('.salto-contenido').evaluate('''e => {
        const r = e.getBoundingClientRect();
        return e.contains(document.elementFromPoint(r.x + r.width/2, r.y + r.height/2));
    }'''), 'Enlace para saltar al contenido tapado')
    for width in (320, 375, 390, 600, 768, 1024, 1120, 1121, 1440, 1920):
        page.set_viewport_size({'width': width, 'height': 900})
        for path in ('index.php', 'catalogo.php', 'catalogo.php?categoria=Sin%20productos', 'catalogo.php?buscar=Martillo&categoria=Herramientas'):
            response = page.goto(base + path, wait_until='networkidle')
            check(response.status == 200, 'HTTP incorrecto: ' + path)
            metrics = layout(page)
            check(metrics['scroll'] <= metrics['width'] + 1 and not metrics['outside'], f'{width} {path}: desbordamiento {metrics}')
            check(page.locator('img').evaluate_all('(images) => images.every(i => i.complete && i.naturalWidth > 0)'), 'Imagen rota')
            overlap = page.evaluate('''() => {
                const selectors = ['.marca', '.buscador', '.cabecera__acciones', '.boton-menu'];
                const rects = selectors.map(s => document.querySelector(s)).filter(e => e.getClientRects().length)
                    .map(e => e.getBoundingClientRect());
                return rects.some((a, i) => rects.slice(i+1).some(b =>
                    Math.min(a.right,b.right)-Math.max(a.left,b.left)>1 &&
                    Math.min(a.bottom,b.bottom)-Math.max(a.top,b.top)>1));
            }''')
            check(not overlap, f'{width}: controles de cabecera superpuestos')
            menu(page, width)
            if width in (390, 768, 1440):
                page.screenshot(path=str(ARTIFACTS / f'{width}-{path.split("?")[0]}' .replace('.php', '.png')), full_page=True)
        print(f'OK responsive/menú: {width}px', flush=True)

    page.goto(base + 'index.php', wait_until='networkidle')
    check(page.locator('.categoria-destacada').count() == 6, 'Portada debe limitar a seis categorías')
    check(page.locator('a[href^="https://wa.me/"]').count() == 2, 'WhatsApp debe quedar solo en navegación y pie')
    check(page.locator('.portada-ayuda').count() == 0, 'Sección redundante sigue presente')
    for href in page.locator('.categoria-destacada').evaluate_all('(els) => els.map(e => e.href)'):
        page.goto(href, wait_until='networkidle')
        category = page.locator('#categoria_catalogo').input_value()
        check(category == parse_qs(urlparse(href).query)['categoria'][0], 'Tarjeta no conserva categoría')
        check(page.locator('.buscador input[name="categoria"]').input_value() == category, 'Buscador perdió categoría')
    page.goto(base + 'catalogo.php?categoria=Sin%20productos')
    check(page.locator('#productos').inner_text().find('No hay productos publicados') >= 0, 'Falta estado de categoría vacía')
    page.locator('#categoria_catalogo').select_option('Herramientas')
    page.locator('.catalogo__boton').click()
    page.locator('#busqueda_cabecera').fill('Martillo')
    with page.expect_navigation(wait_until='networkidle'):
        page.locator('#busqueda_cabecera').press('Enter')
    check(page.locator('#categoria_catalogo').input_value() == 'Herramientas', 'Búsqueda pierde categoría')
    check(page.locator('.producto h2').all_text_contents() == ['Martillo de prueba'], 'Filtros combinados incorrectos')
    page.locator('#categoria_catalogo').select_option('Sin productos')
    page.locator('.catalogo__boton').focus()
    with page.expect_navigation(wait_until='networkidle'):
        key(page, 'Enter')
    check(page.locator('#busqueda_cabecera').input_value() == 'Martillo', 'Filtro pierde búsqueda')
    check('No encontramos coincidencias' in page.locator('#productos').inner_text(), 'Falta estado combinado vacío')
    page.locator('.catalogo__limpiar').click()
    check(page.locator('#categoria_catalogo').input_value() == '' and page.locator('#busqueda_cabecera').input_value() == '', 'Limpiar filtros falla')

    page.set_viewport_size({'width': 390, 'height': 844})
    for name in ('index.php', 'catalogo.php'):
        page.goto(base + name + '?fixture_vacia=1', wait_until='networkidle')
        check(page.locator('.categoria-destacada, .producto').count() == 0, 'Fixture vacía publica elementos')
        check(layout(page)['scroll'] <= 390, 'Estado vacío desborda')
        check('Estamos organizando' in page.locator('main').inner_text() if name == 'index.php' else 'Estamos preparando' in page.locator('main').inner_text(), 'Estado vacío incorrecto')
        page.screenshot(path=str(ARTIFACTS / ('vacio-' + name.replace('.php', '.png'))), full_page=True)
        page.goto(base + name + '?fixture_error=1', wait_until='networkidle')
        check(page.locator('[role="alert"]').count() == 1 and 'detalle privado' not in page.content(), 'Error interno expuesto')

    page.goto(base + 'index.php')
    page.locator('.boton-menu').click()
    page.set_viewport_size({'width': 1440, 'height': 900})
    page.wait_for_function('!document.body.classList.contains("menu-bloqueado")')
    check(not page.locator('main').evaluate('(e) => e.inert'), 'Redimensionar deja fondo bloqueado')

    page.goto(base + 'index.php')
    page.locator('.categoria-destacada').last.scroll_into_view_if_needed()
    page.wait_for_function('window.animacionesPortada > 0')
    page.evaluate('window.scrollTo({top: 0, behavior: "instant"})')
    page.locator('.salto-contenido').focus()
    check(page.locator('.salto-contenido').evaluate('''e => {
        const r = e.getBoundingClientRect();
        return e.contains(document.elementFromPoint(r.x + r.width/2, r.y + r.height/2));
    }'''), 'Enlace para saltar al contenido tapado')
    key(page, 'Enter')
    check(page.url.endswith('#contenido'), 'Salto al contenido falla')
    page.locator('.portada-local figcaption a').click()
    check(page.url.endswith('#contacto'), 'Contacto no navega al pie')

    page.emulate_media(reduced_motion='reduce')
    page.goto(base + 'index.php')
    page.locator('.categoria-destacada').last.scroll_into_view_if_needed()
    check(page.evaluate('window.animacionesPortada') == 0, 'Se iniciaron animaciones con movimiento reducido')
    check(page.locator('[data-revelar]').evaluate_all('(els) => els.every(e => e.getAnimations().length === 0)'), 'Animaciones ignoran movimiento reducido')
    check(page.locator('[data-revelar]').evaluate_all('(els) => els.every(e => getComputedStyle(e).opacity === "1")'), 'Contenido oculto')

    # Sin JavaScript la navegación y el contenido deben seguir disponibles.
    nojs = browser.new_context(java_script_enabled=False, viewport={'width': 375, 'height': 812})
    nojs.route('**/*', lambda route: route.continue_() if route.request.url.startswith(base) else route.abort())
    static = nojs.new_page()
    static.goto(base + 'index.php')
    check(static.locator('.navegacion').is_visible(), 'Sin JS desaparece navegación')
    check(static.locator('.categoria-destacada').first.is_visible(), 'Sin JS desaparecen categorías')
    nojs.close()
    context.close()
    check(not ERRORS, f'Errores de navegador: {ERRORS}')
    print('OK: tarjetas, filtros, búsqueda, teclado, anclas, movimiento reducido y sin JS', flush=True)


def main():
    with tempfile.TemporaryDirectory(prefix='el-cruce-fixture-') as directory:
        root = Path(directory)
        for relative in ('public/index.php', 'public/catalogo.php', 'app/Vistas/plantillas/cabecera_publica.php', 'app/Vistas/plantillas/pie_publico.php'):
            destination = root / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(ROOT / relative, destination)
        shutil.copytree(ROOT / 'public/assets', root / 'public/assets')
        (root / 'config').mkdir()
        (root / 'config/conexion.php').write_text('''<?php
function obtener_conexion(): PDO {
    static $db;
    if (isset($_GET['fixture_error'])) throw new RuntimeException('detalle privado');
    if ($db instanceof PDO) return $db;
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('CREATE TABLE categorias (id_categoria INTEGER PRIMARY KEY, nombre TEXT)');
    $db->exec('CREATE TABLE productos (id_producto INTEGER PRIMARY KEY, id_categoria INTEGER, nombre TEXT, descripcion TEXT, imagen TEXT, visible INTEGER)');
    if (isset($_GET['fixture_vacia'])) return $db;
    $insertar = $db->prepare('INSERT INTO categorias VALUES (?, ?)');
    foreach (['Acabados','Herramientas','Pinturas & decoración "hogar" <prueba>','Sin productos','Solo ocultos',str_repeat('NombreExtenso', 7),'Última categoría'] as $i=>$nombre) $insertar->execute([$i+1,$nombre]);
    $db->exec("INSERT INTO productos VALUES (1,2,'Martillo de prueba','Producto ficticio de prueba',NULL,1),(2,1,'Brocha','Producto ficticio de prueba',NULL,1),(3,5,'Producto oculto','No publicar',NULL,0)");
    return $db;
}
''', encoding='utf-8')
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        base = f'http://127.0.0.1:{port}/'
        with (ARTIFACTS / 'php.log').open('w') as log:
            server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', str(root / 'public')], stdout=log, stderr=log)
            try:
                for attempt in range(50):
                    try:
                        with urlopen(base + 'index.php', timeout=1) as response:
                            check(response.status == 200, 'Fixture no disponible')
                        break
                    except OSError:
                        time.sleep(.1)
                else:
                    raise RuntimeError('No inició PHP')
                with sync_playwright() as playwright:
                    browser = playwright.chromium.launch(executable_path=CHROME, headless=True)
                    try:
                        run(browser, base)
                    finally:
                        browser.close()
            finally:
                server.terminate()
                server.wait(timeout=10)
    print(json.dumps({'capturas': str(ARTIFACTS), 'mysql': 'no utilizado'}, ensure_ascii=False))


if __name__ == '__main__':
    main()

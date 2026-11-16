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
        page.locator('.navegacion__categorias summary').click()
        page.locator('.megamenu__lista a').first.focus()
        key(page, 'Escape')
        check(page.locator('.navegacion__categorias summary').evaluate('e => e === document.activeElement'), 'Escape no devuelve foco al disparador')
        page.locator('.navegacion__categorias summary').click()
        page.locator('#busqueda_cabecera').click()
        check(page.locator('.navegacion__categorias').get_attribute('open') is None, 'Clic fuera no cierra categorías')
        return
    button.click()
    check(button.get_attribute('aria-expanded') == 'true', 'No abre menú')
    check(panel.get_attribute('aria-modal') == 'true', 'Falta semántica de diálogo')
    check(page.locator('main').evaluate('(e) => e.inert'), 'Fondo sigue activo')
    check(page.locator('.menu-panel__cerrar').evaluate('(e) => e === document.activeElement'), 'Foco inicial incorrecto')
    key(page, 'Tab', shift=True)
    check(page.locator('.navegacion__lista > a').last.evaluate('(e) => e === document.activeElement'), 'Foco sale por inicio')
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


def header_checks(page, width):
    page.evaluate('window.scrollTo({top: 450, behavior: "instant"})')
    check(page.locator('.cabecera').evaluate('e => Math.abs(e.getBoundingClientRect().top) < 1'), 'Cabecera no es sticky')
    for selector in ('.marca', '.buscador'):
        check(page.locator(selector).evaluate('''e => {
            const r = e.getBoundingClientRect();
            return r.top >= 0 && r.bottom <= innerHeight;
        }'''), 'Logo o buscador ocultos al desplazar')
    menu(page, width)  # También abrir/cerrar después de desplazarse.
    if width <= 1120:
        page.locator('.boton-menu').click()
    page.locator('.navegacion__categorias summary').click()
    links = page.locator('.megamenu__lista a')
    check(page.locator('.navegacion__categorias').get_attribute('open') is not None, 'Megamenú cerrado tras desplazarse')
    check(links.first.is_visible(), 'Enlaces de categorías no visibles')
    check(links.count() == 7, 'El megamenú debe mostrar todas las categorías, no solo las seis de portada')
    check(page.locator('#categorias-menu').evaluate('''e => {
        const r = e.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth;
    }'''), 'Megamenú fuera de la pantalla')
    if width > 1120:
        check(links.first.evaluate('''e => {
            const r=e.getBoundingClientRect();
            return e.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2));
        }'''), 'Megamenú tapado tras desplazarse')
        check(page.locator('.megamenu__lista').evaluate('e => getComputedStyle(e).columnCount') == ('5' if width >= 1400 else '4'), 'Distribución incorrecta en escritorio')
    else:
        check(page.locator('.megamenu__lista').evaluate('e => getComputedStyle(e).columnCount') == '1', 'Móvil debe usar una columna')
    if width in (375, 1440):
        page.screenshot(path=str(ARTIFACTS / f'{width}-megamenu.png'))
    key(page, 'Escape')
    if width <= 1120:
        page.locator('#panel-navegacion').wait_for(state='hidden')
    page.evaluate('window.scrollTo({top: 0, behavior: "instant"})')


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
            header_checks(page, width)
            if width in (390, 768, 1440):
                page.screenshot(path=str(ARTIFACTS / f'{width}-{path.split("?")[0]}' .replace('.php', '.png')), full_page=True)
        print(f'OK responsive/menú: {width}px', flush=True)

    page.goto(base + 'index.php', wait_until='networkidle')
    check(page.locator('.categoria-destacada').count() == 6, 'Portada debe limitar a seis categorías')
    check(page.locator('a[href^="https://wa.me/"]').count() == 1 and page.locator('.pie-pagina__whatsapp-enlace').is_visible(), 'La portada debe conservar WhatsApp en el pie')
    check(page.locator('.portada-ayuda').count() == 0, 'Sección redundante sigue presente')
    for href in page.locator('.categoria-destacada').evaluate_all('(els) => els.map(e => e.href)'):
        page.goto(href, wait_until='networkidle')
        category = page.locator('.buscador input[name="categoria"]').input_value()
        check(category == parse_qs(urlparse(href).query)['categoria'][0], 'Tarjeta no conserva categoría')
        check(page.locator('.buscador input[name="categoria"]').input_value() == category, 'Buscador perdió categoría')
    page.goto(base + 'catalogo.php?categoria=Sin%20productos')
    check(page.locator('#productos').inner_text().find('No hay productos publicados') >= 0, 'Falta estado de categoría vacía')
    page.locator('.navegacion__categorias summary').click()
    page.locator('.megamenu__lista a').filter(has_text='Herramientas').click()
    page.locator('#busqueda_cabecera').fill('Martillo')
    with page.expect_navigation(wait_until='networkidle'):
        page.locator('#busqueda_cabecera').press('Enter')
    check(page.locator('.buscador input[name="categoria"]').input_value() == 'Herramientas', 'Búsqueda pierde categoría')
    check(page.locator('.producto h2').all_text_contents() == ['Martillo de prueba'], 'Filtros combinados incorrectos')
    page.locator('.navegacion__categorias summary').click()
    page.locator('.megamenu__lista a').filter(has_text='Sin productos').focus()
    with page.expect_navigation(wait_until='networkidle'):
        key(page, 'Enter')
    check(page.locator('#busqueda_cabecera').input_value() == 'Martillo', 'Filtro pierde búsqueda')
    check('No encontramos coincidencias' in page.locator('#productos').inner_text(), 'Falta estado combinado vacío')
    page.locator('.catalogo__limpiar').click()
    check(page.locator('.buscador input[name="categoria"]').count() == 0 and page.locator('#busqueda_cabecera').input_value() == '', 'Limpiar filtros falla')
    check(page.locator('#filtros, main form').count() == 0, 'El catálogo sin filtros debe quedar limpio')

    # Los enlaces del menú usan nombres reales escapados, incluso caracteres especiales.
    page.set_viewport_size({'width': 1440, 'height': 900})
    page.goto(base + 'catalogo.php?buscar=Martillo')
    links = page.locator('.megamenu__lista a').evaluate_all('(els) => els.map(e => ({href:e.href, text:e.firstChild.textContent}))')
    for link in links:
        page.goto(link['href'])
        check(page.locator('.buscador input[name="categoria"]').input_value() == link['text'], 'Enlace del megamenú pierde categoría')
        check(page.locator('#busqueda_cabecera').input_value() == 'Martillo', 'Megamenú pierde búsqueda')
    page.locator('#busqueda_cabecera').fill('"<& prueba')
    page.locator('.buscador button').click()
    check(page.locator('#busqueda_cabecera').input_value() == '"<& prueba', 'Búsqueda por botón pierde caracteres especiales')
    for path in ('index.php', 'catalogo.php'):
        for query, message in (('fixture_vacia=1', 'Aún no hay categorías'), ('fixture_error=1', 'no están disponibles')):
            page.goto(base + path + '?' + query)
            page.locator('.navegacion__categorias summary').click()
            check(message in page.locator('#categorias-menu').inner_text(), 'Falta estado vacío o error del megamenú')

    page.goto(base + 'index.php#inicio')
    page.wait_for_timeout(700)
    check(page.locator('#inicio').evaluate('e => e.getBoundingClientRect().top >= document.querySelector(".cabecera").getBoundingClientRect().bottom - 1'), 'La cabecera tapa el destino del ancla')

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


def run_megamenu_interactions(browser, base):
    context = browser.new_context(viewport={'width': 1440, 'height': 900})
    context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(base) else route.abort())
    page = context.new_page()
    page.on('pageerror', lambda error: ERRORS.append(str(error)))
    for width in (1121, 1440):
        page.set_viewport_size({'width': width, 'height': 900})
        page.goto(base + 'index.php')
        page.evaluate('window.scrollTo({top: 400, behavior: "instant"})')
        trigger = page.locator('.navegacion__categorias summary')
        trigger.hover()
        check(page.locator('.navegacion__categorias').get_attribute('open') is not None, 'Hover no abre automáticamente')
        check(page.locator('#categorias-menu').evaluate('e => Math.abs(e.getBoundingClientRect().top - document.querySelector(".navegacion").getBoundingClientRect().bottom) < 1'), 'Hay un espacio entre navegación y panel')
        # Cruzar el borde inferior del botón antes de recorrer las columnas.
        rect = trigger.bounding_box()
        page.mouse.move(rect['x'] + rect['width'] / 2, rect['y'] + rect['height'] + 2, steps=8)
        for link in (page.locator('.megamenu__lista a').first, page.locator('.megamenu__lista a').last):
            link.hover()
            page.wait_for_timeout(250)
            check(page.locator('.navegacion__categorias').get_attribute('open') is not None, 'Menú se cierra dentro del panel')
        page.locator('.marca').hover()
        page.wait_for_function('!document.querySelector(".navegacion__categorias").open')
        trigger.hover()
        page.locator('.marca').hover()
        trigger.hover()
        page.wait_for_timeout(250)
        check(page.locator('.navegacion__categorias').get_attribute('open') is not None, 'Un cierre pendiente causa parpadeo al regresar')
        key(page, 'Escape')
        page.wait_for_timeout(250)
        check(page.locator('.navegacion__categorias').get_attribute('open') is None, 'Escape reabre el menú con el cursor encima')
        page.locator('.marca').hover()
        trigger.click()
        check(page.locator('.navegacion__categorias').get_attribute('open') is not None, 'Clic después de hover cierra accidentalmente')
        trigger.click()
        check(page.locator('.navegacion__categorias').get_attribute('open') is None, 'Segundo clic no cierra')
        trigger.focus()
        key(page, 'Enter')
        key(page, 'Tab')
        page.locator('.marca').hover()
        page.wait_for_timeout(250)
        check(page.locator('.navegacion__categorias').get_attribute('open') is not None, 'Ratón oculta el menú durante navegación con teclado')
        key(page, 'Escape')
        check(trigger.evaluate('e => e === document.activeElement'), 'Escape no devuelve el foco')
        trigger.hover()
        page.locator('.marca').hover()
        page.wait_for_function('!document.querySelector(".navegacion__categorias").open')
        key(page, 'Space')
        page.locator('#busqueda_cabecera').click()
        check(page.locator('.navegacion__categorias').get_attribute('open') is None, 'Clic exterior no cierra')
        print(f'OK hover/clic/teclado: {width}px', flush=True)
    context.close()

    for width in (375, 1024, 1440):
        touch = browser.new_context(has_touch=True, viewport={'width': width, 'height': 900})
        touch.route('**/*', lambda route: route.continue_() if route.request.url.startswith(base) else route.abort())
        page = touch.new_page()
        page.on('pageerror', lambda error: ERRORS.append(str(error)))
        page.goto(base + 'catalogo.php')
        if width <= 1120:
            page.locator('.boton-menu').tap()
        trigger = page.locator('.navegacion__categorias summary')
        trigger.tap()
        check(page.locator('.megamenu__lista').is_visible(), 'Pulsación táctil no abre')
        trigger.tap()
        check(page.locator('.navegacion__categorias').get_attribute('open') is None, 'Segunda pulsación táctil no cierra')
        trigger.tap()
        page.locator('.megamenu__lista a').filter(has_text='Herramientas').tap()
        check(page.locator('.buscador input[name="categoria"]').input_value() == 'Herramientas', 'Pulsación en categoría no aplica filtro')
        check(page.locator('.navegacion__categorias').get_attribute('open') is None, 'Selección táctil deja menú abierto')
        touch.close()
        print(f'OK pulsación táctil: {width}px', flush=True)
    check(not ERRORS, f'Errores JavaScript: {ERRORS}')


def run_admin(browser, base):
    context = browser.new_context()
    context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(base) else route.abort())
    page = context.new_page()
    page.on('pageerror', lambda error: ERRORS.append(str(error)))
    page.goto(base + 'administracion.php')
    check(page.url.endswith('iniciar_sesion.php'), 'El panel debe exigir autenticación')
    page.locator('#correo').fill('fixture@example.test')
    page.locator('#contrasena').fill('incorrecta')
    page.locator('.acceso-boton').click()
    check(page.locator('.acceso-error').is_visible(), 'No rechaza contraseña incorrecta')
    page.locator('#contrasena').fill('Fixture-Only-123')
    page.locator('.acceso-boton').click()
    check(page.url.endswith('administracion.php'), 'Inicio de sesión no lleva al panel')
    for width in (320, 375, 768, 1024, 1440, 1920):
        page.set_viewport_size({'width': width, 'height': 900})
        for path in ('administracion.php', 'categorias.php', 'categorias.php?editar=2'):
            response = page.goto(base + path)
            check(response.status == 200, 'Panel no disponible: ' + path)
            check(page.evaluate('document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1'),
                  f'{width}: desbordamiento de página administrativa')
            check(page.evaluate('typeof bootstrap.Alert === "function"'), 'Bundle Bootstrap no disponible')
            if path == 'administracion.php':
                check(page.locator('.panel-indicadores .card').count() == 3, 'Faltan indicadores')
            else:
                check(page.locator('table tbody tr').count() == 7, 'Listado incompleto')
                check(page.locator('tr').filter(has_text='Herramientas').locator('button').count() == 0,
                      'Categoría con productos ofrece eliminación')
                if width == 320:
                    check(page.locator('.table-responsive').evaluate('e => e.scrollWidth > e.clientWidth'),
                          'La tabla no admite desplazamiento local')
                if 'editar' in path:
                    check(page.locator('#nombre_categoria').input_value() == 'Herramientas', 'Edición perdió el nombre')
            if width in (375, 1440):
                page.screenshot(path=str(ARTIFACTS / f'{width}-{path.split("?")[0]}.png'), full_page=True)
        print(f'OK panel/categorías: {width}px', flush=True)
    page.goto(base + 'categorias.php')
    page.locator('#nombre_categoria').fill('Herramientas')
    page.locator('.panel-formulario button[type="submit"]').click()
    check(page.locator('.alert-danger').is_visible(), 'Falta mensaje de categoría duplicada')
    page.goto(base + 'categorias.php')
    page.locator('tr').filter(has_text='Sin productos').locator('button').click()
    check(page.locator('.panel-confirmacion').is_visible(), 'No aparece confirmación de eliminación')
    page.locator('.panel-confirmacion a').click()
    check(page.locator('.panel-confirmacion').count() == 0, 'Cancelar no cierra confirmación')
    page.locator('form[action="cerrar_sesion.php"] button').click()
    page.goto(base + 'administracion.php')
    check(page.url.endswith('iniciar_sesion.php'), 'Cerrar sesión no revoca acceso al panel')
    check(not ERRORS, f'Errores JavaScript: {ERRORS}')
    context.close()
    print('OK: tabla, edición, validación y confirmación de eliminación', flush=True)


def main():
    with tempfile.TemporaryDirectory(prefix='el-cruce-fixture-') as directory:
        root = Path(directory)
        for relative in ('public/index.php', 'public/catalogo.php', 'public/administracion.php', 'public/categorias.php', 'public/iniciar_sesion.php', 'public/cerrar_sesion.php',
                         'app/Servicios/autenticacion.php', 'app/Servicios/panel_administrativo.php', 'app/Servicios/categorias.php', 'app/Servicios/imagenes_productos.php', 'app/Servicios/detalles_productos.php', 'app/Servicios/catalogo.php',
                         'app/Vistas/plantillas/cabecera_administrativa.php', 'app/Vistas/plantillas/pie_administrativo.php',
                         'app/Vistas/plantillas/cabecera_publica.php', 'app/Vistas/plantillas/pie_publico.php'):
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
    $db->exec('CREATE TABLE productos (id_producto INTEGER PRIMARY KEY, id_categoria INTEGER, nombre TEXT, descripcion TEXT, imagen TEXT, visible INTEGER, codigo TEXT)');
    $db->exec('CREATE TABLE usuarios (id_usuario INTEGER PRIMARY KEY, nombre TEXT, correo TEXT, rol TEXT, activo INTEGER, clave_hash TEXT)');
    $db->prepare("INSERT INTO usuarios VALUES (1, 'Personal de prueba', 'fixture@example.test', 'administrador', 1, ?)")->execute([password_hash('Fixture-Only-123', PASSWORD_DEFAULT)]);
    $db->exec('CREATE TABLE solicitudes (estado TEXT)');
    if (isset($_GET['fixture_vacia'])) return $db;
    $insertar = $db->prepare('INSERT INTO categorias VALUES (?, ?)');
    foreach (['Acabados','Herramientas','Pinturas & decoración "hogar" <prueba>','Sin productos','Solo ocultos',str_repeat('NombreExtenso', 7),'Última categoría'] as $i=>$nombre) $insertar->execute([$i+1,$nombre]);
    $db->exec("INSERT INTO productos VALUES (1,2,'Martillo de prueba','Producto ficticio de prueba',NULL,1,'COD-1'),(2,1,'Brocha','Producto ficticio de prueba',NULL,1,'COD-2'),(3,5,'Producto oculto','No publicar',NULL,0,'COD-3')");
    return $db;
}
''', encoding='utf-8')
        # Esta entrada existe solo en la copia temporal: nunca se publica ni usa MySQL.
        (root / 'public/fixture_sesion.php').write_text('''<?php
require __DIR__ . '/../app/Servicios/autenticacion.php';
establecer_sesion_usuario(['id_usuario'=>1,'nombre'=>'Personal de prueba','correo'=>'fixture@example.test','rol'=>'administrador']);
echo 'OK';
''', encoding='utf-8')
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        base = f'http://127.0.0.1:{port}/'
        with (ARTIFACTS / 'php.log').open('w') as log:
            (root / 'sesiones').mkdir()
            # Las comillas pertenecen al valor INI; protegen espacios y ~ en Windows.
            server = subprocess.Popen([PHP, '-d', 'session.save_path="' + (root / 'sesiones').as_posix() + '"', '-S', f'127.0.0.1:{port}', '-t', str(root / 'public')], stdout=log, stderr=log)
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
                        run_megamenu_interactions(browser, base)
                        run_admin(browser, base)
                    finally:
                        browser.close()
            finally:
                server.terminate()
                server.wait(timeout=10)
    print(json.dumps({'capturas': str(ARTIFACTS), 'mysql': 'no utilizado'}, ensure_ascii=False))


if __name__ == '__main__':
    main()

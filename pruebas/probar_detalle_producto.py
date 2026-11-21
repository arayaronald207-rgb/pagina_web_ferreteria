"""Detalle y ampliación opcional: reutiliza la copia aislada de probar_productos.
Solo escribe en SQLite y archivos temporales. Nunca lee .env ni usa MySQL.
"""
import base64
from contextlib import closing
import sqlite3
from urllib.parse import parse_qs, urlparse
import probar_productos as fixture

check = fixture.check


def run(browser, base, root):
    admin = browser.new_context(viewport={'width': 1440, 'height': 900})
    publico = browser.new_context(viewport={'width': 1440, 'height': 900})
    errors = []
    for context in (admin, publico):
        context.route('**/*', lambda r: r.continue_() if r.request.url.startswith(base) else r.abort())
    page = admin.new_page()
    visitante = publico.new_page()
    for tab in (page, visitante):
        tab.on('pageerror', lambda error: errors.append(str(error)))
    page.goto(base + 'iniciar_sesion.php')
    page.locator('#correo').fill('admin@example.test')
    page.locator('#contrasena').fill('Fixture-Only-123')
    page.locator('.acceso-boton').click()
    page.goto(base + 'productos.php?nuevo=1')
    check(page.locator('#especificacion_marca').count() == 0, 'Sin 003 conserva el formulario original')
    nombre = 'Taladro <serie> & portátil'
    descripcion = 'Uso sobre mampostería.\n' + 'Descripción completa de prueba. ' * 100 + '\n<script>alert(1)</script> FIN'
    page.locator('#codigo_producto').fill('MANUAL-ABC-123')
    page.locator('#nombre_producto').fill(nombre)
    page.locator('#descripcion_producto').fill(descripcion)
    page.locator('#categoria_producto').select_option('1')
    page.locator('#visible_producto').select_option('1')
    page.locator('.panel-producto-formulario button[type="submit"]').click()
    with closing(sqlite3.connect(root / 'datos.sqlite')) as db:
        product_id = db.execute("SELECT id_producto FROM productos WHERE codigo='MANUAL-ABC-123'").fetchone()[0]
        url = base + f'producto.php?id={product_id}'
        for termino in ('Talad', 'ABC-12', 'mamposter'):
            visitante.goto(base + 'catalogo.php?buscar=' + termino + '&categoria=Herramientas')
            check(visitante.locator('.producto').count() == 1, 'Búsqueda pública parcial: ' + termino)
            for selector in ('.producto__imagen', '.producto h2 a', '.producto__detalles'):
                visitante.locator(selector).click()
                check(visitante.locator('#titulo-producto').inner_text() == nombre, 'Detalle desde ' + selector)
                check(visitante.locator('.detalle__descripcion').inner_text() == descripcion, 'Descripción sin recortes y escapada')
                check(visitante.locator('.detalle__codigo strong').inner_text() == 'MANUAL-ABC-123', 'Código manual conservado')
                check(visitante.locator('.detalle__categoria').inner_text() == 'Herramientas', 'Categoría real')
                check(visitante.locator('#imagen-principal').get_attribute('src').endswith('producto_sin_imagen.svg'), 'Imagen predeterminada')
                check(visitante.locator('.detalle__especificaciones').count() == 0, 'No inventar especificaciones')
                check(visitante.locator('input[type="search"]').count() == 1, 'Un solo buscador')
                href = visitante.locator('.detalle__whatsapp').get_attribute('href')
                check(urlparse(href).path == '/50670988980', 'WhatsApp oficial')
                mensaje = parse_qs(urlparse(href).query)['text'][0]
                check(nombre in mensaje and 'MANUAL-ABC-123' in mensaje, 'WhatsApp incluye nombre y código')
                check(visitante.locator('.detalle__volver').get_attribute('href') == f'catalogo.php?buscar={termino}&categoria=Herramientas#productos', 'Regreso conserva filtros')
                visitante.locator('.detalle__volver').click()
            page.goto(base + 'productos.php?buscar=' + termino + '&categoria=1&estado=1')
            check(page.locator('tbody tr').count() == 1, 'Búsqueda administrativa parcial: ' + termino)
        page.goto(base + 'productos.php?buscar=SEED&categoria=1&estado=1')
        page.get_by_role('link', name='Siguiente', exact=True).click()
        check(page.locator('tbody tr').count() == 1 and 'categoria=1' in page.url and 'estado=1' in page.url, 'Paginación conserva filtros')
        for query in ('', '?id=0', '?id=-1', '?id=abc', '?id[]=1', '?id=999999', '?id=1%20OR%201=1'):
            response = visitante.goto(base + 'producto.php' + query)
            check(response.status == 404, 'Identificador no consultable: ' + query)
        print('OK detalle sin migración 003, enlaces, búsqueda, filtros, descripción completa, código y WhatsApp', flush=True)

        # Equivalente SQLite de la ampliación, únicamente dentro de la copia temporal.
        db.execute('PRAGMA foreign_keys=ON')
        db.execute('ALTER TABLE productos ADD COLUMN especificaciones TEXT NULL')
        db.execute('CREATE TABLE producto_imagenes (id_imagen INTEGER PRIMARY KEY AUTOINCREMENT, id_producto INTEGER NOT NULL REFERENCES productos ON DELETE CASCADE, imagen TEXT NOT NULL)')
        db.commit()
        page.goto(base + f'productos.php?editar={product_id}')
        token = page.locator('input[name="token_csrf"]').first.input_value()
        photos = []
        for color in ('#153657', '#ef1520', '#dadada'):
            image = page.evaluate('''color => { const c=document.createElement('canvas'); c.width=160;c.height=120;
                const x=c.getContext('2d');x.fillStyle=color;x.fillRect(0,0,160,120);return c.toDataURL('image/png');}''', color)
            photos.append({'name': 'prueba.png', 'mimeType': 'image/png', 'buffer': base64.b64decode(image.split(',')[1])})
        page.locator('#especificacion_marca').fill('Marca <prueba> & compañía')
        page.locator('#especificacion_potencia').fill('750 W')
        page.locator('#fotografia_producto').set_input_files(photos[0])
        page.locator('#fotografias_producto').set_input_files(photos[1:])
        page.locator('.panel-producto-formulario button[type="submit"]').click()
        check(page.locator('.alert-success').is_visible(), 'Guardar especificaciones y galería')
        visitante.goto(url)
        check(visitante.locator('.detalle__miniatura').count() == 3, 'Galería: principal más dos adicionales')
        check(visitante.locator('.detalle__especificaciones dt').all_text_contents() == ['Marca', 'Potencia'], 'Solo especificaciones registradas')
        check(visitante.locator('.detalle__especificaciones dd').first.inner_text() == 'Marca <prueba> & compañía', 'Especificaciones escapadas')
        second = visitante.locator('.detalle__miniatura').nth(1)
        second.click()
        check(visitante.locator('#imagen-principal').get_attribute('src') == second.get_attribute('href'), 'Miniatura cambia imagen')
        visitante.locator('.detalle__miniatura').nth(2).focus()
        visitante.keyboard.press('Enter')
        check(visitante.locator('.detalle__miniatura').nth(2).get_attribute('aria-current') == 'true', 'Galería por teclado')
        for width in (320, 375, 768, 1024, 1440, 1920):
            page.set_viewport_size({'width': width, 'height': 900})
            page.goto(base + f'productos.php?editar={product_id}')
            check(page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), f'Formulario ampliado sin desbordamiento {width}')
            visitante.set_viewport_size({'width': width, 'height': 900})
            visitante.goto(url)
            visitante.wait_for_function('[...document.images].every(e => e.complete && e.naturalWidth > 0)')
            check(visitante.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), f'Detalle sin desbordamiento {width}')
            image_box = visitante.locator('.detalle__galeria').bounding_box()
            info_box = visitante.locator('.detalle__informacion').bounding_box()
            check(info_box['x'] > image_box['x'] if width >= 992 else info_box['y'] >= image_box['y'] + image_box['height'], 'Distribución responsive')
            if width in (375, 1440):
                visitante.screenshot(path=str(fixture.ARTIFACTS / f'{width}-detalle.png'), full_page=True)
        touch = browser.new_context(has_touch=True, viewport={'width': 375, 'height': 850})
        touch.route('**/*', lambda r: r.continue_() if r.request.url.startswith(base) else r.abort())
        movil = touch.new_page()
        movil.goto(url)
        movil.locator('.detalle__miniatura').nth(1).tap()
        check(movil.locator('.detalle__miniatura').nth(1).get_attribute('aria-current') == 'true', 'Galería táctil')
        movil.locator('.boton-menu').tap()
        movil.locator('.navegacion__categorias summary').tap()
        check(movil.locator('.megamenu__lista').is_visible(), 'Menú compartido en detalle móvil')
        touch.close()
        print('OK galería, especificaciones, teclado, táctil y responsive 320–1920 px', flush=True)

        def post(extra=None, photo=None):
            fields = {'accion': 'editar', 'id_producto': str(product_id), 'token_csrf': token, 'nombre': nombre,
                      'codigo': 'MANUAL-ABC-123', 'descripcion': descripcion, 'id_categoria': '1', 'visible': '1'}
            fields.update(extra or {})
            if photo:
                fields['fotografias[0]'] = photo
                return admin.request.post(base + 'productos.php', multipart=fields)
            return admin.request.post(base + 'productos.php', form=fields)

        storage = root / 'almacenamiento/productos'
        files_before = set(storage.iterdir())
        for fields in ({'token_csrf': 'incorrecto'}, {'especificaciones[marca]': 'x' * 151}, {'quitar_imagenes[]': '999999'}):
            check(post(fields).status in (400, 422), 'Validación de ampliación')
        check(post(photo={'name': 'falso.png', 'mimeType': 'image/png', 'buffer': b'<?php die(); ?>'}).status == 422, 'Galería rechaza archivos falsos')
        db.execute("CREATE TRIGGER fallo_galeria BEFORE INSERT ON producto_imagenes BEGIN SELECT RAISE(ABORT, 'fallo temporal'); END")
        db.commit()
        try:
            check(post(photo=photos[1]).status == 500, 'Fallo simulado de galería')
            check(set(storage.iterdir()) == files_before, 'Rollback de galería sin huérfanos')
        finally:
            db.execute('DROP TRIGGER fallo_galeria')
            db.commit()
        page.goto(base + f'productos.php?editar={product_id}')
        page.locator('#fotografias_producto').set_input_files(photos * 2)
        page.locator('.panel-producto-formulario button[type="submit"]').click()
        check(page.locator('.panel-aviso--error').is_visible(), 'Límite de cinco fotos adicionales')
        check(set(storage.iterdir()) == files_before, 'Validaciones no crean archivos')

        # Guardar datos normales conserva galería y especificaciones omitidas.
        check(post().ok, 'Compatibilidad con formularios sin campos opcionales')
        check(db.execute('SELECT COUNT(*) FROM producto_imagenes WHERE id_producto=?', (product_id,)).fetchone()[0] == 2, 'Conservar galería')
        image_url = visitante.locator('.detalle__miniatura').nth(1).get_attribute('href')
        admin.request.post(base + 'productos.php', form={'accion': 'publicacion', 'id_producto': str(product_id), 'visible': '0', 'token_csrf': token})
        check(visitante.goto(url).status == 404, 'Producto oculto no accesible públicamente')
        check(admin.request.get(url).status == 404, 'Ni personal accede al oculto por URL pública')
        check(publico.request.get(base + image_url).status == 404 and admin.request.get(base + image_url).status == 200, 'Foto adicional oculta protegida')
        page.goto(base + f'productos.php?editar={product_id}')
        removed = db.execute('SELECT imagen FROM producto_imagenes WHERE id_producto=? ORDER BY id_imagen LIMIT 1', (product_id,)).fetchone()[0]
        page.locator('input[name="quitar_imagenes[]"]').first.check()
        page.locator('#especificacion_marca').fill('')
        page.locator('#especificacion_potencia').fill('')
        page.locator('#visible_producto').select_option('1')
        page.locator('.panel-producto-formulario button[type="submit"]').click()
        check(not (root / 'almacenamiento' / removed).exists(), 'Retirar foto adicional limpia el disco')
        visitante.goto(url)
        check(visitante.locator('.detalle__especificaciones').count() == 0, 'Quitar especificaciones oculta la sección')
        page.goto(base + 'productos.php?buscar=MANUAL-ABC-123')
        page.get_by_role('button', name='Eliminar ' + nombre, exact=True).click()
        page.get_by_role('button', name='Sí, eliminar producto').click()
        check(visitante.goto(url).status == 404, 'Producto eliminado devuelve 404')
        check(db.execute('SELECT COUNT(*) FROM producto_imagenes WHERE id_producto=?', (product_id,)).fetchone()[0] == 0, 'Eliminar producto limpia relaciones de galería')
        check(list(storage.iterdir()) == [], 'Eliminar producto limpia todas sus fotos')
        check(not errors, 'Errores JavaScript: ' + repr(errors))
    admin.close()
    publico.close()
    print('OK validación, CSRF, rollback, ocultación, conservación y eliminación de fotografías adicionales', flush=True)


if __name__ == '__main__':
    fixture.run = run
    fixture.main()

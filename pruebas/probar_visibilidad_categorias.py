"""Visibilidad de categorías en una copia temporal con SQLite; nunca usa MySQL ni .env.

Ejecutar con el Python del entorno de pruebas. --manual abre la copia aislada.
No se ejecutan los archivos SQL de migraciones.
"""
import base64
from contextlib import closing
import sqlite3
from playwright.sync_api import expect
import probar_productos as fixture

check = fixture.check


def run(browser, base, root):
    admin = browser.new_context(viewport={'width': 1366, 'height': 900})
    publico = browser.new_context()
    for context in (admin, publico):
        context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(base) else route.abort())
    page = admin.new_page()
    visitante = publico.new_page()
    errors = []
    for tab in (page, visitante):
        tab.on('pageerror', lambda error: errors.append(str(error)))

    def login(tab, correo):
        tab.goto(base + 'iniciar_sesion.php')
        tab.locator('#correo').fill(correo)
        tab.locator('#contrasena').fill('Fixture-Only-123')
        tab.locator('.acceso-boton').click()
        tab.goto(base + 'categorias.php')
        return tab.locator('input[name="token_csrf"]').first.input_value()

    token = login(page, 'admin@example.test')

    def post(data, status=303, context=admin, csrf=token):
        response = context.request.post(base + 'categorias.php', form={'token_csrf': csrf, **data}, max_redirects=0)
        check(response.status == status, f'Categorías: esperado {status}, obtenido {response.status}')
        return response

    def visibilidad(id_categoria, valor, **kwargs):
        return post({'accion': 'visibilidad', 'id_categoria': str(id_categoria), 'visible': valor}, **kwargs)

    def masiva(ids, operacion, categoria=None):
        def api(datos):
            response = admin.request.post(base + 'productos_masivos.php', data=datos, headers={'X-CSRF-Token': token})
            check(response.status == 200, 'Acción masiva con categorías ocultas')
            return response.json()['trabajo']
        trabajo = api({'accion': 'previa', 'ids': ids, 'operacion': operacion, 'categoria': categoria})
        api({'accion': 'confirmar', 'trabajo': trabajo['id'], 'confirmar': True})
        while trabajo['estado'] != 'terminado':
            trabajo = api({'accion': 'lote', 'trabajo': trabajo['id'], 'inicio': trabajo['procesados']})
        return trabajo

    with closing(sqlite3.connect(root / 'datos.sqlite')) as db:
        db.execute('PRAGMA foreign_keys=ON')
        post({'accion': 'crear', 'nombre': '  Sin   clasificar  '})
        provisional, visible = db.execute("SELECT id_categoria,visible FROM categorias WHERE nombre='Sin clasificar'").fetchone()
        check(visible == 0, 'Sin clasificar se crea oculta después de normalizar su nombre')
        post({'accion': 'crear', 'nombre': 'Otra categoría'})
        otra, visible = db.execute("SELECT id_categoria,visible FROM categorias WHERE nombre='Otra categoría'").fetchone()
        check(visible == 1, 'Categorías normales se crean visibles')
        db.executemany('INSERT INTO productos (codigo,nombre,descripcion,id_categoria,visible) VALUES (?,?,?,?,?)',
                       [(f'VIS-{i:03}', f'Visible ficticio {i:03}', 'Búsqueda temporal', 1, 1) for i in range(50)]
                       + [(f'IND-{i}', f'Oculto individual {i}', 'Privado', 1, 0) for i in range(5)]
                       + [(f'SIN-{i}', f'Sin clasificar ficticio {i}', 'Privado', provisional, 1) for i in range(7)]
                       + [(f'OTR-{i}', f'Otro ficticio {i}', 'Público', otra, 1) for i in range(2)])
        # Imágenes ficticias dentro de la copia temporal; también probar la galería opcional.
        fotos = root / 'almacenamiento/productos'
        fotos.mkdir(parents=True, exist_ok=True)
        png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a4N0AAAAASUVORK5CYII=')
        principal = 'a' * 32 + '.png'
        adicional = 'b' * 32 + '.png'
        for nombre in (principal, adicional):
            (fotos / nombre).write_bytes(png)
        db.execute('UPDATE productos SET imagen=? WHERE id_producto=1', ('productos/' + principal,))
        db.commit()
        check(publico.request.get(base + 'imagen_producto.php?archivo=' + principal).status == 200, 'Foto pública sin galería opcional')
        db.execute('ALTER TABLE productos ADD COLUMN especificaciones TEXT')
        db.execute('CREATE TABLE producto_imagenes (id_imagen INTEGER PRIMARY KEY, id_producto INTEGER REFERENCES productos ON DELETE CASCADE, imagen TEXT)')
        db.execute('INSERT INTO producto_imagenes VALUES (1,1,?)', ('productos/' + adicional,))
        db.commit()
        productos = db.execute('SELECT * FROM productos ORDER BY id_producto').fetchall()
        galeria = db.execute('SELECT * FROM producto_imagenes').fetchall()
        historial = db.execute('SELECT * FROM detalle_solicitud').fetchall()

        visitante.goto(base + 'catalogo.php?buscar=VIS-&categoria=Herramientas')
        expect(visitante.locator('.producto')).to_have_count(24)
        expect(visitante.get_by_role('navigation', name='Paginación del catálogo')).to_contain_text('50 productos')
        visitante.get_by_role('link', name='Siguiente', exact=True).click()
        expect(visitante.locator('.producto')).to_have_count(24)
        check('buscar=VIS-' in visitante.url and 'categoria=Herramientas' in visitante.url, 'Paginación pública conserva búsqueda y categoría')
        visitante.get_by_role('link', name='Siguiente', exact=True).click()
        expect(visitante.locator('.producto')).to_have_count(2)
        for path in ('index.php', 'catalogo.php', 'producto.php?id=1'):
            visitante.goto(base + path)
            expect(visitante.locator('.megamenu__lista')).not_to_contain_text('Sin clasificar')
        sin_id = db.execute("SELECT id_producto FROM productos WHERE codigo='SIN-0'").fetchone()[0]
        check(publico.request.get(base + f'producto.php?id={sin_id}').status == 404, 'Publicado en Sin clasificar no tiene detalle público')

        # Acción real de interfaz, sin JavaScript adicional ni cambios de diseño.
        page.goto(base + 'categorias.php')
        page.get_by_role('button', name='Cambiar visibilidad de Herramientas', exact=True).click()
        expect(page.locator('.alert-success')).to_contain_text('Categoría oculta')
        check(db.execute('SELECT visible FROM categorias WHERE id_categoria=1').fetchone()[0] == 0, 'Ocultar desde panel guarda solo visibilidad de categoría')
        for path in ('index.php', 'catalogo.php'):
            visitante.goto(base + path)
            expect(visitante.locator('.megamenu__lista')).not_to_contain_text('Herramientas')
            if path == 'index.php':
                expect(visitante.locator('#categorias-destacadas')).not_to_contain_text('Herramientas')
            else:
                expect(visitante.locator('.producto')).to_have_count(2)
        for query in ('buscar=VIS-', 'buscar=B%C3%BAsqueda', 'buscar=SEED-001'):
            visitante.goto(base + 'catalogo.php?' + query)
            expect(visitante.locator('.producto')).to_have_count(0)
        visitante.goto(base + 'catalogo.php?categoria=Herramientas&pagina=999')
        expect(visitante.locator('.producto')).to_have_count(0)
        expect(visitante.locator('main')).to_contain_text('La categoría seleccionada no está disponible')
        expect(visitante.locator('input[name="categoria"]')).to_have_count(0)
        expect(visitante.locator('main')).not_to_contain_text('Herramientas')
        for context in (admin, publico):
            check(context.request.get(base + 'producto.php?id=1').status == 404, 'Detalle público restringido incluso con sesión de personal')
        for nombre in (principal, adicional):
            url = base + 'imagen_producto.php?archivo=' + nombre
            check(publico.request.get(url).status == 404, 'Imagen oculta no disponible por URL directa')
            check(publico.request.head(url).status == 404, 'HEAD tampoco publica la fotografía')
            check(admin.request.get(url).status == 200, 'Personal conserva acceso a la fotografía para administrarla')
        check(db.execute('SELECT * FROM productos ORDER BY id_producto').fetchall() == productos, 'Ocultar conserva todos los campos de todos los productos')
        check(db.execute('SELECT * FROM producto_imagenes').fetchall() == galeria, 'Galería intacta')
        check(db.execute('SELECT * FROM detalle_solicitud').fetchall() == historial, 'Historial intacto')

        post({'accion': 'editar', 'id_categoria': '1', 'nombre': 'Herramientas renombradas'})
        check(db.execute('SELECT visible FROM categorias WHERE id_categoria=1').fetchone()[0] == 0, 'Editar nombre no publica una categoría oculta')
        post({'accion': 'editar', 'id_categoria': '1', 'nombre': 'Herramientas'})
        visibilidad(1, '1')
        visibilidad(1, '1')  # Reintento no invierte el estado.
        check(db.execute('SELECT * FROM productos ORDER BY id_producto').fetchall() == productos, 'Mostrar conserva la publicación individual, incluidos los ocultos')
        visitante.goto(base + 'catalogo.php?buscar=VIS-&categoria=Herramientas')
        expect(visitante.get_by_role('navigation', name='Paginación del catálogo')).to_contain_text('50 productos')
        visitante.goto(base + 'catalogo.php?buscar=IND-')
        expect(visitante.locator('.producto')).to_have_count(0)
        for nombre in (principal, adicional):
            check(publico.request.get(base + 'imagen_producto.php?archivo=' + nombre).status == 200, 'Mostrar restaura fotografías de productos publicados')

        # Permisos, CSRF, validaciones, GET inocuo y error de almacenamiento.
        estado_categorias = db.execute('SELECT * FROM categorias ORDER BY id_categoria').fetchall()
        visibilidad(1, '0', status=400, csrf='invalido')
        visibilidad(1, '0', status=400, csrf='')
        for datos in ({'visible': '2'}, {'visible': ''}, {'visible[]': '0'}, {'id_categoria': '999999', 'visible': '0'},
                      {'id_categoria': '1 OR 1=1', 'visible': '0'}):
            post({'accion': 'visibilidad', 'id_categoria': '1', **datos}, status=422)
        check(publico.request.post(base + 'categorias.php', form={'accion': 'visibilidad', 'id_categoria': '1', 'visible': '0'}, max_redirects=0).status == 303,
              'Visitante no modifica categorías por POST directo')
        check(admin.request.get(base + 'categorias.php?accion=visibilidad&id_categoria=1&visible=0').status == 200, 'GET solo consulta')
        check(admin.request.put(base + 'categorias.php').status == 405, 'Métodos no permitidos rechazados')
        db.execute("CREATE TRIGGER fallo_visibilidad BEFORE UPDATE OF visible ON categorias BEGIN SELECT RAISE(ABORT,'detalle privado'); END")
        db.commit()
        try:
            response = visibilidad(1, '0', status=500)
            check('detalle privado' not in response.text(), 'Error interno no expuesto')
        finally:
            db.execute('DROP TRIGGER fallo_visibilidad')
            db.commit()
        check(db.execute('SELECT * FROM categorias ORDER BY id_categoria').fetchall() == estado_categorias, 'Peticiones rechazadas no modifican categorías')
        check(db.execute('SELECT * FROM productos ORDER BY id_producto').fetchall() == productos, 'Peticiones rechazadas no modifican productos')

        empleado = browser.new_context()
        empleada_page = empleado.new_page()
        empleado_csrf = login(empleada_page, 'empleado@example.test')
        visibilidad(otra, '0', context=empleado, csrf=empleado_csrf)
        check(db.execute('SELECT visible FROM categorias WHERE id_categoria=?', (otra,)).fetchone()[0] == 0, 'Empleado conserva permisos de categorías')
        db.execute('UPDATE usuarios SET activo=0 WHERE id_usuario=2')
        db.commit()
        visibilidad(otra, '1', context=empleado, csrf=empleado_csrf)  # redirección al login
        check(db.execute('SELECT visible FROM categorias WHERE id_categoria=?', (otra,)).fetchone()[0] == 0, 'Cuenta desactivada no cambia visibilidad')
        db.execute('UPDATE usuarios SET activo=1 WHERE id_usuario=2')
        db.commit()
        empleado.close()

        # Categorías ocultas disponibles en filtros, edición y destino masivo.
        page.goto(base + 'productos.php?editar=1')
        for selector in ('#categoria_producto', '#categoria_filtro', '#masiva-categoria'):
            expect(page.locator(selector + f' option[value="{provisional}"]')).to_have_count(1)
        resultado = masiva([1, 2], 'categoria', str(provisional))
        check(resultado['modificados'] == 2, 'Gestión masiva permite asignar una categoría oculta')
        masiva([1, 2], 'ocultar')
        masiva([1, 2], 'publicar')
        check(db.execute('SELECT visible FROM productos WHERE id_producto=1').fetchone()[0] == 1, 'Publicación individual se mantiene independiente')
        check(publico.request.get(base + 'producto.php?id=1').status == 404, 'Publicar masivamente no evita la ocultación por categoría')
        # Crear y editar mediante el CRUD individual también admite la categoría oculta.
        response = admin.request.post(base + 'productos.php', form={'token_csrf': token, 'accion': 'crear', 'codigo': 'NUEVO-OCULTO',
            'nombre': 'Nuevo ficticio', 'descripcion': 'Temporal', 'id_categoria': str(provisional), 'visible': '1'}, max_redirects=0)
        check(response.status == 303, 'CRUD crea producto asignado a categoría oculta')
        nuevo = db.execute("SELECT id_producto FROM productos WHERE codigo='NUEVO-OCULTO'").fetchone()[0]
        check(publico.request.get(base + f'producto.php?id={nuevo}').status == 404, 'Nuevo producto publicado sigue oculto por categoría')
        page.goto(base + f'productos.php?editar={nuevo}')
        page.locator('#nombre_producto').fill('Nuevo ficticio editado')
        page.locator('.panel-producto-formulario button[type="submit"]').click()
        expect(page.locator('.alert-success')).to_be_visible()
        visibilidad(provisional, '1')
        check(publico.request.get(base + f'producto.php?id={nuevo}').status == 200, 'Sin clasificar puede mostrarse expresamente')
        visibilidad(provisional, '0')
        masiva([1, 2], 'categoria', '1')
        check(publico.request.get(base + 'producto.php?id=1').status == 200, 'Mover a categoría visible devuelve el producto publicado al catálogo')
        check(db.execute('SELECT * FROM productos WHERE id_producto<=21 ORDER BY id_producto').fetchall() == productos[:21], 'Productos iniciales conservan sus datos tras acciones reversibles')

        # Las únicas escrituras de esquema son en el archivo SQLite temporal.
        # Simular 004 pendiente sin ejecutar ningún archivo de migración.
        page.goto(base + 'categorias.php')
        for width in (320, 375, 768, 1366):
            page.set_viewport_size({'width': width, 'height': 900})
            check(page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), f'Categorías sin desbordamiento a {width}px')
            if width in (375, 1366):
                page.screenshot(path=str(fixture.ARTIFACTS / f'{width}-visibilidad-categorias.png'), full_page=True)
        db.execute('ALTER TABLE categorias RENAME COLUMN visible TO visible_pendiente')
        db.commit()
        page.goto(base + 'categorias.php')
        expect(page.locator('.alert-warning')).to_contain_text('pendiente de activación')
        expect(page.get_by_role('button', name='Cambiar visibilidad de Herramientas', exact=True)).to_be_disabled()
        visibilidad(1, '0', status=422)
        post({'accion': 'crear', 'nombre': 'Categoría en esquema anterior'})
        for path in ('index.php', 'catalogo.php', 'productos.php', 'producto.php?id=1'):
            check(admin.request.get(base + path).status == 200, 'Esquema anterior sigue operativo: ' + path)
        visitante.goto(base + 'catalogo.php?buscar=SIN-')
        expect(visitante.locator('.producto')).to_have_count(0)
        expect(visitante.locator('.megamenu__lista')).not_to_contain_text('Sin clasificar')
        check(publico.request.get(base + f'producto.php?id={sin_id}').status == 404, 'Sin clasificar también protegida antes de aplicar 004')
        check(not errors, 'Sin errores JavaScript: ' + repr(errors))
    admin.close()
    publico.close()
    print('OK visibilidad, valores predeterminados, menú, búsqueda/paginación, detalle e imágenes, CRUD y masivas, permisos/CSRF, conservación de datos, responsive y esquema anterior', flush=True)


if __name__ == '__main__':
    fixture.run = run
    fixture.main(visibilidad_categorias=True)

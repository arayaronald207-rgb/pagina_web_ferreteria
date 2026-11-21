"""Gestión masiva en copia temporal SQLite; nunca utiliza .env ni MySQL."""
from contextlib import closing
import re
import sqlite3
from playwright.sync_api import expect
import probar_productos as fixture

check = fixture.check


def run(browser, base, root):
    context = browser.new_context(viewport={'width': 1440, 'height': 1000})
    context.route('**/*', lambda r: r.continue_() if r.request.url.startswith(base) else r.abort())
    page = context.new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.goto(base + 'productos.php')
    check(page.url.endswith('iniciar_sesion.php'), 'Exige autenticación')
    page.locator('#correo').fill('admin@example.test')
    page.locator('#contrasena').fill('Fixture-Only-123')
    page.locator('.acceso-boton').click()
    with closing(sqlite3.connect(root / 'datos.sqlite')) as db:
        db.execute("INSERT INTO categorias VALUES (3,'Sin clasificar')")
        db.executemany('INSERT INTO productos (codigo,nombre,descripcion,id_categoria,visible,imagen) VALUES (?,?,?,3,0,?)',
                       [(f'IMP-{i:06}', f'Importado {i:06}', 'Descripción lote manual', 'assets/images/producto_sin_imagen.svg') for i in range(1, 4663)])
        db.commit()
        original = db.execute('SELECT * FROM productos ORDER BY id_producto').fetchall()
        page.goto(base + 'productos.php')
        token = page.locator('#gestion-masiva').get_attribute('data-token')

        def api(action, extra=None, expected=200, ctx=context, csrf=token):
            response = ctx.request.post(base + 'productos_masivos.php', data={'accion': action, **(extra or {})}, headers={'X-CSRF-Token': csrf})
            check(response.status == expected, f'{action}: {response.status} {response.text()}')
            return response.json().get('trabajo')

        page.get_by_role('link', name='Sin clasificar: 4662 productos', exact=True).click()
        expect(page.locator('#seleccionar-pagina')).to_be_enabled()
        page.locator('#seleccionar-pagina').check()
        selected = {int(v) for v in page.locator('[data-seleccionar-producto]').evaluate_all('(els) => els.map(e => e.dataset.seleccionarProducto)')}
        page.get_by_role('link', name='Siguiente', exact=True).click()
        expect(page.locator('#seleccionar-pagina')).to_be_enabled()
        page.locator('[data-seleccionar-producto]').first.check()
        selected.add(int(page.locator('[data-seleccionar-producto]').first.get_attribute('data-seleccionar-producto')))
        page.locator('#buscar_productos').fill('IMP-004662')
        page.get_by_role('button', name='Buscar productos').click()
        expect(page.locator('#seleccionar-pagina')).to_be_enabled()
        page.locator('[data-seleccionar-producto]').check()
        selected.add(int(page.locator('[data-seleccionar-producto]').get_attribute('data-seleccionar-producto')))
        expect(page.locator('#seleccion-cantidad')).to_contain_text('22 productos')
        page.locator('#masiva-categoria').select_option('2')
        page.locator('#masiva-revisar').click()
        expect(page.locator('#masiva-previa')).to_be_visible()
        expect(page.locator('#masiva-resumen')).to_contain_text('22 cambios previstos')
        expect(page.locator('#masiva-resumen')).to_contain_text('Pinturas')
        expect(page.locator('#masiva-grupos')).to_contain_text('Sin clasificar')
        expect(page.locator('#masiva-confirmar')).to_be_disabled()
        check(db.execute('SELECT * FROM productos ORDER BY id_producto').fetchall() == original, 'Selección y vista previa no escriben productos')
        for width in (320, 768, 1440):
            page.set_viewport_size({'width': width, 'height': 1000})
            check(page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), f'Responsive {width}')
        page.locator('#masiva-aceptar').check()
        page.locator('#masiva-confirmar').click()
        expect(page.locator('#masiva-resultado')).to_contain_text('Operación terminada.')
        expect(page.locator('#seleccion-cantidad')).to_contain_text('0 productos')
        check({r[0] for r in db.execute('SELECT id_producto FROM productos WHERE id_categoria=2')} == selected, 'Solo cambia la selección entre páginas y filtros')
        check(db.execute('SELECT COUNT(*) FROM productos WHERE id_producto>21 AND visible<>0').fetchone()[0] == 0, 'Clasificar no publica')
        before_bulk = db.execute('SELECT * FROM productos ORDER BY id_producto').fetchall()
        api('previa', {'ids': [], 'operacion': 'publicar'}, 422)
        api('previa', {'ids': [999999], 'operacion': 'publicar'}, 422)
        api('previa', {'ids': ['1 OR 1=1'], 'operacion': 'publicar'}, 422)
        api('previa', {'ids': [22], 'operacion': 'categoria', 'categoria': '999999'}, 422)
        api('previa', {'ids': [22], 'operacion': 'publicar'}, 400, csrf='invalido')
        check(context.request.get(base + 'productos_masivos.php').status == 405, 'GET no modifica datos')
        work = api('previa', {'ids': [22, 23, 24, 22], 'operacion': 'publicar'})
        check(work['total'] == 3, 'Normaliza IDs repetidos')
        api('lote', {'trabajo': work['id'], 'inicio': 0}, 422)
        api('confirmar', {'trabajo': work['id']}, 422)
        api('confirmar', {'trabajo': 'otro', 'confirmar': True}, 422)
        api('confirmar', {'trabajo': work['id'], 'confirmar': True})
        check(api('confirmar', {'trabajo': work['id'], 'confirmar': True})['procesados'] == 0, 'Reintentar confirmación no inicia lotes')
        check(db.execute('SELECT * FROM productos ORDER BY id_producto').fetchall() == before_bulk, 'Confirmar aún no ejecuta lotes')
        # Error a mitad del lote: rollback íntegro, progreso intacto y reintento seguro.
        db.execute("CREATE TRIGGER fallo_masivo BEFORE UPDATE ON productos WHEN NEW.id_producto=23 BEGIN SELECT RAISE(ABORT,'fallo privado'); END")
        db.commit()
        api('lote', {'trabajo': work['id'], 'inicio': 0}, 500)
        check(db.execute('SELECT * FROM productos ORDER BY id_producto').fetchall() == before_bulk, 'Rollback íntegro del lote')
        check(api('estado')['procesados'] == 0, 'Fallo no avanza progreso')
        db.execute('DROP TRIGGER fallo_masivo'); db.commit()
        # Cambio concurrente de categoría y producto eliminado únicamente en la fixture.
        db.execute('UPDATE productos SET id_categoria=1 WHERE id_producto=23')
        db.execute('DELETE FROM productos WHERE id_producto=24'); db.commit()
        result = api('lote', {'trabajo': work['id'], 'inicio': 0})
        check(result['modificados'] == 1 and result['omitidos'] == 2, 'No sobrescribe cambios concurrentes ni productos eliminados')
        check(api('lote', {'trabajo': work['id'], 'inicio': 0}) == result, 'Reintento terminado no repite cambios')
        ids = [r[0] for r in db.execute('SELECT id_producto FROM productos WHERE id_producto>21')]
        work = api('previa', {'ids': ids, 'operacion': 'categoria', 'categoria': '1'})
        api('confirmar', {'trabajo': work['id'], 'confirmar': True})
        first = api('lote', {'trabajo': work['id'], 'inicio': 0})
        check(first['procesados'] == 100, 'Lotes limitados a 100')
        check(api('lote', {'trabajo': work['id'], 'inicio': 0}) == first, 'Respuesta perdida no procesa otro lote')
        check(api('confirmar', {'trabajo': work['id'], 'confirmar': True}) == first, 'Confirmación repetida conserva progreso')
        api('previa', {'ids': [22], 'operacion': 'ocultar'}, 422)
        api('lote', {'trabajo': work['id'], 'inicio': 500}, 422)
        # Recargar solo restaura el progreso, no ejecuta escrituras.
        page.goto(base + 'productos.php')
        expect(page.locator('#masiva-continuar')).to_be_visible()
        check(api('estado')['procesados'] == 100, 'Recarga requiere continuar explícitamente')
        page.locator('#masiva-continuar').click()
        expect(page.locator('#masiva-resultado')).to_contain_text('Operación terminada.', timeout=60000)
        result = api('estado')
        check(result['procesados'] == len(ids) == 4661, 'Selección grande completa en 47 lotes')
        check(db.execute('SELECT COUNT(*) FROM productos WHERE id_producto>21 AND id_categoria<>1').fetchone()[0] == 0, 'Destino aplicado a la selección completa')
        work = api('previa', {'ids': [22, 23], 'operacion': 'ocultar'})
        api('confirmar', {'trabajo': work['id'], 'confirmar': True})
        result = api('lote', {'trabajo': work['id'], 'inicio': 0})
        check(result['modificados'] == 1 and result['sin_cambios'] == 1, 'Ocultar distingue productos que ya estaban ocultos')
        api('cancelar', {'trabajo': work['id']})
        api('lote', {'trabajo': work['id'], 'inicio': 0}, 422)
        # Revisa todos los campos ajenos a categoría/publicación, no solo un ejemplo.
        final = db.execute('SELECT * FROM productos ORDER BY id_producto').fetchall()
        original_by_id = {r[0]: r for r in original}
        for row in final:
            previous = original_by_id[row[0]]
            check(row[:4] == previous[:4] and row[5] == previous[5], 'Conserva código, nombre, descripción e imagen')
        check(final[:21] == original[:21], 'Conserva íntegros productos existentes no seleccionados')
        check(db.execute('SELECT COUNT(*) FROM detalle_solicitud').fetchone()[0] == 1, 'Historial intacto')
        employee = browser.new_context()
        other = employee.new_page(); other.goto(base + 'iniciar_sesion.php')
        other.locator('#correo').fill('empleado@example.test'); other.locator('#contrasena').fill('Fixture-Only-123'); other.locator('.acceso-boton').click()
        other.goto(base + 'productos.php')
        check(other.locator('#gestion-masiva').count() == 0, 'Empleado conserva listado sin acciones masivas')
        csrf = other.locator('input[name="token_csrf"]').first.input_value()
        api('previa', {'ids': [22], 'operacion': 'publicar'}, 403, employee, csrf)
        employee.close()
        check(not errors, 'Sin errores JavaScript: ' + repr(errors))
    context.close()
    print('OK selección entre páginas/filtros, vista previa sin escrituras, consentimiento, 47 lotes, rollback, reintentos, cambios concurrentes, permisos, CSRF, responsive y conservación de datos', flush=True)
    run_recuperacion(browser, base, root)


def run_recuperacion(browser, base, root):
    """Fallos de red y pestañas reales, siempre sobre la misma copia temporal."""
    context = browser.new_context(viewport={'width': 1366, 'height': 900})
    context.route('**/*', lambda r: r.continue_() if r.request.url.startswith(base) else r.abort())
    page = context.new_page()
    page.goto(base + 'iniciar_sesion.php')
    page.locator('#correo').fill('admin@example.test')
    page.locator('#contrasena').fill('Fixture-Only-123')
    page.locator('.acceso-boton').click()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    with closing(sqlite3.connect(root / 'datos.sqlite')) as db:
        db.execute("INSERT INTO categorias (id_categoria,nombre) VALUES (4,'Pruebas visibles')")
        db.executemany('INSERT INTO productos (codigo,nombre,descripcion,id_categoria,visible) VALUES (?,?,?,4,0)',
                       [(f'REC-{i:04}', f'Recuperación {i:04}', 'Datos temporales') for i in range(121)])
        db.commit()
        ids = [r[0] for r in db.execute("SELECT id_producto FROM productos WHERE codigo LIKE 'REC-%' ORDER BY id_producto")]
        page.goto(base + 'productos.php?buscar=REC-&categoria=4&estado=0&pagina=2')
        expect(page.locator('#seleccionar-pagina')).to_be_enabled()
        check(page.locator('[data-seleccionar-producto]').count() == 20, 'Paginación con búsqueda, categoría y estado')
        anterior = page.get_by_role('link', name='Anterior', exact=True).get_attribute('href')
        check('buscar=REC-' in anterior and 'categoria=4' in anterior and 'estado=0' in anterior, 'Paginación conserva los tres filtros')
        page.locator('[data-seleccionar-producto]').first.check()
        page.locator('#masiva-operacion').select_option('publicar')
        page.locator('#masiva-revisar').click()
        expect(page.locator('#masiva-previa')).to_be_visible()
        page.locator('#masiva-limpiar').click()
        page.reload()
        expect(page.locator('#seleccionar-pagina')).to_be_enabled()
        expect(page.locator('#masiva-previa')).to_be_hidden()
        expect(page.locator('#seleccion-cantidad')).to_contain_text('0 productos')
        check(db.execute("SELECT COUNT(*) FROM productos WHERE codigo LIKE 'REC-%' AND visible=1").fetchone()[0] == 0,
              'Limpiar y recargar no recupera una confirmación de otra selección')

        # Otra pestaña no debe ofrecer confirmar la vista previa de esta.
        page.locator('[data-seleccionar-producto]').first.check()
        selected = int(page.locator('[data-seleccionar-producto]').first.get_attribute('data-seleccionar-producto'))
        page.locator('#masiva-operacion').select_option('publicar')
        page.locator('#masiva-revisar').click()
        expect(page.locator('#masiva-previa')).to_be_visible()
        other = context.new_page()
        other.goto(base + 'productos.php')
        expect(other.locator('#seleccionar-pagina')).to_be_enabled()
        expect(other.locator('#masiva-previa')).to_be_hidden()
        other.close()

        def perder_respuesta(accion):
            perdida = False
            def handler(route):
                nonlocal perdida
                if not perdida and route.request.post_data_json.get('accion') == accion:
                    perdida = True
                    response = route.fetch()
                    check(response.status == 200, 'El servidor recibió ' + accion)
                    route.abort('failed')
                else:
                    route.fallback()
            page.route('**/productos_masivos.php', handler)

        perder_respuesta('confirmar')
        page.locator('#masiva-aceptar').check()
        page.locator('#masiva-confirmar').click()
        expect(page.locator('#masiva-continuar')).to_be_visible()
        expect(page.locator('#masiva-continuar')).to_be_enabled()
        check(db.execute('SELECT visible FROM productos WHERE id_producto=?', (selected,)).fetchone()[0] == 0,
              'Respuesta de confirmación perdida recupera estado sin escribir')
        with page.expect_navigation():
            page.locator('#masiva-continuar').click()
        expect(page.locator('#masiva-resultado')).to_contain_text('Operación terminada.')
        check('estado=0' in page.url and 'pagina=2' in page.url, 'Finalización conserva filtros y página')
        expect(page.locator(f'[data-seleccionar-producto="{selected}"]')).to_have_count(0)
        check(db.execute('SELECT visible FROM productos WHERE id_producto=?', (selected,)).fetchone()[0] == 1,
              'Listado actualizado retira el publicado del filtro ocultos')
        publico = browser.new_context()
        visitante = publico.new_page()
        code = db.execute('SELECT codigo FROM productos WHERE id_producto=?', (selected,)).fetchone()[0]
        visitante.goto(base + 'catalogo.php?buscar=' + code)
        expect(visitante.locator('.producto')).to_have_count(1)
        check(visitante.goto(base + f'producto.php?id={selected}').status == 200, 'Publicación masiva habilita detalle público')

        token = page.locator('#gestion-masiva').get_attribute('data-token')
        def api(payload, status=200):
            response = context.request.post(base + 'productos_masivos.php', data=payload, headers={'X-CSRF-Token': token})
            check(response.status == status, f'API recuperación: {response.status}')
            return response.json().get('trabajo')

        # Rechazar formas y límites maliciosos antes de cualquier escritura.
        snapshot = db.execute('SELECT * FROM productos ORDER BY id_producto').fetchall()
        for values in ({'a': ids[0]}, [True], [1.5], [None], [[ids[0]]], [2147483648], ids * 83):
            api({'accion': 'previa', 'ids': values, 'operacion': 'publicar'}, 422)
        api({'accion': 'previa', 'ids': [ids[0]], 'operacion': 'eliminar'}, 422)
        for body in ('{', 'null', '"texto"', ' ' * 200001):
            response = context.request.post(base + 'productos_masivos.php', data=body,
                                            headers={'X-CSRF-Token': token, 'Content-Type': 'application/json'})
            check(response.status == 422, 'Cuerpo inválido o excesivo rechazado')
        check(db.execute('SELECT * FROM productos ORDER BY id_producto').fetchall() == snapshot, 'Validaciones no modifican registros')

        # Ocultar por interfaz y perder la respuesta del último lote: debe recuperar y refrescar.
        page.goto(base + 'productos.php?buscar=' + code)
        expect(page.locator('#seleccionar-pagina')).to_be_enabled()
        page.locator('#seleccionar-pagina').check()
        page.locator('#masiva-operacion').select_option('ocultar')
        page.locator('#masiva-revisar').click()
        expect(page.locator('#masiva-previa')).to_be_visible()
        page.locator('#masiva-aceptar').check()
        perder_respuesta('lote')
        with page.expect_navigation():
            page.locator('#masiva-confirmar').click()
        expect(page.locator('#masiva-resultado')).to_contain_text('Operación terminada.')
        expect(page.get_by_role('button', name='Cambiar publicación')).to_have_text('Publicar')
        visitante.goto(base + 'catalogo.php?buscar=' + code)
        expect(visitante.locator('.producto')).to_have_count(0)
        check(visitante.goto(base + f'producto.php?id={selected}').status == 404, 'Ocultar masivamente protege también el detalle')
        publico.close()

        # Un error de validación tras finalizar no debe borrar una nueva selección.
        page.locator('#seleccionar-pagina').check()
        page.locator('#masiva-revisar').click()  # categoría destino vacía
        expect(page.locator('#masiva-error')).to_be_visible()
        expect(page.locator('#seleccion-cantidad')).to_contain_text('1 productos')

        # Pausa y cierre conservan los lotes guardados y exigen confirmar la detención.
        work = api({'accion': 'previa', 'ids': ids, 'operacion': 'categoria', 'categoria': '2'})
        api({'accion': 'confirmar', 'trabajo': work['id'], 'confirmar': True})
        page.reload()
        expect(page.locator('#masiva-continuar')).to_be_visible()
        pausado = False
        def pausar(route):
            nonlocal pausado
            if not pausado and route.request.post_data_json.get('accion') == 'lote':
                pausado = True
                response = route.fetch()
                page.evaluate("document.getElementById('masiva-pausar').click()")
                route.fulfill(response=response)
            else:
                route.fallback()
        page.route('**/productos_masivos.php', pausar)
        page.locator('#masiva-continuar').click()
        expect(page.locator('#masiva-resultado')).to_contain_text('100 de 121 procesados')
        expect(page.locator('#masiva-continuar')).to_be_visible()
        page.once('dialog', lambda dialog: dialog.dismiss())
        page.locator('#masiva-cancelar').click()
        expect(page.locator('#masiva-continuar')).to_be_visible()
        check(api({'accion': 'estado'})['procesados'] == 100, 'Cancelar diálogo de detención conserva trabajo')
        perder_respuesta('cancelar')
        page.once('dialog', lambda dialog: dialog.accept())
        with page.expect_navigation():
            page.locator('#masiva-cancelar').click()
        expect(page.locator('#seleccionar-pagina')).to_be_enabled()
        check(api({'accion': 'estado'}) is None, 'Detener elimina únicamente el trabajo pendiente')
        check(db.execute("SELECT COUNT(*) FROM productos WHERE codigo LIKE 'REC-%' AND id_categoria=2").fetchone()[0] == 100,
              'Detener conserva exactamente el lote guardado')

        # Fallo al consultar estado: controles bloqueados hasta recuperar conexión.
        page.route('**/productos_masivos.php', lambda route: route.abort('failed'))
        page.reload()
        expect(page.locator('#masiva-sincronizar')).to_be_visible()
        expect(page.locator('#seleccionar-pagina')).to_be_disabled()
        page.unroute('**/productos_masivos.php')
        page.locator('#masiva-sincronizar').click()
        expect(page.locator('#seleccionar-pagina')).to_be_enabled()
        expect(page.locator('#masiva-sincronizar')).to_be_hidden()

        # El panel también aparece tras un POST inválido del CRUD individual.
        # Actualizar al finalizar debe navegar por GET, sin reenviar ese formulario.
        page.goto(base + 'productos.php?nuevo=1&buscar=' + code)
        page.locator('#codigo_producto').fill('SEED-001')
        page.locator('#nombre_producto').fill('Duplicado temporal')
        page.locator('#categoria_producto').select_option('1')
        page.locator('.panel-producto-formulario button[type="submit"]').click()
        expect(page.locator('.panel-aviso--error')).to_be_visible()
        expect(page.locator('#seleccionar-pagina')).to_be_enabled()
        page.locator('#seleccionar-pagina').check()
        page.locator('#masiva-categoria').select_option('1')
        page.locator('#masiva-revisar').click()
        expect(page.locator('#masiva-previa')).to_be_visible()
        page.locator('#masiva-aceptar').check()
        with page.expect_navigation() as navegacion:
            page.locator('#masiva-confirmar').click()
        check(navegacion.value.request.method == 'GET', 'Finalización no reenvía el POST individual')
        expect(page.locator('#masiva-resultado')).to_contain_text('Operación terminada.')
        expect(page.locator('.panel-aviso--error')).to_have_count(0)

        work = api({'accion': 'previa', 'ids': [ids[-1]], 'operacion': 'publicar'})
        snapshot = db.execute('SELECT * FROM productos ORDER BY id_producto').fetchall()
        for accion in ('previa', 'confirmar', 'lote', 'cancelar', 'estado'):
            response = context.request.post(base + 'productos_masivos.php',
                                            data={'accion': accion, 'trabajo': work['id'], 'confirmar': True, 'inicio': 0})
            check(response.status == 400, 'CSRF ausente bloquea ' + accion)
        for consentimiento in (False, 'true', 1):
            api({'accion': 'confirmar', 'trabajo': work['id'], 'confirmar': consentimiento}, 422)
        check(api({'accion': 'estado'})['estado'] == 'previa', 'Peticiones inválidas conservan la previa sin confirmar')
        anonimo = browser.new_context()
        response = anonimo.request.post(base + 'productos_masivos.php', data={'accion': 'previa'}, max_redirects=0)
        check(response.status == 303 and 'iniciar_sesion.php' in response.headers['location'], 'Endpoint exige sesión incluso en POST directo')
        anonimo.close()

        # Caducar solo el trabajo de la sesión temporal, sin esperar una hora.
        sid = next(cookie['value'] for cookie in context.cookies() if cookie['name'] == 'ferreteria_el_cruce')
        session_file = root / 'sesiones' / ('sess_' + sid)
        contenido, replacements = re.subn(rb'("vence";i:)\d+;', rb'\g<1>1;', session_file.read_bytes())
        check(replacements == 1, 'Caducidad localizada en la sesión aislada')
        session_file.write_bytes(contenido)
        api({'accion': 'confirmar', 'trabajo': work['id'], 'confirmar': True}, 422)
        check(api({'accion': 'estado'}) is None, 'Vista previa vencida no puede ejecutarse')

        # Revocación en cada petición, también con un trabajo ya confirmado.
        work = api({'accion': 'previa', 'ids': [ids[-1]], 'operacion': 'publicar'})
        api({'accion': 'confirmar', 'trabajo': work['id'], 'confirmar': True})
        db.execute('UPDATE usuarios SET activo=0 WHERE id_usuario=1')
        db.commit()
        try:
            response = context.request.post(base + 'productos_masivos.php',
                                            data={'accion': 'lote', 'trabajo': work['id'], 'inicio': 0},
                                            headers={'X-CSRF-Token': token}, max_redirects=0)
            check(response.status == 303, 'Administrador desactivado no ejecuta el lote confirmado')
        finally:
            db.execute('UPDATE usuarios SET activo=1 WHERE id_usuario=1')
            db.commit()
        check(db.execute('SELECT * FROM productos ORDER BY id_producto').fetchall() == snapshot,
              'CSRF, caducidad, visitante y cuenta revocada no modifican productos')
        check(not errors, 'Sin errores JavaScript en recuperación: ' + repr(errors))
    context.close()
    print('OK previas obsoletas, pestañas, respuestas perdidas, actualización de filtros/listado, catálogo y detalle públicos, límites, pausa, recuperación de red, caducidad y revocación', flush=True)


if __name__ == '__main__':
    fixture.run = run
    fixture.main()

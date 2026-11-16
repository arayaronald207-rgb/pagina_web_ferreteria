"""Gestión masiva en copia temporal SQLite; nunca utiliza .env ni MySQL."""
from contextlib import closing
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


if __name__ == '__main__':
    fixture.run = run
    fixture.main()

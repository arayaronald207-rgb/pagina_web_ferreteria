"""Prueba el importador solo en la copia temporal SQLite de probar_productos.
El Excel real se lee, pero todas las escrituras van a la fixture, nunca a MySQL.
"""
from contextlib import closing
from pathlib import Path
import json
import sqlite3
from urllib.parse import urlparse, parse_qs
from xml.sax.saxutils import escape
from zipfile import ZipFile, ZIP_DEFLATED
import probar_productos as fixture

check = fixture.check
EXCEL = Path(r'C:\Users\Ronald Araya\Downloads\Reporte_De_Inventario (2).xlsx')


def xlsx(path, rows, dtd=False, bomb=False):
    ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
    data = [('Codigo', 'Descripcion', 'Familia', 'Precio Costo')]
    data.extend((c, n, 'GENERAL', '987654321COSTO-PRIVADO') for c, n in rows)
    xml = [f'<worksheet xmlns="{ns}"><sheetData>']
    for r, values in enumerate(data, 1):
        xml.append(f'<row r="{r}">')
        for col, value in zip('ABCD', values):
            if isinstance(value, tuple):
                kind, value = value
                if kind == 'numeric':
                    xml.append(f'<c r="{col}{r}" t="n"><v>{value}</v></c>')
                else:
                    xml.append(f'<c r="{col}{r}" t="str"><f>1+1</f><v>{escape(value)}</v></c>')
            else:
                xml.append(f'<c r="{col}{r}" t="inlineStr"><is><t>{escape(value)}</t></is></c>')
        xml.append('</row>')
    xml.append('</sheetData></worksheet>')
    with ZipFile(path, 'w', ZIP_DEFLATED) as z:
        z.writestr('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>')
        z.writestr('xl/workbook.xml', ('<!DOCTYPE workbook [<!ENTITY xx SYSTEM "file:///no-leer">]>' if dtd else '') + f'<workbook xmlns="{ns}" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Inventario" sheetId="1" r:id="rId1"/></sheets></workbook>')
        z.writestr('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>')
        z.writestr('xl/worksheets/sheet1.xml', 'x' * (20 * 1024 * 1024 + 1) if bomb else ''.join(xml))


def run_preview_search(browser, base, root):
    """Buscar/excluir solo escribe en el trabajo privado de la fixture temporal."""
    context = browser.new_context(viewport={'width': 1440, 'height': 900})
    context.route('**/*', lambda r: r.continue_() if r.request.url.startswith(base) else r.abort())
    page = context.new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.goto(base + 'iniciar_sesion.php')
    page.locator('#correo').fill('admin@example.test')
    page.locator('#contrasena').fill('Fixture-Only-123')
    page.locator('.acceso-boton').click()
    sample = root / 'busqueda.xlsx'
    xlsx(sample, [(f'{i:05}', f'Taladro ÁRBOL {i:03}') for i in range(105)] +
         [('bk-elec-104', 'Sierra 50%_ <prueba>'), ('1610', 'Llave')])
    with closing(sqlite3.connect(root / 'datos.sqlite')) as catalog:
        original = catalog.execute('SELECT * FROM productos ORDER BY id_producto').fetchall()
        categories = catalog.execute('SELECT * FROM categorias').fetchall()
        page.goto(base + 'importar_productos.php')
        page.locator('#excel').set_input_files(str(sample))
        page.get_by_role('button', name='Analizar y ver vista previa').click()
        job = parse_qs(urlparse(page.url).query)['trabajo'][0]
        job_url = base + f'importar_productos.php?trabajo={job}'
        token = page.locator('input[name="token_csrf"]').first.input_value()
        table = page.locator('#seleccion-importacion tbody')

        def navigate(locator):
            with page.expect_navigation(wait_until='load'):
                locator.click()

        def search(value, excluded=False):
            page.locator('#buscar-importacion').fill(value)
            page.locator('#solo-excluidos').set_checked(excluded)
            navigate(page.get_by_role('button', name='Buscar', exact=True))

        def count(expected):
            check(page.locator('#coincidencias-importacion').inner_text() == f'{expected} coincidencias de 107 registros analizados.', 'Recuento filtrado exacto')

        count(107)
        search('áRbOl')
        count(105)
        check(table.locator('tr').count() == 50, 'Búsqueda Unicode parcial limitada a 50')
        for checkbox in table.get_by_role('checkbox').all():
            checkbox.check()
        navigate(page.get_by_role('link', name='Siguiente', exact=True))
        check('pagina=2' in page.url and table.locator('tr').count() == 50, 'Guardar automáticamente al paginar conservando búsqueda')
        page.get_by_role('checkbox', name='Excluir fila 52', exact=True).check()
        search('BK-ELEC-104')
        count(1)
        check('bk-elec-104' in table.inner_text(), 'Búsqueda por código sin cambiar mayúsculas ni valor original')
        page.get_by_role('checkbox', name='Excluir fila 107', exact=True).check()
        navigate(page.get_by_role('link', name='Limpiar búsqueda y filtros', exact=True))
        count(107)
        check(table.locator('input:checked').count() == 50, 'Limpiar guarda cambios y conserva exclusiones fuera de la búsqueda')
        search('', excluded=True)
        count(52)
        navigate(page.get_by_role('link', name='Siguiente', exact=True))
        check(table.locator('input:checked').count() == 2 and 'solo_excluidos=1' in page.url, 'Excluidos paginados sin perder el filtro')
        page.get_by_role('checkbox', name='Excluir fila 52', exact=True).uncheck()
        page.get_by_role('checkbox', name='Excluir fila 107', exact=True).uncheck()
        page.get_by_role('button', name='Guardar exclusiones de esta página').click()
        count(50)
        check('Página 1 de 1' in page.inner_text('nav[aria-label="Paginación de vista previa"]'), 'Ajustar página al retirar exclusiones del último resultado')
        check(table.locator('input:checked').count() == 50, 'Retirar exclusiones no afecta las otras páginas')
        search('00000')
        count(1)
        check('00000' in table.inner_text() and table.locator('input:checked').count() == 1, 'Código con ceros iniciales y exclusión conservados')
        # La selección enviada no puede cambiar filas fuera de los resultados.
        response = context.request.post(base + 'importar_productos.php', form={
            'trabajo': job, 'accion': 'seleccion', 'token_csrf': token, 'pagina': '1',
            'buscar': '00000', 'excluir[]': '107'})
        check(response.status == 422, 'Rechaza modificar filas ajenas a la página filtrada')
        search('%_')
        count(1)
        check('<prueba>' in table.inner_text() and table.locator('prueba').count() == 0, 'Comodines literales y salida escapada')
        search("' OR 1=1 --")
        count(0)
        check('No hay registros que coincidan' in table.inner_text(), 'Consulta preparada y estado sin coincidencias')
        check(context.request.get(job_url + '&buscar[]=x').status == 422, 'Filtro malformado controlado')
        search('00000')
        # Un fallo al guardar debe impedir abandonar la selección pendiente.
        page.get_by_role('checkbox', name='Excluir fila 2', exact=True).uncheck()
        def fail_save(route):
            if route.request.method == 'POST': route.fulfill(status=503, body='Temporal')
            else: route.fallback()
        context.route('**/importar_productos.php', fail_save)
        page.locator('#buscar-importacion').fill('Llave')
        page.get_by_role('button', name='Buscar', exact=True).click()
        page.locator('#exclusiones-pendientes:not([hidden])').wait_for()
        check('buscar=00000' in page.url and not table.get_by_role('checkbox').is_checked(), 'Fallo de guardado mantiene selección y página')
        context.unroute('**/importar_productos.php', fail_save)
        navigate(page.get_by_role('button', name='Buscar', exact=True))
        count(1)
        search('00000')
        check(not table.get_by_role('checkbox').is_checked(), 'Reintento guarda desmarcado antes de buscar')
        for width in (320, 768, 1440):
            page.set_viewport_size({'width': width, 'height': 900})
            check(page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), f'Buscador responsive a {width}px')
        with closing(sqlite3.connect(root / 'almacenamiento/importaciones' / f'{job}.sqlite')) as private:
            check(private.execute('SELECT COUNT(*) FROM filas WHERE excluido=1').fetchone()[0] == 49, 'Persistencia exacta entre búsquedas, páginas y limpieza')
            check(private.execute('SELECT codigo FROM filas WHERE fila=2').fetchone()[0] == '00000', 'Buscar nunca transforma códigos')
            check(json.loads(private.execute('SELECT datos FROM meta').fetchone()[0])['estado'] == 'previa', 'Buscar no confirma ni procesa lotes')
        check(catalog.execute('SELECT * FROM productos ORDER BY id_producto').fetchall() == original, 'Búsqueda/exclusiones no escriben en catálogo')
        check(catalog.execute('SELECT * FROM categorias').fetchall() == categories, 'Búsqueda/exclusiones no escriben categorías')
        check(not errors, 'Sin errores JavaScript en búsqueda: ' + repr(errors))
    context.close()
    # La alternativa sin JavaScript conserva guardado manual y enlaces GET.
    context = browser.new_context(java_script_enabled=False)
    page = context.new_page()
    page.goto(base + 'iniciar_sesion.php')
    page.locator('#correo').fill('admin@example.test')
    page.locator('#contrasena').fill('Fixture-Only-123')
    page.locator('.acceso-boton').click()
    page.goto(job_url + '&buscar=00000')
    page.get_by_role('checkbox', name='Excluir fila 2', exact=True).check()
    page.get_by_role('button', name='Guardar exclusiones de esta página').click()
    check(page.locator('#buscar-importacion').input_value() == '00000', 'Guardado manual conserva búsqueda sin JavaScript')
    page.locator('#buscar-importacion').fill('Llave')
    page.get_by_role('button', name='Buscar', exact=True).click()
    page.get_by_role('link', name='Limpiar búsqueda y filtros').click()
    check(page.get_by_role('checkbox', name='Excluir fila 2', exact=True).is_checked(), 'Exclusiones persistentes sin JavaScript')
    context.close()
    print('OK búsqueda parcial Unicode/código, 50 filas, exclusiones persistentes, filtro excluidos, guardado automático y manual, fallos de red y responsive', flush=True)


def run(browser, base, root):
    run_preview_search(browser, base, root)
    context = browser.new_context(viewport={'width': 1440, 'height': 900})
    context.route('**/*', lambda r: r.continue_() if r.request.url.startswith(base) else r.abort())
    page = context.new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.goto(base + 'importar_productos.php')
    check(page.url.endswith('iniciar_sesion.php'), 'Importador exige autenticación')
    page.locator('#correo').fill('admin@example.test')
    page.locator('#contrasena').fill('Fixture-Only-123')
    page.locator('.acceso-boton').click()
    page.goto(base + 'productos.php')
    page.get_by_role('link', name='Importar Excel', exact=True).click()
    token = page.locator('input[name="token_csrf"]').first.input_value()

    def upload(path):
        page.goto(base + 'importar_productos.php')
        page.locator('#excel').set_input_files(str(path))
        page.get_by_role('button', name='Analizar y ver vista previa').click()
        check('trabajo=' in page.url, 'Vista previa: ' + page.locator('body').inner_text()[:900])
        return parse_qs(urlparse(page.url).query)['trabajo'][0]

    def post(job, action, extra=None, json_response=False):
        return context.request.post(base + 'importar_productos.php', form={'token_csrf': token, 'trabajo': job, 'accion': action, **(extra or {})},
                                    headers={'Accept': 'application/json'} if json_response else {})

    with closing(sqlite3.connect(root / 'datos.sqlite')) as db:
        original = db.execute('SELECT * FROM productos ORDER BY id_producto').fetchall()
        cats_before = db.execute('SELECT * FROM categorias').fetchall()
        for name, content in [('falso.xlsx', b'<?php echo 1;?>'), ('falso.xls', b'PK\x03\x04falso')]:
            response = context.request.post(base + 'importar_productos.php', multipart={'token_csrf': token, 'accion': 'analizar', 'excel': {'name': name, 'mimeType': 'application/zip', 'buffer': content}})
            check(response.status == 422, 'Rechaza formato falso')
        for mode in ('dtd', 'bomb'):
            path = root / (mode + '.xlsx')
            xlsx(path, [('00001', 'Prueba')], **{mode: True})
            response = context.request.post(base + 'importar_productos.php', multipart={'token_csrf': token, 'accion': 'analizar', 'excel': {'name': path.name, 'mimeType': 'application/zip', 'buffer': path.read_bytes()}})
            check(response.status == 422, 'Rechaza XML peligroso o descompresión excesiva')

        sample = root / 'muestra.xlsx'
        xlsx(sample, [('00001', '  Martillo   <prueba> '), ('bk-elec-104', 'Sierra'), ('01610', 'Llave'), ('1610', 'Kit'),
                      ('SEED-001', 'No sustituir existente'), ('DUP', 'Duplicado A'), ('dup', 'Duplicado B'),
                      ('codigo con espacio', 'Inválido'), ('NOMBRE', ''), (('numeric', '1234'), 'Numérico'), (('formula', 'X'), 'Fórmula')])
        job = upload(sample)
        check(db.execute('SELECT * FROM productos ORDER BY id_producto').fetchall() == original, 'Analizar no escribe productos')
        check(db.execute('SELECT * FROM categorias').fetchall() == cats_before, 'Analizar no crea categorías')
        report = context.request.get(base + f'importar_productos.php?trabajo={job}&reporte=1')
        check(report.status == 200 and '00001' in report.text() and 'bk-elec-104' in report.text(), 'Reporte conserva códigos')
        check('987654321' not in report.text() and 'Precio Costo' not in report.text(), 'Reporte no contiene finanzas')
        check(post(job, 'lote', json_response=True).status == 422, 'No procesar sin confirmar')
        check(post(job, 'confirmar', {'confirmar': '1', 'categoria': '1'}).status == 422, 'Bloquear filas inválidas incluidas')
        # Conservar DUP y excluir únicamente su repetición; el duplicado se recalcula.
        for row in (8, 9, 10, 11, 12):
            page.get_by_role('checkbox', name=f'Excluir fila {row}', exact=True).check()
        page.get_by_role('button', name='Guardar exclusiones de esta página').click()
        check(post(job, 'confirmar', {'confirmar': '1', 'categoria': 'sin_clasificar'}).status == 422, 'Categoría provisional exige consentimiento explícito')
        check(post(job, 'confirmar', {'confirmar': '1', 'categoria': 'sin_clasificar', 'autorizar_provisional': '1', 'token_csrf': 'no'}).status == 400, 'Confirmación exige CSRF')
        response = post(job, 'confirmar', {'confirmar': '1', 'categoria': 'sin_clasificar', 'autorizar_provisional': '1'})
        check(response.ok, 'Confirmación explícita aceptada')
        check(db.execute('SELECT * FROM categorias').fetchall() == cats_before, 'Confirmar aún no ejecuta el lote')
        # El fallo a mitad del lote debe revertir incluso la categoría provisional.
        db.execute("CREATE TRIGGER fallar BEFORE INSERT ON productos WHEN NEW.codigo='1610' BEGIN SELECT RAISE(ABORT, 'error privado de prueba'); END")
        db.commit()
        response = post(job, 'lote', json_response=True)
        check(response.status == 500 and 'error privado' not in response.text(), 'Fallo controlado sin datos internos')
        check(db.execute('SELECT * FROM productos ORDER BY id_producto').fetchall() == original, 'Rollback completo del lote')
        check(db.execute('SELECT * FROM categorias').fetchall() == cats_before, 'Rollback de categoría provisional')
        db.execute('DROP TRIGGER fallar'); db.commit()
        response = post(job, 'lote', json_response=True)
        check(response.ok, 'Reintento de lote: ' + response.text())
        summary = response.json()['resumen']
        check(summary['creados'] == 5 and summary['omitidos'] == 1 and summary['excluidos'] == 5, 'Resumen real de creados/existentes/excluidos')
        check(db.execute("SELECT codigo,visible FROM productos WHERE codigo IN ('00001','bk-elec-104','01610','1610') ORDER BY id_producto").fetchall() == [('00001',0),('bk-elec-104',0),('01610',0),('1610',0)], 'Códigos exactos y publicación oculta')
        check(db.execute('SELECT * FROM productos WHERE id_producto<=21 ORDER BY id_producto').fetchall() == original, 'Existentes intactos')
        check(post(job, 'lote', json_response=True).json()['resumen']['creados'] == 5, 'Repetir un lote terminado no duplica')

        # El empleado mantiene permisos habituales, pero no puede autorizar Sin clasificar.
        empleado = browser.new_context()
        empleado.route('**/*', lambda r: r.continue_() if r.request.url.startswith(base) else r.abort())
        pe = empleado.new_page(); pe.goto(base + 'iniciar_sesion.php')
        pe.locator('#correo').fill('empleado@example.test'); pe.locator('#contrasena').fill('Fixture-Only-123'); pe.locator('.acceso-boton').click()
        check(empleado.request.get(base + f'importar_productos.php?trabajo={job}').status == 422, 'Vista previa privada por cuenta')
        pe.goto(base + 'importar_productos.php'); pe.locator('#excel').set_input_files(str(sample)); pe.get_by_role('button', name='Analizar y ver vista previa').click()
        check(pe.locator('#categoria_importacion option[value="sin_clasificar"]').count() == 0, 'Empleado no tiene opción provisional')
        employee_file = root / 'empleado.xlsx'; xlsx(employee_file, [('EMP-001', 'Producto empleado')])
        pe.goto(base + 'importar_productos.php'); pe.locator('#excel').set_input_files(str(employee_file)); pe.get_by_role('button', name='Analizar y ver vista previa').click()
        employee_job = parse_qs(urlparse(pe.url).query)['trabajo'][0]
        employee_token = pe.locator('input[name="token_csrf"]').first.input_value()
        provisional = db.execute("SELECT id_categoria FROM categorias WHERE nombre='Sin clasificar'").fetchone()[0]
        for category in ('sin_clasificar', str(provisional)):
            response = empleado.request.post(base + 'importar_productos.php', form={'accion': 'confirmar', 'trabajo': employee_job, 'token_csrf': employee_token, 'categoria': category, 'confirmar': '1', 'autorizar_provisional': '1'})
            check(response.status == 422 and 'Solo un administrador' in response.text(), 'Servidor rechaza autorización provisional de empleado')
        response = empleado.request.post(base + 'importar_productos.php', form={'accion': 'confirmar', 'trabajo': employee_job, 'token_csrf': employee_token, 'categoria': '1', 'confirmar': '1'})
        check(response.ok, 'Empleado puede importar con categoría normal existente')
        empleado.close()
        print('OK carga, vista previa sin escrituras, privacidad, CSRF, exclusiones, códigos, categoría provisional y rollback', flush=True)

        # Control de exclusiones sin guardar y progreso real mediante JavaScript.
        jsfile = root / 'javascript.xlsx'
        xlsx(jsfile, [(f'JS-{n:04}', f'Producto de navegador {n}') for n in range(202)])
        jsjob = upload(jsfile)
        page.locator('#categoria_importacion').select_option('1'); page.locator('#confirmar_ocultos').check()
        page.get_by_role('checkbox', name='Excluir fila 2', exact=True).check()
        page.locator('#confirmar-importacion button').click()
        check(page.locator('#exclusiones-pendientes').is_visible(), 'No confirmar exclusiones sin guardar')
        page.get_by_role('button', name='Guardar exclusiones de esta página').click()
        page.locator('#categoria_importacion').select_option('1'); page.locator('#confirmar_ocultos').check()
        page.locator('#confirmar-importacion button').click()
        page.get_by_role('button', name='Iniciar o continuar procesamiento').click()
        page.wait_for_selector('.alert-success', timeout=60000)
        check('201 de 201' in page.locator('#estado-importacion').inner_text(), 'Progreso JavaScript y resumen final reales')

        # Simular un corte después del commit de catálogo y antes del registro privado.
        recovery = root / 'recuperacion.xlsx'; xlsx(recovery, [('RECUP-A', 'Primero'), ('RECUP-B', 'Segundo'), ('RECUP-C', 'Tercero')])
        recovery_job = upload(recovery)
        check(post(recovery_job, 'confirmar', {'confirmar': '1', 'categoria': '1'}).ok, 'Confirmar recuperación')
        db.execute("INSERT INTO productos (codigo,nombre,id_categoria,visible) VALUES ('RECUP-A','Primero',1,0)"); db.commit()
        with closing(sqlite3.connect(root / 'almacenamiento/importaciones' / (recovery_job + '.sqlite'))) as work:
            work.execute("UPDATE filas SET estado='en_lote' WHERE fila IN (2,3)"); work.commit()
        response = post(recovery_job, 'lote', json_response=True)
        check(response.ok and response.json()['resumen']['revisar'] == 1 and response.json()['resumen']['creados'] == 2, 'Recuperación conservadora sin atribuir ni duplicar registros')
        print('OK permisos de empleado, exclusiones sin guardar, progreso JavaScript y recuperación tras interrupción', flush=True)

        # Archivo real: importación solamente dentro de SQLite temporal, en 24 lotes.
        check(EXCEL.is_file(), 'Se necesita el Excel analizado para la prueba a escala real')
        job = upload(EXCEL)
        check(page.locator('tbody tr').count() == 50, 'Vista previa limitada a 50 filas')
        with closing(sqlite3.connect(root / 'almacenamiento/importaciones' / (job + '.sqlite'))) as work:
            check(work.execute('SELECT COUNT(*) FROM filas').fetchone()[0] == 4665, '4.665 registros sin título ni totales')
            check(work.execute("SELECT COUNT(*) FROM filas WHERE error<>'' OR duplicado=1").fetchone()[0] == 0, 'Excel analizado válido')
            expected = work.execute("SELECT COUNT(*) FROM filas WHERE instr(lower(codigo), 'bk-1037')>0 OR instr(lower(nombre), 'bk-1037')>0").fetchone()[0]
        page.locator('#buscar-importacion').fill('BK-1037')
        with page.expect_navigation(wait_until='load'):
            page.get_by_role('button', name='Buscar', exact=True).click()
        check(page.locator('#coincidencias-importacion').inner_text() == f'{expected} coincidencias de 4665 registros analizados.', 'Buscar en el archivo completo, no solo en las 50 filas visibles')
        check('bk-1037' in page.locator('tbody').inner_text(), 'Localizar código original en los 4.665 registros')
        with page.expect_navigation(wait_until='load'):
            page.get_by_role('link', name='Limpiar búsqueda y filtros').click()
        for width in (320, 375, 768, 1440):
            page.set_viewport_size({'width': width, 'height': 900})
            check(page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), f'Importador responsive {width}')
            if width in (375, 1440):
                page.screenshot(path=str(fixture.ARTIFACTS / f'{width}-importador.png'))
        page.set_viewport_size({'width': 1440, 'height': 900})
        page.get_by_role('link', name='Siguiente', exact=True).click()
        check(page.locator('tbody tr').count() == 50, 'Segunda página de vista previa')
        page.get_by_role('checkbox', name='Excluir fila 54', exact=True).check()
        page.get_by_role('button', name='Guardar exclusiones de esta página').click()
        check(post(job, 'confirmar', {'confirmar': '1', 'categoria': '1'}).ok, 'Confirmar categoría existente')
        done = 0; calls = 0
        while True:
            response = post(job, 'lote', json_response=True); check(response.ok, response.text())
            result = response.json(); summary = result['resumen']; calls += 1
            check(done <= summary['procesados'] <= done + 200, 'Progreso por filas efectivamente procesadas')
            done = summary['procesados']
            if result['estado'] == 'terminado': break
            check(calls < 30, 'La importación debe terminar')
        check(calls == 24 and done == 4664, '24 lotes con una exclusión')
        check(summary['creados'] + summary['omitidos'] == 4664 and summary['revisar'] == 0, 'Resumen cuadra con filas procesadas')
        check(db.execute('SELECT COUNT(*) FROM productos WHERE id_producto>21 AND visible<>0').fetchone()[0] == 0, 'Todos los importados permanecen ocultos')
        check(db.execute('SELECT * FROM productos WHERE id_producto<=21 ORDER BY id_producto').fetchall() == original, 'Carga masiva no cambia existentes')
        check(db.execute("SELECT codigo FROM productos WHERE codigo='bk-1037'").fetchone()[0] == 'bk-1037', 'Minúsculas del Excel real preservadas')
        # Regresar al detalle conserva página, búsqueda y categoría sin cargar todo el catálogo.
        db.execute("UPDATE productos SET visible=1 WHERE id_producto>21 AND id_producto<=90"); db.commit()
        page.goto(base + 'catalogo.php?categoria=Herramientas&pagina=2')
        check(page.locator('.producto').count() == 24, 'Catálogo público limitado a 24 productos')
        page.locator('.producto__detalles').first.click()
        check('pagina=2' in page.locator('.detalle__volver').get_attribute('href'), 'Detalle conserva página')
        page.locator('.detalle__volver').click()
        check('pagina=2' in page.url and page.locator('.producto').count() == 24, 'Regreso a la segunda página')
        page.goto(base + 'catalogo.php?buscar=SEED&pagina=999999999')
        check(page.locator('.producto').count() == 21, 'Paginación fuera de rango se ajusta al resultado filtrado')
        check(not errors, 'Errores JavaScript: ' + repr(errors))
    context.close()
    print('OK 4.665 registros en copia temporal, 24 lotes, resumen y paginación pública con retorno al detalle', flush=True)


if __name__ == '__main__':
    fixture.run = run
    fixture.main()

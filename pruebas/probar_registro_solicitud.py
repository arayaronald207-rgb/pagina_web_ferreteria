"""XAMPP existente. Preparación de borradores y POST inválidos; nunca registra en MySQL.
La confirmación exitosa se simula interceptando la respuesta en el navegador.
"""
import json
import re
import sys
sys.dont_write_bytecode = True
from urllib.parse import parse_qs
from playwright.sync_api import sync_playwright, expect
from probar_lista_solicitud import BASE, CHROME, KEY, ROOT, estado_mysql


def main():
    antes = estado_mysql()
    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=CHROME, headless=True)
        try:
            context = browser.new_context(viewport={'width': 1366, 'height': 900})
            envios_interceptados = []
            respuesta = {'exito': False, 'html': ''}

            def proteger(route):
                request = route.request
                if not request.url.startswith(BASE):
                    route.abort(); return
                if request.method == 'GET' and request.url.endswith('solicitar_proforma.php?intento=confirmacion-simulada'):
                    route.fulfill(content_type='text/html; charset=utf-8', body=respuesta['html']); return
                if request.method == 'POST':
                    if request.url == BASE + 'solicitud_api.php':
                        route.continue_(); return
                    campos = parse_qs(request.post_data or '')
                    if request.url == BASE + 'solicitar_proforma.php' and campos.get('accion') == ['preparar']:
                        route.continue_(); return  # Solo valida y guarda un borrador en sesión.
                    if request.url.split('?')[0] == BASE + 'solicitar_proforma.php' and campos.get('accion') == ['enviar']:
                        envios_interceptados.append(campos)
                        if respuesta['exito']:
                            route.fulfill(content_type='text/html; charset=utf-8', body=respuesta['html'])
                        else:
                            route.abort('failed')
                        return
                    raise AssertionError('POST inesperado bloqueado: ' + request.url)
                if request.method == 'GET':
                    route.continue_(); return
                route.abort()

            context.route('**/*', proteger)
            page = context.new_page()
            errores = []
            page.on('pageerror', lambda error: errores.append(str(error)))
            page.goto(BASE + 'mi_solicitud.php')
            expect(page.locator('#solicitud-continuar')).to_be_disabled()
            page.goto(BASE + 'catalogo.php')
            agregar = page.locator('[data-agregar-solicitud]').first
            expect(agregar).to_be_enabled()
            id_producto = int(agregar.get_attribute('data-agregar-solicitud'))
            agregar.click()
            expect(page.locator('[data-solicitud-contador]')).to_have_text('1 producto')
            page.goto(BASE + 'mi_solicitud.php')
            expect(page.locator('#solicitud-continuar')).to_be_enabled()
            lista_original = page.evaluate('(k) => localStorage.getItem(k)', KEY)
            csrf_lista = page.locator('[name=csrf]').input_value()
            token_lista = page.locator('[name=intento]').input_value()
            page.locator('#solicitud-continuar').click()
            expect(page.locator('#registro-solicitud')).to_be_visible()
            assert '?intento=' in page.url  # POST -> 303 -> GET.
            url_formulario = page.url
            expect(page.locator('.solicitud__resumen-producto')).to_have_count(1)
            expect(page.locator('.solicitud__resumen-producto')).to_contain_text('Cantidad: 1')
            assert page.evaluate('(k) => localStorage.getItem(k)', KEY) == lista_original

            # Repetir preparación conserva el mismo borrador y no inserta filas.
            preparacion = {'accion': 'preparar', 'csrf': csrf_lista, 'intento': token_lista,
                           'productos': json.dumps([{'id_producto': id_producto, 'cantidad': 1}])}
            repetir = context.request.post(BASE + 'solicitar_proforma.php', form=preparacion, max_redirects=0)
            assert repetir.status == 303 and repetir.headers['location'].endswith(token_lista)
            ajeno = browser.new_context()
            assert ajeno.request.get(url_formulario).status == 422
            ajeno.close()

            # Estos POST se rechazan antes de escribir. Ninguno contiene todos los datos válidos.
            csrf = page.locator('#registro-solicitud [name=csrf]').input_value()
            intento = page.locator('#registro-solicitud [name=intento]').input_value()
            invalidos = [
                ({'accion': 'enviar', 'csrf': 'invalido', 'intento': intento, 'confirmacion': '1'}, 403),
                ({'accion': 'enviar', 'csrf': csrf, 'intento': intento, 'confirmacion': '1', 'cliente_nombre': ''}, 422),
                ({'accion': 'enviar', 'csrf': csrf, 'intento': intento, 'cliente_nombre': 'Nombre Ficticio'}, 422),
            ]
            for campos, codigo in invalidos:
                result = context.request.post(BASE + 'solicitar_proforma.php', form=campos)
                assert result.status == codigo
                assert 'id="solicitud-confirmada"' not in result.text()
                assert 'SQLSTATE' not in result.text()

            # Validación del navegador y controles adaptables.
            enviar = page.locator('#registro-enviar')
            enviar.click()
            assert not envios_interceptados
            page.locator('#cliente_nombre').fill('Persona Ficticia')
            page.locator('#cliente_whatsapp').fill('123')
            page.locator('#cliente_correo').fill('prueba@example.test')
            page.locator('[name=confirmacion]').check()
            enviar.click()
            assert not envios_interceptados
            assert page.locator('#cliente_whatsapp').evaluate('(e) => !e.checkValidity()')
            page.locator('#cliente_whatsapp').fill('+506 8888 8888')
            for width in (320, 375, 768, 1366):
                page.set_viewport_size({'width': width, 'height': 900})
                page.wait_for_timeout(300)
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), f'Desbordamiento a {width}'
            page.evaluate('document.activeElement.blur(); window.scrollTo(0,0)')
            page.wait_for_timeout(300)
            page.screenshot(path=str(ROOT / 'tmp' / 'registro-escritorio.png'), full_page=True)
            page.set_viewport_size({'width': 375, 'height': 900})
            page.wait_for_timeout(300)
            page.screenshot(path=str(ROOT / 'tmp' / 'registro-movil.png'), full_page=True)
            page.set_viewport_size({'width': 1366, 'height': 900})

            # Una respuesta PHP de error conserva valores escapados y la lista.
            campos_error = {'accion': 'enviar', 'csrf': csrf, 'intento': intento, 'confirmacion': '1',
                            'cliente_nombre': 'Persona Ficticia <script>ejemplo</script>', 'cliente_whatsapp': '+50688888888',
                            'cliente_correo': 'invalido', 'observaciones': 'Texto <script>window.malicioso=1</script>'}
            rechazo = context.request.post(BASE + 'solicitar_proforma.php', form=campos_error)
            assert rechazo.status == 422 and '&lt;script&gt;' in rechazo.text()
            page.route('**/error-simulado', lambda route: route.fulfill(content_type='text/html; charset=utf-8', body=rechazo.text()))
            page.goto(BASE + 'error-simulado')
            expect(page.locator('#registro-error')).to_contain_text('correo electrónico válido')
            expect(page.locator('#observaciones')).to_have_value(campos_error['observaciones'])
            assert page.evaluate('window.malicioso === undefined')
            assert page.evaluate('(k) => localStorage.getItem(k)', KEY) == lista_original
            page.goto(url_formulario)

            # Fallo de red al enviar: se bloquea el POST real y la lista permanece intacta.
            def completar():
                page.locator('#cliente_nombre').fill('Persona Ficticia')
                page.locator('#cliente_whatsapp').fill('+506 8888 8888')
                page.locator('#cliente_correo').fill('prueba@example.test')
                page.locator('[name=confirmacion]').check()

            completar()
            page.locator('#registro-enviar').click()
            expect(page.locator('#registro-error')).to_contain_text('No recibimos la confirmación')
            expect(page.locator('#cliente_nombre')).to_have_value('Persona Ficticia')
            expect(page.locator('#registro-enviar')).to_be_enabled()
            assert page.evaluate('(k) => localStorage.getItem(k)', KEY) == lista_original
            assert len(envios_interceptados) == 1

            # Confirmación ficticia en memoria: no llega al controlador de registro.
            html_base = context.request.get(url_formulario).text()
            recibo = json.dumps({'id': 314, 'productos': [{'id_producto': id_producto, 'cantidad': 1}]})
            bloque = '<main class="solicitud contenedor" id="contenido" data-registro-solicitud>' \
                     '<h1>Solicitud n.º 314 registrada</h1><p id="solicitud-limpieza"></p>' \
                     '<script type="application/json" id="solicitud-confirmada">' + recibo + '</script></main>'
            respuesta['html'] = re.sub(r'<main\b.*?</main>', lambda _: bloque, html_base, flags=re.S)
            respuesta['exito'] = True
            completar()
            # La respuesta simulada de fetch lleva una URL de confirmación; el GET posterior
            # también se intercepta. Ningún envío válido alcanza PHP ni MySQL.
            page.locator('#registro-solicitud').evaluate('(f,url) => f.action=url', BASE + 'solicitar_proforma.php?intento=confirmacion-simulada')
            page.locator('#registro-enviar').click()
            expect(page.locator('#solicitud-limpieza')).to_contain_text('se retiraron')
            almacen = page.evaluate('(k) => JSON.parse(localStorage.getItem(k))', KEY)
            assert almacen['productos'] == [] and almacen['solicitudesConfirmadas'] == [314]
            assert len(envios_interceptados) == 2
            page.reload()
            expect(page.locator('#solicitud-limpieza')).to_contain_text('se retiraron')
            assert len(envios_interceptados) == 2
            # Recargar un recibo antiguo no elimina una lista nueva.
            page.evaluate('([k,id]) => {const x=JSON.parse(localStorage.getItem(k)); x.productos=[{id_producto:id,cantidad:4}]; localStorage.setItem(k,JSON.stringify(x));}', [KEY, id_producto])
            page.reload()
            expect(page.locator('#solicitud-limpieza')).to_contain_text('se retiraron')
            assert page.evaluate('(k) => JSON.parse(localStorage.getItem(k)).productos[0].cantidad', KEY) == 4
            # Otro recibo retira solo lo enviado y conserva cantidades añadidas en otra pestaña.
            respuesta['html'] = respuesta['html'].replace('"id": 314', '"id": 315')
            page.reload()
            expect(page.locator('#solicitud-limpieza')).to_contain_text('se retiraron')
            assert page.evaluate('(k) => JSON.parse(localStorage.getItem(k)).productos[0].cantidad', KEY) == 3
            assert not errores, errores
        finally:
            browser.close()
            assert estado_mysql() == antes, 'Cambió MySQL durante las pruebas; revisar actividad concurrente'
    print('OK: preparación real en XAMPP, resumen, sesión privada, CSRF y validaciones sin insertar solicitudes.')
    print('OK: navegador a 320/375/768/1366 px, errores y red sin pérdida de lista; confirmación simulada y recarga sin doble limpieza.')
    print('OK: huellas de productos, categorías, solicitudes y detalle idénticas. Ninguna solicitud ficticia en MySQL.')


if __name__ == '__main__':
    main()

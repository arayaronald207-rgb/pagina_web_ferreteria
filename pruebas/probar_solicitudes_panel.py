"""Pruebas en XAMPP existente. MySQL solo lectura; vistas y cambios ficticios en memoria.
Nunca inicia servidores, crea cuentas ni envía cambios de estado autenticados a MySQL.
"""
import json
import re
import subprocess
import sys
from urllib.parse import parse_qs, urlparse

sys.dont_write_bytecode = True
from playwright.sync_api import sync_playwright, expect
from probar_lista_solicitud import BASE, CHROME, PHP, ROOT, estado_mysql


def ejecutar_php(*args):
    return subprocess.run([PHP, str(ROOT / 'pruebas/probar_solicitudes_sin_mysql.php'), *args],
                          cwd=ROOT, capture_output=True, text=True, encoding='utf-8', check=True).stdout


def main():
    antes = estado_mysql()
    # Consultas reales con la conexión protegida contra escrituras.
    codigo = '''<?php
require getcwd().'/app/Servicios/panel_administrativo.php';
require getcwd().'/app/Servicios/solicitudes.php';
$db=obtener_conexion(); $db->exec('SET SESSION TRANSACTION READ ONLY');
$s=buscar_solicitud($db,1);
if (!$s) throw new RuntimeException('No existe la solicitud de referencia');
$porNumero=listar_solicitudes($db,['buscar'=>'1']);
if (!in_array(1,array_column($porNumero['solicitudes'],'id_solicitud'))) throw new RuntimeException('Busqueda por numero');
$porNombre=listar_solicitudes($db,['buscar'=>$s['cliente_nombre'],'estado'=>$s['estado']]);
if ($porNombre['total']<1) throw new RuntimeException('Busqueda por cliente y estado');
foreach (ESTADOS_SOLICITUD as $estado) {
    $q=$db->prepare('SELECT COUNT(*) FROM solicitudes WHERE estado=?'); $q->execute([$estado]);
    if (listar_solicitudes($db,['estado'=>$estado])['total'] !== (int)$q->fetchColumn()) throw new RuntimeException('Filtro estado');
}
$detalle=detalle_solicitud_panel($db,1);
$q=$db->prepare('SELECT producto_nombre,cantidad FROM detalle_solicitud WHERE id_solicitud=? ORDER BY id_detalle'); $q->execute([1]);
if (array_map(fn($fila)=>[$fila['producto_nombre'],$fila['cantidad']],$detalle)!==array_map(fn($fila)=>[$fila['producto_nombre'],$fila['cantidad']],$q->fetchAll())) throw new RuntimeException('Detalle original');
if ((int)indicadores_panel($db)['pendientes']!==listar_solicitudes($db,['estado'=>'Pendiente'])['total']) throw new RuntimeException('Contador');
echo 'OK MySQL de solo lectura: busquedas, filtros, detalle original y contador.';
'''
    result = subprocess.run([PHP], input=codigo, cwd=ROOT, capture_output=True, text=True, encoding='utf-8', check=True)
    print(result.stdout)
    for rol in ('administrador', 'empleado'):
        assert ejecutar_php('acceso', rol) == 'ACCESO AUTORIZADO'
    for rol in ('visitante', 'desconocido'):
        assert 'ACCESO AUTORIZADO' not in ejecutar_php('acceso', rol)
    assert 'ACCESO AUTORIZADO' not in ejecutar_php('acceso', 'empleado', 'vencida')

    vistas = {tipo: ejecutar_php('vista', tipo) for tipo in ('listado', 'detalle', 'confirmacion', 'resultado', 'vacio', 'error')}
    vista_empleado = ejecutar_php('vista', 'detalle', 'empleado')
    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=CHROME, headless=True)
        try:
            anonimo = browser.new_context()
            for ruta in ('solicitudes.php', 'solicitud.php?id=1', 'administracion.php'):
                r = anonimo.request.get(BASE + ruta, max_redirects=0)
                assert r.status == 303 and r.headers['location'] == 'iniciar_sesion.php'
                assert 'Cliente' not in r.text()
            r = anonimo.request.post(BASE + 'solicitud.php?id=1', form={'accion': 'guardar_estado'}, max_redirects=0)
            assert r.status == 303 and r.headers['location'] == 'iniciar_sesion.php'
            anonimo.close()

            context = browser.new_context(viewport={'width': 1366, 'height': 900})
            estado = {'vista': 'listado', 'envios': 0, 'cambios': 0}
            def ruta_segura(route):
                request = route.request
                url = urlparse(request.url)
                if request.url.startswith(BASE + 'assets/') and request.method == 'GET':
                    route.continue_(); return
                if not request.url.startswith(BASE):
                    route.abort(); return
                if url.path.endswith('/solicitudes.php') and request.method == 'GET':
                    if estado['vista'] not in ('vacio', 'error'):
                        estado['vista'] = 'listado'
                    html = vistas[estado['vista']]
                elif url.path.endswith('/solicitud.php'):
                    if request.method == 'POST':
                        campos = parse_qs(request.post_data or '')
                        assert campos.get('token_csrf'), 'Falta CSRF en el formulario'
                        if campos.get('accion') == ['revisar_estado']:
                            assert campos.get('estado') == ['En proceso']
                            estado['vista'] = 'confirmacion'
                            estado['envios'] += 1
                        elif campos.get('accion') == ['guardar_estado']:
                            assert campos.get('confirmacion') == ['ficticio']
                            estado['vista'] = 'resultado'
                            estado['cambios'] += 1
                        else:
                            raise AssertionError('Acción no prevista bloqueada')
                    elif estado['vista'] != 'error':
                        estado['vista'] = 'detalle'
                    html = vista_empleado if estado['vista'] == 'detalle' and estado.get('empleado') else vistas[estado['vista']]
                else:
                    route.abort(); return
                route.fulfill(content_type='text/html; charset=utf-8', body=html)

            context.route('**/*', ruta_segura)
            page = context.new_page()
            errores = []
            page.on('pageerror', lambda e: errores.append(str(e)))
            page.goto(BASE + 'solicitudes.php')
            expect(page.locator('.panel-navegacion [aria-current]')).to_have_text('Solicitudes')
            expect(page.locator('tbody tr')).to_have_count(1)
            expect(page.locator('tbody')).to_contain_text('<script>window.inyectado=1</script>')
            assert page.evaluate('window.inyectado === undefined')
            page.locator('#solicitudes-buscar').fill('Nombre & prueba')
            page.locator('#solicitudes-estado').select_option('Pendiente')
            page.get_by_role('button', name='Buscar', exact=True).click()
            query = parse_qs(urlparse(page.url).query)
            assert query['buscar'] == ['Nombre & prueba'] and query['estado'] == ['Pendiente']
            page.get_by_role('link', name='Siguiente', exact=True).click()
            assert parse_qs(urlparse(page.url).query)['pagina'] == ['2']
            page.get_by_role('link', name='Ver solicitud 7', exact=True).click()
            expect(page.locator('h1')).to_have_text('Solicitud n.º 7')
            expect(page.locator('.panel-solicitud-observaciones')).to_contain_text('<script>window.inyectado=1</script>')
            expect(page.locator('#responder-whatsapp')).to_have_attribute('href', 'https://wa.me/50688888888')
            expect(page.locator('#responder-whatsapp')).to_have_attribute('target', '_blank')
            assert page.evaluate('window.inyectado === undefined')
            expect(page.locator('#nuevo-estado option[value="Pendiente"]')).to_be_disabled()
            page.locator('#nuevo-estado').select_option('En proceso')
            page.get_by_role('button', name='Revisar cambio de estado').click()
            expect(page.get_by_role('heading', name='Confirmar cambio de estado')).to_be_visible()
            assert estado['cambios'] == 0
            page.get_by_role('link', name='Cancelar cambio').click()
            expect(page.locator('#estado-actual-solicitud')).to_have_text('Pendiente')
            page.locator('#nuevo-estado').select_option('En proceso')
            page.get_by_role('button', name='Revisar cambio de estado').click()
            page.get_by_role('button', name='Sí, cambiar estado').click()
            expect(page.get_by_role('status')).to_contain_text('estado actualizado a En proceso')
            expect(page.locator('#estado-actual-solicitud')).to_have_text('En proceso')
            assert estado['cambios'] == 1

            for tipo in ('listado', 'detalle'):
                page.goto(BASE + ('solicitudes.php' if tipo == 'listado' else 'solicitud.php?id=7'))
                for ancho in (320, 375, 768, 1366):
                    page.set_viewport_size({'width': ancho, 'height': 900})
                    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), (tipo, ancho)
                page.evaluate('document.activeElement.blur(); window.scrollTo(0,0)')
                page.screenshot(path=str(ROOT / 'tmp' / ('panel-solicitudes-' + tipo + '.png')), full_page=True)
            page.set_viewport_size({'width': 375, 'height': 900})
            page.screenshot(path=str(ROOT / 'tmp' / 'panel-solicitudes-movil.png'), full_page=True)
            estado['empleado'] = True
            page.goto(BASE + 'solicitud.php?id=7')
            expect(page.locator('.panel-cuenta')).to_contain_text('Empleado')
            expect(page.locator('#revisar-estado-solicitud')).to_be_visible()
            expect(page.locator('.panel-navegacion')).not_to_contain_text('Usuarios')
            estado['vista'] = 'vacio'
            page.goto(BASE + 'solicitudes.php')
            expect(page.locator('.panel-vacio')).to_contain_text('No hay solicitudes')
            estado['vista'] = 'error'
            page.goto(BASE + 'solicitud.php?id=7')
            expect(page.get_by_role('alert')).to_contain_text('no existe')
            expect(page.locator('form#revisar-estado-solicitud')).to_have_count(0)
            assert not errores, errores
        finally:
            browser.close()
            assert estado_mysql() == antes, 'Cambió MySQL; revisar actividad concurrente'
    print('OK: visitantes bloqueados en XAMPP; roles y sesión vencida comprobados en memoria.')
    print('OK: vistas reales con datos ficticios, escape XSS, WhatsApp, confirmación, cancelación y móvil.')
    print('OK: solicitud n.º 1, detalle, productos y categorías intactos; ningún cambio de estado enviado a MySQL.')


if __name__ == '__main__':
    main()

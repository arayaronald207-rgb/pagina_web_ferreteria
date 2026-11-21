"""Pruebas contra XAMPP existente: solo GET y el POST de consulta de solicitud_api.
No crea servidores, copias del proyecto ni bases temporales. No escribe en MySQL.
Las listas de prueba viven en el navegador de pruebas, separado del navegador del usuario.
"""
import json
import os
import re
from pathlib import Path
import shutil
import subprocess
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
BASE = 'http://localhost/pagina_web_ferreteria/public/'
PHP = shutil.which('php') or r'C:\xampp2\php\php.exe'
CHROME = os.environ.get('CRUCE_CHROME', r'C:\Program Files\Google\Chrome\Application\chrome.exe')
KEY = 'el-cruce:solicitud:v1:' + urlparse(BASE).path


def estado_mysql():
    codigo = '''<?php
require getcwd().'/config/conexion.php';
$db=obtener_conexion(); $estado=[];
foreach (['productos'=>'id_producto', 'categorias'=>'id_categoria', 'solicitudes'=>'id_solicitud', 'detalle_solicitud'=>'id_detalle'] as $tabla=>$id) {
    $hash=hash_init('sha256');
    foreach ($db->query('SELECT * FROM '.$tabla.' ORDER BY '.$id) as $fila) hash_update($hash, json_encode($fila));
    $estado[$tabla]=hash_final($hash);
}
$estado['oculto']=$db->query('SELECT id_producto FROM productos WHERE visible=0 LIMIT 1')->fetchColumn();
echo json_encode($estado);
'''
    result = subprocess.run([PHP], input=codigo, capture_output=True, text=True, cwd=ROOT, check=True)
    return json.loads(result.stdout)


def main():
    antes = estado_mysql()
    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=CHROME, headless=True)
        try:
            context = browser.new_context(viewport={'width': 1366, 'height': 900})
            context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(BASE)
                and (route.request.method == 'GET' or route.request.url == BASE + 'solicitud_api.php') else route.abort())
            page = context.new_page()
            errors = []
            page.on('pageerror', lambda error: errors.append(str(error)))
            assert page.goto(BASE + 'catalogo.php').status == 200
            boton = page.locator('[data-agregar-solicitud]').first
            expect(boton).to_be_enabled()
            id_producto = int(boton.get_attribute('data-agregar-solicitud'))

            def api(productos, status=200):
                response = context.request.post(BASE + 'solicitud_api.php', data={'productos': productos})
                assert response.status == status, response.text()
                return response.json()

            linea = {'id_producto': id_producto, 'cantidad': 1}
            producto_real = api([linea])['productos'][0]
            assert api([linea, linea])['productos'][0]['cantidad'] == 2
            assert context.request.get(BASE + 'solicitud_api.php').status == 405
            assert context.request.post(BASE + 'solicitud_api.php', form={'productos': '1'}).status == 415
            for raw in ('{', 'null', ' ' * 16001):
                assert context.request.post(BASE + 'solicitud_api.php', data=raw, headers={'Content-Type': 'application/json'}).status == 422
            for cantidad in (0, -1, 1.5, 1000, True, '1'):
                api([{'id_producto': id_producto, 'cantidad': cantidad}], 422)
            api([linea] * 101, 422)
            api([{**linea, 'nombre': '<script>alterado</script>'}], 422)
            assert api([{'id_producto': 2147483647, 'cantidad': 1}])['productos'] == []
            if antes['oculto']:
                result = api([{'id_producto': int(antes['oculto']), 'cantidad': 1}])
                assert result['productos'] == [] and result['retirados'] == [int(antes['oculto'])]
                boton.evaluate('(b, id) => b.dataset.agregarSolicitud = id', int(antes['oculto']))
                boton.click()
                expect(page.locator('#solicitud-notificacion')).to_contain_text('no se agregó')
                expect(page.locator('[data-solicitud-contador]')).to_have_text('0 productos')
                boton.evaluate('(b, id) => b.dataset.agregarSolicitud = id', id_producto)

            boton.click()
            expect(page.locator('[data-solicitud-contador]')).to_have_text('1 producto')
            expect(boton).to_be_enabled()
            boton.click()
            expect(boton).to_be_enabled()
            page.locator('.boton-lista').click()
            expect(page.locator('[data-cantidad-solicitud]')).to_have_value('2')
            expect(page.locator('.solicitud__producto')).to_have_count(1)
            expect(page.locator('.solicitud__codigo')).to_have_text('Código: ' + producto_real['codigo'])
            expect(page.locator('.solicitud__informacion h2')).to_have_text(producto_real['nombre'])
            expect(page.locator('#solicitud-continuar')).to_be_enabled()
            cantidad = page.locator('[data-cantidad-solicitud]')
            cantidad.fill('3'); cantidad.press('Tab')
            expect(page.locator('#solicitud-notificacion')).to_contain_text('Cantidad actualizada')
            for invalida in ('0', '-1', '1.5', '1000', '1e2', ''):
                cantidad.fill(invalida); cantidad.dispatch_event('change')
                expect(cantidad).to_have_value('3')
            page.reload()
            expect(cantidad).to_have_value('3')
            page.goto(BASE + f'producto.php?id={id_producto}')
            expect(page.locator('.detalle__whatsapp')).to_have_attribute('href', re.compile(r'https://wa.me/'))
            expect(page.locator('[data-agregar-solicitud]')).to_be_enabled()
            page.locator('[data-agregar-solicitud]').click()
            expect(page.locator('[data-agregar-solicitud]')).to_be_enabled()
            page.locator('.boton-lista').click()
            expect(cantidad).to_have_value('4')
            page.once('dialog', lambda dialog: dialog.dismiss())
            page.locator('[data-quitar-solicitud]').click()
            expect(page.locator('.solicitud__producto')).to_have_count(1)
            page.once('dialog', lambda dialog: dialog.accept())
            page.locator('[data-quitar-solicitud]').click()
            expect(page.locator('#solicitud-vacia')).to_be_visible()
            print('OK XAMPP: agregar, duplicados, cantidades, recarga, detalle/WhatsApp y eliminación con confirmación')

            # Dos pestañas comparten la selección y serializan las incorporaciones.
            page.goto(BASE + f'producto.php?id={id_producto}')
            otra = context.new_page()
            otra.goto(BASE + f'producto.php?id={id_producto}')
            expect(page.locator('[data-agregar-solicitud]')).to_be_enabled()
            expect(otra.locator('[data-agregar-solicitud]')).to_be_enabled()
            page.locator('[data-agregar-solicitud]').click()
            otra.locator('[data-agregar-solicitud]').click()
            expect(otra.locator('[data-agregar-solicitud]')).to_be_enabled()
            page.goto(BASE + 'mi_solicitud.php')
            expect(cantidad).to_have_value('2')
            otra.close()
            guardada = page.evaluate('(key) => localStorage.getItem(key)', KEY)
            page.route('**/solicitud_api.php', lambda route: route.abort('failed'))
            page.reload()
            expect(page.locator('#solicitud-error')).to_be_visible()
            assert page.evaluate('(key) => localStorage.getItem(key)', KEY) == guardada
            page.unroute('**/solicitud_api.php')
            page.locator('#solicitud-reintentar').click()
            expect(cantidad).to_have_value('2')
            page.once('dialog', lambda dialog: dialog.dismiss())
            page.locator('#solicitud-vaciar').click()
            expect(cantidad).to_have_value('2')
            page.once('dialog', lambda dialog: dialog.accept())
            page.locator('#solicitud-vaciar').click()
            expect(page.locator('#solicitud-vacia')).to_be_visible()
            page.evaluate('(key) => localStorage.setItem(key, "corrupta")', KEY)
            page.reload()
            expect(page.locator('#solicitud-error')).to_be_visible()
            page.once('dialog', lambda dialog: dialog.accept())
            page.locator('#solicitud-vaciar').click()
            expect(page.locator('#solicitud-vacia')).to_be_visible()
            print('OK pestañas, fallos de red sin pérdida de lista, vaciado y recuperación de almacenamiento corrupto')

            # Respuestas ficticias solo en memoria del navegador. La base real tiene
            # un único producto público; no se cambia su visibilidad para poblar pruebas.
            ficticios = [2000000001, 2000000002]
            def respuesta_ficticia(route):
                entrada = route.request.post_data_json['productos']
                productos = [{'id_producto': p['id_producto'], 'cantidad': p['cantidad'],
                    'nombre': '<script>Texto ficticio</script> Producto de prueba con nombre largo',
                    'codigo': f"FICT-{p['id_producto']}", 'imagen': 'assets/images/producto_sin_imagen.svg'} for p in entrada]
                route.fulfill(json={'productos': productos, 'retirados': []})
            page.route('**/solicitud_api.php', respuesta_ficticia)
            page.evaluate('([key, ids]) => localStorage.setItem(key, JSON.stringify({version:1, productos:ids.map(id_producto=>({id_producto,cantidad:1}))}))', [KEY, ficticios])
            page.reload()
            expect(page.locator('.solicitud__producto')).to_have_count(2)
            expect(page.locator('.solicitud__informacion script')).to_have_count(0)
            for width in (320, 375, 768, 1366):
                page.set_viewport_size({'width': width, 'height': 900})
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), f'Desbordamiento a {width}px'
                if width <= 600:
                    expect(page.locator('[data-solicitud-insignia]')).to_be_visible()
                    expect(page.locator('[data-solicitud-insignia]')).to_have_text('2')
            page.unroute('**/solicitud_api.php')
            page.reload()  # El servidor real descarta esos IDs inexistentes.
            expect(page.locator('#solicitud-vacia')).to_be_visible()
            expect(page.locator('#solicitud-notificacion')).to_contain_text('Se retiraron 2 productos')
            assert not errors, errors
            print('OK varios productos ficticios, escape de texto, responsive y retirada de identificadores no públicos al revalidar')
        finally:
            browser.close()
    assert estado_mysql() == antes, 'Cambió el estado de MySQL durante la prueba; revisar actividad concurrente'
    print('OK productos, categorías, solicitudes e historial idénticos antes/después. Sin escrituras en MySQL.')


if __name__ == '__main__':
    main()

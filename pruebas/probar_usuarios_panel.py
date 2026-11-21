"""XAMPP existente: lectura de MySQL, bloqueo a visitantes y vistas con datos ficticios.
Todos los POST de la interfaz se interceptan; no crea ni cambia cuentas reales.
"""
import json
import subprocess
from urllib.parse import parse_qs, urlparse
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
PHP = r'C:\xampp2\php\php.exe'
PYTEST = ROOT / 'pruebas/probar_usuarios_sin_mysql.php'
BASE = 'http://localhost/pagina_web_ferreteria/public/'
CHROME = r'C:\Program Files\Google\Chrome\Application\chrome.exe'


def php(codigo):
    return subprocess.run([PHP], input=codigo, cwd=ROOT, capture_output=True, text=True, encoding='utf-8', check=True).stdout


def fixture(*args):
    return subprocess.run([PHP, str(PYTEST), *args], cwd=ROOT, capture_output=True, text=True, encoding='utf-8', check=True).stdout


def estado_mysql():
    return json.loads(php('''<?php
require getcwd().'/config/conexion.php'; $db=obtener_conexion(); $estado=[];
$db->exec('SET SESSION TRANSACTION READ ONLY');
foreach(['usuarios'=>'id_usuario','productos'=>'id_producto','categorias'=>'id_categoria','solicitudes'=>'id_solicitud','detalle_solicitud'=>'id_detalle'] as $tabla=>$id) {
    $hash=hash_init('sha256');
    foreach($db->query('SELECT * FROM '.$tabla.' ORDER BY '.$id) as $fila) hash_update($hash,json_encode($fila));
    $estado[$tabla]=hash_final($hash);
}
echo json_encode($estado);
'''))


def main():
    antes = estado_mysql()
    print(php('''<?php
require getcwd().'/config/conexion.php'; require getcwd().'/app/Servicios/usuarios.php';
$db=obtener_conexion(); $db->exec('SET SESSION TRANSACTION READ ONLY');
$cuentas=listar_usuarios($db);
if(count($cuentas)!==(int)$db->query('SELECT COUNT(*) FROM usuarios')->fetchColumn()) throw new RuntimeException('Listado incompleto');
foreach($cuentas as $cuenta) {
    if(isset($cuenta['clave_hash'])) throw new RuntimeException('El listado incluye hashes');
    if(buscar_usuario_panel($db,(int)$cuenta['id_usuario'])!==$cuenta) throw new RuntimeException('Edicion no coincide con lectura');
}
// PREPARE comprueba la sintaxis y las columnas. No ejecuta ninguna escritura.
// MariaDB también impide PREPARE de DML en una sesión READ ONLY.
$db->exec('SET SESSION TRANSACTION READ WRITE');
$db->prepare('INSERT INTO usuarios (nombre, correo, rol, clave_hash, activo) VALUES (:nombre, :correo, :rol, :clave_hash, 1)');
$db->prepare('UPDATE usuarios SET nombre = :nombre, correo = :correo, rol = :rol WHERE id_usuario = :id');
$db->prepare('UPDATE usuarios SET activo = :activo WHERE id_usuario = :id');
$db->prepare('UPDATE usuarios SET clave_hash = :clave_hash WHERE id_usuario = :id');
$db->exec('SET SESSION TRANSACTION READ ONLY');
echo 'OK MySQL: lectura de cuentas sin hashes y compatibilidad de consultas; ninguna escritura.';
'''))
    assert fixture('acceso', 'administrador') == 'ACCESO AUTORIZADO'
    assert 'ACCESO AUTORIZADO' not in fixture('acceso', 'empleado')
    vistas = {tipo: fixture('vista', tipo) for tipo in ('listado', 'crear', 'editar', 'clave', 'confirmar', 'error', 'resultado', 'empleado')}
    for html in vistas.values():
        assert '$2y$' not in html and 'clave_hash' not in html and 'PruebaLocal123!' not in html

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=CHROME, headless=True)
        try:
            anonimo = browser.new_context()
            for url in ('usuarios.php', 'usuarios.php?nuevo=1', 'usuarios.php?editar=1', 'usuarios.php?clave=1'):
                response = anonimo.request.get(BASE + url, max_redirects=0)
                assert response.status == 303 and response.headers['location'] == 'iniciar_sesion.php'
            response = anonimo.request.post(BASE + 'usuarios.php', form={'accion': 'confirmar'}, max_redirects=0)
            assert response.status == 303 and response.headers['location'] == 'iniciar_sesion.php'
            anonimo.close()

            contexto = browser.new_context(viewport={'width': 1366, 'height': 900})
            estado = {'vista': 'listado', 'cambios': 0}

            def ruta_segura(route):
                request = route.request
                if request.method == 'GET' and request.url.startswith(BASE + 'assets/'):
                    route.continue_(); return
                if not request.url.startswith(BASE + 'usuarios.php'):
                    route.abort(); return
                if request.method == 'GET':
                    query = parse_qs(urlparse(request.url).query)
                    estado['vista'] = 'crear' if 'nuevo' in query else 'editar' if 'editar' in query else 'clave' if 'clave' in query else 'listado'
                    if 'empleado' in query: estado['vista'] = 'empleado'
                elif request.method == 'POST':
                    entrada = parse_qs(request.post_data or '')
                    assert entrada.get('token_csrf'), 'Falta token CSRF'
                    accion = entrada.get('accion', [''])[0]
                    if accion == 'revisar':
                        estado['vista'] = 'confirmar'
                    elif accion == 'cancelar':
                        assert entrada.get('confirmacion')
                        estado['vista'] = 'listado'
                    elif accion == 'confirmar':
                        assert entrada.get('confirmacion')
                        if entrada.get('clave_actual') == ['PruebaLocal123!']:
                            estado['vista'] = 'resultado'; estado['cambios'] += 1
                        else: estado['vista'] = 'error'
                    else: raise AssertionError('POST imprevisto bloqueado')
                else:
                    route.abort(); return
                route.fulfill(content_type='text/html; charset=utf-8', body=vistas[estado['vista']])

            contexto.route('**/*', ruta_segura)
            page = contexto.new_page()
            errores = []
            page.on('pageerror', lambda error: errores.append(str(error)))
            page.goto(BASE + 'usuarios.php')
            expect(page.locator('.panel-navegacion [aria-current]')).to_have_text('Usuarios')
            expect(page.locator('tbody tr')).to_have_count(4)
            expect(page.locator('tbody tr').first.get_by_role('button', name='Desactivar')).to_have_count(0)
            expect(page.locator('tbody')).to_contain_text('<script>window.inyectado=1</script>')
            assert page.evaluate('window.inyectado === undefined')
            page.get_by_role('link', name='Crear cuenta', exact=True).click()
            expect(page.locator('#usuario-rol')).to_have_value('empleado')
            for field in ('#usuario-nombre', '#usuario-correo', '#usuario-clave', '#usuario-clave-repetida'):
                expect(page.locator(field)).to_have_attribute('required', '')
            expect(page.locator('#usuario-clave')).to_have_attribute('type', 'password')
            page.goto(BASE + 'usuarios.php?editar=2')
            expect(page.locator('#usuario-nombre')).to_contain_text('')
            expect(page.locator('#usuario-nombre')).to_have_value('<script>window.inyectado=1</script> Persona')
            expect(page.locator('#usuario-clave')).to_have_count(0)
            page.goto(BASE + 'usuarios.php?clave=2')
            expect(page.locator('#usuario-clave')).to_have_value('')
            expect(page.locator('#usuario-clave-repetida')).to_have_value('')
            page.goto(BASE + 'usuarios.php')
            page.locator('tbody tr').nth(1).get_by_role('button', name='Desactivar', exact=True).click()
            expect(page.get_by_role('heading', name='Confirmar: Desactivar cuenta')).to_be_visible()
            assert estado['cambios'] == 0
            expect(page.locator('#clave-actual')).to_have_value('')
            page.get_by_role('button', name='Cancelar operación').click()
            assert estado['cambios'] == 0
            page.locator('tbody tr').nth(1).get_by_role('button', name='Desactivar', exact=True).click()
            page.locator('#clave-actual').fill('incorrecta')
            page.get_by_role('button', name='Confirmar y guardar').click()
            expect(page.get_by_role('alert')).to_contain_text('contraseña del administrador no es correcta')
            expect(page.locator('#clave-actual')).to_have_value('')
            assert estado['cambios'] == 0
            page.locator('#clave-actual').fill('PruebaLocal123!')
            page.get_by_role('button', name='Confirmar y guardar').click()
            expect(page.get_by_role('status')).to_have_text('Cuenta desactivada correctamente.')
            assert estado['cambios'] == 1

            for tipo, ruta in [('listado','usuarios.php'), ('formulario','usuarios.php?nuevo=1')]:
                page.goto(BASE + ruta)
                for ancho in (320,375,768,1366):
                    page.set_viewport_size({'width': ancho, 'height': 900})
                    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), (tipo,ancho)
                page.screenshot(path=str(ROOT / 'tmp' / ('usuarios-' + tipo + '.png')), full_page=True)
            page.set_viewport_size({'width':375,'height':900})
            page.screenshot(path=str(ROOT / 'tmp' / 'usuarios-movil.png'), full_page=True)
            page.goto(BASE + 'usuarios.php?empleado=1')
            expect(page.locator('.panel-navegacion a[href="usuarios.php"]')).to_have_count(0)
            for ruta in ('productos.php', 'categorias.php', 'solicitudes.php'):
                expect(page.locator('.panel-navegacion a[href="' + ruta + '"]')).to_be_visible()
            assert not errores, errores
        finally:
            browser.close()
            assert estado_mysql() == antes, 'Cambió MySQL durante la prueba; revisar actividad concurrente'
    print('OK: acceso anónimo bloqueado en XAMPP y permiso exclusivo de administrador comprobado en memoria.')
    print('OK: formularios, confirmación, cancelación, contraseñas sin exponer, escape XSS y móvil de 320 a 1366 px.')
    print('OK: usuarios, productos, categorías, solicitudes y detalles idénticos antes y después. Sin cuentas ficticias en MySQL.')


if __name__ == '__main__':
    main()

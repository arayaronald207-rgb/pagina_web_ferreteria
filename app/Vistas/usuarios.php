<div class="panel-titulo">
    <p class="panel-etiqueta">ADMINISTRACIÓN</p><h1>Usuarios y permisos</h1>
    <p>Administra las cuentas personales del equipo. Administradores y empleados pueden gestionar productos, categorías y solicitudes; solo los administradores gestionan usuarios.</p>
</div>
<?php if (is_string($mensaje) && $mensaje !== ''): ?><p class="alert alert-success panel-aviso" role="status"><?= escapar_panel($mensaje) ?></p><?php endif; ?>
<?php if ($error_formulario !== ''): ?><p class="alert alert-danger panel-aviso panel-aviso--error" role="alert"><?= escapar_panel($error_formulario) ?></p><?php endif; ?>
<?php if ($confirmar !== null): ?>
<section class="card panel-tarjeta panel-confirmacion" aria-labelledby="confirmar-usuario-titulo">
    <?php $etiqueta = match ($confirmar['operacion']) { 'crear' => 'Crear cuenta', 'editar' => 'Editar datos y rol', 'clave' => 'Cambiar contraseña', default => $confirmar['datos']['activo'] ? 'Activar cuenta' : 'Desactivar cuenta' }; ?>
    <h2 id="confirmar-usuario-titulo">Confirmar: <?= escapar_panel($etiqueta) ?></h2>
    <dl class="panel-solicitud-datos">
        <dt>Cuenta</dt><dd><?= escapar_panel($confirmar['nombre_anterior'] ?: $confirmar['datos']['nombre']) ?> · <?= escapar_panel($confirmar['correo_anterior'] ?: $confirmar['datos']['correo']) ?></dd>
        <?php if (in_array($confirmar['operacion'], ['crear', 'editar'], true)): ?>
            <dt>Nombre y correo que se guardarán</dt><dd><?= escapar_panel($confirmar['datos']['nombre']) ?> · <?= escapar_panel($confirmar['datos']['correo']) ?></dd>
            <dt>Rol</dt><dd><?= $confirmar['datos']['rol'] === 'administrador' ? 'Administrador: también podrá gestionar cuentas y permisos.' : 'Empleado: podrá gestionar productos, categorías y solicitudes.' ?></dd>
        <?php endif; ?>
    </dl>
    <?php if ($confirmar['operacion'] === 'crear'): ?><p>La cuenta se creará activa, con la contraseña que acabas de establecer.</p><?php endif; ?>
    <?php if ($confirmar['operacion'] === 'clave'): ?><p>Se reemplazará la contraseña de esta cuenta. Las otras sesiones vinculadas a la contraseña anterior deberán volver a iniciar sesión al acceder al panel.</p><?php endif; ?>
    <?php if ($confirmar['operacion'] === 'estado' && !$confirmar['datos']['activo']): ?><p>La cuenta perderá acceso al sistema. Se conservarán sus datos y relaciones.</p><?php endif; ?>
    <?php if ($confirmar['id'] === $actor && ($confirmar['datos']['rol'] ?? null) === 'empleado'): ?><p class="alert alert-warning">Tu cuenta pasará a ser Empleado y dejarás de tener acceso a Usuarios. Debe quedar otro administrador activo.</p><?php endif; ?>
    <form class="panel-formulario" action="usuarios.php" method="post" id="confirmar-usuario">
        <input type="hidden" name="token_csrf" value="<?= escapar_panel(obtener_token_csrf()) ?>">
        <input type="hidden" name="accion" value="confirmar">
        <input type="hidden" name="confirmacion" value="<?= escapar_panel($token_confirmacion) ?>">
        <label for="clave-actual">Tu contraseña de administrador</label>
        <input class="form-control" id="clave-actual" name="clave_actual" type="password" autocomplete="current-password" required maxlength="4096">
        <p class="panel-ayuda mt-2">Confirma con la contraseña de tu propia cuenta. La revisión vence en diez minutos.</p>
        <button class="btn btn-primary panel-boton mt-2" type="submit">Confirmar y guardar</button>
    </form>
    <form class="mt-3" action="usuarios.php" method="post">
        <input type="hidden" name="token_csrf" value="<?= escapar_panel(obtener_token_csrf()) ?>">
        <input type="hidden" name="accion" value="cancelar">
        <input type="hidden" name="confirmacion" value="<?= escapar_panel($token_confirmacion) ?>">
        <button class="btn btn-outline-primary panel-boton" type="submit">Cancelar operación</button>
    </form>
</section>
<?php elseif ($formulario !== null): ?>
<section class="card panel-tarjeta panel-formulario" aria-labelledby="usuario-formulario-titulo">
    <h2 id="usuario-formulario-titulo"><?= $modo === 'crear' ? 'Nueva cuenta' : ($modo === 'clave' ? 'Cambiar contraseña' : 'Editar cuenta') ?></h2>
    <?php if ($modo === 'clave'): ?><p>Cuenta: <?= escapar_panel($formulario['nombre']) ?> · <?= escapar_panel($formulario['correo']) ?></p><?php endif; ?>
    <form action="usuarios.php" method="post" id="formulario-usuario">
        <input type="hidden" name="token_csrf" value="<?= escapar_panel(obtener_token_csrf()) ?>">
        <input type="hidden" name="accion" value="revisar">
        <input type="hidden" name="operacion" value="<?= escapar_panel($modo) ?>">
        <?php if ($formulario['id_usuario'] !== null): ?><input type="hidden" name="id_usuario" value="<?= (int) $formulario['id_usuario'] ?>"><?php endif; ?>
        <div class="row g-3">
        <?php if ($modo !== 'clave'): ?>
            <div class="col-12 col-lg-6"><label for="usuario-nombre">Nombre completo</label><input class="form-control" id="usuario-nombre" name="nombre" required minlength="2" maxlength="100" value="<?= escapar_panel($formulario['nombre']) ?>" autocomplete="off"></div>
            <div class="col-12 col-lg-6"><label for="usuario-correo">Correo electrónico</label><input class="form-control" id="usuario-correo" name="correo" type="email" required maxlength="150" value="<?= escapar_panel($formulario['correo']) ?>" autocomplete="off"></div>
            <div class="col-12"><label for="usuario-rol">Rol</label><select class="form-select" id="usuario-rol" name="rol" required>
                <option value="empleado"<?= $formulario['rol'] === 'empleado' ? ' selected' : '' ?>>Empleado</option>
                <option value="administrador"<?= $formulario['rol'] === 'administrador' ? ' selected' : '' ?>>Administrador</option>
            </select></div>
        <?php endif; ?>
        <?php if ($modo === 'crear' || $modo === 'clave'): ?>
            <div class="col-12"><p class="panel-ayuda mb-0" id="usuario-clave-ayuda">Utiliza al menos 12 caracteres, con mayúsculas, minúsculas y números. Máximo 72 bytes; las letras con tilde ocupan más de uno. Las contraseñas no se muestran ni se recuperan.</p></div>
            <div class="col-12 col-lg-6"><label for="usuario-clave">Nueva contraseña</label><input class="form-control" id="usuario-clave" name="clave_nueva" type="password" required minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="usuario-clave-ayuda"></div>
            <div class="col-12 col-lg-6"><label for="usuario-clave-repetida">Repetir nueva contraseña</label><input class="form-control" id="usuario-clave-repetida" name="clave_repetida" type="password" required minlength="12" maxlength="72" autocomplete="new-password"></div>
        <?php endif; ?>
        <div class="col-12 panel-acciones"><button class="btn btn-primary panel-boton" type="submit">Revisar cambios</button><a class="btn btn-outline-primary panel-boton" href="usuarios.php">Volver al listado</a></div>
        </div>
    </form>
</section>
<?php endif; ?>
<?php if ($cuentas !== null): ?>
<section class="card panel-tarjeta" aria-labelledby="usuarios-listado-titulo">
    <div class="panel-acciones mb-3"><h2 id="usuarios-listado-titulo" class="mb-0">Cuentas del personal</h2><a class="btn btn-primary panel-boton" href="usuarios.php?nuevo=1">Crear cuenta</a></div>
    <p>Las cuentas se activan o desactivan; no se eliminan. Tu cuenta no puede desactivarse y siempre debe quedar un administrador activo.</p>
    <div class="table-responsive" role="region" aria-labelledby="usuarios-listado-titulo" tabindex="0">
        <table class="table table-hover align-middle panel-tabla panel-usuarios-tabla">
            <caption class="visually-hidden">Nombre, correo, rol, estado y acciones de las cuentas del personal</caption>
            <thead><tr><th scope="col">Nombre</th><th scope="col">Correo</th><th scope="col">Rol</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($cuentas as $cuenta): ?>
                <tr>
                    <th scope="row"><?= escapar_panel($cuenta['nombre']) ?><?php if ((int) $cuenta['id_usuario'] === $actor): ?><small class="d-block panel-ayuda">Tu cuenta</small><?php endif; ?></th>
                    <td><?= escapar_panel($cuenta['correo']) ?></td><td><?= $cuenta['rol'] === 'administrador' ? 'Administrador' : 'Empleado' ?></td>
                    <td><span class="badge <?= (int) $cuenta['activo'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= (int) $cuenta['activo'] === 1 ? 'Activa' : 'Inactiva' ?></span></td>
                    <td><div class="panel-acciones">
                        <a class="btn btn-outline-primary panel-boton" href="usuarios.php?editar=<?= (int) $cuenta['id_usuario'] ?>">Editar</a>
                        <a class="btn btn-outline-primary panel-boton" href="usuarios.php?clave=<?= (int) $cuenta['id_usuario'] ?>">Cambiar contraseña</a>
                        <?php if ((int) $cuenta['id_usuario'] !== $actor): ?>
                        <form action="usuarios.php" method="post">
                            <input type="hidden" name="token_csrf" value="<?= escapar_panel(obtener_token_csrf()) ?>">
                            <input type="hidden" name="accion" value="revisar"><input type="hidden" name="operacion" value="estado">
                            <input type="hidden" name="id_usuario" value="<?= (int) $cuenta['id_usuario'] ?>"><input type="hidden" name="activo" value="<?= (int) $cuenta['activo'] === 1 ? '0' : '1' ?>">
                            <button class="btn btn-outline-primary panel-boton" type="submit"><?= (int) $cuenta['activo'] === 1 ? 'Desactivar' : 'Activar' ?></button>
                        </form>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

# Primer administrador local

1. Inicia MySQL y Apache desde XAMPP.
2. Abre PowerShell y ejecuta:

   ```powershell
   & 'C:\xampp\php\php.exe' 'C:\xampp\htdocs\pagina_web_ferreteria\pruebas\crear_administrador_inicial.php'
   ```

3. Introduce nombre, correo y contraseña. La contraseña se solicita dos veces y
   no se muestra al escribir ni queda en el historial. Usa al menos 12 caracteres
   y como máximo 72 bytes UTF-8 (por ejemplo, entre 16 y 32 caracteres ASCII).
   Pulsa Esc durante la entrada de contraseña para cancelar.
4. Escribe `SI` únicamente si autorizas crear el administrador. Cualquier otra
   respuesta cancela sin acceder a MySQL. Si ya existe un administrador, el programa
   no crea otro. Solo se guarda el hash generado con `password_hash()`, nunca la
   contraseña en texto plano.
5. Cuando aparezca «Cuenta administradora creada», abre
   `http://localhost/pagina_web_ferreteria/public/iniciar_sesion.php` e ingresa
   con el correo y la contraseña elegidos.

Ejecuta el comando en una terminal interactiva de Windows, sin redirigir la entrada
o la salida. El script utiliza Windows PowerShell para leer la contraseña oculta.

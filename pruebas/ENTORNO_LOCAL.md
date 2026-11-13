# Vista previa local con XAMPP

1. Inicia Apache y MySQL desde el panel de XAMPP.
2. En la pestaña del navegador integrado de VS Code, sustituye la dirección
   anterior por:

   http://localhost/pagina_web_ferreteria/public/iniciar_sesion.php

3. Guarda esa dirección como favorita en el navegador integrado y cierra cualquier
   pestaña que siga apuntando a `127.0.0.1:8010`.

También puedes abrir la vista previa de este Markdown (`Ctrl+Shift+V`) y pulsar
[Abrir el inicio de sesión en XAMPP](http://localhost/pagina_web_ferreteria/public/iniciar_sesion.php).
El ajuste del proyecto `workbench.browser.openLocalhostLinks` habilita la apertura
de enlaces locales en el navegador integrado de VS Code. No cambia la dirección de
las pestañas ya abiertas ni inicia un servidor.

XAMPP atiende esta instalación por el puerto 80, implícito en la dirección.
El puerto 8010 pertenecía a una dirección guardada en una pestaña de VS Code;
no hay un servidor escuchando allí. Si reaparece `ERR_CONNECTION_REFUSED`, revisa
primero la dirección de la pestaña y que Apache esté iniciado. No hace falta
ejecutar `php -S` ni instalar otra extensión de servidor.

# El Cruce Digital

## Descripción del proyecto

El Cruce Digital es un sistema web desarrollado para **Ferretería El Cruce**, ubicada en Mollejones de Platanares, Pérez Zeledón, Costa Rica.

El objetivo del proyecto es ofrecer un catálogo digital donde los clientes puedan consultar los productos de la ferretería y, posteriormente, solicitar cotizaciones.

El sistema también contempla un panel administrativo para gestionar la información del catálogo.

Este proyecto se desarrolla como parte del curso de **Programación Avanzada** y está basado en una necesidad real del negocio.

## Tecnologías utilizadas

- HTML5
- CSS3
- JavaScript
- PHP
- MySQL
- Apache (XAMPP)
- Git y GitHub

## Funcionalidades desarrolladas hasta el momento

- Página principal con información e imágenes del negocio.
- Diseño adaptable a computadoras y dispositivos móviles.
- Navegación mediante menú responsive.
- Catálogo conectado a la base de datos.
- Búsqueda de productos y filtros por categoría.
- Inicio y cierre de sesión administrativo.
- Panel administrativo con información general.
- Gestión de categorías.
- Validaciones de formularios y protección de sesiones.
- Integración de WhatsApp y Google Maps.

**Nota:** El catálogo todavía no contiene productos reales, ya que está pendiente la importación de los registros del sistema interno de la ferretería.

## Funcionalidades pendientes

- Importación y clasificación de productos desde Excel.
- Administración completa de productos e imágenes.
- Lista de productos para solicitar cotizaciones.
- Registro y gestión de solicitudes de cotización.
- Administración completa de usuarios.
- Pruebas finales y preparación para su publicación.

## Requisitos para ejecutar el proyecto

Para ejecutar el proyecto localmente se necesita:

- XAMPP con Apache, PHP y MySQL.
- Un navegador web.
- El código fuente descargado desde GitHub.

## Instrucciones de instalación

### 1. Descargar el proyecto

Descargar el repositorio desde GitHub utilizando la opción **Code → Download ZIP**, o clonarlo mediante Git.

Extraer la carpeta y colocarla dentro de:

`C:\xampp\htdocs\pagina_web_ferreteria`

Si se utiliza otra instalación de XAMPP, se debe colocar en la carpeta `htdocs` correspondiente.

### 2. Iniciar XAMPP

Abrir el panel de control de XAMPP e iniciar los servicios:

- Apache
- MySQL

### 3. Crear la base de datos

Abrir phpMyAdmin:

`http://localhost/phpmyadmin`

Crear una base de datos con el nombre:

`ferreteria_el_cruce`

Seleccionar la base de datos y utilizar la opción **Importar** para ejecutar el archivo:

`base_datos/migraciones/001_crear_tablas.sql`

Este archivo contiene la estructura inicial de las tablas.

### 4. Configurar la conexión

En la carpeta principal del proyecto, copiar el archivo:

`.env.example`

Y renombrar la copia como:

`.env`

Configurar los datos de conexión de acuerdo con la instalación local de MySQL.

Ejemplo para XAMPP:

```env
APP_NAME="FERRETERÍA EL CRUCE"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost/pagina_web_ferreteria/public

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ferreteria_el_cruce
DB_USERNAME=root
DB_PASSWORD=
```

### 5. Abrir el proyecto

Ingresar desde el navegador a:

`http://localhost/pagina_web_ferreteria/public/`

Para acceder a la página de inicio de sesión administrativo:

`http://localhost/pagina_web_ferreteria/public/iniciar_sesion.php`

### 6. Acceso administrativo

Por seguridad, el repositorio público no incluye credenciales de acceso ni una base de datos con contraseñas de usuarios reales.

Para probar el panel administrativo, se debe crear una cuenta inicial en la base de datos local siguiendo las indicaciones del archivo:

`pruebas/PRIMER_ADMINISTRADOR.md`

## Estructura general

```text
pagina_web_ferreteria/
├── app/
│   ├── Servicios/
│   └── Vistas/
├── base_datos/
│   └── migraciones/
├── config/
├── pruebas/
├── public/
│   ├── assets/
│   │   ├── css/
│   │   ├── imagenes/
│   │   └── js/
│   ├── index.php
│   ├── catalogo.php
│   ├── categorias.php
│   ├── administracion.php
│   └── iniciar_sesion.php
├── .env.example
└── .htaccess
```

## Estado del proyecto

**En desarrollo.**

Actualmente se encuentra implementada la base del sistema, incluyendo las páginas públicas, la conexión con MySQL, el inicio de sesión, el panel administrativo y la gestión de categorías.

Las demás funcionalidades se incorporarán durante las siguientes etapas.

## Autor

Ronald Araya

Proyecto académico — Programación Avanzada.
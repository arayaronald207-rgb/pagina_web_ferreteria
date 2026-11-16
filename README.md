# El Cruce Digital

## Descripción del proyecto

El Cruce Digital es un proyecto web desarrollado para **Ferretería El Cruce**, ubicada en Mollejones de Platanares, Pérez Zeledón, Costa Rica.

Su objetivo es crear un catálogo digital donde los clientes puedan consultar los productos de la ferretería y solicitar cotizaciones sin necesidad de visitar el establecimiento.

El sistema también contará con un panel administrativo para gestionar los productos, las categorías y las solicitudes de los clientes.

Este proyecto forma parte del curso de **Programación Avanzada** y busca ofrecer una solución a una necesidad real del negocio.

## Tecnologías utilizadas

- **HTML5:** estructura de las páginas.
- **CSS3:** diseño y estilos personalizados.
- **Bootstrap 5.3.9:** componentes visuales y diseño responsive.
- **JavaScript:** navegación e interactividad.
- **PHP:** lógica del sistema y procesamiento de información.
- **MySQL:** almacenamiento de datos.
- **XAMPP:** servidor local con Apache y MySQL.
- **Git y GitHub:** control de versiones.

## Funcionalidades desarrolladas

Hasta el momento, el proyecto cuenta con:

- Página principal con información e imágenes reales de la ferretería.
- Diseño adaptable a computadoras, tabletas y teléfonos.
- Encabezado fijo al desplazarse, con logotipo y buscador.
- Menú desplegable de categorías conectado a MySQL.
- Catálogo de productos con búsqueda y filtros por categoría.
- Página individual de productos publicados, con fotografía, código, descripción
  completa y consulta por WhatsApp. Búsqueda parcial por nombre, código o descripción.
- Inicio y cierre de sesión administrativo.
- Panel administrativo con estadísticas generales.
- Gestión de categorías.
- Administración de productos: registro, edición, eliminación con confirmación,
  publicación, fotografías, búsqueda por nombre, código o descripción, filtros y paginación.
- Validaciones de formularios y medidas de seguridad.
- Integración con WhatsApp y Google Maps.

Se utiliza **Bootstrap 5** junto con CSS personalizado para organizar las tarjetas, formularios, tablas y columnas del sistema, manteniendo los colores e identidad de la ferretería.

**Nota:** La carga completa de productos del sistema interno de la ferretería sigue pendiente.

## Funcionalidades pendientes

- Importar y clasificar los productos desde Excel.
- Autorizar y aplicar la migración 003 para habilitar especificaciones técnicas
  y fotografías adicionales; el código está preparado y probado con datos temporales.
- Implementar la lista de productos para cotización.
- Registrar y administrar solicitudes de cotización.
- Completar la administración de usuarios.
- Realizar las pruebas finales y preparar la publicación del sitio web.

## Requisitos

Para ejecutar el proyecto localmente se necesita:

- XAMPP con PHP, Apache y MySQL.
- Un navegador web.
- El código fuente del proyecto.

## Instalación

### 1. Descargar el proyecto

Descargar el repositorio desde GitHub mediante **Code → Download ZIP** o clonarlo con Git.

Colocar la carpeta del proyecto en el directorio `htdocs` de XAMPP.

Por ejemplo:

`C:\xampp\htdocs\pagina_web_ferreteria`

### 2. Iniciar los servicios

Abrir XAMPP e iniciar:

- Apache
- MySQL

### 3. Crear la base de datos

Ingresar a:

`http://localhost/phpmyadmin`

Crear una base de datos llamada:

`ferreteria_el_cruce`

Seleccionarla e importar el archivo:

`base_datos/migraciones/001_crear_tablas.sql`

### 4. Configurar la conexión

En la carpeta principal del proyecto, crear una copia del archivo `.env.example` y renombrarla como `.env`.

Configurar los datos de conexión según la instalación local.

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

### 5. Abrir la página

Desde el navegador, ingresar a:

**Página principal:**

`http://localhost/pagina_web_ferreteria/public/`

**Inicio de sesión administrativo:**

`http://localhost/pagina_web_ferreteria/public/iniciar_sesion.php`

### 6. Acceso administrativo

El repositorio no incluye las credenciales de los usuarios reales.

Para crear un administrador inicial en una instalación local, consultar el archivo:

`pruebas/PRIMER_ADMINISTRADOR.md`

### 7. Activar la administración de productos

La migración `base_datos/migraciones/002_codigo_productos.sql` **ya está aplicada en
el MySQL local del proyecto**. No volver a ejecutarla. El módulo y el catálogo
utilizan la columna `productos.codigo` y su índice único.

Solo en una instalación nueva, ejecutar 001 y luego 002 una sola vez. En la base
existente no repetir ninguna. Acceder con una cuenta autorizada a
`public/productos.php`; no es necesario cambiar `.env` ni las credenciales.

`003_detalles_productos.sql` está preparada **sin ejecutar**: añade especificaciones
opcionales y la tabla de fotografías adicionales. El detalle público funciona ya
con la imagen principal y los datos actuales. Los controles de especificaciones y
galería se habilitan automáticamente cuando exista el esquema completo de 003.
Ver [Detalle de productos](pruebas/DETALLE_PRODUCTOS.md) para su alcance y pruebas.

Los requisitos de fotografías, las pruebas con datos temporales y los detalles de
activación se describen en [Administración de productos](pruebas/ADMINISTRACION_PRODUCTOS.md).

## Estructura del proyecto

```text
pagina_web_ferreteria/
├── app/                 # Lógica y vistas del sistema
├── almacenamiento/      # Fotografías privadas de productos
├── base_datos/          # Script de creación de tablas
├── config/              # Conexión con MySQL
├── pruebas/             # Pruebas y documentación
├── public/
│   ├── assets/
│   │   ├── css/         # Estilos personalizados
│   │   ├── imagenes/    # Imágenes del sitio
│   │   ├── js/          # JavaScript
│   │   └── vendor/
│   │       └── bootstrap/ # Bootstrap 5.3.9
│   ├── index.php        # Página principal
│   ├── catalogo.php     # Catálogo de productos
│   ├── producto.php     # Detalle público de un producto
│   ├── categorias.php   # Gestión de categorías
│   ├── productos.php    # Administración de productos
│   ├── imagen_producto.php # Entrega controlada de fotografías
│   ├── administracion.php
│   └── iniciar_sesion.php
├── .env.example
└── .htaccess
```

## Estado actual

**Proyecto en desarrollo.**

Actualmente se encuentra implementada la estructura principal del sistema, la conexión con MySQL, el catálogo y el detalle público, la autenticación administrativa, la gestión de categorías, la administración de productos y el diseño responsive. La migración 002 está aplicada. La ampliación de especificaciones y fotografías adicionales requiere autorizar la migración 003.

Las funcionalidades restantes se desarrollarán durante las siguientes etapas del proyecto.

## Autor

**Ronald Araya**

Proyecto académico — Programación Avanzada.

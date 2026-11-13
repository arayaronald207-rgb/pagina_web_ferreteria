# Documento de referencia y primera etapa administrativa

Revisión: 13 de noviembre de 2026.
Referencia: `El_Cruce_Digital_entrega_final_progra_avanzada.pdf`, aportado por el propietario.
El nombre académico es «El Cruce Digital»; el nombre comercial visible sigue siendo
«FERRETERÍA EL CRUCE», según la instrucción del propietario.

## Requisitos aplicados

| Referencia del PDF | Aplicación en esta etapa |
| --- | --- |
| Alcances, página 7; casos de uso, página 18 | Empleado y administrador gestionan categorías. El administrador hereda las funciones operativas. |
| RF006, página 12; RNF001, página 15 | Sesión y rol comprobados en el servidor. Se reutilizan las funciones de autenticación existentes. |
| RF007, página 13 | Listar, crear y editar categorías. El borrado requiere confirmación y ausencia de productos asociados, según la solicitud adicional del propietario. |
| RF009, página 14 | Usuarios permanece pendiente y solo aparece para administradores. No existe todavía un módulo de gestión de cuentas. |
| RF010, página 14 | Contador real de solicitudes cuyo estado es exactamente `Pendiente`. |
| RNF002, página 15 | PDO preparado, escape HTML, mensajes de error generales; el dashboard no muestra información de clientes. |
| RNF003, página 16 | Diseño responsive, incluidos los anchos de aceptación de 375 y 1366 px. |
| Diccionario, páginas 20–22 | Se conserva el esquema de cinco tablas. Categorías tiene nombre único y máximo de 100 caracteres. |

Los totales de productos y categorías son indicadores adicionales solicitados por el
propietario; no representan existencias. No se añadieron precios, inventario ni pagos.
La condición de borrado considera también productos ocultos. La restricción UNIQUE
y la clave foránea existentes respaldan las validaciones ante solicitudes concurrentes.

## Diferencias y alcance pendiente

- El PDF describe una propuesta y deja las evidencias pendientes (páginas 23–24).
  Las pruebas de esta etapa se documentan aquí; no se atribuyen al documento original.
- RF007 se implementa únicamente para categorías en esta etapa. Productos, solicitudes
  y usuarios permanecen identificados como pendientes, sin enlaces a rutas inexistentes.
- RF004–RF005 (lista y registro de proformas), RF008 y RF009 requieren etapas posteriores.
- RF007 menciona ocultación en el catálogo, pero el diccionario solo define `visible`
  en productos. No se añadió un campo para ocultar categorías.
- No se detectó una incompatibilidad de estructura que requiera migraciones para
  el dashboard o categorías. No se alteraron tablas ni restricciones.

## Seguridad incorporada

- Cada entrada al nuevo panel verifica en MySQL que la cuenta de la sesión sigue activa
  y mantiene su rol. Una desactivación o cambio de rol exige volver a iniciar sesión.
  Esto se implementa en un servicio del panel, sin modificar el servicio de autenticación.
- POST y token CSRF para crear, editar y solicitar/confirmar eliminación.
- Confirmación de eliminación vinculada a la sesión y a la categoría, válida por cinco
  minutos y consumida al intentar eliminar. GET no elimina registros.
- Se vuelve a comprobar la ausencia de productos en la propia sentencia DELETE.
- Duplicados detectados con la comparación de MySQL y la restricción única existente.
- Redirección después de operaciones correctas para evitar reenvíos por recarga.
- Cierre de sesión mediante el endpoint POST y CSRF existente.

## Comprobaciones realizadas sin escribir en MySQL

- Sintaxis de los archivos PHP creados/modificados y `git diff --check`.
- HTTP real desde XAMPP: dashboard y categorías redirigen al acceso con 303 sin sesión;
  un POST de eliminación sin sesión también se rechaza mediante esa redirección.
- Lectura real de indicadores: 0 productos, 0 categorías y 0 solicitudes pendientes
  en la base local al realizar la prueba. Existía una cuenta administradora activa.
- Ejecución de páginas con sesiones de prueba aisladas en una carpeta temporal:
  CSRF ausente devuelve 400; nombre vacío y eliminación sin confirmación devuelven 422;
  sesión vencida o rol inconsistente redirigen al acceso. GET con una supuesta acción
  de eliminación solo muestra el listado.
- Control de autorización reutilizado: una sesión simulada de empleado recibe 403
  cuando se exige administrador. Su navegación no contiene Usuarios.
- `probar_categorias_sin_mysql.php`: 24 comprobaciones y operaciones CRUD simuladas.
  Valida nombres Unicode, identificadores, escape HTML, CSRF, duplicados, registros
  inexistentes, edición sin cambios y errores de integridad. Usa dobles de PDO;
  no abre una conexión a MySQL ni crea datos.
- Chrome: 21 combinaciones de dashboard, categorías vacío y listado simulado en
  320, 375, 768, 1024, 1366, 1920 y 2560 px, sin desbordamiento horizontal detectado.
  Se revisaron capturas en móvil, tablet y escritorio. El HTML procede de ejecución
  PHP de lectura; las filas pobladas se simularon únicamente en el navegador.

Para repetir las pruebas sin base de datos desde PowerShell:

```powershell
& 'C:\xampp\php\php.exe' 'C:\xampp\htdocs\pagina_web_ferreteria\pruebas\probar_categorias_sin_mysql.php'
```

## Pruebas pendientes que requieren autorización para datos reales

- Crear una categoría, editarla y eliminarla con confirmación desde el navegador.
- Probar duplicados reales (incluidas variantes de mayúsculas y acentos).
- Intentar borrar una categoría con productos visibles u ocultos asociados.
- Repetir el flujo completo con una cuenta real de empleado; probar desactivación
  y cambios de rol durante una sesión.
- Probar concurrencia con conexiones MySQL reales y el objetivo RNF005 con un conjunto
  de aproximadamente 100 productos. No se ha medido ese escenario.

No se ejecutaron migraciones, inserciones, actualizaciones o eliminaciones en MySQL
durante estas comprobaciones. No se modificaron las páginas públicas, el mapa,
las credenciales ni las funciones existentes de autenticación.

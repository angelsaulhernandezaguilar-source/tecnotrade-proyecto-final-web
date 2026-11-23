# TecnoTrade — proyecto final web

Tienda de dispositivos con servidor **PHP 8.1+**, base de datos **MySQL 8 / MariaDB 10.4+** y, en el navegador, **Vue 3** para el catálogo y el carrito.

## Ejecutar en local sin XAMPP

Esta carpeta incluye PHP 8.3 portátil (`php/`). Solo requiere un MySQL en marcha (en esta computadora, el servicio `MySQL80`).

1. Doble clic en `iniciar.bat`. Arranca el servidor en <http://localhost:8088/> y abre el navegador.
2. La primera vez se abre `instalar.php`: escribe la contraseña de **tu** usuario `root` de MySQL y crea la cuenta de administrador.
3. Las siguientes veces basta con `iniciar.bat`. Cierra su ventana para detener el servidor.

`iniciar.bat` usa el servidor integrado de PHP, que solo escucha en `127.0.0.1`. Como ese servidor no lee `.htaccess`, `local/router.php` aplica las mismas reglas y niega el acceso a `server/`, `database/`, `tests/`, `docs/`, `php/`, `frontend/` y `local/`.

Si la carpeta viene de Git, `php/` solo trae `php.ini`: `iniciar.bat` descarga PHP 8.3 la primera vez con `local/descargar-php.ps1` y comprueba su SHA-256.

## Sobre el proyecto y descarga en PowerPoint

La tienda tiene la página **Sobre el proyecto** (`#/proyecto`, enlace en el pie). Muestra:

- qué hace cada característica del framework y en qué archivo está aplicada;
- los criterios de elección de Vue 3 frente a React y Angular, y cómo se cumple cada uno;
- las rutas de la API en PHP, con buscador y filtro por área;
- el botón **Descargar PowerPoint**, que entrega `public/descargas/TecnoTrade-Presentacion.pptx`: 17 diapositivas con arquitectura, framework, APIs, base de datos y pruebas, con notas del orador.

El contenido de la página sale de `frontend/src/proyecto.json`. La presentación es un archivo ya generado: si cambias ese contenido, hay que actualizarla aparte.

## Vue 3: framework del lado del cliente

La investigación `docs/Investigacion_Frameworks_Lado_Cliente_4CV4.pdf` compara Vue, React y Angular y elige **Vue 3**. Aquí se aplica sobre la tienda real, no sobre datos ficticios: los componentes consultan la API PHP y el servidor sigue validando existencias, precios y sesión.

| Concepto de la investigación | Dónde está en el proyecto |
| --- | --- |
| Componentes reutilizables (2.1) | `frontend/src/components/`: `CatalogoVista`, `FiltrosCatalogo`, `TarjetaProducto`, `ResumenCarrito`, `CarritoVista`, `FilaCarrito`, `IconoUi`, `ProyectoVista` |
| Estado reactivo (2.2) | `frontend/src/tienda.js`: objeto `reactive()` con carrito, favoritos, categorías y usuario |
| `v-model` y buscador (4.5) | `FiltrosCatalogo.vue`; el catálogo filtra mientras se escribe, sin botón «Aplicar» |
| Propiedades calculadas (4.5, 4.6) | `productosFiltrados` en `CatalogoVista.vue`; `total` y `articulos` en `ResumenCarrito.vue` y `CarritoVista.vue` |
| Eventos `@click` y carrito (4.6) | `agregar()` en `TarjetaProducto.vue`; `cambiarCantidad()` y `eliminar()` en `CarritoVista.vue` |
| Leer productos reales de una API (5.2) | `cargarProductos()` llama a `api.php?path=/api/products` |
| Integración progresiva en una página existente (3.4, 4.7) | `frontend/src/main.js` reemplaza las páginas `#/catalog` y `#/cart` y añade `#/proyecto`; el resto de la tienda sigue igual |

**Por qué no se carga Vue desde un CDN.** La tienda envía la cabecera `Content-Security-Policy: script-src 'self'`, que bloquea scripts de otros sitios y la compilación de plantillas en el navegador. Por eso se siguió la ruta que la propia investigación señala para proyectos que crecen (3.4): componentes de archivo único `.vue` compilados con Vite a un solo archivo, `public/vue/tienda-vue.js`, que PHP sirve como cualquier otro. Además funciona sin Internet.

**Para modificar un componente** (solo entonces hace falta Node.js):

```
cd frontend
npm install
npm run build
```

El resultado se escribe en `public/vue/`. Para ejecutar la tienda no se necesita Node.js.

`public/app.js` conserva el enrutador y las demás páginas. Se le añadieron cuatro puntos de enlace: el evento `tt:state` que avisa a Vue de cada cambio de estado, las funciones expuestas en `window.UI`, el desmontaje de la app Vue al cambiar de página y favoritos sin redibujar toda la pantalla. Si `tienda-vue.js` no cargara, `app.js` muestra sus versiones originales del catálogo y del carrito.

## Ejecutar con XAMPP
Usa Apache y el servicio que el panel de XAMPP llama «MySQL»; las versiones actuales de XAMPP suelen incluir MariaDB. La subcarpeta `php/` no se usa con XAMPP.

Esta es una instalación local independiente. La versión publicada sigue en <https://tecnotrade-tienda.grassyhawk132.chatgpt.site> y continúa usando su infraestructura de Internet. Este paquete no descarga sus datos ni sincroniza cambios con ella.

### Instalación en Windows

1. Instala XAMPP con PHP 8.1 o posterior. Inicia **Apache** y **MySQL** desde su panel.
2. Copia esta carpeta completa a `C:\xampp\htdocs\tecnotrade`.
3. Abre <http://localhost/tecnotrade/instalar.php>. No abras el HTML desde el explorador de archivos.
4. Indica la conexión de MySQL/MariaDB, la URL de esta instalación y los datos del administrador que crearás. Elige una contraseña de 12 a 128 caracteres.
5. El instalador crea las tablas, las categorías, las referencias de modelos y la cuenta administradora. No hace falta importar el SQL manualmente en una instalación nueva.
6. Abre <http://localhost/tecnotrade/>, inicia sesión y entra en **Mi panel**.

En una instalación local nueva, los valores habituales de XAMPP son servidor `127.0.0.1`, puerto `3306`, usuario `root`, contraseña vacía y nombre de base `tecnotrade`. Usa tus valores si ya los cambiaste. La cuenta de MySQL es diferente de la cuenta de administrador de la tienda. Las credenciales locales de BD se guardan en `server/config.local.php`.

En macOS/Linux copia la carpeta al directorio `htdocs` de tu XAMPP y concede a Apache permisos de escritura en el directorio de configuración y almacenamiento indicados por el instalador. Usa la URL y el puerto que corresponden a tu Apache. No se incluye ni se afirma haber probado un ejecutable de XAMPP para Windows; el paquete contiene el código PHP de la aplicación.

## Uso después de instalar

Inicia Apache y MySQL y visita la URL local. La base persiste al cerrar el navegador o reiniciar Apache. El instalador se cierra después de configurar la tienda. No lo uses para reinstalar sobre información existente.

Los productos iniciales están en **borrador**, sin precio ni existencias. Desde **Administración → Productos** registra la información real, su precio en pesos mexicanos, inventario, garantía y fotografías antes de publicar. Los importes del servidor se guardan como enteros en centavos.

La búsqueda ofrece sugerencias de los productos publicados y aprobados. El cliente puede guardar favoritos, modificar su carrito, generar pedidos, consultar su seguimiento y abrir conversaciones con soporte. El botón **¿Olvidaste tu contraseña?** inicia la recuperación por correo cuando configures ese servicio.

## Usuarios y permisos

| Rol | Funciones |
| --- | --- |
| Cliente | Registro público, compras, favoritos, canjes, saldo, pedidos y consultas propias. |
| Administración | Productos, categorías, promociones, referencias de mercado, cuentas del personal, inventario y registro de pagos confirmados. |
| Logística | Pedidos y seguimiento de preparación, despacho y entrega de pedidos pagados. |
| Soporte | Consultas y conversaciones de atención a clientes. |
| Técnico | Revisiones de seminuevos e inspección y oferta de los equipos recibidos en canje. |

Administración crea las cuentas del personal desde **Mi panel → Usuarios**. El registro público siempre crea un cliente; no permite elegir privilegios. No hay una contraseña administradora compartida o predeterminada.

Los seminuevos se registran individualmente, con máximo una unidad por producto. La cuenta de Técnico emite su revisión antes de que Administración pueda publicarlos.

## Canje y saldo a favor

1. El cliente elige el modelo y capacidad que declara, su condición y un producto disponible del catálogo. Puede adjuntar fotografías.
2. Las referencias de mercado son precios de venta de terceros, con fuente y fechas. Para una estimación automática se requieren al menos dos vendedores independientes con referencias vigentes, disponibles y de condición comparable. Si faltan, se requiere valoración manual.
3. Técnico revisa el equipo, registra las pruebas, serie/IMEI y oferta final. La fotografía por sí sola no confirma modelo, autenticidad ni funcionamiento.
4. El cliente revisa el precio actual del producto y acepta explícitamente la oferta. El servidor valida inventario, importes, aceptación e identificador de operación dentro de una transacción.
5. El valor aprobado se aplica al producto. Si el producto cuesta más, la diferencia queda pendiente de pago. Si el equipo entregado vale más, el excedente se conserva como saldo a favor del cliente, sin retiro en efectivo.

Las referencias incluidas no se actualizan automáticamente ni son precios en tiempo real. Administración puede renovarlas en **Mercado**. Nunca se utilizan referencias vencidas como si se acabaran de consultar.

## Pagos y correo

La aplicación registra pedidos y pagos manuales. No hay pasarela de tarjetas conectada ni confirmación automática de transferencias. Administración debe comprobar que recibió el dinero antes de registrar un pago con referencia única. Logística comienza la preparación cuando el pedido está pagado.

La recuperación usa enlaces de un solo uso y duración limitada. Para enviar correos configura en `server/config.local.php`:

```php
'mail_key' => 'TU_CLAVE_PRIVADA_DE_RESEND',
'mail_from' => 'TecnoTrade <cuenta@tu-dominio-verificado.com>',
'app_url' => 'http://localhost/tecnotrade/',
```

La cuenta y el remitente deben estar autorizados en Resend y PHP debe tener cURL habilitado. Sin esa configuración, la pantalla de recuperación muestra una respuesta general y no envía correo. La aplicación no muestra tokens de recuperación en el navegador ni revela si un correo está registrado. Los enlaces apuntan a `app_url`, por lo que esa dirección debe ser accesible para quien los recibe. Guarda las claves en tu configuración privada; no las agregues al JavaScript ni a una copia que compartas.

## Celular e Internet

En el teléfono puedes usar directamente el [sitio publicado](https://tecnotrade-tienda.grassyhawk132.chatgpt.site). `localhost` en un celular apunta al propio celular, no a tu computadora.

XAMPP no es un servicio de alojamiento. Esta instalación local no cambia el sitio publicado ni lo mueve a otro proveedor. Para una demostración en la red local, la computadora y el teléfono deben compartir red, Apache debe admitir esa conexión y la URL configurada debe corresponder a la dirección privada de la computadora. Es un ajuste de tu red y no un despliegue público. No abras puertos del router para publicar esta instalación de desarrollo.

## Respaldo

Antes de modificar la instalación, exporta la base de datos completa desde phpMyAdmin y conserva una copia del directorio de almacenamiento de imágenes configurado y de `server/config.local.php` en un lugar privado. La exportación contiene información de usuarios. Restaurar requiere el SQL y sus imágenes; copiar solo el frontend no recupera pedidos ni fotografías subidas.

No importes el SQL de ejemplo ni ejecutes de nuevo una instalación sobre datos que necesitas conservar. El paquete no incluye una migración automática de usuarios/contraseñas desde Cloudflare D1 ni desde el antiguo SQLite de Node.

## Solución de problemas

- **Apache no inicia:** revisa su registro y comprueba que otro programa no esté usando el puerto 80/443. Si Apache usa otro puerto, inclúyelo en la URL, por ejemplo `http://localhost:8080/tecnotrade/`.
- **MySQL no inicia o conexión rechazada:** verifica el servicio y el puerto. El puerto HTTP de Apache y el de MySQL son diferentes.
- **Access denied / acceso denegado de BD:** usa el usuario y contraseña reales de tu servidor de MySQL/MariaDB. La contraseña de la tienda no sustituye esa cuenta.
- **No se encuentra el instalador:** comprueba que no quedó una carpeta anidada extra. Debe existir `htdocs/tecnotrade/instalar.php`.
- **Aparece el código PHP o se descarga:** abre la dirección `http://localhost/...` con Apache/PHP funcionando; no uses `file://` ni un servidor de archivos estáticos.
- **No hay productos:** publica inventario real desde Administración. Los borradores no aparecen en el catálogo público.
- **Cambiaste la carpeta, URL o puerto:** actualiza `app_url` en la configuración local y usa la nueva dirección para entrar. El frontend calcula el prefijo de la instalación y conserva la navegación dentro de la carpeta.
- **No llega la recuperación:** comprueba Resend, remitente verificado, cURL y los datos de correo en la configuración. Sin proveedor de correo no se envían mensajes.

## Código

`index.php` sirve únicamente archivos públicos permitidos y sustituye el prefijo de la instalación. Los estáticos usan `index.php?file=...` y la API usa `api.php?path=...`; no necesitan reglas de reescritura de Apache. Las rutas del navegador siguen el formato `#/catalog`, `#/account` y `#/panel`.

`server/` contiene PHP y configuración privada; `database/` contiene el esquema; `public/` contiene imágenes, estilos y JavaScript. Las imágenes privadas de un canje se entregan mediante la API, con control de acceso. El historial de saldo, los pagos, los pedidos y el inventario se validan en el servidor. Las sesiones usan cookies HttpOnly y formularios protegidos con CSRF. Las consultas usan PDO y parámetros; las mutaciones financieras se completan o revierten juntas en MySQL/MariaDB.

Las fotografías de referencia conservan sus créditos y licencias en la página **Autoría y licencias** dentro de la tienda. La foto naranja aportada por el cliente sigue identificada como modelo por confirmar.

## Archivos para exponer el proyecto

`docs/PITCH.md` contiene el guion de presentación y una demostración recomendada. `docs/modelo-er.svg` y `docs/modelo-er.mmd` muestran las 21 entidades de negocio. La tabla adicional `rate_limits` es técnica; el esquema completo de esta edición tiene 22 tablas.

Puedes consultar la base local desde `http://localhost/phpmyadmin/`, seleccionando el nombre que elegiste durante la instalación. Si Apache usa otro puerto, inclúyelo también en esa dirección.

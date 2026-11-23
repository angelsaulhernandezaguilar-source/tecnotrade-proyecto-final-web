# Verificación de la edición XAMPP

Comprobada el 26 de octubre de 2026 con PHP 8.2.35, Apache 2.4.69 y MariaDB 10.4.34 en un entorno Linux aislado. Las pruebas usan una base local de prueba; no se modificó la instalación de Internet. No se ejecutó XAMPP para Windows en este entorno.

- Instalación HTTP desde cero, 22 tablas InnoDB y cuenta administradora propia; el asistente queda cerrado al terminar.
- 33 grupos de comprobaciones de API: cinco roles, registro fijo de clientes, CSRF, origen, búsquedas sugeridas, precios calculados por el servidor, idempotencia, cancelación, fotos privadas, revisiones, soporte, pagos manuales y recuperación.
- Seis aceptaciones simultáneas de un mismo canje crearon un solo pedido. Una oferta de 1,500 pesos aplicada a un producto de 1,000 dejó 500 pesos de saldo. Dos compras simultáneas de la última unidad produjeron una sola venta.
- Historial de saldo coincide con balances; no quedaron saldos ni inventarios negativos; sesiones guardadas mediante hash.
- Restricciones SQL rechazaron precio/stock negativos y una clave foránea inexistente. Límites de acceso permanecen aun después de revertir una solicitud fallida.
- Instalador rechaza acceso no local, formularios sin CSRF válido y bases que ya contienen usuarios, sin sobrescribir información.
- Desconexión real de MySQL durante una transacción: rollback protegido, bloqueo liberado al cerrar conexión y respuesta de resultado incierto. El navegador no reenvía automáticamente una mutación cuyo resultado no pudo confirmarse.
- Chromium real en escritorio de 1440 px y móvil de 390 px: 33 visitas de rutas y ocho acciones de formularios, incluidas alta/edición/foto/retiro de producto, cierre de sesión, sugerencias con teclado y carrito. Sin errores de página, consola, solicitudes HTTP ni desbordamiento horizontal en esos recorridos.
- Subcarpeta /tecnotrade/, archivos y API mediante dispatchers PHP con mod_rewrite deshabilitado; las rutas privadas siguen bloqueadas.
- La API local se intenta aunque navigator.onLine indique que no hay Internet. Identificadores seguros también disponibles en contextos HTTP de red local.
- PHP lint de todas las fuentes y análisis de sintaxis de los tres scripts JavaScript.

Los guiones de regresión están en tests/. Exigen una URL local y una base cuyo nombre termine en _qa; crean datos ficticios y no deben ejecutarse sobre una base comercial.

Las pruebas de recuperación verifican tokens y cambios de contraseña en la base de prueba. No se enviaron correos reales ni se conectó una pasarela de tarjetas. La configuración privada y los datos de prueba se excluyen del ZIP de entrega.

## Verificación del proyecto final (2026-11-23)

Entorno: PHP 8.3.37 incluido (`iniciar.bat` / servidor integrado) y MySQL 8.0, base de pruebas `tecnotrade_qa` en una instancia temporal.

- `tests/integration.py`: 33 de 33 comprobaciones aprobadas.
- Catálogo Vue (`#/catalog`): búsqueda sin distinguir acentos, filtros por categoría y condición, orden por precio, aviso sin resultados, botón deshabilitado sin existencias, contador y total del carrito al instante.
- Carrito Vue (`#/cart`): cambio de cantidad, cantidad inválida rechazada, aviso al exceder inventario, eliminar y carrito vacío; como invitado (localStorage) y como cliente (API).
- Favoritos desde la tarjeta sin redibujar la página.
- Consola del navegador sin errores ni bloqueos de Content-Security-Policy.
- Rutas privadas (`server/`, `database/`, `tests/`, `local/`, `frontend/`) responden 403 con el servidor integrado.

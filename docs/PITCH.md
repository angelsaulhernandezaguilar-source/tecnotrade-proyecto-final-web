# Presentación de TecnoTrade

## Pitch de aproximadamente 90 segundos

> TecnoTrade es una tienda en línea de dispositivos tecnológicos nuevos y seminuevos que organiza todo el proceso: elegir un equipo, comprarlo, revisar su entrega y recibir atención después de la venta.
>
> El cliente puede buscar productos, revisar sus características, guardar favoritos y gestionar su carrito desde una cuenta. También puede solicitar un canje: elige el modelo de su dispositivo, adjunta fotografías y recibe una estimación orientativa. El valor final depende de una revisión técnica; la foto por sí sola no identifica ni valora el equipo.
>
> Cuando el técnico emite una oferta y el cliente la acepta, el importe se aplica al nuevo dispositivo. Si sobra, queda como saldo a favor; si falta, el pedido conserva el importe pendiente de pago.
>
> Para operar la tienda, cada persona tiene responsabilidades claras. El administrador gestiona productos, promociones y personal. Logística prepara y entrega pedidos pagados. Soporte atiende las conversaciones del cliente. Y el técnico documenta las pruebas de los seminuevos y canjes.
>
> La aplicación trabaja con un servidor y una base de datos compartida, de modo que los cambios se conservan y los permisos se validan al realizar cada operación.
>
> Esta edición se ejecuta con Apache, PHP y MySQL/MariaDB en XAMPP. La tienda publicada en Internet tiene una instalación independiente. Los pagos se registran manualmente; el correo de recuperación necesita configurar su proveedor y los cobros con tarjeta requieren una pasarela adicional.

## Versión breve

> TecnoTrade conecta la venta de tecnología con el canje, la revisión técnica, la logística y el soporte. El cliente compra y consulta sus pedidos desde su cuenta; cada área trabaja con permisos propios sobre una base de datos compartida. El canje requiere una oferta revisada y aceptada, y su excedente se conserva como saldo a favor.

## Demostración recomendada

Antes de presentar, inicia Apache y MySQL en XAMPP, completa instalar.php para crear el administrador, configura al menos un producto con precio e inventario reales de prueba y crea las cuentas del personal que vayas a mostrar. No existe una contraseña común predeterminada.

1. **Catálogo:** busca un equipo, abre su ficha y muestra precio, condición, garantía y disponibilidad.
2. **Cliente:** registra una cuenta de prueba, guarda un favorito, modifica su carrito y crea un pedido. Si no se ha cubierto con saldo, explica por qué queda pendiente de pago.
3. **Canje:** selecciona un modelo y condición, adjunta fotos y explica qué significa la estimación provisional.
4. **Técnico:** abre la solicitud, documenta las pruebas y emite el dictamen con su oferta. Regresa al cliente para aceptar y comprobar el pedido y el saldo.
5. **Operación:** desde administración, muestra inventario y personal. Si demuestras pago manual, utiliza únicamente un pago de prueba que hayas decidido registrar; aclara que el sistema no verifica bancos ni procesa tarjetas.
6. **Entrega y soporte:** abre un pedido pagado desde logística y actualiza su estado. Crea un ticket desde el cliente y responde desde soporte para mostrar la conversación conservada.
7. **Modelo de datos:** abre `modelo-er.svg` para explicar cómo se relacionan cuentas, productos, pedidos, revisiones, canjes y saldo.

## Respuestas claras para la exposición

**¿Qué usuarios necesita?** Cliente, administrador, logística, soporte y técnico. El visitante puede explorar sin crear una cuenta.

**¿La foto reconoce el dispositivo?** No. El cliente selecciona el modelo. La fotografía documenta su estado y la revisión técnica determina si puede aceptarse y por cuánto.

**¿La estimación es una oferta definitiva?** No. Usa referencias disponibles para orientar; la oferta final requiere revisión y aceptación del cliente.

**¿Qué sucede con el excedente del canje?** Queda como saldo a favor en la cuenta para compras posteriores. No se entrega en efectivo.

**¿Ya acepta tarjetas?** No hay una pasarela conectada. Los importes no cubiertos quedan pendientes; el administrador puede registrar pagos que ya haya comprobado por otro medio.

**¿Funciona abriendo el HTML?** Esta versión necesita Apache y MySQL de XAMPP. Después de iniciar ambos, se abre http://localhost/tecnotrade/. Los datos se guardan en MySQL/MariaDB, no en el navegador.

**¿Ya está publicada?** La instalación de Internet está en https://tecnotrade-tienda.grassyhawk132.chatgpt.site. Esta edición XAMPP se ejecuta localmente y usa otra base de datos. Copiarla a htdocs no la publica ni sincroniza con Internet.

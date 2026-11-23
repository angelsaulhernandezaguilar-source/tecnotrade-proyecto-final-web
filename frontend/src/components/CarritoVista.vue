<!-- Página #/cart. El carrito vive en el estado reactivo; al cambiar una
     cantidad o quitar un producto, los importes y el total se recalculan solos. -->
<template>
  <div class="container page-shell cart-page">
    <div class="page-heading">
      <p class="eyebrow">CASI TUYO</p>
      <h1>Tu carrito.</h1>
      <p>Un paso más cerca de tu próximo dispositivo. Revisa cantidades y disponibilidad.</p>
    </div>

    <div class="split-layout">
      <section>
        <p v-if="cargando" class="muted" role="status">Cargando tu carrito…</p>
        <template v-else>
          <FilaCarrito
            v-for="linea in lineas"
            :key="linea.productId"
            :linea="linea"
            :ocupado="guardando"
            @cantidad="cambiarCantidad(linea, $event)"
            @eliminar="eliminar(linea)"
          />
          <div v-if="!lineas.length" class="empty-state">
            <IconoUi nombre="box" />
            <h2>Tu carrito está esperando tu próximo descubrimiento.</h2>
            <p>Explora el catálogo y agrega los equipos que te interesen.</p>
          </div>
        </template>
        <a class="cart-continue" href="#/catalog"><IconoUi nombre="arrow" /> Seguir explorando</a>
      </section>

      <aside class="summary-card">
        <p class="eyebrow">TU SELECCIÓN</p>
        <h2>Resumen del pedido</h2>
        <div class="summary-row">
          <span>Productos ({{ articulos }})</span><strong>{{ dinero(total) }}</strong>
        </div>
        <div class="summary-row"><span>Envío</span><strong>Sin cargo adicional</strong></div>
        <div class="summary-row total"><span>Total MXN</span><strong>{{ dinero(total) }}</strong></div>
        <a v-if="listoParaPedir" class="btn btn--primary btn--block" href="#/checkout">Continuar con el pedido</a>
        <button v-else type="button" class="btn btn--primary btn--block" disabled>Agrega productos disponibles</button>
        <p class="safe-note">
          El servidor verifica el precio y las existencias al confirmar. Si queda una diferencia después de
          aplicar tu saldo, el pedido permanecerá pendiente de pago.
        </p>
      </aside>
    </div>
  </div>
</template>

<script>
import FilaCarrito from './FilaCarrito.vue';
import IconoUi from './IconoUi.vue';
import { UI, tienda, precio, dinero, guardarCarrito } from '../tienda.js';

export default {
  name: 'CarritoVista',
  components: { FilaCarrito, IconoUi },
  data() {
    return {
      cargando: true,
      guardando: false,
      detalles: {}, // productId -> producto consultado en la API (o null)
    };
  },
  computed: {
    // Une lo que hay en el carrito con los datos actuales de cada producto.
    lineas() {
      return tienda.carrito.map((articulo) => ({ ...articulo, product: this.detalles[articulo.productId] || null }));
    },
    articulos() {
      return this.lineas.reduce((suma, l) => suma + l.quantity, 0);
    },
    total() {
      return this.lineas.reduce((suma, l) => suma + (l.product ? precio(l.product) * l.quantity : 0), 0);
    },
    listoParaPedir() {
      return this.lineas.length > 0 && this.lineas.every((l) => l.product && l.quantity <= l.product.stock);
    },
  },
  async mounted() {
    try {
      await UI.loadCart(); // carrito de la cuenta (servidor) o del invitado (navegador)
      const consultados = await UI.cartProducts();
      this.detalles = Object.fromEntries(consultados.map((l) => [l.productId, l.product]));
    } catch (fallo) {
      UI.toast(fallo.message);
    } finally {
      this.cargando = false;
    }
  },
  methods: {
    dinero,
    async guardar(articulos) {
      this.guardando = true;
      try {
        await guardarCarrito(articulos);
      } catch (fallo) {
        UI.toast(fallo.message);
      } finally {
        this.guardando = false;
      }
    },
    cambiarCantidad(linea, cantidad) {
      return this.guardar(
        tienda.carrito.map((a) => (a.productId === linea.productId ? { ...a, quantity: cantidad } : a)),
      );
    },
    eliminar(linea) {
      return this.guardar(tienda.carrito.filter((a) => a.productId !== linea.productId));
    },
  },
};
</script>

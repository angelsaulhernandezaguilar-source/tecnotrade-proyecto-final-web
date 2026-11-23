<!-- Franja del catálogo con el contador y el total del carrito.
     Ambos son propiedades calculadas: cambian al instante al pulsar "Agregar". -->
<template>
  <div class="vue-resumen" role="status">
    <IconoUi nombre="cart" />
    <span>
      <strong>{{ articulos }}</strong> {{ articulos === 1 ? 'artículo' : 'artículos' }} en tu carrito
    </span>
    <span class="vue-resumen__total">Total: <strong>{{ dinero(total) }}</strong></span>
    <a class="text-link" href="#/cart">Ver carrito <IconoUi nombre="arrow" /></a>
  </div>
</template>

<script>
import IconoUi from './IconoUi.vue';
import { tienda, precio, dinero } from '../tienda.js';

export default {
  name: 'ResumenCarrito',
  components: { IconoUi },
  // Productos del catálogo: de aquí salen los precios de lo que hay en el carrito.
  props: { productos: { type: Array, default: () => [] } },
  computed: {
    articulos() {
      return tienda.carrito.reduce((suma, a) => suma + a.quantity, 0);
    },
    total() {
      const precios = new Map(this.productos.map((p) => [p.id, precio(p)]));
      return tienda.carrito.reduce((suma, a) => suma + (precios.get(a.productId) || 0) * a.quantity, 0);
    },
  },
  methods: { dinero },
};
</script>

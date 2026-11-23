<!-- Un renglón del carrito: producto, cantidad editable e importe. -->
<template>
  <article class="cart-row">
    <img :src="imagen" alt="" width="75" height="80">
    <div>
      <h3>{{ producto ? producto.name : 'Producto no disponible' }}</h3>
      <p>{{ producto ? producto.condition : 'Retira este producto para continuar.' }}</p>
      <button type="button" class="remove-item" :disabled="ocupado" @click="$emit('eliminar')">
        <IconoUi nombre="trash" />Eliminar
      </button>
      <p v-if="excedeExistencias" class="error-box">La cantidad supera el inventario actual.</p>
    </div>
    <label class="solo-lectores" :for="idCampo">Cantidad de {{ producto ? producto.name : 'producto' }}</label>
    <input
      :id="idCampo"
      type="number"
      min="1"
      :max="producto ? Math.max(1, producto.stock) : 1"
      step="1"
      :value="linea.quantity"
      :disabled="!producto || ocupado"
      @change="cambiar"
    >
    <strong>{{ producto ? dinero(importe) : '—' }}</strong>
  </article>
</template>

<script>
import IconoUi from './IconoUi.vue';
import { UI, precio, dinero } from '../tienda.js';

export default {
  name: 'FilaCarrito',
  components: { IconoUi },
  props: {
    // { productId, quantity, product } — product es null si ya no está publicado.
    linea: { type: Object, required: true },
    ocupado: { type: Boolean, default: false },
  },
  emits: ['cantidad', 'eliminar'],
  computed: {
    producto() {
      return this.linea.product;
    },
    idCampo() {
      return 'cantidad-' + this.linea.productId;
    },
    imagen() {
      return UI.safeImage(this.producto ? this.producto.image : '');
    },
    importe() {
      return precio(this.producto) * this.linea.quantity;
    },
    excedeExistencias() {
      return Boolean(this.producto) && this.linea.quantity > this.producto.stock;
    },
  },
  methods: {
    dinero,
    cambiar(evento) {
      const cantidad = Number(evento.target.value);
      if (!Number.isInteger(cantidad) || cantidad < 1 || cantidad > 99) {
        evento.target.value = this.linea.quantity; // se restaura el valor válido
        UI.toast('La cantidad debe ser un número entero entre 1 y 99.');
        return;
      }
      this.$emit('cantidad', cantidad);
    },
  },
};
</script>

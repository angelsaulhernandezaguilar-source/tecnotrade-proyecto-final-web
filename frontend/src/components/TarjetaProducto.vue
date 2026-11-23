<!-- Tarjeta de un producto del catálogo. Recibe el producto por props y
     reacciona a los clics con @click (agregar al carrito y favoritos). -->
<template>
  <article class="product-card">
    <div class="product-card__media">
      <a class="imagen-producto" :href="enlace">
        <img :src="imagen" width="400" height="320" loading="lazy" :alt="producto.name">
      </a>
      <span v-if="descuento" class="product-card__discount">−{{ descuento }}%</span>
      <button
        type="button"
        class="favorite-button"
        :class="{ 'is-favorite': esFavorito }"
        :aria-label="(esFavorito ? 'Quitar de' : 'Agregar a') + ' favoritos: ' + producto.name"
        :aria-pressed="String(esFavorito)"
        :disabled="ocupadoFavorito"
        @click="cambiarFavorito"
      >
        <IconoUi nombre="heart" />
      </button>
    </div>
    <div class="product-card__body">
      <p class="card-condition">{{ producto.condition }} · {{ producto.storage || 'Equipo verificado' }}</p>
      <h3><a :href="enlace">{{ producto.name }}</a></h3>
      <p class="product-card__spec">{{ resumen }}</p>
      <div class="product-card__prices">
        <span class="precio">{{ dinero(precioActual) }}</span>
        <span v-if="precioAnterior" class="precio--anterior">{{ dinero(precioAnterior) }}</span>
      </div>
      <div class="product-card__bottom">
        <span class="stock-label" :class="{ 'stock-label--empty': agotado }">
          {{ agotado ? 'Sin existencias' : 'Disponible' }}
        </span>
        <button type="button" class="card-add" :disabled="agotado || ocupado" @click="agregar">
          <IconoUi nombre="cart" /> {{ ocupado ? 'Agregando…' : 'Agregar' }}
        </button>
      </div>
    </div>
  </article>
</template>

<script>
import IconoUi from './IconoUi.vue';
import { UI, tienda, precio, dinero, agregarAlCarrito, alternarFavorito } from '../tienda.js';

export default {
  name: 'TarjetaProducto',
  components: { IconoUi },
  props: { producto: { type: Object, required: true } },
  data() {
    return { ocupado: false, ocupadoFavorito: false };
  },
  computed: {
    enlace() {
      return '#/product?id=' + encodeURIComponent(this.producto.id);
    },
    imagen() {
      return UI.safeImage(this.producto.image);
    },
    precioActual() {
      return precio(this.producto);
    },
    precioAnterior() {
      const anterior = Number(this.producto.oldPriceCents);
      const lista = Number(this.producto.priceCents);
      if (anterior > this.precioActual) return anterior;
      return lista > this.precioActual ? lista : 0;
    },
    descuento() {
      return this.precioAnterior ? Math.round((1 - this.precioActual / this.precioAnterior) * 100) : 0;
    },
    resumen() {
      const p = this.producto;
      return p.screen || (p.description || '').slice(0, 70) || 'Consulta las características del equipo';
    },
    agotado() {
      return this.producto.stock < 1;
    },
    esFavorito() {
      return tienda.favoritos.includes(this.producto.id);
    },
  },
  methods: {
    dinero,
    async agregar() {
      this.ocupado = true;
      try {
        await agregarAlCarrito(this.producto);
      } catch (error) {
        UI.toast(error.message);
      } finally {
        this.ocupado = false;
      }
    },
    async cambiarFavorito() {
      this.ocupadoFavorito = true;
      try {
        await alternarFavorito(this.producto);
      } catch (error) {
        UI.toast(error.message);
      } finally {
        this.ocupadoFavorito = false;
      }
    },
  },
};
</script>

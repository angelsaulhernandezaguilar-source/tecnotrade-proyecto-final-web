<!-- Página #/catalog. Pide los productos publicados a la API PHP una sola vez
     y después filtra y ordena en el navegador con una propiedad calculada. -->
<template>
  <div class="container page-shell catalog-page">
    <div class="page-heading">
      <p class="eyebrow">EL CATÁLOGO</p>
      <h1>{{ titulo }}</h1>
      <p>Explora el catálogo y compara los equipos disponibles.</p>
    </div>

    <div class="catalogo-layout">
      <FiltrosCatalogo
        v-model:busqueda="busqueda"
        v-model:categoria="categoria"
        v-model:condicion="condicion"
        v-model:orden="orden"
        :categorias="categorias"
        @limpiar="limpiarFiltros"
      />

      <div class="catalogo-resultados">
        <ResumenCarrito :productos="productos" />

        <p v-if="cargando" class="muted" role="status">Cargando el catálogo…</p>

        <div v-else-if="error" class="empty-state" role="alert">
          <IconoUi nombre="help" />
          <h2>No pudimos cargar el catálogo.</h2>
          <p>{{ error }}</p>
          <button type="button" class="btn btn--primary" @click="cargarProductos">Volver a intentar</button>
        </div>

        <template v-else>
          <p class="catalogo-results-meta" role="status">
            {{ productosFiltrados.length }}
            {{ productosFiltrados.length === 1 ? 'dispositivo disponible' : 'dispositivos disponibles' }}
            · Precios en MXN
          </p>
          <div class="product-grid product-grid--catalog">
            <TarjetaProducto v-for="p in productosFiltrados" :key="p.id" :producto="p" />
            <div v-if="!productosFiltrados.length" class="empty-state">
              <IconoUi nombre="box" />
              <h2>No hay dispositivos disponibles con estos filtros.</h2>
              <p>
                Prueba otra búsqueda o categoría. Publicaremos los equipos cuando su inventario y
                condiciones estén confirmados.
              </p>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<script>
import FiltrosCatalogo from './FiltrosCatalogo.vue';
import TarjetaProducto from './TarjetaProducto.vue';
import ResumenCarrito from './ResumenCarrito.vue';
import IconoUi from './IconoUi.vue';
import { UI, tienda, precio } from '../tienda.js';

// "Cámara" y "camara" deben coincidir: se comparan sin acentos ni mayúsculas.
const normalizar = (texto) =>
  String(texto || '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .trim();

const ORDENES = ['name', 'price_asc', 'price_desc'];

export default {
  name: 'CatalogoVista',
  components: { FiltrosCatalogo, TarjetaProducto, ResumenCarrito, IconoUi },
  // Filtros iniciales tomados de la dirección, p. ej. #/catalog?category=audio
  props: { parametros: { type: Object, default: () => ({}) } },
  data() {
    return {
      productos: [],
      cargando: true,
      error: '',
      busqueda: this.parametros.q || '',
      categoria: this.parametros.category || '',
      condicion: this.parametros.condition || '',
      orden: ORDENES.includes(this.parametros.sort) ? this.parametros.sort : 'name',
    };
  },
  computed: {
    categorias() {
      return tienda.categorias;
    },
    titulo() {
      const elegida = this.categorias.find((c) => c.id === this.categoria);
      return elegida ? elegida.name : 'Encuentra tu próximo dispositivo.';
    },
    productosFiltrados() {
      const buscado = normalizar(this.busqueda);
      const lista = this.productos.filter(
        (p) =>
          (!buscado || normalizar(p.name + ' ' + p.description).includes(buscado)) &&
          (!this.categoria || p.categoryId === this.categoria) &&
          (!this.condicion || p.condition === this.condicion),
      );
      const porNombre = (a, b) => a.name.localeCompare(b.name, 'es', { numeric: true, sensitivity: 'base' });
      if (this.orden === 'price_asc') return lista.sort((a, b) => precio(a) - precio(b) || porNombre(a, b));
      if (this.orden === 'price_desc') return lista.sort((a, b) => precio(b) - precio(a) || porNombre(a, b));
      return lista.sort(porNombre);
    },
    // Los filtros activos, en el mismo formato que usa la dirección de la página.
    consulta() {
      const q = new URLSearchParams();
      if (this.busqueda.trim()) q.set('q', this.busqueda.trim());
      if (this.categoria) q.set('category', this.categoria);
      if (this.condicion) q.set('condition', this.condicion);
      if (this.orden !== 'name') q.set('sort', this.orden);
      return q.toString();
    },
  },
  watch: {
    // Mantiene la dirección al día para poder recargar o compartir el filtro.
    // replaceState no dispara "hashchange", así que la página no se redibuja.
    consulta(nueva) {
      history.replaceState(null, '', '#/catalog' + (nueva ? '?' + nueva : ''));
    },
    titulo(nuevo) {
      document.title = 'TecnoTrade · ' + nuevo;
    },
  },
  mounted() {
    this.cargarProductos();
  },
  methods: {
    async cargarProductos() {
      this.cargando = true;
      this.error = '';
      try {
        const respuesta = await UI.api('/products');
        this.productos = respuesta.products || [];
      } catch (fallo) {
        this.error = fallo.message;
      } finally {
        this.cargando = false;
      }
    },
    limpiarFiltros() {
      this.busqueda = '';
      this.categoria = '';
      this.condicion = '';
      this.orden = 'name';
    },
  },
};
</script>

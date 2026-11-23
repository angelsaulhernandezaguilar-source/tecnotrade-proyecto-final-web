<!-- Buscador y filtros. Usa v-model para enlazar cada control con el estado
     del catálogo: no hay botón "Aplicar", la lista cambia mientras escribes. -->
<template>
  <aside class="catalogo-filtros">
    <h2 class="vue-filtros__titulo">Refina tu búsqueda</h2>
    <form role="search" @submit.prevent>
      <div class="field">
        <label for="filtro-busqueda">Buscar</label>
        <input
          id="filtro-busqueda"
          type="search"
          maxlength="100"
          placeholder="Nombre del equipo"
          :value="busqueda"
          @input="$emit('update:busqueda', $event.target.value)"
        >
      </div>
      <div class="field">
        <label for="filtro-categoria">Categoría</label>
        <select
          id="filtro-categoria"
          :value="categoria"
          @change="$emit('update:categoria', $event.target.value)"
        >
          <option value="">Todas las categorías</option>
          <option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div class="field">
        <label for="filtro-condicion">Condición</label>
        <select
          id="filtro-condicion"
          :value="condicion"
          @change="$emit('update:condicion', $event.target.value)"
        >
          <option value="">Todas las condiciones</option>
          <option value="Nuevo">Nuevo</option>
          <option value="Seminuevo">Seminuevo</option>
        </select>
      </div>
      <div class="field">
        <label for="filtro-orden">Ordenar por</label>
        <select id="filtro-orden" :value="orden" @change="$emit('update:orden', $event.target.value)">
          <option value="name">Nombre</option>
          <option value="price_asc">Menor precio</option>
          <option value="price_desc">Mayor precio</option>
        </select>
      </div>
      <button type="button" class="btn btn--secondary btn--block" :disabled="!hayFiltros" @click="$emit('limpiar')">
        Limpiar filtros
      </button>
    </form>
  </aside>
</template>

<script>
// Cada filtro llega por props y se devuelve con un evento update:…, que es
// justo lo que v-model necesita para funcionar sobre un componente propio.
export default {
  name: 'FiltrosCatalogo',
  props: {
    busqueda: { type: String, default: '' },
    categoria: { type: String, default: '' },
    condicion: { type: String, default: '' },
    orden: { type: String, default: 'name' },
    categorias: { type: Array, default: () => [] },
  },
  emits: ['update:busqueda', 'update:categoria', 'update:condicion', 'update:orden', 'limpiar'],
  computed: {
    hayFiltros() {
      return Boolean(this.busqueda || this.categoria || this.condicion || this.orden !== 'name');
    },
  },
};
</script>

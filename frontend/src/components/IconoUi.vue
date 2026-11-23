<!-- Icono SVG de la tienda. Los trazos son constantes definidas en app.js
     (no vienen del usuario), por eso es seguro insertarlos con v-html. -->
<template>
  <svg
    class="ui-icon"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.6"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    v-html="trazo"
  ></svg>
</template>

<script>
import { UI } from '../tienda.js';

export default {
  name: 'IconoUi',
  props: { nombre: { type: String, required: true } },
  computed: {
    trazo() {
      return UI.iconPaths[this.nombre] || UI.iconPaths.box;
    },
  },
};
</script>

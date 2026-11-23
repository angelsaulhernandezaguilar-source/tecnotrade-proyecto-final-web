// Punto de entrada de Vue 3 en TecnoTrade.
//
// Integración progresiva: la tienda conserva su enrutador por hash (#/catalog,
// #/cart…). Aquí se reemplazan el catálogo y el carrito por componentes Vue y
// se añade la página #/proyecto. Si este archivo no llegara a cargar, app.js
// sigue mostrando sus versiones originales del catálogo y del carrito.
import { createApp } from 'vue';
import CatalogoVista from './components/CatalogoVista.vue';
import CarritoVista from './components/CarritoVista.vue';
import ProyectoVista from './components/ProyectoVista.vue';
import { sincronizar } from './tienda.js';
import './estilos.css';

let aplicacion = null;

function desmontar() {
  if (aplicacion) {
    aplicacion.unmount();
    aplicacion = null;
  }
}

function montar(Componente, propiedades = {}) {
  desmontar();
  sincronizar();
  aplicacion = createApp(Componente, propiedades);
  aplicacion.mount('#vue-raiz');
}

// app.js llama a desmontar() antes de dibujar cualquier otra página.
window.TTVue = { desmontar };

const paginaVue = (Componente, propiedades) => ({
  html: '<div id="vue-raiz"></div>',
  bind: () => montar(Componente, propiedades),
});

if (window.UI && window.PAGES) {
  window.PAGES.catalog = async (parametros) =>
    paginaVue(CatalogoVista, { parametros: Object.fromEntries(parametros) });
  window.PAGES.cart = async () => paginaVue(CarritoVista);
  window.PAGES.proyecto = async () => paginaVue(ProyectoVista);
}

<!-- Página #/proyecto. Explica cómo se aplicó el framework y qué APIs tiene la
     tienda, y permite descargar esa misma información en PowerPoint. -->
<template>
  <div class="container page-shell proyecto-page">
    <div class="page-heading">
      <p class="eyebrow">PROYECTO FINAL · GRUPO 4CV4</p>
      <h1>Sobre el proyecto.</h1>
      <p>Cómo se aplicó Vue 3 en la tienda, qué APIs ofrece el servidor y dónde está cada parte.</p>
    </div>

    <section class="surface proyecto-descarga">
      <div>
        <h2>Descarga esta información en PowerPoint</h2>
        <p class="muted">
          La presentación incluye la arquitectura, los criterios del framework, las APIs, la base de datos y
          las pruebas, con notas para exponer.
        </p>
      </div>
      <a class="btn btn--primary" :href="presentacion" download="TecnoTrade-Presentacion.pptx">
        Descargar PowerPoint <IconoUi nombre="arrow" />
      </a>
    </section>

    <section class="proyecto-seccion">
      <p class="eyebrow">CARACTERÍSTICAS DEL FRAMEWORK</p>
      <h2>Qué hace cada una y dónde está en la tienda</h2>
      <div class="proyecto-rejilla">
        <article v-for="c in caracteristicas" :key="c.nombre" class="surface proyecto-tarjeta">
          <h3>{{ c.nombre }}</h3>
          <p class="muted">{{ c.funcion }}</p>
          <p>{{ c.enProyecto }}</p>
          <code>{{ c.archivo }}</code>
        </article>
      </div>
    </section>

    <section class="proyecto-seccion">
      <p class="eyebrow">CRITERIOS DE ELECCIÓN</p>
      <h2>Por qué Vue 3 y cómo se cumple cada criterio</h2>
      <div class="proyecto-tabla">
        <table>
          <thead>
            <tr>
              <th scope="col">Criterio</th>
              <th scope="col">Vue 3</th>
              <th scope="col">React</th>
              <th scope="col">Angular</th>
              <th scope="col">En TecnoTrade</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in criterios" :key="c.criterio">
              <th scope="row">{{ c.criterio }}</th>
              <td class="proyecto-elegido">{{ c.vue }}</td>
              <td>{{ c.react }}</td>
              <td>{{ c.angular }}</td>
              <td>{{ c.enProyecto }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="safe-note">Evaluación cualitativa de la investigación para este proyecto; no es una medición general.</p>
    </section>

    <section class="proyecto-seccion">
      <p class="eyebrow">APIS DEL SERVIDOR</p>
      <h2>Rutas de la API en PHP</h2>
      <p class="muted">
        El navegador pide <code>api.php?path=/api/…</code> y recibe JSON. Para modificar datos se exige sesión,
        token CSRF y el rol correcto.
      </p>
      <div class="proyecto-filtros">
        <div class="field">
          <label for="api-busqueda">Buscar una ruta</label>
          <input id="api-busqueda" v-model="busqueda" type="search" maxlength="60" placeholder="Por ejemplo: cart">
        </div>
        <div class="proyecto-grupos" role="group" aria-label="Grupo de rutas">
          <button
            v-for="g in grupos"
            :key="g"
            type="button"
            class="btn"
            :class="g === grupo ? 'btn--oscuro' : 'btn--secondary'"
            :aria-pressed="String(g === grupo)"
            @click="grupo = g"
          >
            {{ g }}
          </button>
        </div>
      </div>
      <p class="catalogo-results-meta" role="status">
        {{ rutasFiltradas.length }} {{ rutasFiltradas.length === 1 ? 'ruta' : 'rutas' }}
      </p>
      <div class="proyecto-tabla">
        <table>
          <thead>
            <tr>
              <th scope="col">Método</th>
              <th scope="col">Ruta</th>
              <th scope="col">Qué hace</th>
              <th scope="col">Quién</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in rutasFiltradas" :key="r.metodo + r.ruta">
              <td><span class="badge">{{ r.metodo }}</span></td>
              <th scope="row">
                <code>{{ r.ruta }}</code>
                <span v-if="r.vue" class="pill proyecto-vue">Vue</span>
              </th>
              <td>{{ r.hace }}</td>
              <td>{{ r.quien }}</td>
            </tr>
            <tr v-if="!rutasFiltradas.length">
              <td colspan="4" class="muted">Ninguna ruta coincide con la búsqueda.</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="safe-note">Las rutas marcadas con «Vue» son las que usan los componentes del catálogo y del carrito.</p>
    </section>
  </div>
</template>

<script>
import IconoUi from './IconoUi.vue';
import { UI } from '../tienda.js';
import datos from '../proyecto.json';

const TODOS = 'Todos';

export default {
  name: 'ProyectoVista',
  components: { IconoUi },
  data() {
    return {
      caracteristicas: datos.caracteristicas,
      criterios: datos.criterios,
      busqueda: '',
      grupo: TODOS,
    };
  },
  computed: {
    presentacion() {
      return UI.staticURL('descargas/TecnoTrade-Presentacion.pptx');
    },
    grupos() {
      return [TODOS, ...datos.apis.map((g) => g.grupo)];
    },
    // Todas las rutas en una sola lista, cada una con su grupo y quién la usa.
    rutas() {
      return datos.apis.flatMap((g) => g.rutas.map((r) => ({ ...r, grupo: g.grupo, quien: g.quien })));
    },
    rutasFiltradas() {
      const buscado = this.busqueda.trim().toLowerCase();
      return this.rutas.filter(
        (r) =>
          (this.grupo === TODOS || r.grupo === this.grupo) &&
          (!buscado || (r.ruta + ' ' + r.hace + ' ' + r.metodo).toLowerCase().includes(buscado)),
      );
    },
  },
};
</script>

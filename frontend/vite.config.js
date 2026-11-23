import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// Compila los componentes .vue en UN solo archivo JavaScript clásico (IIFE)
// que PHP sirve desde public/vue/. Así la tienda sigue funcionando en XAMPP
// sin Node.js: Node solo se necesita para volver a compilar tras un cambio.
//
// Se precompilan las plantillas porque la tienda envía la cabecera
// Content-Security-Policy "script-src 'self'": eso impide cargar Vue desde un
// CDN y también compilar plantillas en el navegador (requiere 'unsafe-eval').
export default defineConfig({
  plugins: [vue()],
  define: { 'process.env.NODE_ENV': JSON.stringify('production') },
  build: {
    outDir: '../public/vue',
    emptyOutDir: true,
    lib: {
      entry: 'src/main.js',
      formats: ['iife'],
      name: 'TecnoTradeVue',
      fileName: () => 'tienda-vue.js',
      cssFileName: 'tienda-vue',
    },
  },
});

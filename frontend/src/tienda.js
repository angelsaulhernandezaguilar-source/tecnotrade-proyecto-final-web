// Estado reactivo compartido por los componentes Vue.
//
// La tienda original (public/app.js) guarda su estado en un objeto normal de
// JavaScript y expone sus funciones en window.UI. Aquí se copia ese estado a
// un objeto reactive() de Vue: cuando cambia, todo componente que lo use se
// vuelve a dibujar solo. app.js avisa de cada cambio con el evento 'tt:state'.
import { reactive } from 'vue';

export const UI = window.UI;

export const tienda = reactive({
  usuario: null,
  categorias: [],
  carrito: [], // [{ productId, quantity }]
  favoritos: [], // [productId]
});

export function sincronizar() {
  const estado = UI.state;
  tienda.usuario = estado.user;
  tienda.categorias = estado.categories;
  tienda.carrito = estado.cart.map((articulo) => ({ ...articulo }));
  tienda.favoritos = [...estado.favorites];
}

document.addEventListener('tt:state', sincronizar);

// Precio vigente de un producto en centavos (incluye promociones del servidor).
export const precio = (producto) => UI.price(producto);
export const dinero = (centavos) => UI.money(centavos);

// Las operaciones pasan por la API PHP: el servidor valida existencias y sesión.
export async function agregarAlCarrito(producto, cantidad = 1) {
  await UI.addProduct(producto.id, cantidad);
}

export async function guardarCarrito(articulos) {
  await UI.saveCart(articulos.map(({ productId, quantity }) => ({ productId, quantity })));
}

export async function alternarFavorito(producto) {
  await UI.toggleFavorite(producto.id, false);
}

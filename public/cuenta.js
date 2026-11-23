/* Cuenta de cliente y autenticación. Las sesiones y los saldos pertenecen al servidor. */
(() => {
  'use strict';
  const P = window.PAGES = window.PAGES || {};
  const U = () => window.UI;
  const esc = value => U().esc(value);
  const money = cents => U().money(Number(cents) || 0);
  const date = value => U().date(value);
  const api = (path, options) => U().api(path, options);
  const go = route => U().go(route);
  const toast = message => U().toast(message);
  const errorText = error => error?.message || 'No fue posible completar la solicitud. Inténtalo de nuevo.';
  const formData = form => Object.fromEntries(new FormData(form));
  const isClient = () => U().state.user?.role === 'cliente';
  const badge = value => `<span class="badge">${esc(value || 'Pendiente')}</span>`;
  const field = (id, label, type = 'text', attrs = '') => `<div class="field"><label for="${id}">${label}</label><input id="${id}" name="${id}" type="${type}" ${attrs}></div>`;
  const formError = (form, message) => {
    let node = form.querySelector('[data-form-error]');
    if (!node) {
      node = document.createElement('p'); node.dataset.formError = ''; node.className = 'form-error'; node.setAttribute('role', 'alert'); form.prepend(node);
    }
    node.textContent = message; node.hidden = !message;
    if (message) { node.tabIndex = -1; node.focus(); }
  };
  const bindForm = (selector, action) => {
    document.querySelectorAll(selector).forEach(form => form.addEventListener('submit', async event => {
      event.preventDefault();
      if (form.dataset.busy === 'yes' || !form.reportValidity()) return;
      form.dataset.busy = 'yes'; formError(form, '');
      const buttons = [...form.querySelectorAll('button[type="submit"]')]; buttons.forEach(button => { button.disabled = true; });
      try { await action(form, formData(form)); }
      catch (error) { formError(form, errorText(error)); }
      finally { form.dataset.busy = ''; buttons.forEach(button => { button.disabled = false; }); }
    }));
  };
  const authShell = (title, description, html) => `<section class="page-shell container auth-layout"><div class="auth-intro"><a class="eyebrow" href="#/">TECNOTRADE · TU CUENTA</a><h1>${title}</h1><p class="muted">${description}</p><div class="auth-art"><img src="${U().assetURL('quality-check.svg')}" alt="Ilustración de revisión técnica de un dispositivo" width="400" height="300"></div><p class="muted small">Tu información, tus pedidos y tus solicitudes en un solo lugar.</p></div><div class="surface auth-card">${html}</div></section>`;
  const heading = (title, description = '') => `<div class="page-heading"><p class="eyebrow">MI CUENTA</p><h1>${title}</h1>${description ? `<p class="muted">${description}</p>` : ''}</div>`;
  const empty = (title, description) => U().empty(title, description);
  const localNext = value => typeof value === 'string' && /^\/[a-z][a-z0-9-]*(?:\?[^#]*)?$/i.test(value) ? value : '';

  P.login = async params => ({
    html: authShell('Qué bueno verte de nuevo.', 'Entra con tu correo y contraseña para continuar.', `<h2>Iniciar sesión</h2><form id="login-form">${field('email', 'Correo electrónico', 'email', 'required autocomplete="email" maxlength="254"')}${field('password', 'Contraseña', 'password', 'required autocomplete="current-password" maxlength="128"')}<div class="auth-options"><a class="text-link" href="#/forgot">¿Olvidaste tu contraseña?</a><button type="button" class="text-link" id="password-toggle" aria-controls="password" aria-pressed="false">Mostrar contraseña</button></div><button class="btn btn--primary btn--block" type="submit">Entrar a mi cuenta</button><p class="muted small">¿Es tu primera visita? <a class="text-link" href="#/register">Crear una cuenta</a></p></form><p class="notice small">El personal entra con la cuenta que le asignó administración. El registro público crea únicamente cuentas de cliente.</p>`),
    bind() {
      document.getElementById('password-toggle').addEventListener('click', event => {
        const input = document.getElementById('password'); const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password'; event.currentTarget.textContent = visible ? 'Ocultar contraseña' : 'Mostrar contraseña'; event.currentTarget.setAttribute('aria-pressed', String(visible));
      });
      bindForm('#login-form', async (_form, data) => {
        const result = await api('/auth/login', { method: 'POST', body: { email: data.email.trim(), password: data.password } });
        U().state.user = result.user; if (result.csrfToken) U().state.csrfToken = result.csrfToken;
        await U().refreshUser();
        toast('Sesión iniciada.');
        go(result.user.role === 'cliente' ? localNext(params.get('next')) || '/account' : '/panel');
      });
    }
  });

  P.register = async params => ({
    html: authShell('La tecnología empieza contigo.', 'Crea tu cuenta para comprar, dar seguimiento y solicitar un canje.', `<h2>Crear cuenta de cliente</h2><form id="register-form">${field('name', 'Nombre completo', 'text', 'required autocomplete="name" minlength="2" maxlength="100"')}${field('email', 'Correo electrónico', 'email', 'required autocomplete="email" maxlength="254"')}${field('password', 'Contraseña', 'password', 'required autocomplete="new-password" minlength="12" maxlength="128" aria-describedby="password-help"')}<p id="password-help" class="muted small">Usa al menos 12 caracteres y una contraseña que no utilices en otro servicio.</p>${field('confirmPassword', 'Confirma tu contraseña', 'password', 'required autocomplete="new-password" minlength="12" maxlength="128"')}<p class="muted small">Sólo necesitamos estos datos para crear tu cuenta. Podrás completar tu dirección al hacer un pedido.</p><button type="submit" class="btn btn--primary btn--block">Crear mi cuenta</button><p class="muted small">¿Ya tienes cuenta? <a class="text-link" href="#/login">Inicia sesión</a></p></form>`),
    bind() {
      bindForm('#register-form', async (_form, data) => {
        if (data.password !== data.confirmPassword) throw new Error('Las contraseñas no coinciden.');
        const result = await api('/auth/register', { method: 'POST', body: { name: data.name.trim(), email: data.email.trim(), password: data.password } });
        U().state.user = result.user; if (result.csrfToken) U().state.csrfToken = result.csrfToken;
        await U().refreshUser(); toast('Tu cuenta está lista.'); go(localNext(params.get('next')) || '/account');
      });
    }
  });

  P.forgot = async () => ({
    html: authShell('Recupera tu acceso.', 'Te ayudamos a volver a tu cuenta de forma segura.', `<h2>¿Olvidaste tu contraseña?</h2><form id="forgot-form"><p class="muted">Escribe el correo de tu cuenta. Si corresponde a una cuenta activa, recibirás un enlace para elegir una nueva contraseña.</p>${field('email', 'Correo electrónico', 'email', 'required autocomplete="email" maxlength="254"')}<button class="btn btn--primary btn--block" type="submit">Solicitar enlace de recuperación</button><div id="forgot-result" class="notice" role="status" hidden></div><a class="text-link" href="#/login">Volver a iniciar sesión</a></form>`),
    bind() {
      bindForm('#forgot-form', async (_form, data) => {
        await api('/auth/forgot-password', { method: 'POST', body: { email: data.email.trim() } });
        const result = document.getElementById('forgot-result');
        result.textContent = 'Si existe una cuenta activa con ese correo y el servicio de correo está disponible, recibirás un enlace de recuperación. Revisa también la carpeta de correo no deseado.'; result.hidden = false;
      });
    }
  });

  P.reset = async params => {
    const token = params.get('token') || '';
    return {
      html: authShell('Una nueva contraseña.', 'El enlace de recuperación es de uso único y tiene tiempo de vigencia.', !token ? `${empty('Falta el enlace de recuperación', 'Solicita un nuevo enlace desde la pantalla de recuperación.')}<a class="btn btn--primary" href="#/forgot">Solicitar otro enlace</a>` : `<h2>Restablecer contraseña</h2><form id="reset-form">${field('password', 'Nueva contraseña', 'password', 'required autocomplete="new-password" minlength="12" maxlength="128"')}${field('confirmPassword', 'Confirma la nueva contraseña', 'password', 'required autocomplete="new-password" minlength="12" maxlength="128"')}<p class="muted small">Usa al menos 12 caracteres. Después del cambio podrás iniciar sesión con la nueva contraseña.</p><button type="submit" class="btn btn--primary btn--block">Guardar nueva contraseña</button><a class="text-link" href="#/forgot">Solicitar otro enlace</a></form>`),
      bind() {
        bindForm('#reset-form', async (_form, data) => {
          if (data.password !== data.confirmPassword) throw new Error('Las contraseñas no coinciden.');
          await api('/auth/reset-password', { method: 'POST', body: { token, password: data.password } });
          await U().refreshUser();
          toast('Contraseña actualizada. Inicia sesión con tu nueva contraseña.'); go('/login');
        });
      }
    };
  };

  function accountShell(tab, content, title, description) {
    const user = U().state.user;
    const tabs = user.role === 'cliente' ? [['orders', 'Mis pedidos'], ['trades', 'Mis canjes'], ['wallet', 'Mi saldo'], ['favorites', 'Favoritos'], ['support', 'Soporte'], ['profile', 'Mis datos']] : [['profile', 'Mis datos']];
    return `<section class="page-shell container account-page">${heading(title, description)}<div class="account-layout"><aside class="surface account-sidebar"><div class="account-person"><span class="account-avatar" aria-hidden="true">${esc(user.name.trim().slice(0, 1).toLocaleUpperCase())}</span><strong>${esc(user.name)}</strong><span class="muted small">${esc(user.email)}</span></div><nav aria-label="Secciones de mi cuenta">${tabs.map(([key, label]) => `<a href="#/account?tab=${key}" ${key === tab ? 'aria-current="page" class="is-active"' : ''}>${label}</a>`).join('')}${user.role !== 'cliente' ? '<a href="#/panel">Panel del personal</a>' : ''}</nav><button class="btn btn--secondary btn--block" type="button" id="account-logout">Cerrar sesión</button></aside><div class="account-content">${content}</div></div></section>`;
  }
  function bindLogout() {
    document.getElementById('account-logout')?.addEventListener('click', async event => {
      event.currentTarget.disabled = true;
      try { const result = await api('/auth/logout', { method: 'POST', body: {} }); U().state.user = null; if (result.csrfToken) U().state.csrfToken = result.csrfToken; await U().refreshUser(); go('/'); toast('Cerraste tu sesión.'); }
      catch (error) { toast(errorText(error)); event.currentTarget.disabled = false; }
    });
  }
  const addressText = address => typeof address === 'string' ? address : [address?.recipient, address?.line, address?.city, address?.postalCode, address?.phone].filter(Boolean).join(' · ');
  const itemPrice = item => item.unitPriceCents ?? item.priceCents ?? 0;
  const orderItems = order => `<div class="order-items">${(order.items || []).map(item => `<div class="account-order-item"><span><strong>${esc(item.name)}</strong><small class="muted">${esc(item.condition || '')} · ${item.quantity} ${item.quantity === 1 ? 'pieza' : 'piezas'}</small></span><strong>${money(itemPrice(item) * item.quantity)}</strong></div>`).join('')}</div>`;

  async function ordersTab() {
    const { orders = [] } = await api('/orders');
    return {
      content: orders.length ? `<div class="stack">${orders.map(order => `<article class="surface account-order"><header><div><p class="eyebrow">PEDIDO ${esc(order.id)}</p><p class="muted small">${esc(date(order.createdAt))}</p></div>${badge(order.status)}</header>${orderItems(order)}<div class="account-order-summary"><span>Total <strong>${money(order.totalCents)}</strong></span>${order.walletAppliedCents > 0 ? `<span>Saldo aplicado <strong>${money(order.walletAppliedCents)}</strong></span>` : ''}${order.amountDueCents > 0 ? `<span>Pendiente <strong>${money(order.amountDueCents)}</strong></span>` : ''}</div>${order.amountDueCents > 0 && order.status !== 'Cancelado' ? '<p class="notice small">El pedido está registrado y tiene un importe pendiente. Esta página no cobra tarjetas. Contacta a soporte para recibir las instrucciones de pago de la tienda.</p>' : ''}<details><summary>Detalles y seguimiento</summary><div class="detail-content"><p><strong>Entrega:</strong> ${esc(addressText(order.address))}</p>${order.location ? `<p><strong>Ubicación:</strong> ${esc(order.location)}</p>` : ''}${order.tracking ? `<p><strong>Guía:</strong> ${esc(order.carrier || '')} · ${esc(order.tracking)}</p>` : ''}${(order.history || []).length ? `<ol class="activity-list">${order.history.map(event => `<li><strong>${esc(event.status || event.action || '')}</strong><span>${esc(date(event.createdAt))}</span>${event.location ? `<p>${esc(event.location)}</p>` : ''}</li>`).join('')}</ol>` : ''}</div></details><div class="actions"><a class="btn btn--secondary" href="#/account?tab=support&order=${encodeURIComponent(order.id)}">Pedir ayuda</a>${order.status === 'Pendiente de pago' && !order.tradeinId ? `<button type="button" class="btn btn--secondary" data-cancel-order="${esc(order.id)}">Cancelar pedido pendiente</button>` : ''}</div></article>`).join('')}</div>` : `${empty('Aún no tienes pedidos', 'Cuando completes un pedido, encontrarás aquí su importe y seguimiento.')}<a class="btn btn--primary" href="#/catalog">Explorar dispositivos</a>`,
      bind() {
        document.querySelectorAll('[data-cancel-order]').forEach(button => button.addEventListener('click', async () => {
          if (!confirm('¿Cancelar este pedido pendiente? Se liberarán sus existencias y se devolverá el saldo aplicado, cuando corresponda.')) return;
          button.disabled = true;
          try { await api(`/orders/${encodeURIComponent(button.dataset.cancelOrder)}/cancel`, { method: 'POST', body: {} }); toast('Pedido cancelado.'); go('/account?tab=orders'); }
          catch (error) { toast(errorText(error)); button.disabled = false; }
        }));
      }
    };
  }

  const shippingFields = (prefix, user) => `<div class="form-grid">${field(`${prefix}-recipient`, 'Nombre de quien recibe', 'text', `required minlength="2" maxlength="100" autocomplete="name" value="${esc(user.name)}"`)}${field(`${prefix}-phone`, 'Teléfono (10 dígitos)', 'tel', `required pattern="[0-9]{10}" maxlength="10" inputmode="numeric" autocomplete="tel" value="${esc(user.phone || '')}"`)}${field(`${prefix}-line`, 'Calle, número y colonia', 'text', `required minlength="8" maxlength="220" autocomplete="address-line1" value="${esc(user.address || '')}"`)}${field(`${prefix}-city`, 'Ciudad y estado', 'text', 'required minlength="2" maxlength="100" autocomplete="address-level2"')}${field(`${prefix}-postalCode`, 'Código postal', 'text', 'required pattern="[0-9]{5}" maxlength="5" inputmode="numeric" autocomplete="postal-code"')}</div>`;
  const shippingData = (data, prefix) => ({ recipient: data[`${prefix}-recipient`].trim(), phone: data[`${prefix}-phone`].trim(), line: data[`${prefix}-line`].trim(), city: data[`${prefix}-city`].trim(), postalCode: data[`${prefix}-postalCode`].trim() });

  async function tradesTab() {
    const { tradeins = [] } = await api('/tradeins');
    const user = U().state.user;
    return {
      content: `<div class="section-heading"><div><h2>Tus solicitudes de canje</h2><p class="muted">La estimación inicial no es una oferta definitiva. Tú decides si aceptas la valoración final.</p></div><a class="btn btn--secondary" href="#/trade">Solicitar un canje</a></div>${tradeins.length ? `<div class="stack">${tradeins.map((trade, index) => {
        const target = trade.targetProduct; const expectedPrice = target?.finalPriceCents ?? target?.priceCents; const prefix = `canje-${index}`;
        const diff = Number(expectedPrice || 0) - Number(trade.offerCents || 0);
        const targetReady = target && target.published && target.stock > 0 && (target.condition !== 'Seminuevo' || target.approved) && Number.isInteger(expectedPrice) && expectedPrice > 0;
        return `<article class="surface account-order"><header><div><p class="eyebrow">SOLICITUD ${esc(trade.id)}</p><h3>${esc(trade.modelKey)}</h3><p class="muted small">${esc(date(trade.createdAt))} · Condición declarada: ${esc(trade.condition)}</p></div>${badge(trade.status)}</header><div class="detail-content"><p><strong>Equipo elegido:</strong> ${esc(target?.name || trade.targetProductId)}</p>${trade.estimatedCents !== null && trade.estimatedCents !== undefined ? `<p>Estimación inicial: <strong>${money(trade.estimatedCents)}</strong></p>` : '<p class="muted">Valoración manual pendiente de revisión.</p>'}${trade.notes ? `<p class="muted">${esc(trade.notes)}</p>` : ''}${trade.inspection?.notes ? `<p><strong>Observaciones técnicas:</strong> ${esc(trade.inspection.notes)}</p>` : ''}${trade.inspection?.grade ? `<p class="muted">Grado estético: ${esc(trade.inspection.grade)}${Number.isInteger(trade.inspection.batteryPercent) ? ` · Batería: ${trade.inspection.batteryPercent}%` : ''}</p>` : ''}${trade.photos?.length ? `<div class="photo-grid">${trade.photos.filter(photo => /^\/media\/[a-z0-9-]+$/i.test(U().imagePath(photo.url))).map(photo => `<img src="${esc(U().safeImage(photo.url))}" alt="Foto enviada para revisión de este canje" loading="lazy" width="100" height="100">`).join('')}</div>` : ''}${trade.offerCents !== null && trade.offerCents !== undefined ? `<div class="offer-value"><span>Oferta final por tu equipo</span><strong>${money(trade.offerCents)}</strong></div>` : ''}${trade.status === 'Oferta disponible' && targetReady ? `<p class="notice">Precio actual del equipo elegido: <strong>${money(expectedPrice)}</strong>. ${diff > 0 ? `Diferencia antes de aplicar otro saldo: ${money(diff)}.` : diff < 0 ? `El excedente de ${money(-diff)} quedará como saldo a favor, sin retiro en efectivo.` : 'La oferta cubre el precio del equipo elegido.'}</p><details class="accept-trade"><summary class="btn btn--primary">Revisar y aceptar oferta</summary><form data-accept-trade="${esc(trade.id)}" data-price="${expectedPrice}" data-offer="${trade.offerCents}" data-prefix="${prefix}" data-key="${U().requestKey()}"><h3>Confirma los datos de entrega</h3>${shippingFields(prefix, user)}<label class="check-label"><input type="checkbox" name="useWallet" value="yes"> Aplicar también mi saldo a favor disponible.</label><label class="check-label"><input type="checkbox" required name="accepted" value="yes"> Acepto la oferta final y el precio mostrado. Entiendo que el excedente se conserva como saldo de tienda y que una diferencia positiva queda pendiente de pago.</label><button class="btn btn--primary" type="submit">Aceptar oferta y crear pedido</button></form></details>` : ''}${trade.status === 'Oferta disponible' && !targetReady ? '<p class="notice">El equipo elegido no tiene disponibilidad para completar el canje en este momento. Contacta a soporte antes de aceptar una oferta.</p>' : ''}${trade.orderId ? `<p class="notice">Esta solicitud está vinculada al pedido ${esc(trade.orderId)}.</p><a class="btn btn--secondary" href="#/account?tab=orders">Ver mis pedidos</a>` : ''}${trade.status === 'Rechazado' ? '<p class="notice">El equipo no fue aprobado. Puedes contactar a soporte para conocer los siguientes pasos.</p>' : ''}</div></article>`;
      }).join('')}</div>` : empty('Todavía no has solicitado un canje', 'Elige un modelo, describe su condición y solicita una revisión. No se abonará saldo hasta que aceptes una oferta final.')}`,
      bind() {
        bindForm('[data-accept-trade]', async (form, data) => {
          const result = await api(`/tradeins/${encodeURIComponent(form.dataset.acceptTrade)}/accept`, { method: 'POST', body: { expectedPriceCents: Number(form.dataset.price), expectedOfferCents: Number(form.dataset.offer), idempotencyKey: form.dataset.key, useWallet: data.useWallet === 'yes', address: shippingData(data, form.dataset.prefix) } });
          await U().refreshUser();
          toast(result.order?.amountDueCents > 0 ? 'Oferta aceptada. Tu pedido tiene una diferencia pendiente de pago.' : 'Oferta aceptada. Consulta el pedido y tu saldo actualizado.');
          go('/account?tab=orders');
        });
      }
    };
  }

  async function walletTab() {
    const { balanceCents = 0, entries = [] } = await api('/wallet');
    return {
      content: `<section class="surface wallet-balance"><p class="eyebrow">SALDO DISPONIBLE</p><h2>${money(balanceCents)}</h2><p class="muted">Puedes aplicarlo a tus compras. No es efectivo ni se genera por solicitar una estimación.</p><a class="btn btn--primary" href="#/catalog">Explorar dispositivos</a></section><section class="surface"><h2>Movimientos de saldo</h2>${entries.length ? `<div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Concepto</th><th>Movimiento</th><th>Saldo resultante</th></tr></thead><tbody>${entries.map(entry => `<tr><td>${esc(date(entry.createdAt))}</td><td>${esc(entry.reason)}</td><td>${entry.deltaCents > 0 ? '+' : ''}${money(entry.deltaCents)}</td><td>${money(entry.balanceAfterCents)}</td></tr>`).join('')}</tbody></table></div>` : empty('Tu saldo todavía no tiene movimientos', 'Los abonos y cargos confirmados por el servidor aparecerán aquí.')}</section>`
    };
  }

  async function favoritesTab() {
    const [{ productIds = [] }, { products = [] }] = await Promise.all([api('/favorites'), api('/products')]);
    const favorites = products.filter(product => productIds.includes(product.id));
    return {
      content: favorites.length ? `<div class="product-grid">${favorites.map(product => `<div>${U().card(product)}<button type="button" class="text-link" data-remove-favorite="${esc(product.id)}">Quitar de favoritos</button></div>`).join('')}</div>` : `${empty('Guarda lo que te gusta', 'Agrega dispositivos desde su ficha para encontrarlos fácilmente después. Los productos ocultos ya no se muestran aquí.')}<a href="#/catalog" class="btn btn--primary">Ver catálogo</a>`,
      bind() {
        document.querySelectorAll('[data-remove-favorite]').forEach(button => button.addEventListener('click', async () => {
          button.disabled = true;
          try { const next = productIds.filter(id => id !== button.dataset.removeFavorite); const result = await api('/favorites', { method: 'PUT', body: { productIds: next } }); U().state.favorites = result.productIds || next; toast('Dispositivo eliminado de favoritos.'); go('/account?tab=favorites'); }
          catch (error) { toast(errorText(error)); button.disabled = false; }
        }));
      }
    };
  }

  function profileTab() {
    const user = U().state.user;
    return {
      content: `<section class="surface"><h2>Tus datos</h2><p class="muted">Actualiza los datos de contacto de tu cuenta.</p><form id="profile-form">${field('name', 'Nombre completo', 'text', `required minlength="2" maxlength="100" autocomplete="name" value="${esc(user.name)}"`)}${field('email', 'Correo electrónico', 'email', `readonly aria-readonly="true" value="${esc(user.email)}"`)}${field('phone', 'Teléfono de contacto', 'tel', `maxlength="20" autocomplete="tel" value="${esc(user.phone || '')}"`)}<div class="field"><label for="address">Dirección de contacto</label><textarea id="address" name="address" maxlength="400" autocomplete="street-address">${esc(user.address || '')}</textarea></div><button class="btn btn--primary" type="submit">Guardar mis datos</button></form><hr><a class="text-link" href="#/forgot">Cambiar contraseña mediante enlace de recuperación</a></section>`,
      bind() {
        bindForm('#profile-form', async (_form, data) => { await api('/me', { method: 'PATCH', body: { name: data.name.trim(), phone: data.phone.trim(), address: data.address.trim() } }); await U().refreshUser(); toast('Datos actualizados.'); go('/account?tab=profile'); });
      }
    };
  }

  async function supportTab(params) {
    const { tickets = [] } = await api('/tickets');
    return {
      content: `<section class="surface"><h2>¿En qué podemos ayudarte?</h2><p class="muted">Consulta un pedido, una garantía o tu solicitud de canje. Respondemos dentro de esta cuenta.</p><form id="new-ticket-form">${field('orderId', 'Número de pedido (opcional)', 'text', `maxlength="100" value="${esc(params.get('order') || '')}"`)}<div class="field"><label for="reason">Motivo</label><select id="reason" name="reason" required><option>Estado de mi pedido</option><option>Garantía</option><option>Devolución</option><option>Pago</option><option>Canje</option><option>Otro</option></select></div><div class="field"><label for="message">Tu mensaje</label><textarea id="message" name="message" required minlength="10" maxlength="4000" placeholder="Describe lo que necesitas consultar."></textarea></div><button class="btn btn--primary" type="submit">Crear solicitud de soporte</button></form></section><section><h2>Tus conversaciones</h2>${tickets.length ? `<div class="stack">${tickets.map(ticket => `<article class="surface account-ticket"><header><div><p class="eyebrow">${esc(ticket.id)}</p><h3>${esc(ticket.reason)}</h3><p class="muted small">${esc(date(ticket.createdAt))}${ticket.orderId ? ` · Pedido ${esc(ticket.orderId)}` : ''}</p></div>${badge(ticket.status)}</header><div class="conversation">${(ticket.messages || []).map(message => `<div class="conversation-message ${message.role === 'cliente' ? 'is-customer' : 'is-staff'}"><strong>${esc(message.author || (message.role === 'cliente' ? 'Tú' : 'Soporte'))}</strong><small>${esc(date(message.createdAt))}</small><p>${esc(message.text || message.message)}</p></div>`).join('')}</div><form data-ticket-reply="${esc(ticket.id)}"><div class="field"><label for="reply-${esc(ticket.id)}">Agregar un mensaje</label><textarea id="reply-${esc(ticket.id)}" name="message" required minlength="2" maxlength="4000"></textarea></div><button class="btn btn--secondary" type="submit">Enviar mensaje</button></form></article>`).join('')}</div>` : empty('Aún no tienes conversaciones', 'Cuando envíes una solicitud podrás consultar aquí las respuestas.')}</section>`,
      bind() {
        bindForm('#new-ticket-form', async (_form, data) => { await api('/tickets', { method: 'POST', body: { orderId: data.orderId.trim() || null, reason: data.reason, message: data.message.trim() } }); toast('Solicitud de soporte creada.'); go('/account?tab=support'); });
        bindForm('[data-ticket-reply]', async (form, data) => { await api(`/tickets/${encodeURIComponent(form.dataset.ticketReply)}/messages`, { method: 'POST', body: { message: data.message.trim() } }); toast('Mensaje enviado.'); go('/account?tab=support'); });
      }
    };
  }

  P.account = async params => {
    const user = U().state.user;
    if (!user) return { html: `<section class="page-shell container">${heading('Tu cuenta te espera', 'Inicia sesión para consultar tus pedidos y tus solicitudes.')}<a class="btn btn--primary" href="#/login?next=%2Faccount">Iniciar sesión</a></section>` };
    const selected = params.get('tab') || (user.role === 'cliente' ? 'orders' : 'profile');
    const tab = user.role !== 'cliente' ? 'profile' : ['orders', 'trades', 'wallet', 'favorites', 'profile', 'support'].includes(selected) ? selected : 'orders';
    const titles = { orders: 'Mis pedidos', trades: 'Mis canjes', wallet: 'Mi saldo a favor', favorites: 'Mis favoritos', profile: 'Mis datos', support: 'Centro de ayuda' };
    const renderers = { orders: ordersTab, trades: tradesTab, wallet: walletTab, favorites: favoritesTab, profile: profileTab, support: () => supportTab(params) };
    const result = await renderers[tab]();
    return { html: accountShell(tab, result.content, titles[tab], `Hola, ${user.name.split(' ')[0]}. Este es tu espacio en TecnoTrade.`), bind() {
      bindLogout(); result.bind?.();
      const nav = document.querySelector('.account-sidebar nav');
      const active = nav?.querySelector('[aria-current="page"]');
      if (nav && active && matchMedia('(max-width:650px)').matches) {
        const rect = active.getBoundingClientRect(); const bounds = nav.getBoundingClientRect();
        nav.scrollLeft += rect.left - bounds.left - (nav.clientWidth - rect.width) / 2;
      }
    } };
  };
})();

/* Creación de Medicamentos — interfaz (JavaScript sin dependencias).
 * Los permisos del menú son solo visuales: el servidor (api.php) valida cada acción. */
'use strict';

const estado = { csrf: null, usuario: null, permisos: [], perfiles: [], modoPrueba: false };
const $ = (sel) => document.querySelector(sel);

// ------------------------------------------------------------------ utilidades
/** Crea elementos sin innerHTML con datos (evita inyección de HTML). */
function h(tag, attrs = {}, ...hijos) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else if (k === 'class') el.className = v;
    else if (k === 'dataset') Object.assign(el.dataset, v);
    else el.setAttribute(k, v === true ? '' : v);
  }
  for (const c of hijos.flat()) if (c !== null && c !== undefined && c !== false) el.append(c.nodeType ? c : String(c));
  return el;
}
const puede = (p) => estado.permisos.includes(p);
const chip = (texto, tipo = 'gris') => h('span', { class: `chip ${tipo}` }, texto);
const fecha = (v) => (v ? String(v).replace('T', ' ').slice(0, 16) : '—');
const miles = (n) => Number(n || 0).toLocaleString('es-CO');

function toast(msg, error = false) {
  const t = $('#toast');
  t.textContent = msg;
  t.className = `toast visible${error ? ' error' : ''}`;
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => (t.className = 'toast'), 3500);
}

async function api(accion, { metodo = 'GET', datos = null, params = {} } = {}) {
  const qs = new URLSearchParams({ accion, ...params });
  const opts = { method: metodo, credentials: 'same-origin', headers: {} };
  if (metodo === 'POST') {
    opts.headers['Content-Type'] = 'application/json';
    opts.headers['X-CSRF-Token'] = estado.csrf || '';
    opts.body = JSON.stringify(datos || {});
  }
  const r = await fetch(`api.php?${qs}`, opts);
  let cuerpo;
  try { cuerpo = await r.json(); } catch { cuerpo = { error: 'Respuesta inválida del servidor.' }; }
  if (!r.ok) {
    if (r.status === 401 && accion !== 'login') mostrarLogin();
    if (r.status === 403 && /cambiar su contraseña/i.test(cuerpo.error || '')) dialogoClave(true);
    throw new Error(cuerpo.error || `Error ${r.status}`);
  }
  return cuerpo;
}

/** Diálogo con formulario. campos: [{nombre, etiqueta, tipo, valor, opciones, requerido, ayuda}] */
/**
 * Confirmación con el diálogo propio del aplicativo. No se usa confirm() del navegador porque
 * algunos navegadores (p.ej. el integrado) lo bloquean y responden "cancelar" sin mostrarlo.
 */
function confirmar(titulo, texto, aceptar = 'Confirmar') {
  return new Promise((resolver) => {
    let ok = false;
    dialogo({ titulo, texto, aceptar, alEnviar: async () => { ok = true; }, alCerrar: () => resolver(ok) });
  });
}

/**
 * Campo de texto con lista de sugerencias (reemplaza <datalist>, que el navegador muestra oscuro y desalineado):
 * lista blanca del ancho del campo, filtra al escribir, flechas ↑ ↓ + Enter para elegir, Esc para cerrar.
 * Se puede escribir un valor que no esté en la lista. valores: ['A', 'B'] o [[valor, etiqueta]].
 */
function autocompletar(input, valores) {
  const ops = valores.map((v) => (Array.isArray(v) ? v : [v, v]));
  const panel = h('div', { class: 'auto-panel', hidden: true, role: 'listbox' });
  const caja = h('div', { class: 'auto-caja' }, input, panel);
  input.setAttribute('autocomplete', 'off');
  let activo = -1; let visibles = []; let eligiendo = false;
  const ubicar = () => {
    const r = input.getBoundingClientRect();
    const abajo = window.innerHeight - r.bottom; const alto = Math.min(260, Math.max(abajo, r.top) - 12);
    panel.style.left = `${r.left}px`; panel.style.width = `${r.width}px`; panel.style.maxHeight = `${alto}px`;
    if (abajo < 160 && r.top > abajo) { panel.style.top = ''; panel.style.bottom = `${window.innerHeight - r.top + 2}px`; } else { panel.style.bottom = ''; panel.style.top = `${r.bottom + 2}px`; }
  };
  const pintar = () => {
    const q = input.value.trim().toUpperCase();
    visibles = ops.filter(([v, t]) => !q || String(t).toUpperCase().includes(q) || String(v).toUpperCase().includes(q)).slice(0, 200);
    activo = -1;
    panel.replaceChildren(...(visibles.length ? visibles.map(([v, t], i) => h('div', { class: 'auto-op', role: 'option', dataset: { i },
      onmousedown: (ev) => { ev.preventDefault(); elegir(i); } }, t)) : [h('div', { class: 'auto-vacio' }, 'Sin coincidencias: se usará lo escrito')]));
  };
  const abrir = () => { pintar(); panel.hidden = false; ubicar(); };
  const cerrar = () => { panel.hidden = true; };
  const elegir = (i) => {
    const op = visibles[i]; if (!op) return;
    input.value = op[0]; cerrar();
    eligiendo = true; // el aviso de cambio no debe volver a abrir la lista
    input.dispatchEvent(new Event('input', { bubbles: true })); input.dispatchEvent(new Event('change', { bubbles: true }));
    eligiendo = false;
  };
  const marcar = (i) => {
    const hijos = [...panel.querySelectorAll('.auto-op')]; if (!hijos.length) return;
    activo = (i + hijos.length) % hijos.length;
    hijos.forEach((x, k) => x.classList.toggle('activo', k === activo)); hijos[activo].scrollIntoView({ block: 'nearest' });
  };
  input.addEventListener('focus', abrir);
  input.addEventListener('click', () => { if (panel.hidden) abrir(); });
  input.addEventListener('input', () => { if (eligiendo) return; if (panel.hidden) abrir(); else { pintar(); ubicar(); } });
  input.addEventListener('blur', () => setTimeout(cerrar, 120));
  input.addEventListener('keydown', (ev) => {
    if (panel.hidden && ['ArrowDown', 'ArrowUp'].includes(ev.key)) { abrir(); ev.preventDefault(); return; }
    if (ev.key === 'ArrowDown') { marcar(activo + 1); ev.preventDefault(); }
    else if (ev.key === 'ArrowUp') { marcar(activo - 1); ev.preventDefault(); }
    else if (ev.key === 'Enter' && !panel.hidden && activo >= 0) { elegir(activo); ev.preventDefault(); }
    else if (ev.key === 'Escape' && !panel.hidden) { cerrar(); ev.preventDefault(); ev.stopPropagation(); }
  });
  // si se desplaza la ventana o la página, la lista se reubica junto al campo
  document.addEventListener('scroll', (ev) => { if (!panel.hidden && !panel.contains(ev.target)) ubicar(); }, true);
  return caja;
}

function dialogo({ titulo, texto, campos = [], aceptar = 'Guardar', cerrable = true, alEnviar, alCerrar }) {
  const dlg = $('#dialogo');
  dlg.onclose = () => { dlg.onclose = null; if (alCerrar) alCerrar(); };
  const form = $('#dialogo-form');
  form.replaceChildren();
  form.append(h('h2', {}, titulo));
  if (texto) form.append(h('p', { class: 'nota' }, texto));
  for (const c of campos) {
    let control;
    if (c.tipo === 'select') {
      control = h('select', { name: c.nombre }, ...c.opciones.map(([v, t]) => h('option', { value: v, selected: String(v) === String(c.valor ?? '') }, t)));
    } else if (c.tipo === 'checks') {
      control = h('div', { class: 'fila-acciones' }, ...c.opciones.map(([v, t]) =>
        h('label', { style: 'display:flex;gap:6px;font-weight:400' }, h('input', { type: 'checkbox', name: c.nombre, value: v, checked: (c.valor || []).includes(v) }), t)));
    } else if (c.tipo === 'checkbox') {
      control = h('input', { type: 'checkbox', name: c.nombre, checked: !!c.valor });
    } else if (c.tipo === 'solo_lectura') {
      control = h('input', { value: c.valor ?? '', readonly: true, disabled: true });
    } else {
      control = h('input', { name: c.nombre, type: c.tipo || 'text', value: c.valor ?? '', required: c.requerido,
        placeholder: c.placeholder, autocomplete: c.tipo === 'password' ? 'new-password' : 'off' });
      if (c.sugerencias) control = autocompletar(control, c.sugerencias); // lista blanca alineada al campo
    }
    form.append(h('label', {}, c.etiqueta, control, c.ayuda ? h('span', { class: 'nota' }, c.ayuda) : null));
  }
  const err = h('p', { class: 'error', role: 'alert' });
  form.append(err, h('div', { class: 'fila-acciones' },
    cerrable ? h('button', { class: 'btn sec', value: 'cancelar', formnovalidate: true }, 'Cancelar') : null,
    h('button', { class: 'btn primario derecha', value: 'ok' }, aceptar)));
  form.onsubmit = async (ev) => {
    if (ev.submitter?.value === 'cancelar') return;
    ev.preventDefault();
    const fd = new FormData(form);
    const valores = {};
    for (const c of campos) {
      if (c.tipo === 'solo_lectura') continue;
      valores[c.nombre] = c.tipo === 'checks' ? fd.getAll(c.nombre) : c.tipo === 'checkbox' ? fd.has(c.nombre) : (fd.get(c.nombre) ?? '').toString();
    }
    try { err.textContent = ''; await alEnviar(valores); dlg.close(); } catch (e) { err.textContent = e.message; }
  };
  dlg.oncancel = (ev) => { if (!cerrable) ev.preventDefault(); };
  dlg.showModal();
}

function dialogoClave(obligatorio = false) {
  dialogo({
    titulo: obligatorio ? 'Debe cambiar su contraseña temporal' : 'Cambiar contraseña',
    texto: 'Mínimo 10 caracteres, con letras y números.',
    cerrable: !obligatorio,
    aceptar: 'Cambiar contraseña',
    campos: [
      { nombre: 'actual', etiqueta: 'Contraseña actual', tipo: 'password', requerido: true },
      { nombre: 'nueva', etiqueta: 'Nueva contraseña', tipo: 'password', requerido: true },
      { nombre: 'confirmar', etiqueta: 'Confirmar nueva contraseña', tipo: 'password', requerido: true },
    ],
    alEnviar: async (v) => {
      if (v.nueva !== v.confirmar) throw new Error('La confirmación no coincide.');
      await api('cambiar_clave', { metodo: 'POST', datos: v });
      estado.usuario.debe_cambiar_clave = false;
      toast('Contraseña actualizada.');
      if (obligatorio) navegar();
    },
  });
}

/**
 * FILTRO POR COLUMNA COMO EL AUTOFILTRO DE EXCEL: el botón del encabezado abre la lista de valores de la
 * columna (con los demás filtros ya aplicados) con casillas, «(Seleccionar todo)» y un buscador.
 * Si se escribe «259», la lista muestra solo las coincidencias y se marcan las que se quieran.
 *   obtener(busqueda) -> Promise<{ valores: string[], mas?: boolean }>
 *   etiquetas: { valor: texto } para mostrar (p.ej. 1 -> Activo). alAplicar(null | valores[]).
 */
let filtroAbierto = null;
function cerrarFiltroAbierto() { if (filtroAbierto) { filtroAbierto.remove(); filtroAbierto = null; } }
document.addEventListener('mousedown', (e) => { if (filtroAbierto && !filtroAbierto.contains(e.target) && !e.target.closest('.filtro-btn')) cerrarFiltroAbierto(); });
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') cerrarFiltroAbierto(); });
window.addEventListener('resize', cerrarFiltroAbierto);

function filtroExcel({ titulo, obtener, etiquetas = {}, alAplicar }) {
  let seleccion = null; // null = todos (sin filtro)
  const etiqueta = (v) => (v === '' ? '(Vacías)' : (etiquetas[v] ?? v));
  const boton = h('button', { type: 'button', class: 'filtro-btn', 'aria-label': `Filtrar ${titulo}`, onclick: (e) => { e.stopPropagation(); filtroAbierto?.dataset.dueno === titulo ? cerrarFiltroAbierto() : abrir(); } });
  const pintar = () => {
    boton.textContent = seleccion === null ? 'Todos ▾' : seleccion.length === 1 ? `${etiqueta(seleccion[0])} ▾` : `${seleccion.length} valores ▾`;
    boton.title = seleccion === null ? `Filtrar ${titulo}` : seleccion.map(etiqueta).join(', ').slice(0, 300);
    boton.classList.toggle('activo', seleccion !== null);
  };
  pintar();

  function abrir() {
    cerrarFiltroAbierto();
    const buscar = h('input', { type: 'search', placeholder: 'Buscar…', 'aria-label': `Buscar en ${titulo}` });
    const todos = h('input', { type: 'checkbox', checked: true });
    const lista = h('div', { class: 'filtro-lista' }, h('div', { class: 'nota' }, 'Cargando…'));
    const aviso = h('div', { class: 'nota' });
    // como Excel: al buscar, «Agregar la selección actual al filtro» suma lo marcado a lo que ya estaba filtrado
    const agregar = h('input', { type: 'checkbox' });
    const filaAgregar = h('label', { class: 'filtro-agregar', hidden: true }, agregar, h('span', {}, 'Agregar la selección actual al filtro'));
    let casillas = [];
    let hayMas = false;
    let t;
    async function cargarLista() {
      const b = buscar.value.trim();
      let r;
      try { r = await obtener(b); } catch (e) { lista.replaceChildren(h('div', { class: 'error' }, e.message)); return; }
      hayMas = !!r.mas;
      casillas = r.valores.map((v) => {
        const chk = h('input', { type: 'checkbox', checked: b !== '' || seleccion === null || seleccion.includes(v) });
        chk.addEventListener('change', sincronizar);
        return { v, chk, fila: h('label', { title: etiqueta(v) }, chk, h('span', {}, etiqueta(v))) };
      });
      lista.replaceChildren(...(casillas.length ? casillas.map((c) => c.fila) : [h('div', { class: 'nota' }, 'Sin coincidencias')]));
      aviso.textContent = hayMas ? 'Se muestran los primeros 1.000 valores: escriba para buscar el que necesita.' : '';
      filaAgregar.hidden = !(b !== '' && seleccion !== null); // solo con búsqueda y un filtro ya aplicado
      if (filaAgregar.hidden) agregar.checked = false;
      sincronizar();
    }
    function sincronizar() {
      const n = casillas.filter((c) => c.chk.checked).length;
      todos.checked = casillas.length > 0 && n === casillas.length;
      todos.indeterminate = n > 0 && n < casillas.length;
    }
    todos.addEventListener('change', () => { casillas.forEach((c) => { c.chk.checked = todos.checked; }); sincronizar(); });
    buscar.addEventListener('input', () => { clearTimeout(t); t = setTimeout(cargarLista, 250); });
    const aplicar = () => {
      const marcados = casillas.filter((c) => c.chk.checked).map((c) => c.v);
      if (!marcados.length) { aviso.textContent = 'Seleccione al menos un valor.'; return; }
      // Sin búsqueda y todo marcado = sin filtro. Con búsqueda: solo lo marcado de lo encontrado (como Excel),
      // o, con «Agregar la selección actual al filtro», lo que ya estaba filtrado más lo marcado.
      if (agregar.checked && seleccion !== null) seleccion = [...new Set([...seleccion, ...marcados])];
      else seleccion = !buscar.value.trim() && !hayMas && marcados.length === casillas.length ? null : marcados;
      pintar(); cerrarFiltroAbierto(); alAplicar(seleccion);
    };
    buscar.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); aplicar(); } });
    const pop = h('div', { class: 'filtro-pop', role: 'dialog', 'aria-label': `Filtro de ${titulo}`, dataset: { dueno: titulo } },
      h('strong', {}, titulo), buscar,
      h('label', { class: 'filtro-todos' }, todos, h('span', {}, '(Seleccionar todo)')),
      filaAgregar, lista, aviso,
      h('div', { class: 'fila-acciones' },
        h('button', { type: 'button', class: 'btn sec chico', onclick: () => { seleccion = null; pintar(); cerrarFiltroAbierto(); alAplicar(null); } }, 'Borrar filtro'),
        h('button', { type: 'button', class: 'btn sec chico', onclick: cerrarFiltroAbierto }, 'Cancelar'),
        h('button', { type: 'button', class: 'btn primario chico derecha', onclick: aplicar }, 'Aceptar')));
    document.body.append(pop);
    filtroAbierto = pop;
    const r = boton.getBoundingClientRect();
    pop.style.left = `${Math.max(8, Math.min(r.left, window.innerWidth - pop.offsetWidth - 8))}px`;
    pop.style.top = `${Math.min(r.bottom + 4, window.innerHeight - pop.offsetHeight - 8)}px`;
    buscar.focus();
    cargarLista();
  }
  return { boton, limpiar: () => { seleccion = null; pintar(); }, establecer: (sel) => { seleccion = sel; pintar(); }, activo: () => seleccion !== null };
}

/**
 * Tabla paginada en servidor con FILTROS POR COLUMNA en el encabezado (autofiltro como Excel).
 * Columna: { titulo, campo, valor?(fila), num?, filtro?, clase?(fila) }
 *   clase: clase CSS de la celda según el valor. opciones.clase: clase de la tabla (p.ej. 'tabla-excel').
 *   filtro: omitido -> sobre `campo`; false -> sin filtro; 'estado' -> 1/0 como Activo/Inactivo;
 *           { campo, opciones: [[valor, texto]] } -> filtra otro campo y muestra esos textos.
 * cargar(params) recibe { pagina, filtros } o, para la lista del filtro, { filtros, valores_de, busqueda }.
 * El alto se ajusta a la pantalla (scroll propio con encabezado fijo): la barra horizontal
 * siempre queda visible sin bajar hasta el final de la página.
 */
/**
 * Memoria de filtros por tabla (decisión del usuario): al ir a otra pantalla y volver, cada tabla conserva
 * sus filtros y su página mientras la pestaña del navegador siga abierta (sessionStorage; se borra al salir).
 * La clave es la pantalla + los títulos de las columnas (+ una clave extra si la misma pantalla muestra varias tablas).
 */
const MEMORIA_FILTROS = 'ci-filtros:';
function claveFiltros(titulos, extra = '') { return `${MEMORIA_FILTROS}${location.hash || '#inicio'}:${extra}:${titulos.join('|')}`; }
function leerFiltros(clave) { try { return JSON.parse(sessionStorage.getItem(clave) || 'null'); } catch { return null; } }
function guardarFiltros(clave, valor) { try { sessionStorage.setItem(clave, JSON.stringify(valor)); } catch { /* sin almacenamiento: no se recuerda */ } }
function olvidarFiltros() {
  try { Object.keys(sessionStorage).filter((k) => k.startsWith(MEMORIA_FILTROS)).forEach((k) => sessionStorage.removeItem(k)); } catch { /* nada */ }
}

function tablaPaginada({ cargar, columnas, contenedor, clase = null, alCargar = null, clave = '' }) {
  const memoria = claveFiltros(columnas.map((c) => c.titulo || ''), clave);
  const recordado = leerFiltros(memoria);
  let pagina = Math.max(1, Number(recordado?.pagina) || 1);
  const filtros = { ...(recordado?.filtros || {}) };
  const controles = [];

  const esEstado = (c) => c.filtro === 'estado' || (c.filtro === undefined && ['activo', 'habilitado'].includes(c.campo));
  const celdaFiltro = (c) => {
    if (c.filtro === false || (!c.campo && !(c.filtro && c.filtro.campo))) return h('th', { class: 'filtro' });
    const campo = c.filtro?.campo || c.campo;
    const etiquetas = esEstado(c) ? { 1: 'Activo', 0: 'Inactivo' } : Object.fromEntries(c.filtro?.opciones || []);
    const f = filtroExcel({
      titulo: c.titulo, etiquetas,
      obtener: (busqueda) => cargar({ pagina: 1, filtros: JSON.stringify(filtros), valores_de: campo, busqueda }),
      alAplicar: (sel) => { if (sel === null) delete filtros[campo]; else filtros[campo] = sel; pagina = 1; refrescar(); },
    });
    controles.push({ campo, f });
    return h('th', { class: 'filtro' }, f.boton);
  };

  const tbody = h('tbody');
  const caja = h('div', { class: 'tabla-caja' }, h('table', { class: clase },
    h('thead', {}, h('tr', {}, ...columnas.map((c) => h('th', {}, c.titulo))), h('tr', {}, ...columnas.map(celdaFiltro))),
    tbody));
  controles.forEach((c) => { if (filtros[c.campo] !== undefined) c.f.establecer(filtros[c.campo]); });
  const pie = h('div', { class: 'paginacion' });
  const limpiar = botonBorrarFiltros(() => {
    Object.keys(filtros).forEach((k) => delete filtros[k]);
    controles.forEach((c) => c.f.limpiar());
    pagina = 1; refrescar();
  });
  /** Reemplaza los filtros por los indicados, p.ej. { estado: ['ERROR'] } (indicadores clicables del resumen). */
  const filtrar = (nuevos) => {
    Object.keys(filtros).forEach((k) => delete filtros[k]);
    Object.assign(filtros, nuevos);
    controles.forEach((c) => c.f.establecer(nuevos[c.campo] ?? null));
    pagina = 1; refrescar();
    caja.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  async function refrescar() {
    try {
      const r = await cargar({ pagina, filtros: JSON.stringify(filtros) });
      // página recordada que ya no existe (p.ej. cambiaron los datos): vuelve a la primera
      if (!r.filas.length && pagina > 1) { pagina = 1; return refrescar(); }
      guardarFiltros(memoria, { filtros, pagina });
      const filas = r.filas.map((f) => h('tr', {}, ...columnas.map((c) => h('td', { class: [c.num ? 'num' : '', c.clase ? c.clase(f) : ''].join(' ').trim() || null },
        c.valor ? c.valor(f) : (f[c.campo] ?? '')))));
      if (alCargar) alCargar(r);
      tbody.replaceChildren(...(filas.length ? filas : [h('tr', {}, h('td', { colspan: columnas.length, class: 'nota' }, 'Sin resultados'))]));
      const paginas = Math.max(1, Math.ceil(r.total / r.tam));
      limpiar.actualizar(Object.keys(filtros).length);
      // números de página: primera, última y dos a cada lado de la actual (… en los saltos)
      const ir = (n) => { pagina = n; refrescar(); };
      const nums = [...new Set([1, paginas, ...[-2, -1, 0, 1, 2].map((d) => pagina + d)])].filter((n) => n >= 1 && n <= paginas).sort((a, b) => a - b);
      const botones = [];
      nums.forEach((n, i) => {
        if (i && n - nums[i - 1] > 1) botones.push(h('span', { class: 'pag-salto' }, '…'));
        botones.push(h('button', { type: 'button', class: `pag-num${n === pagina ? ' activo' : ''}`, disabled: n === pagina, onclick: () => ir(n) }, miles(n)));
      });
      pie.replaceChildren(
        h('span', { class: 'pag-total' }, `${miles(r.total)} registros · página ${miles(pagina)} de ${miles(paginas)}`), h('span', { class: 'espacio' }),
        h('button', { type: 'button', class: 'btn sec chico', disabled: pagina <= 1, onclick: () => ir(pagina - 1) }, '‹ Anterior'),
        ...botones,
        h('button', { type: 'button', class: 'btn sec chico', disabled: pagina >= paginas, onclick: () => ir(pagina + 1) }, 'Siguiente ›'));
      ajustarAltoTablas();
    } catch (e) { tbody.replaceChildren(h('tr', {}, h('td', { colspan: columnas.length, class: 'error' }, e.message))); }
  }
  contenedor.append(h('div', { class: 'barra-tabla' }, limpiar), caja, pie);
  refrescar();
  return { refrescar, filtrar, filtros: () => JSON.parse(JSON.stringify(filtros)) };
}

/** Botón "Borrar filtros": siempre visible sobre la tabla; muestra cuántos filtros hay activos. */
function botonBorrarFiltros(alBorrar) {
  const b = h('button', { class: 'btn sec chico', disabled: true, onclick: alBorrar }, '✕ Borrar filtros');
  b.actualizar = (n) => { b.disabled = n === 0; b.textContent = n ? `✕ Borrar filtros (${n})` : '✕ Borrar filtros'; };
  return b;
}

/**
 * Ajusta el alto de cada tabla al espacio visible de la ventana (desde donde empieza la tabla
 * hasta el borde inferior), para que su barra de desplazamiento horizontal siempre se vea.
 */
function ajustarAltoTablas() {
  requestAnimationFrame(() => {
    document.querySelectorAll('.pagina .tabla-caja:not(.fija)').forEach((el) => {
      const arriba = Math.max(el.getBoundingClientRect().top, 80);
      el.style.maxHeight = `${Math.max(260, window.innerHeight - arriba - 70)}px`;
    });
  });
}
window.addEventListener('resize', () => { clearTimeout(ajustarAltoTablas.t); ajustarAltoTablas.t = setTimeout(ajustarAltoTablas, 120); });
// Abrir/cerrar un panel desplegable mueve las tablas de posición: se recalcula su alto.
document.addEventListener('toggle', ajustarAltoTablas, true);
// Menús ⋮ / Exportar: la lista flota sobre la pantalla (position: fixed) junto al botón, para que el desplazamiento de
// una tabla no la recorte; si no cabe abajo se abre hacia arriba. Se cierra al elegir, al hacer clic fuera o al desplazar.
document.addEventListener('toggle', (ev) => {
  const det = ev.target;
  if (!(det instanceof HTMLDetailsElement) || !det.classList.contains('menu-exportar') || !det.open) return;
  document.querySelectorAll('details.menu-exportar[open]').forEach((o) => { if (o !== det) o.open = false; });
  const lista = det.querySelector(':scope > .menu-lista');
  const r = det.querySelector(':scope > summary').getBoundingClientRect();
  if (!lista) return;
  lista.classList.add('flotante');
  const alto = lista.offsetHeight; const ancho = lista.offsetWidth;
  const arriba = r.bottom + 4 + alto > window.innerHeight - 8 && r.top - 4 - alto > 8;
  lista.style.top = `${arriba ? r.top - 4 - alto : r.bottom + 4}px`;
  lista.style.left = `${Math.max(8, Math.min(r.right - ancho, window.innerWidth - ancho - 8))}px`;
}, true);
const cerrarMenus = (ev) => document.querySelectorAll('details.menu-exportar[open]').forEach((d) => {
  if (!ev || !d.contains(ev.target) || ev.target.closest('.menu-opcion')) d.open = false;
});
document.addEventListener('click', cerrarMenus);
document.addEventListener('scroll', (ev) => { if (!(ev.target instanceof Element && ev.target.closest('.menu-lista'))) cerrarMenus(); }, true);
window.addEventListener('resize', () => cerrarMenus());

// ------------------------------------------------------------------ flujo del proceso
const ETAPAS = [
  ['Solicitudes', 'Captura de ítems'],
  ['Bases', 'Cargar / actualizar'],
  ['Validar', 'Reglas automáticas'],
  ['Resultado', 'Validado · Alerta · Error'],
  ['Corregir', 'Alertas y errores'],
  ['Crear', 'Medicamentos / productos'],
  ['Archivos', '6 archivos de cargue'],
  ['Registrar', 'f120_id y auditoría'],
];
function flujo(actual = -1, hechas = 0) {
  return h('div', { class: 'flujo', 'aria-label': 'Etapas del proceso' },
    ...ETAPAS.map(([t, s], i) => h('div', { class: `paso${i === actual ? ' actual' : i < hechas ? ' hecho' : ''}` }, t, h('small', {}, s))));
}

// ------------------------------------------------------------------ páginas
/** Íconos del menú (trazos simples, heredan el color). */
const ICONOS = {
  inicio: '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
  crear: '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M12 12l8-4.5M12 12v9M12 12L4 7.5"/>',
  bases: '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
  nota: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
  catalogos: '<path d="M3 5h6a3 3 0 0 1 3 3v12a2 2 0 0 0-2-2H3zM21 5h-6a3 3 0 0 0-3 3v12a2 2 0 0 1 2-2h7z"/>',
  reglas: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
  usuarios: '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c1-3.5 3.8-5.5 7-5.5s6 2 7 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5c2 .8 3.4 2.8 4 5.5"/>',
  perfiles: '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/><path d="M9 12l2 2 4-4"/>',
  parametros: '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
  auditoria: '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M9 10h6M9 14h6M9 18h3"/>',
  correcciones: '<path d="M14.5 5.5a4 4 0 0 0-5.3 5.1L3.5 16.3a1.7 1.7 0 0 0 2.4 2.4l5.7-5.7a4 4 0 0 0 5.1-5.3l-2.4 2.4-2.2-.4-.4-2.2z"/><path d="M15 17l2 2 4-4"/>',
};
const ICONOS_UI = {
  buscar: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
  subir: '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
  bajar: '<path d="M12 4v12M7 11l5 5 5-5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
  mas: '<path d="M12 5v14M5 12h14"/>',
  recargar: '<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 5v6h-6"/>',
  ojo: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
  puntos: '<circle cx="12" cy="5" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="12" cy="19" r="1.4"/>',
  crear: '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M12 12l8-4.5M12 12v9M12 12L4 7.5"/>',
  carpeta: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
  aprobar: '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/><path d="M9 12l2 2 4-4"/>',
  error: '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5v.5"/>',
  alerta: '<path d="M12 3l10 18H2z"/><path d="M12 10v4.5M12 17.5v.5"/>',
  ok: '<circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/>',
  pendiente: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.5"/>',
  reloj: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  capas: '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
  llave: '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3M15 8l2 2"/>',
  lista: '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
  archivo: '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
  editar: '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M14 6l4 4"/>',
  quitar: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
};
const svgIcono = (nombre, clase = 'ico') => { const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('class', clase); s.setAttribute('aria-hidden', 'true'); s.innerHTML = ICONOS_UI[nombre] || ''; return s; };
const icono = (id, clase = 'ico') => { const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('class', clase); s.setAttribute('aria-hidden', 'true'); s.innerHTML = ICONOS[id] || ''; return s; };

const PAGINAS = [
  { id: 'inicio', titulo: 'Inicio', encabezado: 'Centro de control', sub: 'Estado de las bases, productos y validaciones del Maestro de Medicamentos', grupo: 'Proceso', render: pInicio },
  { id: 'crear', titulo: 'Crear productos', sub: 'Carga, valida y crea productos en un solo flujo', grupo: 'Proceso', permiso: 'PRODUCTO.CREAR', render: (c) => pCrear(c) },
  { id: 'bases', titulo: 'Bases de referencia', sub: 'Bases externas que hacen posibles las validaciones. Al cargar un archivo se verifica y se lee; si no tiene la estructura esperada, se rechaza y sigue la versión anterior.', grupo: 'Proceso', render: pBases },
  { id: 'correcciones', titulo: 'Inconsistencias', encabezado: 'Inconsistencias SIESA y MEDICAR', sub: 'La Maestra Tarifaria manda: lo que no coincide en SIESA o en MEDICAR queda aquí con su estado y se descarga en los archivos de cargue de cada sistema.', grupo: 'Proceso', permiso: 'CORRECCION.CONSULTAR', render: pCorrecciones },
  { id: 'nota', titulo: 'Maestra Tarifaria', grupo: 'Consulta', permiso: 'NT.CONSULTAR', render: pNota },
  { id: 'catalogos', titulo: 'Catálogos', grupo: 'Consulta', render: pCatalogos },
  { id: 'reglas', titulo: 'Reglas de validación', sub: 'ERROR bloquea la creación del producto (o marca la línea de la Maestra Tarifaria); ALERTA requiere revisión o aprobación.', grupo: 'Consulta', render: pReglas },
  { id: 'usuarios', titulo: 'Usuarios', sub: 'Quién ingresa al aplicativo y con qué perfiles. No se borran: se inactivan.', grupo: 'Administración', permiso: 'USUARIO.GESTIONAR', render: pUsuarios },
  { id: 'perfiles', titulo: 'Perfiles y permisos', sub: 'Usuarios → perfiles → permisos → módulos. Los cambios aplican de inmediato a los usuarios del perfil y quedan en auditoría.', grupo: 'Administración', permiso: 'PARAMETRO.ADMINISTRAR', render: pPerfiles },
  { id: 'parametros', titulo: 'Parámetros', sub: 'Valores que usa el aplicativo (lote, lista de precios, año del precio…). Cada cambio queda en auditoría.', grupo: 'Administración', permiso: 'PARAMETRO.ADMINISTRAR', render: pParametros },
  { id: 'auditoria', titulo: 'Auditoría', sub: 'Todo lo que se hace en el aplicativo, con fecha, usuario y el valor anterior y el nuevo.', grupo: 'Administración', permiso: 'AUDITORIA.CONSULTAR', render: pAuditoria },
];

function construirMenu() {
  const nav = $('#nav');
  nav.replaceChildren();
  let grupo = null;
  for (const p of PAGINAS) {
    if (p.permiso && !puede(p.permiso)) continue;
    if (p.grupo !== grupo) { grupo = p.grupo; nav.append(h('div', { class: 'grupo' }, grupo)); }
    nav.append(h('a', { href: `#${p.id}`, dataset: { id: p.id } }, h('span', { class: 'nav-item' }, icono(p.id), p.titulo), p.pronto ? h('span', { class: 'pronto' }, 'Próx.') : null));
  }
}

function navegar() {
  if (!estado.usuario) return;
  if (estado.usuario.debe_cambiar_clave) { dialogoClave(true); return; }
  const id = location.hash.slice(1) || 'inicio';
  const pag = PAGINAS.find((p) => p.id === id && (!p.permiso || puede(p.permiso))) || PAGINAS[0];
  document.querySelectorAll('#nav a').forEach((a) => a.classList.toggle('activo', a.dataset.id === pag.id));
  $('#titulo').textContent = pag.encabezado || pag.titulo;
  $('#subtitulo').textContent = pag.sub || '';
  $('#menu').classList.remove('abierto');
  const cont = $('#pagina');
  cont.replaceChildren();
  Promise.resolve(pag.render(cont)).then(ajustarAltoTablas).catch((e) => cont.append(h('p', { class: 'error' }, e.message)));
  cont.focus();
}

/** Centro de control (fase 10): indicadores, acciones rápidas, estado de las bases y actividad reciente. */
async function pInicio(c) {
  const r = await api('resumen');
  const svg = (paths) => { const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); s.setAttribute('viewBox', '0 0 24 24'); s.innerHTML = paths; return s; };
  const IK = {
    caja: '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M12 12l8-4.5M12 12v9M12 12L4 7.5"/>',
    reloj: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    matraz: '<path d="M9 3h6M10 3v6l-5 9a2 2 0 0 0 1.8 3h10.4a2 2 0 0 0 1.8-3l-5-9V3"/><path d="M7.5 15h9"/>',
    edificio: '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M8 7h2M14 7h2M8 11h2M14 11h2M8 15h2M14 15h2M10 21v-3h4v3"/>',
    capsula: '<rect x="2.5" y="8.5" width="19" height="7" rx="3.5" transform="rotate(-45 12 12)"/><path d="M8.5 8.5l7 7"/>',
  };
  const kpi = (ic, tono, valor, etq, pie, href) => h(href ? 'a' : 'div', { class: `tarjeta kpi2 ${tono}`, href },
    h('span', { class: 'kpi-icono' }, svg(IK[ic])), h('div', {}, h('div', { class: 'valor' }, miles(valor)), h('div', { class: 'etq' }, etq), pie ? h('div', { class: 'kpi-pie' }, pie) : null));
  const pc = r.por_crear; const cm = r.catalogos_mes;
  const mes = (n) => (n ? `↑ +${miles(n)} este mes` : 'Sin cambios este mes');
  c.append(h('div', { class: 'kpis' },
    kpi('caja', 't-azul', r.productos, 'Productos creados', r.productos_semana ? `↑ +${miles(r.productos_semana)} esta semana` : 'Sin creaciones esta semana', puede('PRODUCTO.CREAR') ? '#crear' : null),
    kpi('reloj', 't-verde', pc.total, 'Productos pendientes por crear', pc.total ? `${miles(pc.aptos)} listos · ${miles(pc.error)} con error` : 'Ninguno pendiente', puede('PRODUCTO.CREAR') ? '#crear' : null),
    kpi('matraz', 't-morado', r.catalogos.molecula, 'Moléculas (COD GEN)', mes(cm.molecula), '#catalogos'),
    kpi('edificio', 't-naranja', r.catalogos.laboratorio, 'Laboratorios', mes(cm.laboratorio), '#catalogos'),
    kpi('capsula', 't-azul', r.catalogos.presentacion, 'Presentaciones', mes(cm.presentacion), '#catalogos')));

  // Acciones rápidas (solo las que el perfil puede usar)
  const acciones = [['bases', 'Actualizar bases', '#bases', 'BASE.ACTUALIZAR'], ['crear', 'Crear producto', '#crear', 'PRODUCTO.CREAR'],
    ['nota', 'Ver Maestra Tarifaria', '#nota', 'NT.CONSULTAR'], ['catalogos', 'Abrir catálogos', '#catalogos', null]].filter((a) => !a[3] || puede(a[3]));
  const tarjetaAcciones = h('div', { class: 'tarjeta' }, h('h2', {}, 'Acciones rápidas'),
    h('div', { class: 'acciones-rapidas' }, ...acciones.map(([ic, t, href]) => h('a', { class: 'accion-rapida', href }, icono(ic, 'ico-grande'), h('span', {}, t)))));

  // Actividad reciente
  const IA = { ok: '<circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/>', archivo: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
    alerta: '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18v.5"/>', usuario: '<circle cx="12" cy="8" r="4"/><path d="M4 20c1.5-4 4.5-6 8-6s6.5 2 8 6"/>' };
  const fechaCorta = (f) => { const d = new Date(String(f).replace(' ', 'T')); const hoy = new Date(); const misma = d.toDateString() === hoy.toDateString();
    return (misma ? 'Hoy, ' : d.toLocaleDateString('es-CO') + ', ') + d.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' }); };
  const tarjetaActividad = h('div', { class: 'tarjeta' },
    h('div', { class: 'fila-acciones', style: 'margin-bottom:8px' }, h('h2', { style: 'margin:0' }, 'Actividad reciente'),
      r.ver_auditoria ? h('a', { class: 'enlace derecha', href: '#auditoria' }, 'Ver todo') : null),
    r.actividad.length ? h('ul', { class: 'actividad' }, ...r.actividad.map((a) => h('li', {},
      h('span', { class: `act-icono a-${a.tipo}` }, svg(IA[a.tipo] || IA.archivo)),
      h('div', { class: 'act-texto', title: a.detalle || '' }, h('strong', {}, a.titulo),
        h('small', {}, [fechaCorta(a.fecha), a.usuario].filter(Boolean).join(' · ')))))) : h('p', { class: 'nota' }, 'Aún no hay actividad.'));

  const tarjetaBases = h('div', { class: 'tarjeta' },
    h('div', { class: 'fila-acciones', style: 'margin-bottom:4px' }, h('div', {}, h('h2', { style: 'margin:0' }, 'Estado de las bases externas'),
      h('p', { class: 'nota' }, 'Consulta, actualización y control de las fuentes de información')),
      h('a', { class: 'btn sec chico derecha', href: '#bases' }, 'Ir a Bases de referencia →')),
    tablaBases(r.bases));
  c.append(h('div', { class: 'tablero' }, tarjetaBases, h('div', { class: 'tablero-lado' }, tarjetaAcciones, tarjetaActividad)));
}

/** Chips de configuración y estado de una base externa. */
function chipsBase(b) {
  const actualizacion = b.modo === 'MANUAL' ? chip('Cargue manual', 'info') : chip('Descarga automática', 'info');
  const versiones = b.modo === 'MANUAL' ? (Number(b.guarda_versiones) ? chip('Guarda versiones', 'info') : chip('Reemplaza la anterior', 'gris')) : null;
  const v = b.vigente;
  let est;
  if (!v) est = b.modo === 'AUTOMATICA' ? chip('Pendiente de descarga', 'alerta') : chip('Pendiente de cargue', 'alerta');
  else if (v.estado === 'LEIDA') est = chip(`Versión ${v.version ?? 1} · leída`, 'ok');
  else if (v.estado === 'ERROR') est = chip(`Versión ${v.version ?? 1} · error de lectura`, 'error');
  else est = chip(`Versión ${v.version ?? 1} · recibida, sin leer`, 'alerta');
  // La alerta es la fecha de actualización: verde dentro del límite, roja cuando lo supera.
  const hace = b.dias_sin_actualizar === 0 ? 'hoy' : `hace ${b.dias_sin_actualizar} día(s)`;
  const alerta = v ? chip(`${b.desactualizada ? 'Desactualizada · ' : ''}Actualizada ${String(v.creado_en).slice(0, 10)} (${hace})`,
    b.desactualizada ? 'error' : 'ok') : null;
  return { actualizacion, versiones, est, alerta };
}

function tablaBases(lista) {
  const filas = lista.map((b) => {
    const v = b.vigente;
    const est = !v ? chip(b.modo === 'AUTOMATICA' ? 'Sin descargar' : 'Pendiente', 'alerta') : v.estado === 'LEIDA' ? chip('● Leída', 'ok')
      : v.estado === 'ERROR' ? chip('● Error', 'error') : chip('● Sin leer', 'alerta');
    const hace = !v ? '' : b.dias_sin_actualizar === 0 ? 'hoy' : `hace ${b.dias_sin_actualizar} día(s)`;
    return h('tr', {}, h('td', { class: 'base-nombre', title: v ? `Archivo vigente: ${v.origen}` : '' }, h('strong', {}, b.nombre_mostrar || b.nombre),
      b.numero_contrato ? h('div', { class: 'base-subtit' }, `Contrato n.º ${b.numero_contrato}`) : null),
      h('td', {}, b.modo === 'MANUAL' ? 'Manual' : `Automática`), h('td', {}, v ? `V${v.version ?? 1}` : '—'), h('td', {}, est),
      h('td', { class: 'num' }, v?.estado === 'LEIDA' ? miles(v.filas) : '—'),
      h('td', { class: b.desactualizada ? 'fecha-vencida' : '' }, v ? h('div', {}, String(v.creado_en).slice(0, 10), h('small', { style: 'display:block' }, `(${hace})${b.desactualizada ? ' · desactualizada' : ''}`)) : '—'));
  });
  // en el Centro de control: tabla limpia, sin filtros (las bases son pocas)
  return h('div', { class: 'tabla-tablero' }, h('table', {},
    h('thead', {}, h('tr', {}, ...['Base', 'Actualización', 'Versión', 'Estado', 'Filas leídas', 'Fecha de actualización'].map((t) => h('th', {}, t)))),
    h('tbody', {}, ...filas)));
}

/** Título legible de una columna de base (id_mutual -> Id mutual). */
const tituloColumna = (c) => ({ cum: 'CUM', id_mutual: 'ID MUTUAL', codigo_cup: 'Código CUP', plu_cadena: 'PLU cadena', id_subplan: 'ID subplán',
  cod_gen_audifarma: 'Cód. Audifarma', cod_audifarma: 'Cód. Audifarma', cod_medicar: 'Cód. Medicar', id_mutual_capita: 'ID Mutual cápita' }[c]
  || (c.charAt(0).toUpperCase() + c.slice(1).replace(/_/g, ' ')));

const tamano = (b) => (b == null ? '—' : b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.ceil(b / 1024)} KB`);

/** Sube un archivo con barra de progreso (XMLHttpRequest permite medir el avance). */
function subirArchivo(accion, campos, archivo, alAvanzar) {
  return new Promise((resolver, rechazar) => {
    const fd = new FormData();
    for (const [k, v] of Object.entries(campos)) fd.append(k, v);
    fd.append('archivo', archivo);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', `api.php?accion=${accion}`);
    xhr.setRequestHeader('X-CSRF-Token', estado.csrf || '');
    xhr.upload.onprogress = (e) => e.lengthComputable && alAvanzar(Math.round((e.loaded / e.total) * 100));
    xhr.onload = () => {
      let r; try { r = JSON.parse(xhr.responseText); } catch { r = { error: 'Respuesta inválida del servidor.' }; }
      xhr.status >= 200 && xhr.status < 300 ? resolver(r) : rechazar(new Error(r.error || `Error ${xhr.status}`));
    };
    xhr.onerror = () => rechazar(new Error('No se pudo conectar con el servidor.'));
    xhr.send(fd);
  });
}

/** Datos leídos de una base, con filtros por columna en el encabezado (servidor). */
async function mostrarDatosBase(zona, b, vista = '') {
  zona.replaceChildren(h('p', { class: 'nota' }, 'Cargando…'));
  const primera = await api('base_datos', { params: { base: b.codigo, vista, pagina: 1 } });
  const columnas = primera.columnas.map((col) => ({ campo: col, titulo: tituloColumna(col), num: ['tarifa_pactada', 'valor_unitario'].includes(col),
    valor: ['tarifa_pactada', 'valor_unitario'].includes(col) ? (f) => (f[col] == null ? '' : miles(f[col])) : null }));
  // INVIMA tiene dos vistas del consolidado: por CUM y por registro sanitario.
  const selVista = b.codigo === 'INVIMA' ? h('select', { onchange: (e) => mostrarDatosBase(zona, b, e.target.value).catch((x) => toast(x.message, true)) },
    h('option', { value: 'cum', selected: vista !== 'registro' }, 'Por CUM'), h('option', { value: 'registro', selected: vista === 'registro' }, 'Por registro sanitario')) : null;
  // encabezado (fase 10): ícono, nombre de la base y, debajo, versión · archivo · filas · vigente al; cerrar con ✕
  const v = b.vigente;
  zona.replaceChildren(h('div', { class: 'datos-base-cab' },
    h('span', { class: 'datos-base-ico' }, icono('bases')),
    h('div', { class: 'datos-base-tit' },
      h('span', { class: 'datos-base-sup' }, 'Datos leídos'),
      h('h2', {}, b.nombre_mostrar || b.nombre),
      b.numero_contrato ? h('div', { class: 'base-subtit' }, `Contrato n.º ${b.numero_contrato}`) : null,
      h('div', { class: 'datos-base-meta' }, chip(`Versión ${v.version}`, 'info'),
        h('span', { class: 'datos-base-archivo', title: v.origen || '' }, svgIcono('archivo'), h('span', {}, v.origen || '—')), h('span', {}, `${miles(v.filas || 0)} filas`), h('span', {}, `Vigente al ${fecha(v.creado_en)}`))),
    selVista ? h('label', { class: 'datos-base-vista' }, 'Ver', selVista) : null,
    h('button', { type: 'button', class: 'btn-icono cerrar-x', title: 'Cerrar', 'aria-label': 'Cerrar', onclick: () => zona.replaceChildren() }, '✕')));
  // estilo Excel: letra pequeña, una línea por fila; lo que no cabe se corta con «…» y se ve completo al pasar el mouse
  tablaPaginada({ contenedor: zona, columnas, clase: 'tabla-excel tabla-datos', clave: `${b.codigo}:${vista || ''}`, cargar: (p) => api('base_datos', { params: { base: b.codigo, vista, ...p } }) });
  zona.onmouseover = (ev) => { const td = ev.target.closest?.('.tabla-datos td'); if (td && !td.title && td.scrollWidth > td.clientWidth) td.title = td.textContent; };
  zona.scrollIntoView({ behavior: 'smooth', block: 'start' });
  setTimeout(ajustarAltoTablas, 700); // recalcula el alto con la tabla ya en su posición final
}

/**
 * Descarga automática de INVIMA: corre en segundo plano en el servidor; aquí solo se muestra el
 * avance (consulta cada 3 s). Se puede cerrar la página: el proceso continúa.
 */
const DESCARGAS = {
  INVIMA: { vacio: 'Aún no se ha descargado. Se descarga desde datos.gov.co (≈ 1,8 millones de registros, varios minutos).',
    texto: 'Descarga automática de las 5 fuentes de datos abiertos. Si ninguna cambió desde la última descarga, no se vuelve a bajar.' },
  MEDICAMENTOS_MUTUAL: { vacio: 'Aún no se ha descargado. Se descarga del enlace de Mutual Ser (≈ 50 MB, cerca de 1 minuto).',
    texto: 'Descarga automática de la consulta de medicamentos de Mutual Ser (mismo enlace y columnas del script MaestraMutualser.R). Si el archivo no cambió, no se vuelve a leer.' },
};
/** Panel de una base de descarga automática (INVIMA, Maestra Mutual Ser): lanzar y seguir el avance. */
function panelDescargaAutomatica(b, puedeLanzar) {
  const d = DESCARGAS[b.codigo] || { vacio: 'Aún no se ha descargado.', texto: '' };
  const barra = h('progress', { max: 100, value: 0, style: 'width:100%', hidden: true });
  const msg = h('p', { class: 'nota', style: 'margin:0' });
  const caja = h('div', { class: 'base-auto-estado' });
  const btn = puedeLanzar ? h('button', { type: 'button', class: 'btn primario', title: d.texto, onclick: async () => {
    btn.disabled = true;
    try { await api('base_actualizar', { metodo: 'POST', datos: { base: b.codigo } }); seguir(); }
    catch (e) { toast(e.message, true); btn.disabled = false; }
  } }, svgIcono('recargar'), 'Actualizar ahora') : null;
  let timer;
  async function seguir() {
    clearTimeout(timer);
    if (!document.body.contains(msg) && timer) return; // se salió de la página
    let p;
    try { p = (await api('proceso_estado', { params: { tipo: b.codigo } })).proceso; } catch { return; }
    if (!p) { msg.textContent = d.vacio; caja.className = 'base-auto-estado'; return; }
    const enCurso = p.estado === 'EN_CURSO';
    caja.className = `base-auto-estado ${enCurso ? '' : p.estado === 'ERROR' ? 'e-error' : 'e-ok'}`;
    barra.hidden = !enCurso; barra.value = p.avance;
    if (btn) btn.disabled = enCurso;
    msg.replaceChildren(...(enCurso ? [`${p.avance}% · ${p.mensaje}`] : [
      h('strong', {}, svgIcono(p.estado === 'ERROR' ? 'error' : 'ok'), p.estado === 'OK' ? 'Completada' : p.estado === 'SIN_CAMBIOS' ? 'Sin cambios' : 'Error'),
      h('span', {}, `${p.mensaje || ''} (${fecha(p.terminado_en || p.actualizado_en)})`)]));
    if (enCurso) timer = setTimeout(seguir, 3000);
    else if (seguir.estabaEnCurso) { seguir.estabaEnCurso = false; toast(p.mensaje, p.estado === 'ERROR'); navegar(); return; }
    seguir.estabaEnCurso = enCurso;
  }
  setTimeout(seguir, 0);
  caja.append(barra, msg);
  return h('div', { class: 'base-auto' }, btn, caja);
}

async function pBases(c) {
  const r = await api('bases');
  // Diseño fase 10 (referencia del usuario): dos columnas; cada tarjeta con título, estado, archivo vigente,
  // cargue (o actualización automática) y acciones al pie. La ayuda va en el subtítulo del encabezado.
  const rejilla = h('div', { class: 'bases-rejilla' });
  const zonaDatos = h('div', { class: 'tarjeta', style: 'display:none' });
  const obsZona = new MutationObserver(() => { zonaDatos.style.display = zonaDatos.childElementCount ? '' : 'none'; ajustarAltoTablas(); });
  obsZona.observe(zonaDatos, { childList: true });
  c.append(rejilla, zonaDatos);
  const sep = () => h('span', { class: 'base-sep' }, '|');

  for (const b of r.bases) {
    const { actualizacion, versiones, est, alerta } = chipsBase(b);
    const v = b.vigente;
    const historial = h('div');
    // archivo vigente en una línea: nombre | filas | vigente al (el detalle de la lectura, al pasar el mouse)
    const detalleLectura = v && v.estado === 'LEIDA' ? [v.omitidas ? `${miles(v.omitidas)} omitidas` : '', v.duplicados ? `${miles(v.duplicados)} repetidas` : '',
      v.hoja_leida ? `hoja ${v.hoja_leida}` : '', v.llave ? `llave: ${v.llave}` : '', `leída ${fecha(v.leido_en)}`, `${tamano(v.tamano_bytes)} · ${v.usuario || ''}`].filter(Boolean).join(' · ') : '';
    const archivo = h('div', { class: 'base-archivo', title: detalleLectura },
      svgIcono('archivo'), h('span', { class: 'base-nombre-archivo' }, v?.origen || 'Sin archivo'), sep(),
      h('span', {}, v && v.estado === 'LEIDA' ? `${miles(v.filas)} filas` : '— filas'), sep(),
      h('span', {}, v ? `Vigente al ${fecha(v.creado_en)}` : 'Sin versión vigente'));
    const avisosLectura = v && v.estado === 'LEIDA' && (v.detalle_omitidas?.length || v.detalle_duplicados?.length)
      ? h('details', { class: 'base-detalle' }, h('summary', {}, `Ver filas omitidas / repetidas (${miles((v.omitidas || 0) + (v.duplicados || 0))})`),
        h('ul', { class: 'lista-avisos' }, ...[...v.detalle_omitidas, ...v.detalle_duplicados].map((t) => h('li', {}, t)))) : null;
    const errorLectura = v && v.estado === 'ERROR' ? h('div', { class: 'aviso-caja' }, h('strong', {}, 'No se pudo leer: '), v.error || '') : null;

    // zona de cargue: archivo + botón (manual) o actualizar ahora + estado (automática)
    let cargue = null;
    if (b.modo === 'MANUAL' && r.puede_cargar) {
      const input = h('input', { type: 'file', class: 'oculto-archivo', accept: b.extensiones.split(',').map((e) => `.${e.trim()}`).join(',') });
      const nombreSel = h('span', { class: 'archivo-sel' }, 'Sin archivos seleccionados');
      input.addEventListener('change', () => { nombreSel.textContent = input.files[0]?.name || 'Sin archivos seleccionados'; nombreSel.classList.toggle('lleno', !!input.files[0]); });
      const barra = h('progress', { max: 100, value: 0, hidden: true, style: 'width:100%' });
      const msg = h('p', { class: 'nota', style: 'margin:0' });
      // versiones históricas: nombre y fecha desde la que rige (fecha de ejecución); la de fecha más reciente queda vigente
      const conVersiones = Number(b.guarda_versiones) === 1;
      const hoyTxt = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
      const nombreVer = h('input', { type: 'text', maxlength: 150, placeholder: `Ej.: ${b.nombre} marzo 2026 (vacío: el nombre del archivo)` });
      const desdeVer = h('input', { type: 'date', value: hoyTxt, max: hoyTxt });
      const hastaVer = h('input', { type: 'date', max: hoyTxt, title: 'Opcional: si la deja vacía, la fecha final la da la versión siguiente (o queda vigente hasta hoy)' });
      input.addEventListener('change', () => { if (!nombreVer.value && input.files[0]) nombreVer.placeholder = input.files[0].name.replace(/\.[^.]+$/, ''); });
      const boton = h('button', { type: 'button', class: 'btn primario', onclick: async () => {
        const f = input.files[0];
        if (!f) { msg.textContent = 'Seleccione un archivo.'; return; }
        if (f.size > r.tamano_max_mb * 1048576) { msg.textContent = `El archivo supera ${r.tamano_max_mb} MB.`; return; }
        const historica = conVersiones && v && desdeVer.value < String(v.vigente_desde || '').slice(0, 10);
        const aviso = !conVersiones ? 'Reemplazará el archivo vigente (el anterior se elimina; queda el registro en auditoría).'
          : historica ? `Versión histórica: rige desde el ${desdeVer.value} y queda en el historial, sin reemplazar la vigente (desde el ${String(v.vigente_desde).slice(0, 10)}).`
            : `Nueva versión con fecha inicio ${desdeVer.value}: la actual queda en el historial con fecha final ${desdeVer.value}.`;
        if (conVersiones && !desdeVer.value) { msg.textContent = 'Indique la fecha inicio de la versión.'; return; }
        if (conVersiones && hastaVer.value && hastaVer.value < desdeVer.value) { msg.textContent = 'La fecha final no puede ser anterior a la fecha de inicio.'; return; }
        // Contrato Evento: antes de cargar se lee el número del archivo para distinguir actualización vs contrato nuevo
        if (b.codigo === 'CONTRATO_EVENTO_22382') {
          let rev;
          try { msg.textContent = 'Leyendo el número del contrato…'; rev = await subirArchivo('base_revisar_contrato', {}, f, () => {}); msg.textContent = ''; }
          catch (e) { msg.textContent = ''; toast(e.message, true); return; }
          const num = rev.numeros.join(', '); const ant = rev.actual.join(', ');
          const texto = rev.tipo === 'NUEVO' ? `CONTRATO NUEVO: el archivo es del contrato ${num} y reemplaza al Contrato Evento ${ant}. Las validaciones de la Maestra Tarifaria pasan a hacerse con el contrato ${num}.`
            : rev.tipo === 'ACTUALIZACION' ? `Actualización del Contrato Evento ${num} (mismo número del contrato vigente). ${aviso}` : `Primer cargue: Contrato Evento ${num}.`;
          if (!(await confirmar(rev.tipo === 'NUEVO' ? `Contrato nuevo ${num}` : `Cargar Contrato Evento ${num}`, texto, rev.tipo === 'NUEVO' ? 'Cargar contrato nuevo' : 'Cargar'))) return;
        } else if ((v || historica) && !(await confirmar(`Cargar ${b.nombre}`, aviso, 'Cargar'))) return;
        boton.disabled = true; barra.hidden = false; barra.value = 0; msg.textContent = 'Subiendo…';
        try {
          const res = await subirArchivo('base_cargar', { base: b.codigo, ...(conVersiones ? { nombre_version: nombreVer.value.trim(), vigente_desde: desdeVer.value, vigente_hasta: hastaVer.value } : {}) }, f, (p) => { barra.value = p; msg.textContent = p < 100 ? `Subiendo… ${p}%` : 'Verificando y leyendo el archivo…'; });
          const ctr = res.contrato ? ` Contrato ${res.contrato.numeros.join(', ')}${res.contrato.tipo === 'NUEVO' ? ' (contrato nuevo)' : res.contrato.tipo === 'ACTUALIZACION' ? ' (actualización)' : ''}.` : '';
          toast((res.lectura ? `${b.nombre}: versión ${res.version} leída (${miles(res.lectura.filas)} filas).` : `${b.nombre}: versión ${res.version} cargada.`) + ctr
            + (conVersiones ? (res.vigente ? ` Vigente, fecha inicio ${res.vigente_desde}.` : ` Guardada como histórica (fecha inicio ${res.vigente_desde}).`) : ''));
          navegar();
        } catch (e) { msg.textContent = ''; toast(e.message, true); boton.disabled = false; barra.hidden = true; }
      } }, svgIcono('subir'), v ? 'Cargar nueva versión' : 'Cargar archivo');
      cargue = h('div', { class: 'base-cargue' },
        b.lectura ? h('div', { class: 'base-plantilla' }, h('a', { class: 'btn sec chico', href: `api.php?accion=base_plantilla&base=${encodeURIComponent(b.codigo)}`,
          title: 'Excel con los encabezados exactos que espera el aplicativo y las instrucciones de cada columna' }, svgIcono('bajar'), 'Descargar plantilla'),
          h('span', { class: 'nota' }, 'Encabezados e instrucciones para preparar la nueva versión')) : null,
        conVersiones ? h('div', { class: 'base-version-campos' }, h('label', {}, 'Nombre de la versión', nombreVer), h('label', {}, 'Fecha inicio', desdeVer), h('label', {}, 'Fecha final (opcional)', hastaVer)) : null,
        h('div', { class: 'base-cargue-fila' },
          h('label', { class: 'selector-archivo' }, input, h('span', { class: 'btn-archivo' }, 'Seleccionar archivo'), nombreSel), boton),
        barra, msg);
    } else if (b.modo === 'AUTOMATICA' && b.descarga_automatica) {
      cargue = panelDescargaAutomatica(b, r.puede_cargar);
    } else if (b.modo === 'AUTOMATICA') {
      cargue = h('p', { class: 'nota', style: 'margin:0' }, 'Se actualiza de forma automática.');
    }

    // acciones al pie
    const pie = h('div', { class: 'base-pie' });
    const reservada = b.con_precios && !puede('PRECIO.VER'); // perfil «Consulta sin precios»
    if (reservada) pie.append(h('span', { class: 'chip gris', title: 'Su perfil no tiene acceso a precios ni tarifas' }, 'Datos con tarifas: reservados'));
    if (v?.estado === 'LEIDA' && b.consultable && !reservada) pie.append(h('button', { type: 'button', class: 'btn sec', onclick: () => mostrarDatosBase(zonaDatos, b).catch((e) => toast(e.message, true)) }, svgIcono('ojo'), 'Ver datos'));
    if (b.cargas > 0) {
      const btnHist = h('button', { type: 'button', class: 'btn sec', onclick: async () => {
        if (historial.childElementCount) { historial.replaceChildren(); btnHist.lastChild.textContent = 'Ver historial'; return; }
        const hr = await api('base_historial', { params: { base: b.codigo } });
        btnHist.lastChild.textContent = 'Ocultar historial';
        const conContrato = b.codigo === 'CONTRATO_EVENTO_22382';
        const cambiosTxt = (c) => (c ? (c.anterior ? `${miles(c.nuevos)} nuevos · ${miles(c.retirados)} retirados · ${miles(c.modificados)} modificados` : 'Primera versión') : '—');
        historial.replaceChildren(tablaConFiltros(['Versión', 'Nombre', 'Archivo', ...(conContrato ? ['Contrato'] : []), 'Estado', 'Fecha inicio', 'Fecha final', 'Filas', 'Cambios vs. anterior', 'Por', ''],
          hr.historial.map((x) => h('tr', {}, h('td', {}, `v${x.version ?? '?'}`, Number(x.vigente) ? chip('Vigente', 'ok') : null),
            h('td', { title: x.nombre_version || '' }, x.nombre_version || '—'),
            h('td', { title: x.origen || '' }, x.origen), conContrato ? h('td', {}, x.contrato || '—') : null,
            h('td', {}, chip({ LEIDA: 'Leída', REEMPLAZADA: 'Reemplazada', ERROR: 'Error', RECIBIDA: 'Sin leer' }[x.estado] || x.estado, { LEIDA: 'ok', ERROR: 'error', RECIBIDA: 'alerta' }[x.estado] || 'gris')),
            h('td', {}, x.vigente_desde ? String(x.vigente_desde).slice(0, 10) : '—'), h('td', { title: x.hasta_es_digitada ? 'Fecha final digitada' : 'La da la versión siguiente' }, x.vigente_desde ? (x.vigente_hasta ? String(x.vigente_hasta).slice(0, 10) : 'Hoy (vigente)') : '—'),
            h('td', { class: 'num' }, miles(x.filas || 0)), h('td', {}, cambiosTxt(x.cambios)), h('td', {}, x.usuario || '—'),
            h('td', {}, h('div', { class: 'fila-acciones' },
              Number(b.guarda_versiones) && r.puede_cargar && x.vigente_desde ? h('button', { type: 'button', class: 'btn-icono', title: 'Corregir nombre y vigencia', 'aria-label': 'Corregir vigencia',
                onclick: () => dialogo({ titulo: `Vigencia de la versión v${x.version}`, texto: 'La fecha final es opcional: si la deja vacía, la da la versión siguiente (o queda vigente hasta hoy). La versión con la fecha inicio más reciente es la vigente.',
                  campos: [{ nombre: 'nombre_version', etiqueta: 'Nombre de la versión', valor: x.nombre_version || '' },
                    { nombre: 'vigente_desde', etiqueta: 'Fecha inicio', tipo: 'date', valor: String(x.vigente_desde).slice(0, 10), requerido: true },
                    { nombre: 'vigente_hasta', etiqueta: 'Fecha final (opcional)', tipo: 'date', valor: x.hasta_es_digitada ? String(x.vigente_hasta).slice(0, 10) : '' }],
                  alEnviar: async (vv) => { await api('base_version_editar', { metodo: 'POST', datos: { id: x.id, ...vv } }); toast('Vigencia guardada.'); navegar(); } }) }, svgIcono('editar')) : null,
              x.descargable ? h('a', { class: 'btn sec chico', href: `api.php?accion=base_version_descargar&id=${x.id}` }, '⬇ Archivo') : null,
              x.cambios?.anterior && !x.sin_precios ? h('a', { class: 'btn sec chico', href: `api.php?accion=base_cambios_exportar&id=${x.id}` }, '⬇ Cambios') : null)))), { alto: 300, sinFiltro: [conContrato ? 11 : 10] }));
        historial.className = 'historial-excel'; // estilo Excel (letra pequeña, una línea por versión)
      } }, svgIcono('reloj'), h('span', {}, 'Ver historial'));
      pie.append(btnHist);
    }
    if (r.puede_cargar && b.lectura && v && v.estado !== 'LEIDA') {
      pie.append(h('button', { type: 'button', class: 'btn primario', onclick: async (ev) => {
        ev.currentTarget.disabled = true;
        try { const res = await api('base_procesar', { metodo: 'POST', datos: { base: b.codigo } }); toast(`${b.nombre}: ${miles(res.lectura.filas)} filas leídas.`); navegar(); }
        catch (e) { toast(e.message, true); navegar(); }
      } }, svgIcono('archivo'), 'Leer archivo'));
    }
    pie.append(h('span', { class: 'espacio' }));
    if (r.puede_cargar) {
      pie.append(h('button', { type: 'button', class: 'enlace-limite', title: 'Días máximos sin actualizar: al superarlos, la fecha sale en rojo (regla A07)', onclick: () => dialogo({
        titulo: `Límite de actualización: ${b.nombre}`,
        texto: 'Días máximos desde la fecha de actualización. Al superarlos, la fecha se muestra en rojo como «Desactualizada» (regla A07).',
        campos: [{ nombre: 'dias_alerta', etiqueta: 'Días', tipo: 'number', valor: b.dias_alerta, requerido: true }],
        alEnviar: async (x) => { await api('base_config_guardar', { metodo: 'POST', datos: { base: b.codigo, dias_alerta: x.dias_alerta } }); toast('Guardado.'); navegar(); },
      }) }, `Límite: ${b.dias_alerta} días`));
    }
    if (b.cargas > 0) pie.append(h('span', { class: 'nota' }, `${b.cargas} carga(s)`));

    rejilla.append(h('div', { class: 'tarjeta base-tarjeta' },
      h('div', {}, h('h3', {}, b.nombre_mostrar || b.nombre),
        b.numero_contrato ? h('div', { class: 'base-subtit' }, `Contrato n.º ${b.numero_contrato}`) : null,
        h('p', { class: 'nota' }, b.descripcion || '')),
      h('div', { class: 'base-chips' }, actualizacion, versiones, est, alerta),
      archivo, avisosLectura, errorLectura, cargue, pie, historial));
  }
}

// ------------------------------------------------------------------ Solicitudes de creación (fase 5)
const SOL_ESTADOS = { VALIDADO: 'ok', 'CON ALERTA': 'alerta', ERROR: 'error', 'SIN VALIDAR': 'gris' };
const SOL_TIPOS_APROBACION = { MOLECULA: 'Crear COD GEN (molécula)', LABORATORIO: 'Crear laboratorio', PRESENTACION: 'Crear presentación',
  ASIGNAR_POS: 'Asignar POS / NO POS (COD GEN en ASIGNAR)', INCLUIR_NT: 'Incluir en la Maestra Tarifaria' };

/**
 * Crear productos: solicitudes (grupos de productos que se capturan y validan juntos; el lote de SIESA es PH001
 * para todos y no se valida). Cada producto se valida con las reglas aprobadas (fase 5) y queda en el semáforo:
 * VALIDADO (se crea) · CON ALERTA (se crea, revisar) · ERROR (no se crea hasta corregir o hasta que el
 * Administrador apruebe: molécula, laboratorio, presentación, ASIGNAR o inclusión en la Maestra Tarifaria).
 */
async function pCrear(c) {
  c.replaceChildren();
  productosPorCrear(c);
}

/**
 * Productos por crear (sin solicitudes, decisión del usuario): «+ Crear producto» abre el formulario; al guardar se
 * valida y queda en el semáforo. El Administrador ve aquí mismo las aprobaciones pendientes.
 */
async function productosPorCrear(c) {
  let d, listas;
  try { [d, listas] = await Promise.all([api('sol_actual'), api('sol_listas')]); } catch (e) { c.append(h('p', { class: 'error' }, e.message)); return; }
  const id = d.solicitud.id;
  const recargar = () => pCrear(c);
  const editable = ['BORRADOR', 'VALIDADA'].includes(d.solicitud.estado);
  let tabla = null;
  // productos que se pueden crear (validados y con alerta). El usuario no elige: «Crear» crea todos los que cumplen
  const aptos = d.productos.filter((p) => ['VALIDADO', 'CON ALERTA'].includes(p.resultado));

  const cuenta = (k) => d.conteo[k] || 0;
  const indicador = (k) => (cuenta(k) ? h('button', { type: 'button', class: `chip ${SOL_ESTADOS[k]} chip-filtro`, title: 'Filtrar',
    onclick: () => tabla?.filtrar(1, [k]) }, `${miles(cuenta(k))} ${k.toLowerCase()}`) : '');
  // CREAR (fase 6): todos los que cumplen (validados y con alerta, revalidados al crear) -> productos creados + 6 archivos
  const crear = async () => {
    // f120_id: el usuario digita el consecutivo por el que va SIESA y cada producto toma el siguiente
    let sug = {};
    try { sug = await api('cre_consecutivo'); } catch { /* sin sugerencia */ }
    const ayuda = [sug.maestro_siesa ? `Ítem más alto del Maestro SIESA cargado${sug.maestro_fecha ? ' el ' + sug.maestro_fecha.slice(0, 10) : ''}: ${sug.maestro_siesa}.` : '',
      sug.aplicativo ? `Último f120_id asignado en el aplicativo: ${sug.aplicativo}.` : ''].filter(Boolean).join(' ');
    dialogo({
      titulo: 'Crear productos',
      texto: `Se crean todos los productos sin error (${aptos.length}: validados y con alerta; se revalidan al crear) y se generan los 6 archivos de cargue (SIESA y Medicar). Los que tienen error se quedan en la lista. Cada producto toma el siguiente f120_id: consecutivo + 1, + 2…`,
      campos: [{ nombre: 'consecutivo', etiqueta: 'Consecutivo por el que va SIESA (f120_id)', tipo: 'number', valor: sug.sugerido || '', requerido: true,
        ayuda: (ayuda ? ayuda + ' ' : '') + 'Confírmelo en SIESA antes de crear.' }],
      aceptar: 'Crear',
      alEnviar: async (v) => {
        const r = await api('cre_crear', { metodo: 'POST', datos: { consecutivo: v.consecutivo } });
        const n = Number(v.consecutivo);
        toast(`${r.creados} productos creados (f120_id ${String(n + 1).padStart(7, '0')}${r.creados > 1 ? ' a ' + String(n + r.creados).padStart(7, '0') : ''}). Descargando los 6 archivos…`);
        window.location.href = `api.php?accion=cre_descargar&id=${r.ejecucion}`;
        setTimeout(recargar, 1500);
      },
    });
  };
  const validar = async () => {
    const x = await api('sol_validar', { metodo: 'POST', datos: { id } });
    toast(`Revalidados: ${x.resumen.VALIDADO} validados, ${x.resumen['CON ALERTA']} con alerta, ${x.resumen.ERROR} con error.`); recargar();
  };
  const exportar = (errores) => { window.location.href = `api.php?accion=sol_exportar&id=${id}${errores ? '&errores=1' : ''}`; };
  const menu = (resumen, opciones, clase = 'btn sec') => h('details', { class: 'menu-exportar' }, h('summary', { class: clase }, ...resumen),
    h('div', { class: 'menu-lista' }, ...opciones.map(([t, f, ic]) => h('button', { type: 'button', class: 'menu-opcion', onclick: (ev) => { ev.target.closest('details').open = false; f(); } },
      ic ? svgIcono(ic) : null, t))));
  const quitar = async (p) => {
    if (!await confirmar('Quitar producto', `Se quita «${p.calculado?.descripcion_generica || p.codigo_generico_audifarma}» de la lista por crear.`, 'Quitar')) return;
    await api('sol_item_eliminar', { metodo: 'POST', datos: { lote_id: id, id: p.id } }); toast('Producto quitado.'); recargar();
  };
  // ---- barra de acciones
  const izquierda = editable ? [
    menu([svgIcono('subir'), 'Cargar Excel ▾'], [['Cargar archivo de productos…', () => cargarProductosExcel(recargar), 'subir'],
      ['Descargar plantilla', () => { window.location.href = 'api.php?accion=sol_plantilla'; }, 'bajar']], 'btn primario'),
    h('button', { class: 'btn sec', onclick: () => productoSolicitud(id, null, listas, recargar) }, svgIcono('mas'), 'Agregar producto'),
    d.productos.length ? h('button', { class: 'btn sec', title: 'Vuelve a validar todos con las bases de este momento', onclick: validar }, svgIcono('recargar'), 'Revalidar') : null,
  ] : [];
  const derecha = [
    d.puede_aprobar && d.aprobaciones_pendientes ? h('button', { class: 'btn sec resaltado', onclick: () => panelAprobaciones(recargar) }, svgIcono('aprobar'), `Aprobaciones pendientes (${miles(d.aprobaciones_pendientes)})`) : null,
    h('button', { class: 'btn sec', onclick: () => historialCreaciones() }, svgIcono('carpeta'), 'Creaciones y archivos'),
    d.productos.length ? menu([svgIcono('bajar'), 'Exportar ▾'], [['Todos los productos', () => exportar(false)], ['Solo los que tienen error o advertencia', () => exportar(true)]]) : null,
    editable ? h('button', { class: 'btn primario', disabled: !aptos.length, onclick: crear,
      title: aptos.length ? 'Crea todos los productos sin error y genera los 6 archivos' : 'No hay productos listos: corrija los que están en error o resuelva las aprobaciones' },
    svgIcono('crear'), `Crear productos (${aptos.length})`) : null,
  ];
  c.append(h('div', { class: 'tarjeta barra-acciones' }, h('div', { class: 'grupo-acciones' }, ...izquierda), h('div', { class: 'grupo-acciones' }, ...derecha)));
  if (!d.productos.length) {
    c.append(h('div', { class: 'tarjeta vacio-caja' }, svgIcono('crear', 'ico-vacio'), h('h2', {}, 'No hay productos por crear'),
      h('p', { class: 'nota' }, 'Agregue un producto con el formulario o cargue varios a la vez desde Excel (plantilla o la hoja FORMATO).')));
    return;
  }
  // ---- indicadores por estado (filtran la tabla)
  const ETQ = { VALIDADO: 'Listo', 'CON ALERTA': 'Advertencia', ERROR: 'Error', 'SIN VALIDAR': 'Sin validar' };
  const ICO = { VALIDADO: 'ok', 'CON ALERTA': 'alerta', ERROR: 'error', 'SIN VALIDAR': 'pendiente' };
  const estadoChip = (k) => h('span', { class: `chip-estado e-${SOL_ESTADOS[k] || 'gris'}` }, svgIcono(ICO[k] || 'pendiente'), ETQ[k] || k);
  const resumenEstados = h('div', { class: 'resumen-estados' }, h('span', { class: 'nota' }, `${miles(d.productos.length)} productos por crear:`),
    ...['VALIDADO', 'CON ALERTA', 'ERROR', 'SIN VALIDAR'].filter((k) => cuenta(k)).map((k) =>
      h('button', { type: 'button', class: `chip-estado e-${SOL_ESTADOS[k]} chip-filtro`, title: 'Ver solo estos', onclick: () => tabla?.filtrar(0, [ETQ[k]]) },
        svgIcono(ICO[k]), `${miles(cuenta(k))} ${ETQ[k].toLowerCase()}`)));
  // ---- tabla
  const filas = d.productos.map((p) => {
    const k = p.calculado || {};
    const est = p.resultado || 'SIN VALIDAR';
    const primero = p.resultados.find((x) => x.severidad === 'ERROR') || p.resultados[0];
    const motivo = primero ? `${primero.regla_codigo}: ${primero.accion || primero.campo || ''}` : '';
    return h('tr', { class: est === 'ERROR' ? 'fila-error' : est === 'CON ALERTA' ? 'fila-alerta' : '' },
      h('td', {}, estadoChip(est)),
      h('td', {}, p.codigo_generico_audifarma ?? ''),
      // una sola línea como en Excel: se ve una parte y al pasar el mouse el nombre completo (y en 👁 detalle)
      h('td', { class: 'col-desc' }, h('div', { class: 'desc-linea', title: k.descripcion_generica ?? '' }, k.descripcion_generica ?? ''),
        motivo ? h('div', { class: `motivo-corto ${primero.severidad === 'ERROR' ? 'error' : 'alerta-txt'}`, title: p.resultados.map((x) => `${x.regla_codigo}: ${x.accion || ''}`).join('\n') },
          motivo, p.resultados.length > 1 ? h('span', { class: 'nota' }, ` +${p.resultados.length - 1} más`) : null) : null),
      // columnas angostas (la tabla cabe sin barra horizontal): lo que no cabe se corta con «…» y se ve al pasar el mouse
      ...[p.cum, p.laboratorio, k.presentacion, p.codigo_producto_completo, (k.subplanes || []).join(' + ')].map((v) => h('td', { title: v ?? '' }, v ?? '')),
      h('td', {}, h('div', { class: 'acciones-fila' },
        h('button', { type: 'button', class: 'btn-icono', title: 'Ver detalle', 'aria-label': 'Ver detalle', onclick: () => detalleProducto(p, editable ? { editar: () => productoSolicitud(id, p, listas, recargar), quitar: () => quitar(p) } : null) }, svgIcono('ojo')),
        editable ? h('details', { class: 'menu-exportar' }, h('summary', { class: 'btn-icono', title: 'Más acciones', 'aria-label': 'Más acciones' }, svgIcono('puntos')),
          h('div', { class: 'menu-lista' },
            h('button', { type: 'button', class: 'menu-opcion', onclick: () => productoSolicitud(id, p, listas, recargar) }, svgIcono('editar'), 'Editar'),
            h('button', { type: 'button', class: 'menu-opcion', onclick: () => quitar(p) }, svgIcono('quitar'), 'Quitar'))) : null)));
  });
  const zona = h('div', { class: 'tarjeta tabla-crear' }, resumenEstados);
  tabla = tablaConFiltros(['Estado', 'COD AUF', 'Descripción', 'CUM', 'Laboratorio', 'Presentación', 'Código producto', 'Subplanes', 'Acciones'],
    filas, { sinFiltro: [8], clave: 'productos-por-crear-v3' });
  zona.append(tabla);
  c.append(zona);
}

/** Detalle de un producto por crear: datos, subplanes con su precio, validaciones con qué hacer y aprobaciones. */
function detalleProducto(p, acciones) {
  const k = p.calculado || {};
  const dlg = $('#dialogo'); const form = $('#dialogo-form');
  dlg.onclose = null; dlg.oncancel = null;
  // Diseño fase 10: mismo patrón del formulario del producto (encabezado, secciones numeradas y pie)
  const dato = (t, v, clase = '') => h('div', { class: `dato ${clase}`.trim() }, h('span', {}, t), h('strong', {}, v || '—'));
  const seccion = (n, titulo, ...hijos) => h('section', { class: 'seccion-form' }, h('h3', {}, h('span', { class: 'sec-num' }, String(n)), titulo), ...hijos);
  const rejilla = (...hijos) => h('div', { class: 'rejilla-form' }, ...hijos);
  const lineas = Object.entries(k.lineas || {});
  const pendientes = (p.aprobaciones || []).filter((a) => a.estado === 'PENDIENTE');
  const est = p.resultado || 'SIN VALIDAR';
  const EST = { VALIDADO: ['ok', 'ok', 'Listo para crear'], 'CON ALERTA': ['alerta', 'alerta', 'Listo, con advertencias'], ERROR: ['error', 'error', 'Con errores: no se crea'], 'SIN VALIDAR': ['gris', 'pendiente', 'Sin validar'] }[est]
    || ['gris', 'pendiente', est];
  // línea cuyo precio va a SIESA (lista L01): la EVENTO; si no tiene, la primera
  const principal = (lineas.find(([, x]) => x.contrato === 'EVENTO') || lineas[0] || [])[0];
  const encabezado = h('div', { class: 'form-encabezado' },
    h('div', {},
      h('h2', {}, k.descripcion_generica || p.codigo_generico_audifarma),
      h('div', { class: 'det-sub' },
        h('span', { class: `chip-estado e-${EST[0]}` }, svgIcono(EST[1]), EST[2]),
        p.codigo_producto_completo ? h('span', { class: 'pill-codigo', title: 'Código de producto' }, p.codigo_producto_completo) : null,
        ...(k.subplanes || []).map((x) => h('span', { class: 'chip-estado e-info' }, x)))),
    h('button', { type: 'button', class: 'btn-icono cerrar-x', 'aria-label': 'Cerrar', onclick: () => dlg.close() }, '✕'));
  const precios = lineas.length
    ? h('table', { class: 'tablita tabla-precios' },
      h('thead', {}, h('tr', {}, h('th', {}, 'Subplán'), h('th', {}, 'Contrato'), h('th', {}, 'ID MUTUAL'), h('th', { class: 'num' }, 'Precio M'))),
      h('tbody', {}, ...lineas.map(([sp, x]) => h('tr', {},
        h('td', {}, h('strong', {}, sp)), h('td', {}, x.contrato === 'EVENTO SAS' ? 'EVENTO' : (x.contrato ?? '')), h('td', {}, x.cod_mutual ?? ''),
        h('td', { class: 'num' }, h('strong', {}, x.precio_m == null ? '—' : pesos(x.precio_m)), sp === principal ? h('span', { class: 'chip-estado e-info chip-siesa', title: 'Este es el precio que va a SIESA (lista L01)' }, 'SIESA') : null)))))
    : h('div', { class: 'info-azul' }, svgIcono('pendiente'), h('span', {}, (k.subplanes || []).length ? `Subplán ${(k.subplanes || []).join(' + ')}: sin línea en la Maestra Tarifaria con precio.` : 'Sin subplán asignado.'));
  const validaciones = p.resultados.length
    ? h('div', { class: 'lista-val' }, ...p.resultados.map((x) => h('div', { class: `val-item ${x.severidad === 'ERROR' ? 'v-error' : 'v-alerta'}` },
      h('div', { class: 'val-cab' }, svgIcono(x.severidad === 'ERROR' ? 'error' : 'alerta'), h('strong', {}, `${x.regla_codigo} · ${x.severidad === 'ERROR' ? 'Error' : 'Advertencia'}`),
        h('span', {}, ` ${x.campo ? x.campo + ': ' : ''}${x.valor_encontrado ?? ''}`)),
      x.valor_esperado ? h('div', { class: 'nota' }, `Esperado: ${x.valor_esperado}${x.base_comparada ? ' · ' + x.base_comparada : ''}`) : null,
      x.accion ? h('div', { class: 'val-accion' }, '→ ', x.accion) : null)))
    : h('div', { class: 'resultado-caja r-ok' }, svgIcono('ok', 'ico-resultado'),
      h('div', {}, h('strong', {}, 'Todo en orden'), h('p', { class: 'nota' }, 'Pasa todas las validaciones: se crea con el botón «Crear productos».')));
  form.replaceChildren(encabezado,
    h('div', { class: 'form-cuerpo' },
      seccion(1, 'Identificación', rejilla(dato('COD AUF', p.codigo_generico_audifarma), dato('CUM', p.cum), dato('ID MUTUAL', k.cod_mutual),
        dato('POS / NO POS', k.categoria_pbs), dato('Tipo', p.tipo_medicamento), dato('IVA', p.iva))),
      seccion(2, 'INVIMA', rejilla(dato('Registro sanitario', p.registro_sanitario), dato('Estado INVIMA', p.estado_invima), dato('Fecha registro INVIMA', p.fecha_reg_invima),
        dato('Nombre comercial', p.nombre_comercial, 'ancho-doble'))),
      seccion(3, 'Laboratorio y presentación', rejilla(dato('Laboratorio', p.laboratorio, 'ancho-doble'), dato('Presentación', k.presentacion))),
      seccion(4, 'Subplanes y precio', precios),
      seccion(5, p.resultados.length ? `Validaciones (${p.resultados.length})` : 'Validaciones', validaciones,
        pendientes.length ? h('div', { class: 'info-azul', style: 'margin-top:10px' }, svgIcono('pendiente'),
          h('span', {}, `Pendiente de aprobación del Administrador: ${pendientes.map((a) => SOL_TIPOS_APROBACION[a.tipo]).join(', ')}`)) : null)),
    h('div', { class: 'form-pie' },
      acciones ? h('button', { type: 'button', class: 'btn sec', onclick: () => { dlg.close(); acciones.editar(); } }, svgIcono('editar'), 'Editar') : null,
      acciones ? h('button', { type: 'button', class: 'btn sec', onclick: () => { dlg.close(); acciones.quitar(); } }, svgIcono('quitar'), 'Quitar') : null,
      h('span', { class: 'espacio' }),
      h('button', { class: 'btn primario', value: 'cancelar', formnovalidate: true }, 'Cerrar')));
  form.onsubmit = () => {};
  form.classList.add('form-producto', 'det-producto'); // det-producto: letra más pequeña (pedido del usuario)
  dlg.classList.add('ancho', 'ancho-nt');
  dlg.addEventListener('close', () => { if (!dlg.open) { dlg.classList.remove('ancho', 'ancho-nt'); form.classList.remove('form-producto'); } }, { once: true }); // si ya se abrió otra ventana, no se toca
  // la letra pequeña es solo de esta ventana: se quita al cerrarla o al pasar a otra (p.ej. Editar)
  dlg.addEventListener('close', () => form.classList.remove('det-producto'), { once: true });
  if (!dlg.open) dlg.showModal();
}

/**
 * Formulario de un producto de la solicitud: las 17 columnas que se digitan en la hoja FORMATO (el subplán y la
 * categoría PBS se calculan). Se llena solo desde COD GEN (por el Cód. Audifarma), INVIMA y la Maestra Mutual (por
 * el CUM). Si la molécula, el laboratorio o la presentación no existen, se diligencian y van a aprobación.
 */
/**
 * Lista desplegable con buscador (se ve como un select): el valor queda en un input oculto (el formulario lo lee
 * igual). opciones: [[valor, etiqueta]]. permitirNuevo: si lo escrito no está en la lista, se puede usar igual
 * (p.ej. un laboratorio nuevo que va a aprobación).
 */
function comboBuscable(opciones, { valor = '', placeholder = 'Seleccione', buscar = 'Buscar…', permitirNuevo = false, textoNuevo = (t) => `Usar «${t}»` } = {}) {
  const oculto = h('input', { type: 'hidden' });
  const etiquetaDe = (v) => (opciones.find(([x]) => String(x).toUpperCase() === String(v).toUpperCase()) || [null, v])[1];
  const texto = h('span', { class: 'combo-texto' });
  const pintar = () => { texto.textContent = oculto.value ? etiquetaDe(oculto.value) : placeholder; texto.classList.toggle('vacio', !oculto.value); };
  const busca = h('input', { type: 'search', placeholder: buscar, autocomplete: 'off' });
  const lista = h('div', { class: 'combo-lista', role: 'listbox' });
  const panel = h('div', { class: 'combo-panel', hidden: true }, h('label', { class: 'buscador' }, svgIcono('buscar'), busca), lista);
  const elegir = (v) => { oculto.value = v; pintar(); panel.hidden = true; oculto.dispatchEvent(new Event('input')); oculto.dispatchEvent(new Event('change')); };
  const llenar = () => {
    const q = busca.value.trim().toUpperCase();
    const vistas = opciones.filter(([v, t]) => !q || String(t).toUpperCase().includes(q) || String(v).toUpperCase().includes(q)).slice(0, 200);
    lista.replaceChildren(...vistas.map(([v, t]) => h('button', { type: 'button', class: `combo-opcion${String(v) === oculto.value ? ' elegida' : ''}`, onclick: () => elegir(v) }, t)),
      ...(permitirNuevo && q && !opciones.some(([v, t]) => String(t).toUpperCase() === q) ? [h('button', { type: 'button', class: 'combo-opcion nueva', onclick: () => elegir(busca.value.trim().toUpperCase()) }, svgIcono('mas'), textoNuevo(busca.value.trim().toUpperCase()))] : []),
      ...(!vistas.length && !permitirNuevo ? [h('div', { class: 'nota', style: 'padding:8px' }, 'Sin resultados')] : []));
  };
  busca.addEventListener('input', llenar);
  const boton = h('button', { type: 'button', class: 'combo-boton', onclick: () => { panel.hidden = !panel.hidden; if (!panel.hidden) { busca.value = ''; llenar(); busca.focus(); } } },
    texto, h('span', { class: 'combo-flecha', 'aria-hidden': 'true' }, '▾'));
  const caja = h('div', { class: 'combo' }, boton, panel, oculto);
  document.addEventListener('click', (ev) => { if (!caja.contains(ev.target)) panel.hidden = true; });
  oculto.value = valor || ''; pintar();
  // si el valor se pone desde el código (autollenado), se refresca el texto
  oculto.addEventListener('change', pintar);
  return { el: oculto, caja, establecer: (v) => { oculto.value = v || ''; pintar(); } };
}

function productoSolicitud(loteId, p, listas, alGuardar) {
  const dlg = $('#dialogo');
  const form = $('#dialogo-form');
  dlg.onclose = null;
  const editados = new Set();
  const ini = p || {};
  const opciones = (arr) => [['', '— Seleccione —'], ...arr.map((v) => (Array.isArray(v) ? v : [v, v]))];
  const campo = (nombre, etiqueta, attrs = {}) => {
    let el; let control = null;
    if (attrs.combo) {
      const c = comboBuscable(attrs.combo, { valor: attrs.valor, placeholder: attrs.placeholder || 'Seleccione', buscar: attrs.buscar, permitirNuevo: attrs.permitirNuevo, textoNuevo: attrs.textoNuevo });
      el = c.el; control = c.caja;
    } else if (attrs.opciones) {
      el = h('select', { name: nombre }, ...attrs.opciones.map(([v, t]) => h('option', { value: v }, t)));
    } else {
      el = h('input', { name: nombre, type: attrs.type || 'text', autocomplete: 'off', placeholder: attrs.placeholder });
      if (attrs.sugerencias) control = autocompletar(el, attrs.sugerencias); // lista blanca alineada al campo
    }
    if (attrs.valor !== undefined && attrs.valor !== null && !attrs.combo) el.value = attrs.valor;
    el.addEventListener('input', () => editados.add(nombre));
    el.addEventListener('change', () => editados.add(nombre));
    const nota = h('span', { class: 'nota' }, attrs.ayuda || '');
    return { el, nota, label: h(attrs.combo ? 'div' : 'label', { class: attrs.combo ? 'campo-combo' : null }, etiqueta, control || el, nota) };
  };
  form.replaceChildren();
  const f = {
    codigo_generico_audifarma: campo('codigo_generico_audifarma', 'CODIGO GENERICO AUDIFARMA', { valor: ini.codigo_generico_audifarma }),
    cum: campo('cum', 'CUM', { valor: ini.cum, placeholder: 'CUM, NO APLICA, VITAL NO DISPONIBLE…' }),
    iva: campo('iva', 'IVA', { opciones: opciones(listas.iva), valor: ini.iva }),
    codigo_cum_auxiliar: campo('codigo_cum_auxiliar', 'CODIGO CUM AUXILIAR', { valor: ini.codigo_cum_auxiliar, placeholder: 'o NO APLICA' }),
    registro_sanitario: campo('registro_sanitario', 'REGISTRO SANITARIO', { valor: ini.registro_sanitario }),
    estado_invima: campo('estado_invima', 'ESTADO INVIMA', { opciones: opciones(listas.estado_invima), valor: ini.estado_invima }),
    fecha_reg_invima: campo('fecha_reg_invima', 'FECHA REG INVIMA', { type: 'date', valor: ini.fecha_reg_invima }),
    codigo_ium: campo('codigo_ium', 'CODIGO IUM', { valor: ini.codigo_ium, placeholder: 'o NO APLICA' }),
    codigo_atc: campo('codigo_atc', 'CODIGO ATC', { valor: ini.codigo_atc, placeholder: 'o NO APLICA' }),
    nombre_comercial: campo('nombre_comercial', 'NOMBRE COMERCIAL', { valor: ini.nombre_comercial }),
    laboratorio: campo('laboratorio', 'LABORATORIO', { valor: ini.laboratorio, combo: listas.laboratorios.map((x) => [x, x]), placeholder: 'Seleccione un laboratorio', buscar: 'Buscar laboratorio…',
      permitirNuevo: true, textoNuevo: (t) => `Laboratorio nuevo: «${t}» (va a aprobación)` }),
    tipo_medicamento: campo('tipo_medicamento', 'TIPO DE MEDICAMENTO', { opciones: opciones(listas.tipo_medicamento), valor: ini.tipo_medicamento }),
    presentacion_1: campo('presentacion_1', 'PRESENTACION 1', { opciones: opciones(listas.envase), valor: ini.presentacion_1 }),
    unidad_presentacion: campo('unidad_presentacion', 'UNIDAD DE PRESENTACION', { type: 'number', valor: ini.unidad_presentacion }),
    forma_farmaceutica_rips: campo('forma_farmaceutica_rips', 'FORMA FARMACEUTICA RIPS', { valor: ini.forma_farmaceutica_rips, combo: listas.rips.FFM || [], buscar: 'Buscar por código o nombre…' }),
    concentracion_rips: campo('concentracion_rips', 'CONCENTRACION MEDICAMENTO RIPS', { valor: ini.concentracion_rips, combo: listas.rips.UMM || [], buscar: 'Buscar por código o nombre…' }),
    unidad_medida_rips: campo('unidad_medida_rips', 'UNIDAD MEDIDA RIPS', { valor: ini.unidad_medida_rips, combo: listas.rips.UPR || [], buscar: 'Buscar por código o nombre…' }),
  };
  // datos para aprobaciones
  const prop = ini.propuesta || {};
  const mol = {
    descripcion_general: campo('m_descripcion_general', 'Descripción genérica', { valor: prop.molecula?.descripcion_general }),
    concentracion_agrupada: campo('m_concentracion_agrupada', 'Concentración agrupada', { valor: prop.molecula?.concentracion_agrupada }),
    principio_activo: campo('m_principio_activo', 'Principio activo', { valor: prop.molecula?.principio_activo }),
    forma_farmaceutica: campo('m_forma_farmaceutica', 'Forma farmacéutica', { valor: prop.molecula?.forma_farmaceutica, sugerencias: listas.molecula.forma_farmaceutica }),
    grupo_farmacologico: campo('m_grupo_farmacologico', 'Grupo farmacológico', { valor: prop.molecula?.grupo_farmacologico, sugerencias: listas.molecula.grupo_farmacologico }),
    via_administracion: campo('m_via_administracion', 'Vía de administración', { valor: prop.molecula?.via_administracion, sugerencias: listas.molecula.via_administracion }),
    pos_no_pos: campo('m_pos_no_pos', 'POS / NO POS', { opciones: opciones(['POS', 'NO POS']), valor: prop.molecula?.pos_no_pos }),
    cadena_frio: campo('m_cadena_frio', 'Cadena de frío', { opciones: opciones(listas.molecula.listas.cadena_frio), valor: prop.molecula?.cadena_frio }),
  };
  const asignar = campo('asignar_pos', 'Asignar POS / NO POS (lo aprueba el Administrador)', { opciones: opciones(['POS', 'NO POS']), valor: prop.asignar_pos });
  const unidadOrden = campo('unidad_orden', 'Unidad de orden de la presentación nueva (ej. CX30)', { valor: prop.presentacion?.unidad_orden });
  const secMolecula = h('fieldset', { hidden: true }, h('legend', {}, 'Molécula nueva (COD GEN): se envía a aprobación del Administrador'), ...Object.values(mol).map((x) => x.label),
    h('p', { class: 'nota ancho-total' }, 'El Código Medicar (COD_GEN_SIESA) es automático: se asigna el siguiente consecutivo libre cuando el Administrador aprueba la molécula.'));
  const secAsignar = h('fieldset', { hidden: true }, h('legend', {}, 'COD GEN en «ASIGNAR»'), asignar.label);
  const secPresentacion = h('fieldset', { hidden: true }, h('legend', {}, 'Presentación nueva: se envía a aprobación del Administrador'), unidadOrden.label);
  const infoAuf = h('div', { class: 'nota' });
  const idMutual = h('input', { readonly: true, class: 'solo-lectura', placeholder: 'Se llena con el CUM', value: ini.calculado?.cod_mutual || '' });
  const notaIdMutual = h('span', { class: 'nota' }, ini.calculado?.cod_mutual ? 'De la Maestra Tarifaria' : '');
  const campoIdMutual = h('label', {}, 'ID MUTUAL', idMutual, notaIdMutual);
  // subplanes previstos: se ven antes de guardar (misma regla de la validación)
  // igual que el ID MUTUAL: caja de solo lectura que se llena sola; debajo, de dónde sale o el aviso
  const subplanes = h('input', { readonly: true, class: 'solo-lectura', placeholder: 'Se llenan con el COD AUF y el CUM', value: (ini.calculado?.subplanes || []).join(' + ') });
  const notaSubplanes = h('span', { class: 'nota' });
  const campoSubplanes = h('label', {}, 'SUBPLANES', subplanes, notaSubplanes);
  const infoLab = h('div', { class: 'nota' });
  const err = h('p', { class: 'error', role: 'alert' });
  let auto = null;

  // Al editar, lo guardado se respeta: mientras el COD AUF y el CUM sean los mismos del producto guardado, el autollenado
  // (INVIMA, Maestra Mutual) no escribe encima de un campo con valor; solo muestra la sugerencia si es distinta.
  // Si se cambia el COD AUF o el CUM, vuelve a llenar (salvo lo que el usuario digite en esta edición).
  const mismaClave = () => !!p && f.codigo_generico_audifarma.el.value.trim().toUpperCase() === String(ini.codigo_generico_audifarma ?? '').trim().toUpperCase()
    && f.cum.el.value.trim().toUpperCase() === String(ini.cum ?? '').trim().toUpperCase();
  const poner = (k, v, fuente) => {
    if (!v || editados.has(k)) return;
    const guardado = ini[k] ?? '';
    if (mismaClave() && String(guardado).trim() !== '') {
      f[k].nota.textContent = String(guardado).trim().toUpperCase() === String(v).trim().toUpperCase() ? fuente : `Guardado en el producto (${fuente}: ${v})`;
      return;
    }
    f[k].el.value = v; f[k].el.dispatchEvent(new Event('change')); editados.delete(k); f[k].nota.textContent = fuente;
  };
  const claveLab = (t) => String(t || '').toUpperCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^A-Z0-9]/g, '');
  const labPorClave = new Map(listas.laboratorios.map((x) => [claveLab(x), x]));
  const revisarCatalogos = () => {
    const lab = f.laboratorio.el.value.trim();
    const igual = lab ? labPorClave.get(claveLab(lab)) : null;
    infoLab.hidden = !lab || igual === lab.toUpperCase() || igual === lab;
    infoLab.replaceChildren(svgIcono('pendiente'), h('span', {}, igual ? h('span', {}, 'Se usará el laboratorio del catálogo: ', h('strong', {}, igual)) : h('span', {}, h('strong', {}, 'Laboratorio nuevo: '), 'se envía a aprobación del Administrador al validar.')));
    const pres = f.presentacion_1.el.value && f.unidad_presentacion.el.value ? `${f.presentacion_1.el.value} X ${Number(f.unidad_presentacion.el.value)}`.toUpperCase() : '';
    secPresentacion.hidden = !pres || listas.presentaciones.map((x) => x.toUpperCase()).includes(pres);
  };
  let espera = null;
  const consultar = async () => {
    const auf = f.codigo_generico_audifarma.el.value.trim(); const cum = f.cum.el.value.trim();
    if (!auf && !cum) return;
    try {
      auto = await api('sol_autocompletar', { params: { cod_auf: auf, cum } });
      const m = auto.molecula;
      secMolecula.hidden = !auf || !!m;
      secAsignar.hidden = !(m && String(m.pos_no_pos).toUpperCase() === 'ASIGNAR');
      infoAuf.replaceChildren(!auf ? '' : m ? h('span', {}, chip('COD GEN', 'ok'), ` ${m.descripcion_general} · Cód. Medicar ${m.codigo_medicar || '—'} · ${m.pos_no_pos || ''}`)
        : h('span', { class: 'alerta-txt' }, 'No existe en COD GEN: diligencie la molécula abajo; el Administrador aprueba su creación.'));
      const inv = auto.invima; const mu = auto.mutual;
      if (inv) {
        poner('registro_sanitario', inv.registro_sanitario, 'De INVIMA');
        poner('estado_invima', listas.estado_invima.find((x) => x.toUpperCase() === String(inv.estado_registro).toUpperCase()) || '', 'De INVIMA');
        poner('fecha_reg_invima', inv.fecha_vencimiento ? String(inv.fecha_vencimiento).slice(0, 10) : '', 'Vencimiento del registro (INVIMA)');
        poner('nombre_comercial', mu?.nombre_comercial || inv.producto, mu?.nombre_comercial ? 'De la Maestra Mutual' : 'De INVIMA');
      }
      if (mu) { poner('codigo_atc', mu.codigo_atc, 'De la Maestra Mutual'); poner('codigo_ium', mu.codigo_ium, 'De la Maestra Mutual'); }
      // ID MUTUAL: el de la línea de la Maestra Tarifaria (el que se usa al crear); si el CUM no está en la Maestra Tarifaria, el de la Maestra Mutual
      const sp = auto.subplanes || [];
      subplanes.value = sp.map((x) => x.subplan).join(' + ');
      const avisos = sp.filter((x) => x.nota).map((x) => x.nota);
      notaSubplanes.className = avisos.length ? 'nota error' : 'nota';
      notaSubplanes.textContent = avisos.length ? avisos.join(' · ') : sp.length ? `Por: ${sp.map((x) => x.fuente).join(' · ')}` : (auf || cum ? 'Sin subplán: no está en el Listado Cápita, el Listado EVENTO SAS ni el Contrato Evento' : '');
      const lineaNt = (auto.nt || []).find((x) => x.contrato === 'EVENTO') || (auto.nt || [])[0];
      // orden: línea de la Maestra Tarifaria por el CUM → Maestra Mutual por el CUM → línea de la Maestra Tarifaria con el mismo COD AUF
      const porAuf = auto.nt_cod_auf;
      idMutual.value = lineaNt?.cod_mutual || mu?.id_mutual || porAuf?.cod_mutual || '';
      notaIdMutual.textContent = lineaNt ? `De la Maestra Tarifaria (${(auto.nt || []).map((x) => `${x.contrato}: ${x.cod_mutual}`).join(' · ')})`
        : mu?.id_mutual ? 'De la Maestra Mutual (el CUM aún no está en la Maestra Tarifaria)'
          : porAuf ? `De la Maestra Tarifaria por el COD AUF ${auf} (línea ${porAuf.contrato}, CUM ${porAuf.cum_facturar}): el CUM no tiene ID MUTUAL` : (cum ? 'Sin ID MUTUAL para este CUM ni para el COD AUF' : '');
      if (cum && !inv && !['NO APLICA', 'VITAL NO DISPONIBLE', 'PREPARADO MAGISTRAL'].includes(cum.toUpperCase())) f.cum.nota.textContent = 'El CUM no está en INVIMA';
      else f.cum.nota.textContent = '';
    } catch (e) { err.textContent = e.message; }
  };
  const alCambiar = () => { clearTimeout(espera); espera = setTimeout(consultar, 400); };
  f.codigo_generico_audifarma.el.addEventListener('input', alCambiar);
  f.cum.el.addEventListener('input', alCambiar);
  for (const k of ['laboratorio', 'presentacion_1', 'unidad_presentacion']) { f[k].el.addEventListener('input', revisarCatalogos); f[k].el.addEventListener('change', revisarCatalogos); }

  // ---- presentación: encabezado con pasos, secciones numeradas y pie fijo (fase 10)
  // el asterisco va junto al nombre del campo (el label es una rejilla: texto y asterisco en un mismo bloque)
  const obligatorio = (x) => { const t = x.label.firstChild; t.replaceWith(h('span', {}, t.textContent, h('span', { class: 'req' }, ' *'))); return x.label; };
  for (const k of Object.keys(f)) if (k !== 'fecha_reg_invima') obligatorio(f[k]);
  const pasos = ['Información', 'Validación', 'Confirmación'];
  const indicador = h('ol', { class: 'pasos' }, ...pasos.map((t, n) => h('li', { dataset: { n: n + 1 } }, h('span', { class: 'paso-num' }, String(n + 1)), h('span', {}, t))));
  const marcarPaso = (n) => [...indicador.children].forEach((li, k) => { li.classList.toggle('activo', k === n - 1); li.classList.toggle('hecho', k < n - 1); });
  const seccion = (n, titulo, ...hijos) => h('section', { class: 'seccion-form' }, h('h3', {}, h('span', { class: 'sec-num' }, String(n)), titulo), h('div', { class: 'rejilla-form' }, ...hijos));
  infoLab.className = 'info-azul'; infoLab.hidden = true;
  const vistaForm = h('div', { class: 'form-cuerpo' },
    seccion(1, 'Identificación del producto', f.codigo_generico_audifarma.label, f.cum.label, campoIdMutual, f.iva.label, f.tipo_medicamento.label, campoSubplanes, h('div', { class: 'ancho-total' }, infoAuf)),
    secMolecula, secAsignar,
    seccion(2, 'INVIMA y códigos', f.registro_sanitario.label, f.estado_invima.label, f.fecha_reg_invima.label, f.codigo_cum_auxiliar.label, f.codigo_ium.label, f.codigo_atc.label,
      h('div', { class: 'ancho-doble' }, f.nombre_comercial.label), h('p', { class: 'nota ancho-total' }, 'Se llenan solos desde INVIMA y la Maestra Mutual por el CUM; puede corregirlos.')),
    seccion(3, 'Laboratorio y presentación', h('div', { class: 'ancho-doble' }, f.laboratorio.label, infoLab), f.presentacion_1.label, f.unidad_presentacion.label),
    secPresentacion,
    seccion(4, 'Información RIPS', f.forma_farmaceutica_rips.label, f.concentracion_rips.label, f.unidad_medida_rips.label,
      h('div', { class: 'info-azul ancho-total' }, svgIcono('pendiente'), h('span', {}, 'El subplán, la categoría PBS, el precio y el código de producto se calculan al validar.'))));
  const vistaResultado = h('div', { class: 'form-cuerpo', hidden: true });
  const btnCancelar = h('button', { class: 'btn sec', value: 'cancelar', formnovalidate: true }, 'Cancelar');
  const btnGuardar = h('button', { class: 'btn primario', value: 'ok' }, 'Validar y guardar');
  const pie = h('div', { class: 'form-pie' }, h('span', { class: 'nota' }, h('span', { class: 'req' }, '* '), 'Campos obligatorios'), h('span', { class: 'espacio' }), btnCancelar, btnGuardar);
  form.append(
    h('div', { class: 'form-encabezado' },
      h('div', {}, h('h2', {}, p ? 'Editar producto' : 'Crear producto'), h('p', { class: 'nota' }, 'Completa la información y valida antes de guardar.')),
      indicador,
      h('button', { type: 'button', class: 'btn-icono cerrar-x', 'aria-label': 'Cerrar', onclick: () => dlg.close() }, '✕')),
    vistaForm, vistaResultado, err, pie);
  form.classList.add('form-producto');
  marcarPaso(1);
  let idGuardado = p?.id || 0;
  // paso 2 / 3: resultado de la validación del producto guardado
  const mostrarResultado = async () => {
    const d = await api('sol_actual');
    const x = d.productos.find((q) => q.id === idGuardado);
    if (!x) { dlg.close(); alGuardar(); return; }
    const k = x.calculado || {};
    const listo = ['VALIDADO', 'CON ALERTA'].includes(x.resultado);
    marcarPaso(listo ? 3 : 2);
    vistaForm.hidden = true; vistaResultado.hidden = false;
    vistaResultado.replaceChildren(h('section', { class: 'seccion-form' },
      h('div', { class: `resultado-caja ${listo ? (x.resultado === 'VALIDADO' ? 'r-ok' : 'r-alerta') : 'r-error'}` }, svgIcono(listo ? (x.resultado === 'VALIDADO' ? 'ok' : 'alerta') : 'error', 'ico-resultado'),
        h('div', {}, h('strong', {}, listo ? (x.resultado === 'VALIDADO' ? 'Producto listo para crear' : 'Producto listo para crear, con advertencias') : 'El producto tiene errores y no se puede crear todavía'),
          h('p', { class: 'nota' }, listo ? 'Quedó en la lista; se crea con el botón «Crear productos».' : 'Quedó guardado en la lista. Corrija los datos o espere las aprobaciones indicadas.'))),
      h('div', { class: 'datos-rejilla', style: 'margin:12px 0' },
        h('div', { class: 'dato' }, h('span', {}, 'Código producto'), h('strong', {}, x.codigo_producto_completo || '—')),
        h('div', { class: 'dato' }, h('span', {}, 'Descripción'), h('strong', {}, k.descripcion_generica || '—')),
        h('div', { class: 'dato' }, h('span', {}, 'ID MUTUAL'), h('strong', {}, k.cod_mutual || '—')),
        h('div', { class: 'dato' }, h('span', {}, 'Subplanes'), h('strong', {}, (k.subplanes || []).join(' + ') || '—')),
        h('div', { class: 'dato' }, h('span', {}, 'Precio M (SIESA)'), h('strong', {}, k.precio_m == null ? '—' : miles(Math.round(k.precio_m))))),
      x.resultados.length ? h('ul', { class: 'lista-validaciones' }, ...x.resultados.map((r) => h('li', { class: r.severidad === 'ERROR' ? 'error' : 'alerta-txt' },
        h('strong', {}, `${r.regla_codigo} · ${r.severidad === 'ERROR' ? 'Error' : 'Advertencia'}`), ` ${r.campo ? r.campo + ' = ' : ''}${r.valor_encontrado ?? ''}`,
        r.accion ? h('div', {}, '→ ', r.accion) : null))) : h('p', { class: 'nota' }, 'Sin observaciones.')));
    btnGuardar.hidden = true;
    btnCancelar.textContent = 'Cerrar';
    pie.querySelector('.req')?.parentElement.replaceChildren(h('span', {}));
    if (!listo) pie.insertBefore(h('button', { type: 'button', class: 'btn sec', onclick: () => {
      vistaResultado.hidden = true; vistaForm.hidden = false; btnGuardar.hidden = false; btnCancelar.textContent = 'Cancelar'; marcarPaso(1);
      pie.querySelectorAll('.btn-corregir').forEach((b) => b.remove());
    }, class: 'btn sec btn-corregir' }, svgIcono('editar'), 'Corregir'), btnCancelar);
    alGuardar(); // la lista de fondo se actualiza
  };
  form.onsubmit = async (ev) => {
    if (ev.submitter?.value === 'cancelar') return;
    ev.preventDefault();
    err.textContent = '';
    const v = Object.fromEntries(Object.entries(f).map(([k, x]) => [k, x.el.value]));
    const datos = { ...v, lote_id: loteId, id: idGuardado };
    if (!secMolecula.hidden) datos.molecula_nueva = Object.fromEntries(Object.entries(mol).map(([k, x]) => [k, x.el.value]));
    if (!secAsignar.hidden && asignar.el.value) datos.asignar_pos = asignar.el.value;
    if (!secPresentacion.hidden) datos.presentacion_nueva = { unidad_orden: unidadOrden.el.value.trim().toUpperCase() };
    btnGuardar.disabled = true; btnGuardar.textContent = 'Validando…';
    try {
      const r = await api('sol_item_guardar', { metodo: 'POST', datos });
      idGuardado = r.id;
      await mostrarResultado();
    } catch (e) { err.textContent = e.message; }
    btnGuardar.disabled = false; btnGuardar.textContent = 'Validar y guardar';
  };
  dlg.oncancel = null;
  dlg.classList.add('ancho');
  dlg.classList.add('muy-ancho');
  dlg.addEventListener('close', () => { if (!dlg.open) { dlg.classList.remove('ancho', 'muy-ancho'); form.classList.remove('form-producto'); } }, { once: true });
  if (!dlg.open) dlg.showModal();
  // si se viene de otra ventana (p.ej. el detalle 👁), su cierre llega después y quitaría el ancho: se vuelve a poner
  setTimeout(() => { if (dlg.open && form.classList.contains('form-producto')) dlg.classList.add('ancho', 'muy-ancho'); }, 0);
  revisarCatalogos();
  if (ini.codigo_generico_audifarma || ini.cum) consultar();
}

/**
 * Carga masiva (6.9): plantilla Excel (o la hoja FORMATO del Excel actual) -> vista previa con la validación de cada
 * fila -> agregar a «Crear productos». Las celdas vacías de INVIMA y códigos se llenan solas, como en el formulario.
 */
function cargarProductosExcel(alAplicar) {
  const dlg = $('#dialogo'); const form = $('#dialogo-form');
  dlg.onclose = null; dlg.oncancel = null;
  const input = h('input', { type: 'file', accept: '.xlsx,.xlsm' });
  const msg = h('span', { class: 'nota' });
  const resultado = h('div');
  const btn = h('button', { type: 'button', class: 'btn sec', onclick: () => procesar(false) }, 'Previsualizar');
  async function procesar(aplicar) {
    const archivo = input.files[0];
    if (!archivo) { msg.textContent = 'Seleccione el archivo.'; return; }
    btn.disabled = true;
    msg.textContent = aplicar ? 'Agregando…' : 'Leyendo y validando…';
    try {
      const r = await subirArchivo('sol_importar', { modo: aplicar ? 'aplicar' : 'previsualizar' }, archivo, (p) => { msg.textContent = p < 100 ? `Subiendo ${p} %…` : (aplicar ? 'Agregando…' : 'Leyendo y validando…'); });
      msg.textContent = '';
      const s = r.resumen;
      if (r.aplicado) {
        toast(`${miles(s.cargados)} productos agregados: ${miles(s.validados + s.con_alerta)} se pueden crear y ${miles(s.con_error)} tienen error (corríjalos en la lista).`);
        dlg.close(); alAplicar(); return;
      }
      const bloques = [h('p', { class: 'nota' }, `Hoja «${s.hoja}», encabezados en la fila ${s.fila_encabezado}.`),
        h('div', { class: 'fila-acciones' }, chip(`${miles(s.filas)} filas`, 'gris'), chip(`${miles(s.validados)} validados`, 'ok'), chip(`${miles(s.con_alerta)} con alerta`, 'alerta'),
          chip(`${miles(s.con_error)} con error`, 'error'), s.omitidas ? chip(`${miles(s.omitidas)} no se cargan`, 'gris') : null)];
      if (s.columnas_faltantes.length) bloques.push(h('div', { class: 'aviso-caja', style: 'margin-top:8px' }, `Columnas que no vienen en el archivo (quedan vacías o se llenan solas): ${s.columnas_faltantes.join(', ')}`));
      if (r.omitidas.length) bloques.push(h('div', { class: 'aviso-caja', style: 'margin-top:8px' }, h('strong', {}, 'No se cargan:'),
        h('ul', { class: 'lista-avisos' }, ...r.omitidas.map((o) => h('li', {}, `Fila ${o.fila}: ${o.motivo}`)))));
      if (r.productos.length) bloques.push(tablaConFiltros(['Fila Excel', 'Estado', 'COD AUF', 'CUM', 'Laboratorio', 'Código producto', 'Subplanes', 'Observaciones'],
        r.productos.map((p) => h('tr', {}, h('td', { class: 'num' }, String(p.fila_excel)), h('td', {}, chip(p.resultado, SOL_ESTADOS[p.resultado] || 'gris')),
          h('td', {}, p.cod_auf ?? ''), h('td', {}, p.cum ?? ''), h('td', {}, p.laboratorio ?? ''), h('td', {}, p.codigo ?? ''), h('td', {}, p.subplanes ?? ''),
          h('td', {}, h('div', { class: 'motivos' }, ...p.motivos.map((m) => h('div', { class: /^E/.test(m) ? 'error' : 'alerta-txt' }, m)))))),
        { sinFiltro: [7], alto: 360 }));
      bloques.push(h('div', { class: 'fila-acciones', style: 'margin-top:10px' },
        h('button', { type: 'button', class: 'btn primario', disabled: !r.productos.length, onclick: () => procesar(true) }, `Agregar ${miles(r.productos.length)} productos a la lista`),
        h('span', { class: 'nota' }, 'Los que tienen error quedan en la lista para corregirlos; «Crear» solo crea los que no tienen error.')));
      resultado.replaceChildren(...bloques);
    } catch (e) { msg.textContent = ''; toast(e.message, true); }
    btn.disabled = false;
  }
  input.addEventListener('change', () => resultado.replaceChildren());
  form.replaceChildren(h('h2', {}, 'Cargar productos desde Excel'),
    h('ol', { class: 'nota' },
      h('li', {}, 'Descargue la plantilla (hoja FORMATO con las 17 columnas digitadas, más INSTRUCCIONES y LISTAS) o use la hoja FORMATO del Excel CREAR ITEMS.'),
      h('li', {}, 'Una fila por producto. Si deja vacíos registro, estado y fecha INVIMA, IUM, ATC o nombre comercial, se llenan solos como en el formulario.'),
      h('li', {}, 'Previsualice: cada fila se valida con las mismas reglas. Luego agréguelas a la lista.')),
    h('div', { class: 'fila-acciones' }, h('a', { class: 'btn sec chico', href: 'api.php?accion=sol_plantilla' }, '⬇ Descargar plantilla'), input, btn, msg),
    resultado,
    h('div', { class: 'fila-acciones' }, h('button', { class: 'btn sec derecha', value: 'cancelar', formnovalidate: true }, 'Cerrar')));
  form.onsubmit = () => {};
  dlg.classList.add('ancho');
  dlg.addEventListener('close', () => { if (!dlg.open) dlg.classList.remove('ancho'); }, { once: true }); // si ya se abrió otra ventana, no se toca
  if (!dlg.open) dlg.showModal();
}

/** Creaciones realizadas: volver a descargar los 6 archivos de cada una. */
async function historialCreaciones() {
  const r = await api('cre_historial');
  const dlg = $('#dialogo'); const form = $('#dialogo-form');
  dlg.onclose = null; dlg.oncancel = null;
  const filas = r.creaciones.map((x) => h('tr', {},
    h('td', {}, String(x.id)), h('td', {}, x.inicio.slice(0, 16)), h('td', {}, x.usuario ?? ''), h('td', { class: 'num' }, miles(x.creados)),
    h('td', {}, h('span', { class: 'nota' }, x.codigos ?? '')),
    h('td', {}, h('a', { class: 'btn sec chico', href: `api.php?accion=cre_descargar&id=${x.id}` }, '⬇ 6 archivos (ZIP)'))));
  form.replaceChildren(h('h2', {}, 'Creaciones y archivos de cargue'),
    r.creaciones.length ? tablaConFiltros(['#', 'Fecha', 'Usuario', 'Productos', 'Códigos', ''], filas, { sinFiltro: [5], alto: 420 }) : h('p', { class: 'nota' }, 'Aún no se ha creado ningún producto.'),
    h('div', { class: 'fila-acciones' }, h('button', { class: 'btn sec derecha', value: 'cancelar', formnovalidate: true }, 'Cerrar')));
  form.onsubmit = () => {};
  dlg.classList.add('ancho');
  dlg.addEventListener('close', () => { if (!dlg.open) dlg.classList.remove('ancho'); }, { once: true }); // si ya se abrió otra ventana, no se toca
  if (!dlg.open) dlg.showModal();
}

/** Aprobaciones del Administrador: revisa los datos, los ajusta si hace falta y aprueba (se aplica en el catálogo / Maestra Tarifaria) o rechaza. */
async function panelAprobaciones(alCambiar) {
  const r = await api('sol_aprobaciones');
  const resolver = async (a, aprobar, ajustes = {}, motivo = '') => {
    await api('sol_resolver', { metodo: 'POST', datos: { id: a.id, aprobar, ajustes, motivo } });
    toast(aprobar ? 'Aprobado: se aplicó y las solicitudes se revalidaron.' : 'Rechazado.'); alCambiar();
  };
  const aprobar = (a) => {
    const d = a.datos || {};
    if (a.tipo === 'INCLUIR_NT') { // se revisa en el formulario «Nuevo» de la Maestra Tarifaria y con Guardar se incluye
      nuevoNT(() => resolver(a, true, { _ya_incluido: true }, 'Incluido en la Maestra Tarifaria'), { cod_auf: d.cod_auf, cum_facturar: d.cum_facturar, cum: d.cum, cod_mutual: d.cod_mutual,
        _aprobacion: { por: a.solicitado_por_nombre || '—', origen: `fila ${a.fila} de Crear productos`, volver: () => panelAprobaciones(alCambiar) } });
      return;
    }
    const campos = {
      MOLECULA: [['cod_gen_audifarma', 'Código genérico Audifarma'], ['descripcion_general', 'Descripción genérica'], ['concentracion_agrupada', 'Concentración'],
        ['principio_activo', 'Principio activo'], ['forma_farmaceutica', 'Forma farmacéutica'], ['grupo_farmacologico', 'Grupo farmacológico'], ['via_administracion', 'Vía'],
        ['pos_no_pos', 'POS / NO POS'], ['cadena_frio', 'Cadena de frío']],
      LABORATORIO: [['nombre', 'Nombre del laboratorio']],
      PRESENTACION: [['envase', 'Envase'], ['cantidad', 'Cantidad'], ['unidad_orden', 'Unidad de orden']],
      ASIGNAR_POS: [['cod_gen_audifarma', 'COD GEN'], ['pos_no_pos', 'POS / NO POS']],
    }[a.tipo] || [];
    dialogo({
      titulo: `Aprobar: ${SOL_TIPOS_APROBACION[a.tipo]}`, texto: `Producto fila ${a.fila}. Revise y corrija si hace falta: al aprobar se aplica en el catálogo y se revalida.`
        + (a.tipo === 'MOLECULA' ? ' El Código Medicar se asigna solo (siguiente consecutivo libre).' : ''),
      campos: campos.map(([k, t]) => (k === 'pos_no_pos' ? { nombre: k, etiqueta: t, tipo: 'select', valor: d[k], opciones: [['POS', 'POS'], ['NO POS', 'NO POS']] }
        : { nombre: k, etiqueta: t, valor: d[k] ?? '', requerido: !['cadena_frio'].includes(k) })),
      aceptar: 'Aprobar',
      alEnviar: (v) => resolver(a, true, v),
    });
  };
  const rechazar = (a) => dialogo({
    titulo: 'Rechazar', texto: 'El producto sigue en error hasta que se corrija.', campos: [{ nombre: 'motivo', etiqueta: 'Motivo', requerido: true }], aceptar: 'Rechazar',
    alEnviar: (v) => resolver(a, false, {}, v.motivo),
  });
  // Una tarjeta por solicitud (fase 10): qué se pide, para qué producto, los datos con su nombre y las acciones.
  const ETQ_DATO = { cum_facturar: 'CUM a facturar', cod_auf: 'COD AUF', cod_gen_audifarma: 'COD GEN', contrato: 'Línea', descripcion: 'Descripción',
    descripcion_general: 'Descripción genérica', cum: 'CUM', cod_mutual: 'ID MUTUAL', nombre: 'Laboratorio', envase: 'Envase', cantidad: 'Cantidad',
    unidad_orden: 'Unidad de orden', pos_no_pos: 'POS / NO POS', codigo_medicar: 'Cód. Medicar', concentracion_agrupada: 'Concentración',
    principio_activo: 'Principio activo', forma_farmaceutica: 'Forma farmacéutica', grupo_farmacologico: 'Grupo farmacológico', via_administracion: 'Vía', cadena_frio: 'Cadena de frío' };
  const AYUDA = { INCLUIR_NT: 'Al aprobar se abre el formulario de la Maestra Tarifaria con estos datos para revisar el ID MUTUAL y el precio; con «Aprobar e incluir» se crean las líneas.' };
  const tarjetas = r.pendientes.map((a) => h('article', { class: 'aprob-tarjeta', dataset: { tipo: a.tipo } },
    h('div', { class: 'aprob-cab' },
      h('span', { class: 'chip-estado e-alerta' }, svgIcono('pendiente'), SOL_TIPOS_APROBACION[a.tipo] || a.tipo),
      h('span', { class: 'aprob-origen' }, `Fila ${a.fila} · COD AUF ${a.codigo_generico_audifarma ?? '—'} · CUM ${a.cum ?? '—'}`),
      h('span', { class: 'espacio' }),
      h('span', { class: 'nota' }, `${a.solicitado_por_nombre ?? ''} · ${(a.solicitado_en || '').slice(0, 16)}`)),
    h('div', { class: 'datos-rejilla' }, ...Object.entries(a.datos || {}).filter(([k, v]) => !k.startsWith('_') && v !== null && v !== '')
      .map(([k, v]) => h('div', { class: 'dato' }, h('span', {}, ETQ_DATO[k] || k.replace(/_/g, ' ')), h('strong', {}, String(v))))),
    h('div', { class: 'aprob-pie' }, h('span', { class: 'nota' }, AYUDA[a.tipo] || 'Al aprobar puede revisar y corregir los datos antes de aplicarlos en el catálogo.'),
      h('span', { class: 'espacio' }),
      // type=button: están dentro del formulario del diálogo; sin él, el clic también enviaba el formulario de edición
      // y se aprobaba sin dejar corregir los datos
      h('button', { type: 'button', class: 'btn sec chico', onclick: () => rechazar(a) }, 'Rechazar'),
      h('button', { type: 'button', class: 'btn primario chico', onclick: () => aprobar(a) }, svgIcono('aprobar'), 'Aprobar'))));
  // conteo por tipo: filtra las tarjetas
  const porTipo = {};
  r.pendientes.forEach((a) => { porTipo[a.tipo] = (porTipo[a.tipo] || 0) + 1; });
  let tipoSel = null;
  const chips = Object.entries(porTipo).map(([t, n]) => h('button', { type: 'button', class: 'chip-estado e-gris chip-filtro', dataset: { tipo: t },
    onclick: () => { tipoSel = tipoSel === t ? null : t; pintarFiltro(); } }, `${miles(n)} ${SOL_TIPOS_APROBACION[t] || t}`));
  const pintarFiltro = () => {
    tarjetas.forEach((x) => { x.hidden = tipoSel !== null && x.dataset.tipo !== tipoSel; });
    chips.forEach((x) => x.classList.toggle('e-info', x.dataset.tipo === tipoSel));
  };
  const dlg = $('#dialogo'); const form = $('#dialogo-form');
  dlg.onclose = null; dlg.oncancel = null;
  const encabezado = h('div', { class: 'form-encabezado' },
    h('div', {}, h('h2', {}, 'Aprobaciones pendientes'),
      h('p', { class: 'nota' }, r.pendientes.length ? 'Productos de Crear productos que esperan una decisión del Administrador. Al aprobar o rechazar, se revalidan.' : 'No hay solicitudes por resolver.')),
    h('button', { type: 'button', class: 'btn-icono cerrar-x', 'aria-label': 'Cerrar', onclick: () => dlg.close() }, '✕'));
  form.replaceChildren(encabezado,
    r.pendientes.length ? h('div', { class: 'resumen-estados' }, h('span', { class: 'nota' }, `${miles(r.pendientes.length)} pendientes:`), ...chips) : '',
    r.pendientes.length ? h('div', { class: 'aprob-lista' }, ...tarjetas)
      : h('div', { class: 'vacio-caja' }, svgIcono('ok', 'ico-vacio'), h('p', { class: 'nota' }, 'Todo al día.')),
    h('div', { class: 'form-pie' }, h('span', { class: 'espacio' }), h('button', { class: 'btn sec', value: 'cancelar', formnovalidate: true }, 'Cerrar')));
  form.onsubmit = () => {};
  form.classList.add('form-producto');
  dlg.classList.add('ancho');
  dlg.addEventListener('close', () => {
    if (!dlg.open || !form.contains(encabezado)) form.classList.remove('form-producto');
    if (!dlg.open) dlg.classList.remove('ancho');
  }, { once: true }); // si ya se abrió otra ventana, no se toca
  if (!dlg.open) dlg.showModal();
}

// ------------------------------------------------------------------ Maestra Tarifaria (fase 4)
/** Nombre de la línea de la Maestra Tarifaria para mostrar: EVENTO SAS no es un contrato, es el subplán SAS del contrato EVENTO. */
/** Columnas de precio de la Maestra Tarifaria (no se muestran al perfil «Consulta sin precios»). */
const NT_COLUMNAS_PRECIO = ['precio_venta', 'precio_final', 'precio_contrato', 'precio_maximo', 'precio_regulado', 'tarifa_capita', 'precio_aplica', 'fuente_precio'];
const lineaNT = (k) => (k === 'EVENTO SAS' ? 'EVENTO · EVENTO SAS SIN AUTORIZACION' : k);
const pesos = (v) => (v === null || v === undefined || v === '' ? '' : `$ ${miles(Math.round(Number(v)))}`);

/**
 * Maestra Tarifaria: se mantiene en el aplicativo y se exporta a Excel. Las columnas calculadas (contrato,
 * validadores, precio + IVA, ¿en contrato?, ¿precio correcto?, CUM ↔ código, regulado) las recalcula el
 * servidor contra las bases vigentes en cada consulta.
 */
async function pNota(c) {
  const recargar = () => { c.replaceChildren(); pNota(c); };
  let meta;
  try { meta = await api('nt_listar', { params: { pagina: 1, tam: 10 } }); } catch (e) { c.append(h('p', { class: 'error' }, e.message)); return; }
  const r = meta.resumen;
  const gestionar = meta.puede_gestionar;
  const nombreRegla = Object.fromEntries(meta.reglas.map((x) => [x.codigo, x.descripcion.replace('Maestra Tarifaria: ', '')]));
  let tabla = null;
  // Indicador clicable: filtra la tabla para corregir (reemplaza los filtros; «Borrar filtros» vuelve a todo).
  const indicador = (texto, tipo, filtros) => h('button', { type: 'button', class: `chip ${tipo} chip-filtro`, title: 'Filtrar la tabla',
    onclick: () => tabla?.filtrar(filtros) }, texto);
  // Encabezado compacto (decisión del usuario, 2026-09-29): una barra con los conteos y las acciones, una línea
  // con las reglas que tienen casos, y los paneles (importar, por agregar, precio 1) se abren de a uno bajo la barra.
  // Todo se repinta con cada carga de la tabla: lo que ya no tiene casos (una regla corregida, el precio 1 aprobado,
  // nada por agregar) desaparece de la vista.
  // Diseño fase 10: barra de acciones (como Crear productos); los estados y las reglas van sobre la tabla
  const barra = h('div', { class: 'tarjeta barra-acciones' });
  const estadosZona = h('div', { class: 'tarjeta nt-resumen' });
  const reglasZona = h('div', { class: 'nt-reglas' });
  const panelZona = h('div', { class: 'nt-panel-zona' });
  let panelActivo = null;
  let ultimo = r;
  const abrirPanel = (nombre, crear) => {
    if (panelActivo === nombre) { panelActivo = null; panelZona.replaceChildren(); pintarResumen(ultimo); return; }
    const p = crear();
    p.classList.add('nt-panel');
    p.open = true;
    panelZona.replaceChildren(p);
    p.mostrar?.();
    panelActivo = nombre;
    pintarResumen(ultimo);
  };
  const botonPanel = (nombre, texto, crear, ico = null) => h('button', { type: 'button', class: `btn sec${panelActivo === nombre ? ' activo' : ''}`,
    onclick: () => abrirPanel(nombre, crear) }, ico ? svgIcono(ico) : null, texto);
  const menuExportar = () => {
    const opcion = (texto, titulo, accion) => h('button', { type: 'button', class: 'menu-opcion', title: titulo, onclick: (ev) => { ev.target.closest('details').open = false; accion(); } }, texto);
    return h('details', { class: 'menu-exportar' }, h('summary', { class: 'btn sec' }, svgIcono('bajar'), 'Exportar ▾'),
      h('div', { class: 'menu-lista' },
        opcion('Toda la Maestra Tarifaria', 'Todas las líneas activas', () => { window.location.href = 'api.php?accion=nt_exportar'; }),
        opcion('Lo filtrado en la tabla', 'Las filas que se ven con los filtros actuales', () => descargar(false)),
        opcion('Errores a corregir', 'Solo las líneas con error o alerta (con los filtros actuales), con la observación y cómo corregirla', () => descargar(true))));
  };
  const pintarResumen = (x) => {
    ultimo = x;
    const porAgregar = (x.capita_por_agregar || 0) + (x.evento_sas_por_agregar || 0);
    // Resumen (diseño fase 10): total, estados que filtran y las reglas con casos en un desplegable
    const item = (ico, n, texto, k, clave) => h('button', { type: 'button', class: `nt-res-item r-${k}`, title: 'Ver solo estas líneas',
      onclick: () => { verRegla(null); tabla?.filtrar({ estado: [clave] }); } }, svgIcono(ico), h('strong', {}, miles(n)), h('span', {}, texto));
    const conCasos = meta.reglas.filter((g) => x.reglas[g.codigo]);
    const menuReglas = conCasos.length ? h('details', { class: 'menu-exportar nt-reglas-menu' },
      h('summary', { class: 'btn sec nt-reglas-btn' }, svgIcono('lista'), `${conCasos.length} ${conCasos.length === 1 ? 'regla' : 'reglas'} con casos`, h('span', { class: 'flecha' }, '▾')),
      h('div', { class: 'menu-lista nt-reglas-lista' },
        h('div', { class: 'nt-reglas-tit' }, 'Clic en una regla para ver sus líneas y cómo corregirla'),
        ...conCasos.map((g) => h('button', { type: 'button', class: 'menu-opcion nt-regla-op', onclick: () => verRegla(g, x.reglas[g.codigo]) },
          h('span', { class: `chip ${g.severidad === 'ERROR' ? 'error' : 'alerta'}` }, g.codigo),
          h('span', { class: 'nt-regla-txt' }, nombreRegla[g.codigo]),
          h('strong', {}, miles(x.reglas[g.codigo])))))) : '';
    estadosZona.replaceChildren(
      h('div', { class: 'nt-res-total' }, h('span', { class: 'nt-res-ico' }, svgIcono('archivo')), h('strong', {}, miles(x.total)), h('span', {}, 'líneas')),
      item('ok', x.VALIDADO, 'validadas', 'ok', 'VALIDADO'),
      x['CON ALERTA'] ? item('alerta', x['CON ALERTA'], 'con alerta', 'alerta', 'CON ALERTA') : '',
      x.ERROR ? item('error', x.ERROR, 'con error', 'error', 'ERROR') : '',
      x.INACTIVO ? item('pendiente', x.INACTIVO, 'inactivas', 'gris', 'INACTIVO') : '',
      h('span', { class: 'espacio' }), menuReglas);
    barra.replaceChildren(
      h('div', { class: 'grupo-acciones' },
        gestionar ? h('button', { class: 'btn primario', onclick: () => editarNT(null, recargar) }, svgIcono('mas'), 'Nuevo producto') : '',
        gestionar ? botonPanel('importar', 'Importar Excel', () => panelImportarNT(true, recargar), 'subir') : '',
        porAgregar ? botonPanel('agregar', `Por agregar (${miles(porAgregar)})`, () => panelPorAgregar(porAgregar, gestionar, recargar), 'crear') : '',
        x.precio_uno_pendiente ? botonPanel('uno', `Precio 1 por aprobar (${miles(x.precio_uno_pendiente)})`, () => panelPrecioUno(gestionar, recargar), 'aprobar') : ''),
      h('div', { class: 'grupo-acciones' },
        // pendientes: solo aparecen mientras haya algo por hacer
        x.lineas_por_repetir && gestionar ? h('button', { class: 'btn sec resaltado', onclick: async () => {
          const res = await api('nt_repetir_lineas', { metodo: 'POST' });
          toast(`${miles(res.lineas)} líneas creadas en los contratos que faltaban.`); recargar();
        } }, svgIcono('crear'), `Crear ${miles(x.lineas_por_repetir)} líneas en otros contratos`) : '',
        x.inactivas_medicar && gestionar ? h('button', { class: 'btn sec resaltado', title: 'Líneas inactivas en SIESA y MEDICAR (NT27) o sin PLU en ninguno (NT30)', onclick: async () => {
        if (!await confirmar('Retirar de la Maestra Tarifaria', `Se retiran ${miles(x.inactivas_medicar)} líneas: inactivas en SIESA y en MEDICAR (NT27) o sin PLU en ninguno de los dos (NT30). Antes se guarda la Maestra Tarifaria completa como versión anterior (se consulta y descarga en «Versiones»). Las líneas retiradas no se vuelven a proponer.`, 'Retirar')) return;
        const res = await api('nt_quitar_medicar', { metodo: 'POST' });
        toast(`${miles(res.lineas)} líneas quitadas. La Maestra Tarifaria anterior quedó como versión ${res.version}.`); recargar();
      } }, svgIcono('quitar'), `Retirar ${miles(x.inactivas_medicar)} (inactivas o sin PLU)`) : '',
        h('button', { class: 'btn sec', title: 'Historial de versiones de la Maestra Tarifaria', onclick: () => versionesNT() }, svgIcono('capas'), 'Versiones'),
        h('button', { class: 'btn sec', title: 'Cambios de la Maestra Tarifaria por fecha (creadas, editadas, quitadas, importaciones)', onclick: () => cambiosNT() }, svgIcono('reloj'), 'Cambios'),
        h('button', { class: 'btn sec', title: 'CUM revisados que sí deben tener varios PLU activos en la Maestra Tarifaria', onclick: () => aprobacionesVariosPlu(gestionar, recargar) }, svgIcono('ok'), 'Varios PLU aprobados'),
        meta.puede_descargar && x.total ? menuExportar() : ''));
  };
  const verRegla = (g, n) => {
    if (!g) { reglasZona.replaceChildren(); return; }
    tabla?.filtrar({ reglas: [g.codigo] });
    reglasZona.replaceChildren(h('div', { class: `nt-regla-info ${g.severidad === 'ERROR' ? 'v-error' : 'v-alerta'}` },
      h('div', { class: 'val-cab' }, svgIcono(g.severidad === 'ERROR' ? 'error' : 'alerta'), h('strong', {}, `${g.codigo} · ${nombreRegla[g.codigo]}`),
        h('span', { class: 'nota' }, ` ${miles(n || 0)} líneas`), h('span', { class: 'espacio' }),
        h('button', { type: 'button', class: 'btn sec chico', onclick: () => { verRegla(null); tabla?.filtrar({}); } }, '✕ Quitar filtro')),
      g.accion_sugerida ? h('div', { class: 'val-accion' }, '→ Cómo corregir: ', g.accion_sugerida) : null));
  };
  const descargar = (errores) => {
    const q = new URLSearchParams({ accion: 'nt_exportar', filtros: JSON.stringify(tabla?.filtros() || {}) });
    if (errores) q.set('errores', '1');
    window.location.href = `api.php?${q}`;
  };

  c.append(barra, estadosZona,
    meta.bases_faltantes.length ? h('div', { class: 'aviso-caja', style: 'margin-bottom:10px' },
      h('strong', {}, 'Bases sin cargar: '), meta.bases_faltantes.join(', '), '. Las validaciones que dependen de ellas no se evalúan.') : '',
    panelZona);
  pintarResumen(r);

  if (gestionar && !r.total) abrirPanel('importar', () => panelImportarNT(true, recargar));
  if (!r.total && !r.INACTIVO) {
    c.append(h('div', { class: 'info-caja' }, gestionar ? 'La Maestra Tarifaria está vacía: impórtela desde el Excel actual (hoja EVENTO 2025) con el panel de arriba.' : 'La Maestra Tarifaria aún no se ha cargado.'));
    return;
  }

  // Tabla con las columnas de la hoja EVENTO 2025 (mismos nombres y orden) + estado y observaciones.
  const semaforo = (v) => {
    const t = String(v ?? '');
    if (/^(HOMOLOGAR|SI|\(OK)/.test(t)) return 'c-ok';
    if (/^(\(AGREGAR|REVISAR)/.test(t)) return 'c-alerta';
    if (/^NO APLICA/.test(t)) return '';
    if (/^(VALIDAR|NO|GLOSA|\(ERROR)/.test(t)) return 'c-error';
    return '';
  };
  const colorDe = (campo) => (f) => semaforo(f[campo]);
  const num = (v) => (v === null || v === undefined || v === '' ? '' : miles(Math.round(Number(v))));
  const NT_ICO = { VALIDADO: 'ok', 'CON ALERTA': 'alerta', ERROR: 'error', INACTIVO: 'pendiente' };
  const NT_ETQ = { VALIDADO: 'Validada', 'CON ALERTA': 'Alerta', ERROR: 'Error', INACTIVO: 'Inactiva' };
  const NT_CLS = { VALIDADO: 'ok', 'CON ALERTA': 'alerta', ERROR: 'error', INACTIVO: 'gris' };
  // ESTADO en la tabla (pedido del usuario, 2026-11-05): solo Activo | Inactivo, de la línea o (en su fila) del PLU
  const estadoNT = (f) => (Number(f.activo) === 1
    ? h('span', { class: 'chip-estado e-ok', title: 'Activo' }, svgIcono('ok'), 'Activo')
    : h('span', { class: 'chip-estado e-gris', title: f.plu_inactivo ? `El PLU ${f.plu} está inactivo (en todos los contratos y subplanes del CUM)` : 'Inactivo: no se valida ni se usa al crear productos' },
      svgIcono('pendiente'), 'Inactivo'));
  // ---- activar / inactivar de forma masiva (pedido del usuario, 2026-10-16)
  const seleccion = new Set(); let todasFiltradas = false; let totalFiltrado = 0;
  const barraSel = h('div', { class: 'sel-barra', hidden: true });
  const pintarSeleccion = () => {
    const n = todasFiltradas ? totalFiltrado : seleccion.size;
    barraSel.hidden = !gestionar;
    if (!gestionar) return;
    const pagina = () => [...document.querySelectorAll('.tabla-nt .sel-fila')];
    barraSel.replaceChildren(...[
      h('span', { class: `sel-n${n ? '' : ' sel-ayuda'}` }, n ? `${miles(n)} ${n === 1 ? 'línea seleccionada' : 'líneas seleccionadas'}${todasFiltradas ? ' (todas las filtradas)' : ''}` : 'Seleccione líneas con la casilla para activarlas o inactivarlas'),
      h('button', { type: 'button', class: 'btn sec chico', onclick: () => { todasFiltradas = false; pagina().forEach((c) => { c.checked = true; seleccion.add(c.dataset.clave); }); pintarSeleccion(); } }, 'Seleccionar esta página'),
      h('button', { type: 'button', class: 'btn sec chico', onclick: () => { todasFiltradas = true; pagina().forEach((c) => { c.checked = true; }); pintarSeleccion(); } }, `Seleccionar las ${miles(totalFiltrado)} filtradas`),
      n ? h('button', { type: 'button', class: 'btn sec chico', onclick: () => { seleccion.clear(); todasFiltradas = false; pagina().forEach((c) => { c.checked = false; }); pintarSeleccion(); } }, 'Quitar selección') : null,
      h('span', { class: 'espacio' }),
      n ? h('button', { type: 'button', class: 'btn sec chico btn-peligro', onclick: () => aplicarMasivo(false, n) }, svgIcono('quitar'), 'Inactivar') : null,
      n ? h('button', { type: 'button', class: 'btn primario chico', onclick: () => aplicarMasivo(true, n) }, svgIcono('ok'), 'Activar') : null].filter(Boolean));
  };
  const aplicarMasivo = (activar, n) => dialogo({
    titulo: `${activar ? 'Activar' : 'Inactivar'} ${miles(n)} ${n === 1 ? 'línea' : 'líneas'} de la Maestra Tarifaria`,
    texto: activar ? 'Las líneas vuelven a validarse y a usarse en la creación de productos.'
      : 'Las líneas inactivas no se validan ni se usan al crear productos (no se borran: se pueden volver a activar). Antes se guarda la foto del día.',
    campos: [{ nombre: 'motivo', etiqueta: 'Motivo', requerido: true, placeholder: 'Ej.: retirados del contrato a partir de octubre' }],
    aceptar: activar ? 'Activar' : 'Inactivar',
    alEnviar: async (v) => {
      const r = await api('nt_activar_masivo', { metodo: 'POST', datos: { activar, motivo: v.motivo, todas_filtradas: todasFiltradas,
        filtros: todasFiltradas ? tabla.filtros() : null, filas: todasFiltradas ? [] : [...seleccion].filter(Boolean) } });
      toast(`${miles(r.cambiadas)} ${r.cambiadas === 1 ? 'línea' : 'líneas'} ${activar ? 'activadas' : 'inactivadas'}${r.sin_cambio ? ` · ${miles(r.sin_cambio)} ya estaban así` : ''}. Queda en auditoría.`);
      seleccion.clear(); todasFiltradas = false; tabla.refrescar();
    },
  });
  const accionesFila = (f) => {
    const validarCum = async (validar) => {
      const ok = await confirmar(validar ? 'Validar CUM a mano' : 'Retirar validación del CUM',
        validar ? `El CUM ${f.cum_facturar} no está en INVIMA. Confirme que lo verificó a mano: la alerta NT10 deja de salir en todas sus líneas. Queda en auditoría.`
          : `La alerta NT10 vuelve a salir en las líneas del CUM ${f.cum_facturar}.`, validar ? 'Validar' : 'Retirar');
      if (!ok) return;
      await api('nt_validar_cum', { metodo: 'POST', datos: { cum_facturar: f.cum_facturar, validar } });
      toast(validar ? 'CUM validado.' : 'Validación retirada.'); tabla.refrescar();
    };
    const nt10 = f.motivos.some((m) => m.codigo === 'NT10');
    const extra = [nt10 ? ['Validar CUM a mano', () => validarCum(true), 'ok'] : null,
      f.cum_validado_en ? [`Retirar validación del CUM (${String(f.cum_validado_en).slice(0, 10)})`, () => validarCum(false), 'quitar'] : null].filter(Boolean);
    // activar / inactivar por PLU + CONTRATO: cada fila es «id de la línea|PLU»
    const clave = `${f.id}|${f.plu ?? ''}`;
    const casilla = h('input', { type: 'checkbox', class: 'sel-fila', dataset: { clave }, checked: seleccion.has(clave), title: 'Seleccionar para activar / inactivar este PLU (en todos los contratos y subplanes del CUM)',
      'aria-label': `Seleccionar ${f.cum_facturar}${f.plu ? ` PLU ${f.plu}` : ''}`, onchange: (ev) => { if (ev.target.checked) seleccion.add(clave); else seleccion.delete(clave); todasFiltradas = false; pintarSeleccion(); } });
    return h('div', { class: 'acciones-fila' }, casilla,
      h('button', { type: 'button', class: 'btn-icono', title: 'Editar', 'aria-label': 'Editar', onclick: () => editarNT(f, () => tabla.refrescar()) }, svgIcono('editar')),
      extra.length ? h('details', { class: 'menu-exportar' }, h('summary', { class: 'btn-icono', title: 'Más acciones', 'aria-label': 'Más acciones' }, svgIcono('puntos')),
        h('div', { class: 'menu-lista' }, ...extra.map(([t, fn, ic]) => h('button', { type: 'button', class: 'menu-opcion', onclick: fn }, svgIcono(ic), t)))) : null);
  };
  // Orden de columnas pedido por el usuario (2026-10-11). Lo que solo sirve para validar por debajo (¿en MEDICAR?,
  // PLU MEDICAR, COD MUTUAL FINAL, validador, ¿precio correcto?, CUM y COD, ¿cumple regulado?) no se muestra:
  // sus resultados salen en OBSERVACIONES.
  const columnas = [
    gestionar ? { titulo: '', filtro: false, valor: accionesFila } : null,
    { titulo: 'ESTADO', campo: 'activo_estado', valor: estadoNT, filtro: { campo: 'activo_estado', opciones: [['ACTIVO', 'Activo'], ['INACTIVO', 'Inactivo']] } },
    { titulo: 'FECHA DE CREACION', campo: 'fecha' },
    { titulo: 'CONTRATO', campo: 'contrato_vista' },
    { titulo: 'SUBPLAN', campo: 'subplan' },
    { titulo: 'CUM', campo: 'cum' },
    { titulo: 'CUM FACTURAR', campo: 'cum_facturar' },
    { titulo: 'COD AUF', campo: 'cod_auf' },
    { titulo: 'TIPO PRODUCTO', campo: 'tipo_producto' },
    { titulo: 'DESCRIPCION', campo: 'descripcion', valor: (f) => h('div', { class: 'desc-linea', title: f.descripcion ?? '' }, f.descripcion ?? '') },
    { titulo: 'COD GEN SIESA', campo: 'cod_gen_siesa' },
    { titulo: 'PLU', campo: 'referencia_siesa' },
    { titulo: 'DESCRIPCION COMPLETA', campo: 'descripcion_siesa', valor: (f) => h('div', { class: 'desc-linea', title: f.descripcion_siesa ?? '' }, f.descripcion_siesa ?? '') },
    { titulo: 'ATC', campo: 'atc' },
    { titulo: 'COD MUTUAL', campo: 'cod_mutual' },
    { titulo: `PRECIO DE VENTA ${meta.anio_precio || ''}`.trim(), campo: 'precio_venta', num: true, valor: (f) => num(f.precio_venta) },
    { titulo: 'IVA', campo: 'iva', num: true, valor: (f) => String(Number(f.iva || 0)).replace('.', ',') },
    { titulo: 'PRECIO FINAL + IVA', campo: 'precio_final', num: true, valor: (f) => num(f.precio_final) },
    { titulo: '¿ESTA EN EL CONTRATO?', campo: 'en_contrato', clase: colorDe('en_contrato') },
    { titulo: 'PRECIO CONTRATO', campo: 'precio_contrato', num: true, valor: (f) => (f.precio_contrato === null ? (f.contrato === 'CAPITA' ? 'NO ESTA EN EL LISTADO CAPITA' : 'NO ESTA EN EL CONTRATO')
      : Number(f.precio_contrato).toLocaleString('es-CO', { maximumFractionDigits: 2 })) },
    { titulo: '¿PRECIO CORRECTO - M?', campo: 'precio_maximo', num: true, valor: (f) => num(f.precio_maximo) },
    { titulo: 'PRECIO REGULADO', campo: 'precio_regulado', num: true, valor: (f) => num(f.precio_regulado) },
    { titulo: 'FORMA FARMACEUTICA RIPS', campo: 'ffm_rips' },
    { titulo: 'CONCENTRACION RIPS', campo: 'umm_rips' },
    { titulo: 'UNIDAD MEDIDA RIPS', campo: 'upr_rips' },
    { titulo: 'OBSERVACIONES', campo: 'reglas', valor: (f) => {
      // validada, con alerta o con error (pedido del usuario, 2026-11-05); la inactiva no se valida
      const m = f.motivos || [];
      if (f.estado === 'INACTIVO' && !f.plu_pendiente) return h('span', { class: 'nota' }, 'No se valida (inactivo)');
      const chip = h('span', { class: `chip-estado e-${NT_CLS[f.estado] || 'gris'}` }, svgIcono(NT_ICO[f.estado] || 'pendiente'), NT_ETQ[f.estado] || f.estado);
      if (!m.length) return h('div', { class: 'obs-celda' }, chip);
      const primero = m.find((x) => x.nivel === 'ERROR') || m[0];
      const nt29 = gestionar ? m.find((x) => x.codigo === 'NT29') : null;
      return h('div', { class: 'obs-celda' }, chip,
        nt29 ? botonAprobarVarios(f, nt29, () => tabla.refrescar()) : null,
        nt29 && f.plu_pendiente ? botonDejarInactivo(f, () => tabla.refrescar()) : null,
        h('div', { class: `motivo-corto ${primero.nivel === 'ERROR' ? 'error' : 'alerta-txt'}`, title: m.map((x) => `${x.codigo}: ${x.texto}`).join('\n') },
          `${primero.codigo}: ${primero.texto}`, m.length > 1 ? h('span', { class: 'nota' }, ` +${m.length - 1} más`) : null));
    }, filtro: { campo: 'reglas', opciones: [['', '(Validada · sin observaciones)'], ...meta.reglas.map((x) => [x.codigo, `${x.codigo} · ${nombreRegla[x.codigo]}`])] } },
  ].filter(Boolean).filter((c) => meta.con_precios !== false || !NT_COLUMNAS_PRECIO.includes(c.campo)); // perfil sin precios
  const zona = h('div', { class: 'tarjeta' }, reglasZona, barraSel);
  c.append(zona);
  tabla = tablaPaginada({ contenedor: zona, columnas, clase: `tabla-excel tabla-nt${gestionar ? ' con-acciones' : ''}`, cargar: (p) => api('nt_listar', { params: { ...p, tam: 50 } }), alCargar: (x) => { pintarResumen(x.resumen); if (tabla && !tabla.filtros().reglas) reglasZona.replaceChildren(); totalFiltrado = x.total;
    if (todasFiltradas) document.querySelectorAll('.tabla-nt .sel-fila').forEach((c) => { c.checked = true; }); pintarSeleccion(); } });
}

/**
 * Por AGREGAR: productos del Listado Cápita y del Listado EVENTO SAS que no están en la Maestra Tarifaria, con las
 * MISMAS columnas de la Maestra Tarifaria (prellenadas). Se descargan en Excel, se completan y se cargan con la importación.
 */
function panelPorAgregar(total, gestionar, alAgregar) {
  const zona = h('div', {}, h('p', { class: 'nota' }, 'Cargando…'));
  const det = h('details', { class: 'tarjeta', style: 'margin-bottom:12px' },
    h('summary', { style: 'cursor:pointer;font-weight:600' }, `Por AGREGAR: ${miles(total)} códigos de los listados (Cápita y EVENTO SAS) que no están en la Maestra Tarifaria`),
    gestionar ? h('ol', { class: 'nota' },
      h('li', {}, 'Descargue el Excel: trae estos códigos con las columnas de la Maestra Tarifaria, prellenadas con el listado de origen (COD AUF, ID MUTUAL, CUM, descripción), el tipo y precio (tarifa de cápita o precio del Contrato Evento sin IVA) y el Maestro SIESA (COD GEN SIESA e IVA).'),
      h('li', {}, 'Complete lo que falte (ver la columna OBSERVACION) y corrija lo que sea necesario.'),
      h('li', {}, 'Cárguelo aquí abajo: se muestra la vista previa y, al aplicar, los productos quedan en la Maestra Tarifaria y se validan.')) : h('p', { class: 'nota' }, 'Solo el Administrador puede agregarlos.'),
    gestionar ? h('div', { class: 'fila-acciones', style: 'margin-bottom:8px' }, h('a', { class: 'btn sec', href: 'api.php?accion=nt_por_agregar_plantilla' }, '⬇ Descargar Excel para diligenciar')) : null,
    gestionar ? panelImportarNT(true, alAgregar, 'Cargar el Excel diligenciado') : null,
    zona);
  let carga = null;
  let tablaLocal = null;
  const cargar = () => (carga ??= (async () => {
    try {
      const r = await api('nt_por_agregar');
      const campos = Object.keys(r.columnas);
      const celda = (x, k) => (k === 'precio_venta' ? (x[k] === null ? '' : Number(x[k]).toLocaleString('es-CO', { maximumFractionDigits: 2 })) : k === 'iva' ? String(Number(x[k])).replace('.', ',') : (x[k] ?? ''));
      const filas = r.filas.map((x) => h('tr', {},
        h('td', {}, x.listado),
        ...campos.map((k) => h('td', { class: ['precio_venta', 'iva'].includes(k) ? 'num' : null }, celda(x, k))),
        h('td', {}, x.observacion ? h('span', { class: 'alerta-txt' }, x.observacion) : ''),
        h('td', {}, chip('AGREGAR', 'alerta'), ' ', gestionar ? h('button', { class: 'btn sec chico', onclick: () => editarNT(null, alAgregar, x) }, 'Agregar uno') : null)));
      tablaLocal = tablaConFiltros(['LISTADO', ...Object.values(r.columnas), 'OBSERVACION', ''], filas, { sinFiltro: [campos.length + 2], alto: 420 });
      zona.replaceChildren(tablaLocal);
    } catch (e) { zona.replaceChildren(h('p', { class: 'error' }, e.message)); }
  })());
  det.addEventListener('toggle', () => { if (det.open && total) cargar(); });
  // Desde los indicadores: abre el panel y deja solo el listado indicado (filtro de la columna LISTADO)
  det.mostrar = async (listado) => {
    det.open = true;
    await cargar();
    if (listado) tablaLocal?.filtrar?.(0, [listado]);
  };
  return det;
}

/**
 * Excepción de precio 1 (4.10): todas las líneas con precio de venta 1 en una sola lista, para aprobarlas
 * (o retirar la aprobación) en bloque. Solo el Administrador aprueba; si el precio cambia, la aprobación se pierde.
 */
function panelPrecioUno(gestionar, alCambiar) {
  const zona = h('div', {}, h('p', { class: 'nota' }, 'Cargando…'));
  const det = h('details', { class: 'tarjeta', style: 'margin-bottom:12px' },
    h('summary', { style: 'cursor:pointer;font-weight:600' }, 'Excepción de precio 1: aprobar las líneas con precio de venta 1'),
    h('p', { class: 'nota' }, gestionar ? 'Marque las líneas y apruebe. Una línea aprobada deja de marcar error NT04; si luego se cambia su precio, la aprobación se pierde. Queda en auditoría.'
      : 'Solo el Administrador aprueba la excepción de precio 1.'),
    zona);
  let carga = null;
  const cargar = () => (carga ??= (async () => {
    try {
      const r = await api('nt_precio_uno');
      const marcas = r.filas.map((x) => h('input', { type: 'checkbox', value: x.id }));
      const todas = h('input', { type: 'checkbox', title: 'Marcar todas las pendientes',
        onchange: () => marcas.forEach((m, i) => { if (!r.filas[i].precio1_aprobado_en) m.checked = todas.checked; }) });
      const accion = (aprobar) => async () => {
        const ids = marcas.filter((m) => m.checked).map((m) => Number(m.value));
        if (!ids.length) { toast('Marque al menos una línea.'); return; }
        const res = await api('nt_precio_uno_aprobar', { metodo: 'POST', datos: { ids, aprobar } });
        toast(aprobar ? `${miles(res.lineas)} líneas con precio 1 aprobadas.` : `Aprobación retirada en ${miles(res.lineas)} líneas.`);
        alCambiar();
      };
      const filas = r.filas.map((x, i) => h('tr', {},
        h('td', {}, gestionar ? marcas[i] : ''), h('td', {}, x.contrato), h('td', {}, x.cum_facturar), h('td', {}, x.cod_auf ?? ''),
        h('td', {}, x.descripcion ?? ''), h('td', {}, x.cod_mutual ?? ''), h('td', { class: 'num' }, miles(Math.round(Number(x.precio_venta)))),
        h('td', {}, x.precio1_aprobado_en ? chip('APROBADO', 'ok') : chip('PENDIENTE', 'error')),
        h('td', {}, x.precio1_aprobado_en ? `${x.aprobado_por ?? ''} · ${x.precio1_aprobado_en}` : '')));
      zona.replaceChildren(
        gestionar ? h('div', { class: 'fila-acciones', style: 'margin-bottom:8px' }, h('label', { style: 'display:flex;gap:6px;align-items:center' }, todas, 'Marcar todas las pendientes'),
          h('button', { class: 'btn primario chico', onclick: accion(true) }, 'Aprobar precio 1 de las marcadas'),
          h('button', { class: 'btn sec chico', onclick: accion(false) }, 'Retirar aprobación')) : '',
        tablaConFiltros(['', 'CONTRATO', 'CUM FACTURAR', 'COD AUF', 'DESCRIPCION', 'COD MUTUAL', 'PRECIO', 'ESTADO', 'APROBADO POR · FECHA'], filas, { sinFiltro: [0], alto: 360 }));
    } catch (e) { zona.replaceChildren(h('p', { class: 'error' }, e.message)); }
  })());
  det.addEventListener('toggle', () => { if (det.open) cargar(); });
  det.mostrar = () => { det.open = true; cargar(); };
  return det;
}

/**
 * NUEVO producto en la Maestra Tarifaria (decisión del usuario, 2026-10-03). Al digitar el COD AUF y el CUM a facturar se llena solo:
 *  - COD GEN (moléculas): DESCRIPCION y COD GEN SIESA (Cód. Medicar). Si el COD AUF no existe: alerta, no se deja
 *    guardar y se ofrece agregarlo al catálogo.
 *  - Maestra Mutual: COD MUTUAL (ID MUTUAL del CUM); COD MUTUAL FINAL = COD AUF si tiene varios ID, si no el mismo ID.
 *  - SIESA: IVA. Tipo 714 / 799 por el CUM.
 *  - Precio (hoja DOC CRIS, sin precio Audifarma): CAPITA -> tarifa pactada; EVENTO / EVENTO SAS -> valor del
 *    Contrato Evento sin pasar por la calculadora; si no hay ninguno -> calculadora: compra + % (editable),
 *    con tope en el precio regulado. El precio facturado nunca supera el regulado.
 * Todo se puede corregir antes de guardar.
 */
function nuevoNT(alGuardar, base = {}) {
  const dlg = $('#dialogo');
  const form = $('#dialogo-form');
  dlg.onclose = null;
  let sug = null;
  let editados = new Set(); // campos que el usuario cambió a mano: no se sobreescriben
  const campo = (nombre, etiqueta, attrs = {}, ayuda = null) => {
    const el = attrs.tipo === 'select'
      ? h('select', { name: nombre }, ...attrs.opciones.map(([v, t]) => h('option', { value: v }, t)))
      : h('input', { name: nombre, type: attrs.type || 'text', autocomplete: 'off', placeholder: attrs.placeholder, step: attrs.step });
    if (attrs.valor !== undefined && attrs.valor !== null) el.value = attrs.valor;
    el.addEventListener('input', () => editados.add(nombre));
    const nota = h('span', { class: 'nota' }, ayuda || '');
    return { el, nota, label: h('label', {}, etiqueta, el, nota) };
  };
  const c = {
    cod_auf: campo('cod_auf', 'Cód. Audifarma (COD AUF)', { valor: base.cod_auf, placeholder: 'ej. 118' }),
    cum_facturar: campo('cum_facturar', 'CUM a facturar', { valor: base.cum_facturar, placeholder: 'expediente-consecutivo o código del insumo' }),
    cum: campo('cum', 'CUM', { valor: base.cum, placeholder: 'igual al CUM a facturar si se deja vacío' }),
    descripcion: campo('descripcion', 'Descripción', { valor: base.descripcion }),
    cod_gen_siesa: campo('cod_gen_siesa', 'COD GEN SIESA', { valor: base.cod_gen_siesa }),
    tipo_producto: campo('tipo_producto', 'Tipo de producto', { tipo: 'select', valor: base.tipo_producto ?? '',
      opciones: [['', 'Automático'], ['714', '714 · Medicamento'], ['799', '799 · Insumo']] }),
    cod_mutual: campo('cod_mutual', 'COD MUTUAL (ID MUTUAL)', { valor: base.cod_mutual }),
    iva: campo('iva', 'IVA', { tipo: 'select', valor: String(Number(base.iva) ? 0.19 : 0), opciones: [['0', '0 %'], ['0.19', '19 %']] }),
  };
  // Líneas que se crean (una por contrato) con su precio; el precio de cada una se puede corregir a mano.
  const lineasZona = h('div', { class: 'nt-lineas' });
  let lineas = [];
  const precioManual = {};
  // calculadora DOC CRIS
  const compra = h('input', { type: 'number', step: '0.01', placeholder: 'precio de compra' });
  const pct = h('input', { type: 'number', step: '0.1', style: 'width:80px' });
  const resultadoCalc = h('span', { class: 'nota' });
  const avisoAuf = h('div');
  const fuentes = h('div', { class: 'nt-fuentes' });
  const err = h('p', { class: 'error', role: 'alert' });

  const ivaActual = () => Number(c.iva.el.value || 0);
  const regulado = () => (sug?.regulado ?? null);
  const fmt = (v) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('es-CO', { maximumFractionDigits: 2 }));
  // Precio de cada línea (hoja DOC CRIS, sin precio Audifarma): CAPITA -> tarifa pactada; EVENTO / EVENTO SAS ->
  // precio del Contrato Evento sin calculadora; si el ID MUTUAL no está en el contrato, el de cápita o, si no
  // hay, el de la calculadora (y la línea queda con error NT01). Nunca por encima del precio regulado.
  const calcularLineas = () => {
    if (!sug) { lineasZona.replaceChildren(); return; }
    const iva = ivaActual(); const reg = regulado();
    const tope = (v) => (reg !== null && v * (1 + iva) > reg ? reg / (1 + iva) : v);
    const tipo = c.tipo_producto.el.value || sug.tipo_producto;
    const ctr = sug.contrato_evento[tipo] || Object.values(sug.contrato_evento)[0] || null;
    let calc = null;
    const pc = Number(compra.value); const p = Number(pct.value);
    if (pc) {
      const conMargen = pc * (1 + p / 100);
      const topado = reg !== null && conMargen * (1 + iva) > reg;
      calc = { pv: Math.ceil(topado ? reg / (1 + iva) : conMargen), origen: 'CALCULADORA',
        regla: topado ? `Calculadora: ${fmt(pc)} + ${p} % supera el regulado ${fmt(reg)}` : `Calculadora: compra ${fmt(pc)} + ${p} %` };
      resultadoCalc.textContent = topado ? `= ${fmt(conMargen)} supera el regulado: queda ${fmt(calc.pv)}` : `= ${fmt(calc.pv)}`;
    } else resultadoCalc.textContent = '';
    const capita = sug.tarifa_capita !== null ? { pv: tope(sug.tarifa_capita / (1 + iva)), origen: 'LISTADO CAPITA',
      regla: reg !== null && sug.tarifa_capita > reg ? `Tarifa ${fmt(sug.tarifa_capita)} supera el regulado: se usa el regulado` : `Tarifa pactada ${fmt(sug.tarifa_capita)}` } : null;
    lineas = sug.contratos.map((k) => {
      const existe = sug.lineas_existentes.includes(k);
      let b;
      if (k === 'CAPITA') b = capita;
      else if (ctr) b = { pv: tope(ctr.valor / (1 + iva)), origen: 'CONTRATO', regla: ctr.valor * 1 > (reg ?? Infinity) ? `Contrato ${fmt(ctr.valor)} supera el regulado: se usa el regulado` : `Precio del contrato ${fmt(ctr.valor)}` };
      else b = { ...(capita ? { ...capita, origen: 'COPIA', regla: 'Precio de cápita' } : calc || { pv: null, origen: null, regla: 'Digite el precio de compra en la calculadora' }) };
      const sinContrato = k !== 'CAPITA' && !ctr;
      return { contrato: k, existe, sinContrato, ...b };
    });
    lineasZona.replaceChildren(h('table', { class: 'nt-tabla-lineas' },
      h('thead', {}, h('tr', {}, h('th', {}, 'Línea'), h('th', {}, 'PRECIO DE VENTA (sin IVA)'), h('th', {}, 'Origen del precio'))),
      h('tbody', {}, ...lineas.map((l) => {
        const inp = h('input', { type: 'number', step: '0.01', style: 'width:130px', disabled: l.existe,
          oninput: () => { precioManual[l.contrato] = inp.value; } });
        inp.value = precioManual[l.contrato] ?? (l.pv === null ? '' : Math.round(l.pv * 100) / 100);
        l.input = inp;
        return h('tr', {},
          h('td', {}, chip(lineaNT(l.contrato), l.existe ? 'gris' : 'info'), l.existe ? h('div', { class: 'nota' }, 'Ya existe: no se crea') : '',
            l.sinContrato && !l.existe ? h('div', { class: 'error' }, 'El ID MUTUAL no está en el Contrato Evento: quedará con error NT01') : ''),
          h('td', {}, inp),
          h('td', { class: 'nota' }, precioManual[l.contrato] !== undefined ? 'Digitado' : (l.regla || '')));
      }))));
  };
  const pintarFuentes = () => {
    if (!sug) { fuentes.replaceChildren(); return; }
    const tipo = c.tipo_producto.el.value || sug.tipo_producto;
    const ctr = Object.entries(sug.contrato_evento).map(([s, x]) => `${s}: ${fmt(x.valor)}${x.estado !== 'A' ? ' (inactivo)' : ''}`).join(' · ');
    const dato = (t, v, extra) => h('div', { class: 'dato' }, h('span', {}, t), h('strong', {}, v), extra || '');
    fuentes.replaceChildren(
      h('div', { class: 'datos-rejilla' },
        dato('Contrato Evento', ctr || (sug.cod_mutual ? 'El ID MUTUAL no está en el contrato' : '—'), ctr && !sug.contrato_evento[tipo] ? chip(`no tiene el tipo ${tipo}`, 'alerta') : ''),
        dato('Tarifa Listado Cápita', fmt(sug.tarifa_capita)),
        dato('Precio regulado (tope)', fmt(sug.regulado)),
        sug.precio_nt_cod_auf !== null ? dato('Precio en la Maestra Tarifaria para este COD AUF', fmt(sug.precio_nt_cod_auf)) : ''),
      sug.lineas_existentes.length ? h('div', { class: 'alerta-txt' }, `El CUM a facturar ya tiene líneas en: ${sug.lineas_existentes.map(lineaNT).join(', ')}`) : '');
  };
  const poner = (nombre, valor, nota) => {
    if (!editados.has(nombre) && valor !== null && valor !== undefined) c[nombre].el.value = valor;
    if (nota !== undefined) c[nombre].nota.textContent = nota || '';
  };
  let consulta = 0;
  const consultar = async () => {
    const auf = c.cod_auf.el.value.trim(); const cf = c.cum_facturar.el.value.trim();
    if (!auf && !cf) return;
    const n = ++consulta;
    try {
      const r = await api('nt_sugerencia', { params: { cod_auf: auf, cum_facturar: cf, cod_mutual: editados.has('cod_mutual') ? c.cod_mutual.el.value.trim() : '' } });
      if (n !== consulta) return;
      sug = r;
      // COD GEN (moléculas)
      if (auf && !r.molecula) {
        avisoAuf.replaceChildren(h('div', { class: 'aviso-caja' }, h('strong', {}, `El COD AUF ${r.cod_auf} no existe en el catálogo COD GEN (moléculas). `),
          'Agréguelo primero: no se puede guardar.', ' ',
          puede('CATALOGO.EDITAR') ? h('button', { type: 'button', class: 'btn sec chico', onclick: agregarCodGen }, '+ Agregar al catálogo COD GEN') : ''));
      } else if (r.molecula) {
        avisoAuf.replaceChildren(h('div', { class: 'nota' }, chip('COD GEN', 'ok'), ` ${r.molecula.descripcion_general} · Cód. Medicar ${r.molecula.codigo_medicar || '—'} · ${r.molecula.pos_no_pos || ''}`,
          Number(r.molecula.activo) ? '' : chip('inactivo en COD GEN', 'alerta')));
        poner('descripcion', r.molecula.descripcion_general, 'De COD GEN');
        poner('cod_gen_siesa', r.molecula.codigo_medicar, 'Cód. Medicar de COD GEN');
      } else avisoAuf.replaceChildren();
      // Maestra Mutual, SIESA y tipo
      if (cf) {
        poner('cod_mutual', r.cod_mutual, r.ids_cum.length > 1 ? `El CUM tiene varios ID MUTUAL en la Maestra Mutual: ${r.ids_cum.join(', ')}. Elija uno.`
          : r.ids_cum.length ? 'ID MUTUAL de la Maestra Mutual Ser' : r.cod_mutual_origen ? 'De la Maestra Tarifaria: línea con el mismo COD AUF (el CUM no tiene ID MUTUAL)'
            : r.cod_mutual ? 'Insumo: su código es el ID' : 'El CUM no está en la Maestra Mutual Ser: digítelo');
        if (r.iva !== null) poner('iva', String(r.iva ? 0.19 : 0), 'Grupo impositivo del Maestro SIESA');
        if (!editados.has('tipo_producto')) c.tipo_producto.nota.textContent = r.tipo_producto ? `Automático: ${r.tipo_producto}` : '';
        if (!c.cum.el.value) c.cum.el.placeholder = cf;
      }
      if (!pct.value) pct.value = r.porcentaje;
      pintarFuentes(); calcularLineas();
    } catch (e) { err.textContent = e.message; }
  };
  const agregarCodGen = async () => {
    const valores = Object.fromEntries(Object.entries(c).map(([k, x]) => [k, x.el.value]));
    const sugCat = await api('catalogo_sugerencias');
    // al guardar el COD GEN, el diálogo se cierra: luego se vuelve a abrir este formulario con lo digitado
    EDITORES.molecula(null, sugCat, () => setTimeout(() => nuevoNT(alGuardar, { ...base, ...valores }), 0), { cod_gen_audifarma: valores.cod_auf, descripcion_general: valores.descripcion });
  };
  let espera = null;
  const alCambiarClave = () => { clearTimeout(espera); espera = setTimeout(consultar, 400); };
  c.cod_auf.el.addEventListener('input', alCambiarClave);
  c.cum_facturar.el.addEventListener('input', alCambiarClave);
  c.cod_mutual.el.addEventListener('input', alCambiarClave); // el contrato se busca con el ID digitado
  c.iva.el.addEventListener('change', calcularLineas);
  c.tipo_producto.el.addEventListener('change', () => { pintarFuentes(); calcularLineas(); });
  compra.addEventListener('input', calcularLineas);
  pct.addEventListener('input', calcularLineas);

  // presentación (fase 10): encabezado, secciones numeradas y pie como el formulario del producto
  const apr = base._aprobacion || null; // se abre desde «Aprobaciones pendientes» (INCLUIR_NT)
  for (const k of ['cod_auf', 'cum_facturar']) {
    const t = c[k].label.firstChild; t.replaceWith(h('span', {}, t.textContent, h('span', { class: 'req' }, ' *')));
    if (apr) { c[k].el.readOnly = true; c[k].el.classList.add('solo-lectura'); } // vienen de la solicitud: no se cambian
  }
  const seccion = (n, titulo, ...hijos) => h('section', { class: 'seccion-form' }, h('h3', {}, h('span', { class: 'sec-num' }, String(n)), titulo), h('div', { class: 'rejilla-form' }, ...hijos));
  const encabezado = h('div', { class: 'form-encabezado' },
    h('div', {}, h('h2', {}, apr ? 'Aprobar: incluir en la Maestra Tarifaria' : base.cod_auf ? `Agregar a la Maestra Tarifaria · COD AUF ${base.cod_auf}` : 'Nuevo producto en la Maestra Tarifaria'),
      h('p', { class: 'nota' }, apr ? `Solicitado por ${apr.por} desde la ${apr.origen}. Revise el ID MUTUAL y el precio: al guardar se crean las líneas y la solicitud queda aprobada.`
        : 'Los datos se llenan con el COD AUF y el CUM a facturar; puede corregirlos antes de guardar.')),
    h('button', { type: 'button', class: 'btn-icono cerrar-x', 'aria-label': 'Cerrar', onclick: () => dlg.close() }, '✕'));
  form.replaceChildren(encabezado,
    h('div', { class: 'form-cuerpo' },
      seccion(1, 'Producto', c.cod_auf.label, c.cum_facturar.label, c.cum.label, h('div', { class: 'ancho-total' }, avisoAuf)),
      seccion(2, 'Datos que se llenan solos (puede corregirlos)', h('div', { class: 'ancho-doble' }, c.descripcion.label), c.cod_gen_siesa.label,
        c.cod_mutual.label, c.tipo_producto.label, c.iva.label),
      seccion(3, 'Contratos y precio', h('div', { class: 'ancho-total' }, fuentes),
        h('div', { class: 'ancho-total nt-calc' }, h('strong', {}, 'Calculadora (DOC CRIS):'), compra, '+', pct, '%', resultadoCalc,
          h('span', { class: 'nota', style: 'width:100%' }, 'Se usa solo si no hay tarifa cápita ni precio del contrato. Tope: precio regulado.')),
        h('div', { class: 'ancho-total' }, h('strong', {}, 'Líneas que se crean '),
          h('span', { class: 'nota' }, '(CAPITA si está en el Listado Cápita; en el contrato EVENTO, siempre la línea EVENTO y la del subplán EVENTO SAS SIN AUTORIZACION si está en el Listado EVENTO SAS)'), lineasZona))),
    err,
    h('div', { class: 'form-pie' }, h('span', { class: 'nota' }, h('span', { class: 'req' }, '* '), 'Campos obligatorios'), h('span', { class: 'espacio' }),
      apr ? h('button', { type: 'button', class: 'btn sec', onclick: () => apr.volver() }, '← Volver a aprobaciones') : '',
      h('button', { class: 'btn sec', value: 'cancelar', formnovalidate: true }, 'Cancelar'),
      h('button', { class: 'btn primario', value: 'ok' }, svgIcono(apr ? 'aprobar' : 'ok'), apr ? 'Aprobar e incluir' : 'Guardar')));
  form.classList.add('form-producto');
  form.onsubmit = async (ev) => {
    if (ev.submitter?.value === 'cancelar') return;
    ev.preventDefault();
    err.textContent = '';
    const v = Object.fromEntries(Object.entries(c).map(([k, x]) => [k, x.el.value]));
    if (!v.cod_auf || !v.cum_facturar) { err.textContent = 'El COD AUF y el CUM a facturar son obligatorios.'; return; }
    if (!sug || (v.cod_auf && !sug.molecula)) { await consultar(); if (!sug?.molecula) { err.textContent = `El COD AUF ${v.cod_auf} no existe en COD GEN: agréguelo primero al catálogo.`; return; } }
    if (!v.cum) v.cum = v.cum_facturar;
    const nuevas = lineas.filter((l) => !l.existe);
    if (!nuevas.length) { err.textContent = 'El CUM a facturar ya tiene todas sus líneas en la Maestra Tarifaria.'; return; }
    try {
      const r = await api('nt_guardar', { metodo: 'POST', datos: { ...v, id: 0, activo: true, lineas: nuevas.map((l) => ({
        contrato: l.contrato, precio_venta: l.input.value,
        calculo: precioManual[l.contrato] !== undefined ? null : { origen: l.origen, regla: l.regla, precio_compra: compra.value || null, porcentaje: pct.value || null } })) } });
      dlg.close();
      toast(`Producto agregado en ${r.creadas.join(' y ')}. Validaciones recalculadas.${r.omitidas.length ? ` Ya existía en ${r.omitidas.join(', ')}.` : ''}`);
      alGuardar();
    } catch (e) { err.textContent = e.message; }
  };
  dlg.oncancel = null;
  dlg.classList.add('ancho', 'ancho-nt');
  dlg.addEventListener('close', () => { // si ya se abrió otra ventana, no se toca
    if (!dlg.open || !form.contains(encabezado)) form.classList.remove('form-producto');
    if (!dlg.open) dlg.classList.remove('ancho', 'ancho-nt');
  }, { once: true });
  if (!dlg.open) dlg.showModal();
  setTimeout(() => { if (dlg.open && form.contains(encabezado)) { dlg.classList.add('ancho', 'ancho-nt'); form.classList.add('form-producto'); } }, 0);
  if (base.cod_auf || base.cum_facturar) consultar();
}

/** Crear / editar una fila (Administrador). El CUM a facturar es la llave: no se edita. */
function editarNT(f, alGuardar, base = {}) {
  if (!f) { nuevoNT(alGuardar, base); return; } // crear: formulario con datos automáticos y calculadora
  // Editar una línea (diseño fase 10): encabezado con estado, observaciones de la línea, secciones numeradas y pie
  const dlg = $('#dialogo'); const form = $('#dialogo-form');
  dlg.onclose = null; dlg.oncancel = null;
  const campo = (nombre, etiqueta, el, ayuda = '') => { el.name = nombre; return h('label', {}, etiqueta, el, ayuda ? h('span', { class: 'nota' }, ayuda) : null); };
  const txt = (v, attrs = {}) => { const e = h('input', { type: 'text', autocomplete: 'off', ...attrs }); e.value = v ?? ''; return e; };
  const sel = (v, ops) => { const e = h('select', {}, ...ops.map(([a, b]) => h('option', { value: a }, b))); e.value = v; return e; };
  const ro = (v) => h('input', { value: v ?? '', readonly: true, class: 'solo-lectura', tabindex: -1 });
  const c = {
    cum: txt(f.cum, { placeholder: 'CUM, NO APLICA, VITAL NO DISPONIBLE…' }),
    cod_auf: txt(f.cod_auf),
    tipo_producto: sel(f.tipo_producto ?? '', [['', 'Automático (según el CUM a facturar)'], ['714', '714 · Medicamento'], ['799', '799 · Insumo']]),
    descripcion: txt(f.descripcion),
    cod_gen_siesa: txt(f.cod_gen_siesa),
    cod_mutual: txt(f.cod_mutual),
    // 7F: en CAPITA el precio sale del Listado Cápita (con tope en el regulado); EVENTO y EVENTO SAS se digitan (el contrato es el tope)
    precio_venta: f.fuente_precio && f.fuente_precio !== 'DIGITADO' ? h('input', { type: 'number', value: f.precio_venta ?? '', readonly: true, class: 'solo-lectura', tabindex: -1 })
      : txt(f.precio_venta ?? '', { type: 'number', step: '0.01', min: '0' }),
    iva: sel(String(Number(f.iva) ? 0.19 : 0), [['0', '0 %'], ['0.19', '19 %']]),
  };
  const conFuente = !!f.fuente_precio && f.fuente_precio !== 'DIGITADO';
  // la inactivación es por PLU (aclaración del usuario, 2026-11-05): el interruptor es del PLU de la fila y se aplica en todas las líneas
  // del CUM que lo tienen (todos sus contratos y subplanes); sin PLU, es de la línea
  const porPlu = !!(f.plu && f.id);
  const lineaActiva = Number(f.activo_linea ?? f.activo) === 1;
  const activoInicial = porPlu ? Number(f.activo) === 1 : lineaActiva;
  let activo = activoInicial;
  const txtEstado = () => (activo ? 'Activo' + (porPlu ? '' : 'a') : porPlu ? 'Inactivo: este PLU no se valida ni se usa en este contrato' : 'Inactiva: no se valida ni se usa al crear productos');
  const interruptor = h('button', { type: 'button', class: `interruptor${activo ? ' on' : ''}`, role: 'switch', 'aria-checked': String(activo), 'aria-label': porPlu ? `PLU ${f.plu} activo` : 'Línea activa',
    onclick: () => { activo = !activo; interruptor.classList.toggle('on', activo); interruptor.setAttribute('aria-checked', String(activo)); estadoTxt.textContent = txtEstado(); } }, h('span', {}));
  const estadoTxt = h('span', { class: 'nota' }, txtEstado());
  // precio final con IVA, en vivo
  const final = ro('');
  const calcFinal = () => { const p = Number(c.precio_venta.value); final.value = c.precio_venta.value === '' ? '' : pesos(Math.round(p * (1 + Number(c.iva.value)))); };
  c.precio_venta.addEventListener('input', calcFinal); c.iva.addEventListener('change', calcFinal); calcFinal();
  const seccion = (n, titulo, ...hijos) => h('section', { class: 'seccion-form' }, h('h3', {}, h('span', { class: 'sec-num' }, String(n)), titulo), h('div', { class: 'rejilla-form' }, ...hijos));
  const EST = { VALIDADO: ['ok', 'ok', 'Validada'], 'CON ALERTA': ['alerta', 'alerta', 'Con alerta'], ERROR: ['error', 'error', 'Con error'], INACTIVO: ['gris', 'pendiente', 'Inactiva'] }[f.estado] || ['gris', 'pendiente', f.estado];
  const motivos = (f.motivos || []);
  const encabezado = h('div', { class: 'form-encabezado' },
    h('div', {}, h('h2', {}, `Editar ${f.cum_facturar}`),
      h('div', { class: 'det-sub' }, h('span', { class: `chip-estado e-${EST[0]}` }, svgIcono(EST[1]), EST[2]),
        h('span', { class: 'pill-codigo' }, f.contrato_vista || f.contrato), f.subplan ? h('span', { class: 'chip-estado e-info' }, f.subplan) : null,
        h('span', { class: 'nota' }, 'Las columnas calculadas se recalculan al guardar. Queda en auditoría.'))),
    h('button', { type: 'button', class: 'btn-icono cerrar-x', title: 'Cerrar (Esc)', 'aria-label': 'Cerrar', onclick: () => dlg.close() }, '✕'));
  const err = h('p', { class: 'error', role: 'alert' });
  form.replaceChildren(encabezado,
    h('div', { class: 'form-cuerpo' },
      motivos.length ? h('section', { class: 'seccion-form' }, h('h3', {}, svgIcono('alerta'), `Observaciones de la línea (${motivos.length})`),
        h('div', { class: 'lista-val' }, ...motivos.map((m) => h('div', { class: `val-item ${m.nivel === 'ERROR' ? 'v-error' : 'v-alerta'}` },
          h('div', { class: 'val-cab' }, svgIcono(m.nivel === 'ERROR' ? 'error' : 'alerta'), h('strong', {}, m.codigo), h('span', {}, ` ${m.texto}`),
            m.codigo === 'NT29' && f.id ? botonAprobarVarios(f, m, () => { dlg.close(); alGuardar(); }) : null))))) : null,
      seccion(1, 'Identificación', campo('cum_facturar', 'CUM a facturar', ro(f.cum_facturar)), campo('contrato', 'Contrato · subplán', ro(lineaNT(f.contrato))),
        campo('cum', 'CUM', c.cum), campo('cod_auf', 'COD AUF', c.cod_auf), campo('tipo_producto', 'Tipo de producto', c.tipo_producto)),
      seccion(2, 'Producto y códigos', h('div', { class: 'ancho-doble' }, campo('descripcion', 'Descripción', c.descripcion)),
        campo('cod_gen_siesa', 'COD GEN SIESA', c.cod_gen_siesa, 'Vacío: se toma del Maestro SIESA por el CUM.'),
        campo('cod_mutual', 'COD MUTUAL', c.cod_mutual, 'Con este código se valida el contrato y el precio.')),
      seccion(3, 'Precio y estado', campo('precio_venta', 'Precio de venta (sin IVA)', c.precio_venta, conFuente
        ? `Calculado: ${({ 'LISTADO CAPITA': 'Listado Cápita', 'PRECIO REGULADO': 'tope del precio regulado' })[f.fuente_precio] || f.fuente_precio} ÷ (1 + IVA). No se digita.`
        : f.contrato === 'CAPITA' ? 'Sin tarifa en el Listado Cápita: se usa el precio digitado.'
          : 'Precio propio de la línea: el Contrato Evento es el tope (si el precio final lo supera, GLOSA TARIFA).'), campo('iva', 'IVA', c.iva),
        h('label', {}, 'Precio final + IVA', final),
        h('div', { class: 'campo-interruptor ancho-total' }, interruptor, h('strong', {}, porPlu ? `PLU ${f.plu} activo` : 'Línea activa'), estadoTxt,
          porPlu ? h('span', { class: 'nota' }, 'Cambia este PLU en todos los contratos y subplanes del CUM; los demás PLU quedan igual.') : null))),
    err,
    h('div', { class: 'form-pie' }, h('span', { class: 'espacio' }),
      h('button', { class: 'btn sec', value: 'cancelar', formnovalidate: true }, 'Cancelar'),
      h('button', { class: 'btn primario', value: 'ok' }, svgIcono('ok'), 'Guardar')));
  form.onsubmit = async (ev) => {
    if (ev.submitter?.value === 'cancelar') return;
    ev.preventDefault(); err.textContent = '';
    if (!c.cod_mutual.value.trim()) { err.textContent = 'El COD MUTUAL es obligatorio.'; return; }
    const v = Object.fromEntries(Object.entries(c).map(([k, e]) => [k, e.value]));
    if (conFuente) v.precio_venta = f.precio_digitado ?? ''; // el calculado no se guarda como digitado
    try {
      const r = await api('nt_guardar', { metodo: 'POST', datos: { ...v, id: f.id, activo: porPlu ? lineaActiva : activo } }); // mismos campos que enviaba el formulario anterior
      let cambioPlu = false;
      if (porPlu && activo !== activoInicial) { // solo este PLU + CONTRATO; queda en auditoría con el PLU
        await api('nt_activar_masivo', { metodo: 'POST', datos: { filas: [`${f.id}|${f.plu}`], activar: activo, motivo: 'Desde el formulario de edición' } });
        cambioPlu = true;
      }
      dlg.close();
      toast(r.cambios || cambioPlu ? `Guardado.${cambioPlu ? ` PLU ${f.plu} ${activo ? 'activado' : 'inactivado'} en este contrato.` : ''} Validaciones recalculadas.${r.lineas_repetidas ? ` Se crearon ${r.lineas_repetidas} líneas en otros contratos.` : ''}` : 'Sin cambios.');
      alGuardar();
    } catch (e) { err.textContent = e.message; }
  };
  form.classList.add('form-producto', 'det-producto', 'editar-mt');
  dlg.classList.add('ancho', 'ancho-nt');
  const fuera = (ev) => { if (ev.target === dlg) dlg.close(); };
  dlg.addEventListener('click', fuera);
  dlg.addEventListener('close', () => {
    dlg.removeEventListener('click', fuera);
    if (!dlg.open || !form.contains(encabezado)) form.classList.remove('form-producto', 'det-producto', 'editar-mt');
    if (!dlg.open) dlg.classList.remove('ancho', 'ancho-nt');
  }, { once: true });
  if (!dlg.open) dlg.showModal();
  setTimeout(() => c.cum.focus(), 50);
}

/** Cambios de la Maestra Tarifaria por fecha: línea, campo, antes y después; filtra por fechas y exporta a Excel. */
async function cambiosNT(opciones = {}) {
  const dlg = $('#dialogo'); const form = $('#dialogo-form');
  dlg.onclose = null; dlg.oncancel = null;
  // Diseño fase 10: periodo (atajos, fechas o versión), resumen, tabla tipo Excel y dos descargas:
  // los cambios del periodo y la Maestra Tarifaria tal como estaba al cierre de la fecha «Hasta».
  const hoy = new Date(); const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  const desde = h('input', { type: 'date' }); const hasta = h('input', { type: 'date', value: iso(hoy) });
  const versiones = (await api('nt_versiones').catch(() => ({ versiones: [] }))).versiones.slice().sort((a, b) => String(a.creado_en).localeCompare(String(b.creado_en)));
  const selVersion = h('select', {}, h('option', { value: '' }, '— Por versión —'),
    ...versiones.map((v, i) => h('option', { value: String(i) }, `v${v.version} · ${String(v.creado_en).slice(0, 10)} · ${v.tipo}`)));
  const zona = h('div'); const resumen = h('div', { class: 'cambios-resumen' }); const aviso = h('div');
  const atajo = (t, fn) => h('button', { type: 'button', class: 'chip-periodo', onclick: (ev) => {
    [...ev.currentTarget.parentElement.children].forEach((b) => b.classList.remove('activo')); ev.currentTarget.classList.add('activo');
    const [a, b] = fn(); desde.value = a ? iso(a) : ''; hasta.value = iso(b); selVersion.value = ''; cargar();
  } }, t);
  const dia = 86400000;
  const atajos = h('div', { class: 'periodo-atajos' },
    atajo('Hoy', () => [hoy, hoy]), atajo('Últimos 7 días', () => [new Date(hoy - 6 * dia), hoy]),
    atajo('Este mes', () => [new Date(hoy.getFullYear(), hoy.getMonth(), 1), hoy]),
    atajo('Mes anterior', () => [new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1), new Date(hoy.getFullYear(), hoy.getMonth(), 0)]),
    atajo('Todo', () => [null, hoy]));
  // versión: desde que se guardó hasta la siguiente versión (los cambios que la convierten en la siguiente)
  selVersion.onchange = () => {
    if (selVersion.value === '') return;
    const i = Number(selVersion.value); const v = versiones[i]; const sig = versiones[i + 1];
    desde.value = String(v.creado_en).slice(0, 10); hasta.value = sig ? String(sig.creado_en).slice(0, 10) : iso(hoy);
    [...atajos.children].forEach((b) => b.classList.remove('activo')); cargar();
  };
  desde.onchange = hasta.onchange = () => { selVersion.value = ''; [...atajos.children].forEach((b) => b.classList.remove('activo')); cargar(); };
  const cargar = async () => {
    zona.replaceChildren(h('p', { class: 'nota' }, 'Cargando…')); aviso.replaceChildren();
    btnCorte.lastChild.textContent = `Maestra Tarifaria al cierre del ${hasta.value || iso(hoy)}`;
    const r = await api('nt_cambios', { params: { desde: desde.value, hasta: hasta.value } });
    const cuenta = {}; r.cambios.forEach((x) => { cuenta[x[2]] = (cuenta[x[2]] || 0) + 1; });
    resumen.replaceChildren(h('strong', {}, `${miles(r.cambios.length)} cambios`),
      ...Object.entries(cuenta).sort((a, b) => b[1] - a[1]).map(([t, n]) => h('span', { class: 'chip gris' }, `${t}: ${miles(n)}`)));
    zona.replaceChildren(r.cambios.length ? h('div', { class: 'historial-excel' }, tablaConFiltros(['Fecha', 'Usuario', 'Cambio', 'CUM facturar', 'Contrato · subplán', 'Campo', 'Antes', 'Después'],
      r.cambios.map((x) => h('tr', {}, h('td', {}, String(x[0]).slice(0, 16)), h('td', { title: x[1] ?? '' }, x[1] ?? ''),
        h('td', {}, chip(x[2] ?? '', { Creada: 'ok', Editada: 'info', Quitada: 'error', 'Importación desde Excel': 'alerta' }[x[2]] || 'gris')),
        ...x.slice(3).map((v) => h('td', { title: v ?? '' }, v ?? '')))), { alto: 340 }))
      : h('div', { class: 'vacio-caja' }, svgIcono('ok', 'ico-vacio'), h('p', { class: 'nota' }, 'No hay cambios en ese periodo.')),
      r.cambios.length >= 3000 ? h('p', { class: 'nota' }, 'Se muestran los 3.000 más recientes; el Excel trae todos los del periodo.') : '');
  };
  const exportar = () => { window.location.href = `api.php?${new URLSearchParams({ accion: 'nt_cambios_exportar', desde: desde.value, hasta: hasta.value })}`; };
  const btnCorte = h('button', { type: 'button', class: 'btn primario', title: 'Descarga la Maestra Tarifaria completa tal como estaba al cierre de la fecha «Hasta»', onclick: async () => {
    const fecha = hasta.value || iso(hoy);
    const r = await api('nt_al_corte_revisar', { params: { fecha } });
    if (!r.ok) { aviso.replaceChildren(h('div', { class: 'info-azul', style: 'margin-bottom:8px' }, svgIcono('pendiente'), h('span', {}, r.mensaje))); return; }
    aviso.replaceChildren(h('div', { class: 'info-azul', style: 'margin-bottom:8px' }, svgIcono('ok'), h('span', {}, `Descargando la Maestra Tarifaria al ${fecha} (${r.origen}).`)));
    window.location.href = `api.php?${new URLSearchParams({ accion: 'nt_al_corte', fecha })}`;
  } }, svgIcono('bajar'), h('span', {}, 'Maestra Tarifaria al cierre'));
  const encabezado = h('div', { class: 'form-encabezado' },
    h('div', {}, h('h2', {}, 'Cambios de la Maestra Tarifaria'),
      h('p', { class: 'nota' }, 'Líneas creadas, editadas (campo por campo), quitadas, aprobaciones e importaciones, con fecha y usuario.')),
    h('button', { type: 'button', class: 'btn-icono cerrar-x', title: 'Cerrar (Esc)', 'aria-label': 'Cerrar', onclick: () => dlg.close() }, '✕'));
  form.replaceChildren(encabezado,
    h('div', { class: 'cambios-filtros' },
      h('div', { class: 'cambios-periodo' }, h('span', { class: 'cambios-etq' }, 'Periodo'), atajos),
      h('div', { class: 'cambios-fechas' }, h('label', {}, 'Desde', desde), h('label', {}, 'Hasta', hasta), h('label', {}, 'Versión', selVersion),
        h('span', { class: 'espacio' }),
        h('button', { type: 'button', class: 'btn sec', title: 'Excel con los cambios del periodo', onclick: exportar }, svgIcono('bajar'), 'Cambios del periodo'), puede('PRECIO.VER') && puede('NT.VER_FUERA_CONTRATO') ? btnCorte : '')),
    aviso, resumen, zona);
  form.onsubmit = () => {};
  form.classList.add('form-producto');
  dlg.classList.add('ancho', 'ancho-nt');
  // cerrar fácil: ✕, Esc o clic fuera de la ventana
  const fuera = (ev) => { if (ev.target === dlg) dlg.close(); };
  dlg.addEventListener('click', fuera);
  dlg.addEventListener('close', () => {
    dlg.removeEventListener('click', fuera);
    if (!dlg.open || !form.contains(encabezado)) form.classList.remove('form-producto');
    if (!dlg.open) dlg.classList.remove('ancho', 'ancho-nt');
  }, { once: true });
  if (!dlg.open) dlg.showModal();
  // abierta desde «Versiones»: los cambios de esa versión; si no, este mes
  const iv = opciones.versionId ? versiones.findIndex((v) => Number(v.id) === Number(opciones.versionId)) : -1;
  if (iv >= 0) { selVersion.value = String(iv); selVersion.onchange(); } else atajos.children[2].click();
}

/** Historial de versiones de la Maestra Tarifaria: importaciones y versiones anteriores (al quitar líneas), con descarga del Excel. */
async function versionesNT() {
  const r = await api('nt_versiones');
  const dlg = $('#dialogo'); const form = $('#dialogo-form');
  dlg.onclose = null; dlg.oncancel = null;
  // Diseño fase 10: encabezado con ✕ (también Esc y clic afuera), resumen por tipo y tabla tipo Excel
  const COLOR = { 'Importación desde Excel': 'info', 'Foto diaria': 'ok', 'Versión anterior (respaldo)': 'alerta' };
  const cuenta = {}; r.versiones.forEach((v) => { cuenta[v.tipo] = (cuenta[v.tipo] || 0) + 1; });
  const filas = r.versiones.map((v) => h('tr', {},
    h('td', {}, h('strong', {}, `v${v.version}`)), h('td', {}, String(v.creado_en).slice(0, 16)),
    h('td', {}, chip(v.tipo, COLOR[v.tipo] || 'gris')), h('td', { title: v.origen ?? '' }, v.origen ?? ''),
    h('td', { class: 'num' }, miles(v.filas || 0)), h('td', { title: v.usuario ?? '' }, v.usuario ?? ''),
    h('td', { class: 'num', title: v.quitadas.length ? v.quitadas.slice(0, 80).join(' · ') + (v.quitadas.length > 80 ? ' …' : '') : '' }, v.quitadas.length ? miles(v.quitadas.length) : ''),
    h('td', {}, h('div', { class: 'fila-acciones' },
      v.descargable && puede('PRECIO.VER') && puede('NT.VER_FUERA_CONTRATO') ? h('a', { class: 'btn sec chico', title: 'La Maestra Tarifaria completa de esa versión', href: `api.php?accion=nt_version_descargar&id=${v.id}` }, '⬇ Excel') : null,
      h('button', { type: 'button', class: 'btn sec chico', title: 'Cambios desde esta versión hasta la siguiente', onclick: () => cambiosNT({ versionId: v.id }) }, '↻ Cambios')))));
  const encabezado = h('div', { class: 'form-encabezado' },
    h('div', {}, h('h2', {}, 'Versiones de la Maestra Tarifaria'),
      h('p', { class: 'nota' }, 'Cada importación desde Excel, cada foto diaria y cada respaldo antes de quitar líneas, con su archivo.')),
    h('button', { type: 'button', class: 'btn-icono cerrar-x', title: 'Cerrar (Esc)', 'aria-label': 'Cerrar', onclick: () => dlg.close() }, '✕'));
  form.replaceChildren(encabezado,
    r.versiones.length ? h('div', { class: 'cambios-resumen' }, h('strong', {}, `${miles(r.versiones.length)} versiones`),
      ...Object.entries(cuenta).map(([t, n]) => chip(`${t}: ${miles(n)}`, COLOR[t] || 'gris'))) : '',
    r.versiones.length ? h('div', { class: 'historial-excel' }, tablaConFiltros(['Versión', 'Fecha', 'Tipo', 'Detalle', 'Líneas', 'Usuario', 'Quitadas', ''], filas, { sinFiltro: [7], alto: 420 }))
      : h('div', { class: 'vacio-caja' }, svgIcono('capas', 'ico-vacio'), h('p', { class: 'nota' }, 'Aún no hay versiones.')),
    h('p', { class: 'nota' }, 'Pase el mouse sobre «Quitadas» para ver los CUM quitados en esa versión.'));
  form.onsubmit = () => {};
  form.classList.add('form-producto');
  dlg.classList.add('ancho', 'ancho-nt');
  const fuera = (ev) => { if (ev.target === dlg) dlg.close(); };
  dlg.addEventListener('click', fuera);
  dlg.addEventListener('close', () => {
    dlg.removeEventListener('click', fuera);
    if (!dlg.open || !form.contains(encabezado)) form.classList.remove('form-producto');
    if (!dlg.open) dlg.classList.remove('ancho', 'ancho-nt');
  }, { once: true });
  if (!dlg.open) dlg.showModal();
}

/** Importar / actualizar la Maestra Tarifaria desde Excel: vista previa -> aplicar (Administrador). */
function panelImportarNT(abierto, alAplicar, titulo = 'Importar / actualizar desde Excel') {
  const input = h('input', { type: 'file', accept: '.xlsx,.xlsm' });
  const resultado = h('div');
  const msg = h('span', { class: 'nota' });
  const btn = h('button', { class: 'btn sec', onclick: () => procesar(false) }, 'Previsualizar');
  async function procesar(aplicar) {
    const archivo = input.files[0];
    if (!archivo) { msg.textContent = 'Seleccione el archivo.'; return; }
    btn.disabled = true;
    msg.textContent = aplicar ? 'Aplicando…' : 'Leyendo el archivo…';
    try {
      const r = await subirArchivo('nt_importar', { modo: aplicar ? 'aplicar' : 'previsualizar' }, archivo, (p) => { if (p < 100) msg.textContent = `Subiendo ${p} %…`; else msg.textContent = aplicar ? 'Aplicando…' : 'Leyendo el archivo…'; });
      msg.textContent = '';
      if (r.aplicado) { toast(`Maestra Tarifaria actualizada: ${r.resumen.nuevos} nuevos y ${r.resumen.actualizar} actualizados${r.lineas_repetidas ? `; ${r.lineas_repetidas} líneas creadas en otros contratos` : ''}.`); alAplicar(); return; }
      const s = r.resumen;
      const bloques = [h('p', { class: 'nota' }, `Hoja «${s.hoja}», encabezados en la fila ${s.fila_encabezado}.`),
        h('div', { class: 'fila-acciones' }, chip(`${miles(s.filas_archivo)} filas`, 'gris'), chip(`${miles(s.nuevos)} nuevos`, 'info'),
          chip(`${miles(s.actualizar)} por actualizar`, 'info'), chip(`${miles(s.sin_cambios)} sin cambios`, 'gris'),
          s.repetidas ? chip(`${s.repetidas} CUM repetidos`, 'alerta') : null, s.omitidas ? chip(`${s.omitidas} omitidas`, 'alerta') : null,
          s.no_en_archivo ? chip(`${s.no_en_archivo} no están en el archivo`, 'alerta') : null,
          s.calculadas_editadas ? chip(`${miles(s.calculadas_editadas)} celdas calculadas editadas (no se cargan)`, 'error') : null)];
      // Columnas calculadas modificadas en el archivo: se ignoran; hay que cambiar lo que las origina.
      if (r.calculadas?.length) bloques.push(h('div', { class: 'aviso-caja', style: 'margin-top:8px' },
        h('strong', {}, `Se modificaron ${miles(s.calculadas_editadas)} celdas de columnas calculadas: esas celdas NO se cargan.`),
        h('p', { class: 'nota', style: 'margin:4px 0' }, '«PRECIO FINAL + IVA» se calcula con PRECIO DE VENTA e IVA. «¿PRECIO CORRECTO- M?» es el mayor precio final de las líneas del mismo COD MUTUAL en el mismo contrato (en CAPITA, del mismo COD AUF): para cambiarlo corrija el PRECIO DE VENTA o el COD MUTUAL de la línea que lo sube (lo indica la observación NT19).'),
        tablaSimple(['CUM facturar', 'Contrato', 'Columna', 'En el archivo', 'Calculado'], r.calculadas.map((x) => [x.cum_facturar, x.contrato, x.columna, miles(Math.round(x.archivo)), miles(Math.round(x.calculado))]))));
      if (r.cambios.length) bloques.push(h('h3', { style: 'margin-top:8px' }, 'Cambios'), tablaSimple(['CUM facturar', 'Contrato', 'Campo', 'Antes', 'Después'],
        r.cambios.flatMap((x) => Object.entries(x.campos).map(([k, v]) => [x.cum_facturar, x.contrato, k, v.antes ?? '(vacío)', v.despues]))));
      if (r.nuevos.length && r.nuevos.length < s.filas_archivo) bloques.push(h('h3', { style: 'margin-top:8px' }, 'Nuevos'),
        tablaSimple(['CUM facturar', 'COD MUTUAL', 'Descripción', 'Precio'], r.nuevos.map((n) => [n.cum_facturar, n.cod_mutual, n.descripcion, pesos(n.precio_venta)])));
      const avisos = [...r.repetidos.map((x) => `CUM repetido: ${x}`), ...r.omitidas];
      if (avisos.length) bloques.push(h('div', { class: 'aviso-caja', style: 'margin-top:8px' }, h('ul', { class: 'lista-avisos' }, ...avisos.map((a) => h('li', {}, a)))));
      if (r.no_en_archivo.length) bloques.push(h('details', { style: 'margin-top:8px' }, h('summary', { style: 'cursor:pointer' },
        `${r.no_en_archivo.length} productos de la Maestra Tarifaria no vienen en el archivo (no se borran; se desactivan con «Editar»)`), h('p', { class: 'nota' }, r.no_en_archivo.join(', '))));
      const hay = s.nuevos || s.actualizar;
      bloques.push(h('div', { class: 'fila-acciones', style: 'margin-top:10px' },
        h('button', { class: 'btn primario', disabled: !hay, onclick: async () => {
          if (await confirmar('Aplicar a la Maestra Tarifaria', `Se crearán ${miles(s.nuevos)} productos y se actualizarán ${miles(s.actualizar)}. El archivo queda guardado con su versión y cada cambio en auditoría.`, 'Aplicar')) procesar(true);
        } }, 'Aplicar'), hay ? null : h('span', { class: 'nota' }, 'No hay cambios para aplicar.')));
      resultado.replaceChildren(...bloques);
    } catch (e) { msg.textContent = ''; toast(e.message, true); }
    btn.disabled = false;
  }
  input.addEventListener('change', () => resultado.replaceChildren());
  return h('details', { class: 'tarjeta', open: abierto, style: 'margin-bottom:12px' },
    h('summary', { style: 'cursor:pointer;font-weight:600' }, titulo),
    h('ol', { class: 'nota' },
      h('li', {}, 'Sirve el Excel actual (hoja EVENTO 2025) o el que se exporta desde aquí: la hoja y los encabezados se ubican solos.'),
      h('li', {}, 'Se toman solo las columnas digitadas (CONTRATO, CUM, CUM FACTURAR, COD AUF, TIPO, DESCRIPCION, COD GEN SIESA, COD MUTUAL, COD MUTUAL FINAL, PRECIO DE VENTA, IVA). Las calculadas (PRECIO FINAL + IVA, ¿PRECIO CORRECTO- M?, validaciones…) no se cargan: el aplicativo las recalcula y la vista previa avisa si se editaron.'),
      h('li', {}, 'Celda vacía = no cambia. Los productos que no vengan en el archivo no se borran.'),
      h('li', {}, 'Revise la vista previa antes de aplicar.')),
    h('div', { class: 'fila-acciones' }, input, btn, msg), resultado);
}

const CATS = [
  ['molecula', 'COD GEN (moléculas)', [['cod_gen_audifarma', 'Cód. Audifarma'], ['descripcion_general', 'Descripción'], ['codigo_medicar', 'Cód. Medicar'], ['concentracion_agrupada', 'Concentración'], ['principio_activo', 'Principio activo'], ['forma_farmaceutica', 'Forma'], ['via_administracion', 'Vía'], ['cadena_frio', 'Frío'], ['activo', 'Estado']]],
  ['laboratorio', 'Laboratorios', [['codigo', 'Código'], ['nombre', 'Laboratorio'], ['activo', 'Estado']]],
  ['presentacion', 'Presentaciones', [['codigo', 'Código'], ['presentacion_agrupada', 'Presentación'], ['unidad_orden', 'Unidad de orden'], ['activo', 'Estado']]],
  ['subplan', 'Subplanes Medicar', [['id_subplan', 'ID'], ['nombre', 'Subplán'], ['clasificacion', 'Clasificación'], ['fuente', 'Fuente de precio'], ['activo', 'Estado']]],
  ['grupo_imp', 'Grupos impositivos', [['codigo', 'Código'], ['etiqueta_iva', 'IVA'], ['descripcion', 'Descripción'], ['iva_medicar', 'IVA Medicar %'], ['activo', 'Estado']]],
  ['tipo_inv', 'Tipos de inventario', [['codigo', 'Código'], ['tipo_medicamento', 'Tipo'], ['descripcion', 'Descripción'], ['activo', 'Estado']]],
  // Las tres hojas TABLA-* del Excel, como catálogos separados (4º elemento: tipo y filtro fijo en el servidor)
  ['rips_ffm', 'TABLA-FFM (Forma farmacéutica)', [['codigo', 'Código'], ['nombre', 'Nombre'], ['habilitado', 'Habilitado']], { tipo: 'rips', params: { tabla: 'FFM' } }],
  ['rips_umm', 'TABLA-UMM (Unidad de medida)', [['codigo', 'Código'], ['nombre', 'Nombre'], ['habilitado', 'Habilitado']], { tipo: 'rips', params: { tabla: 'UMM' } }],
  ['rips_upr', 'TABLA-UPR (Unidad de presentación)', [['codigo', 'Código'], ['nombre', 'Nombre'], ['habilitado', 'Habilitado']], { tipo: 'rips', params: { tabla: 'UPR' } }],
  ['ium', 'IUM', [['codigo', 'Código IUM'], ['nombre', 'Nombre'], ['activo', 'Estado']]],
  ['campo_tec', 'Campos de descripciones técnicas', [['orden', '#'], ['f104_id', 'f104_id'], ['activo', 'Estado']]],
];
/** Formularios de edición de catálogos (solo Administrador; el servidor valida CATALOGO.EDITAR). */
const EDITORES = {
  laboratorio: (f, sug, alGuardar) => dialogo({
    titulo: f ? `Editar laboratorio ${f.codigo}` : 'Nuevo laboratorio',
    texto: f ? 'El código no se modifica: hace parte del código de producto ya creado.' : 'El nombre se guarda en mayúsculas y sin espacios repetidos.',
    campos: [
      { tipo: 'solo_lectura', etiqueta: 'Código', valor: f ? f.codigo : `${sug.siguiente_laboratorio} (siguiente consecutivo)` },
      { nombre: 'nombre', etiqueta: 'Nombre del laboratorio', valor: f?.nombre, requerido: true },
      f ? { nombre: 'activo', etiqueta: 'Activo', tipo: 'checkbox', valor: Number(f.activo) } : null,
    ].filter(Boolean),
    alEnviar: async (v) => { await api('laboratorio_guardar', { metodo: 'POST', datos: { ...v, id: f?.id, activo: f ? v.activo : true } }); toast('Laboratorio guardado.'); alGuardar(); },
  }),
  presentacion: (f, sug, alGuardar) => dialogo({
    titulo: f ? `Editar presentación: ${f.presentacion_agrupada}` : 'Nueva presentación',
    texto: f ? 'La presentación y su código no se modifican (hacen parte del código de producto).' : 'Se arma como en el Excel: ENVASE X CANTIDAD (ej. CAJA X 50).',
    campos: f ? [
      { tipo: 'solo_lectura', etiqueta: 'Código', valor: f.codigo },
      { nombre: 'unidad_orden', etiqueta: 'Unidad de orden (SIESA)', valor: f.unidad_orden, requerido: true },
      { nombre: 'activo', etiqueta: 'Activo', tipo: 'checkbox', valor: Number(f.activo) },
    ] : [
      { tipo: 'solo_lectura', etiqueta: 'Código', valor: `${sug.siguiente_presentacion} (siguiente consecutivo)` },
      { nombre: 'envase', etiqueta: 'Envase', tipo: 'select', opciones: sug.envases.map((e) => [e, e]) },
      { nombre: 'cantidad', etiqueta: 'Cantidad (unidades de presentación)', tipo: 'number', requerido: true },
      { nombre: 'unidad_orden', etiqueta: 'Unidad de orden (SIESA)', requerido: true, placeholder: 'ej. CX50', ayuda: '2 a 10 letras o números, sin espacios.' },
    ],
    alEnviar: async (v) => {
      const r = await api('presentacion_guardar', { metodo: 'POST', datos: { ...v, id: f?.id, activo: f ? v.activo : true } });
      toast(r.aviso || 'Presentación guardada.', !!r.aviso); alGuardar();
    },
  }),
  molecula: (f, sug, alGuardar, pre = {}) => editarMolecula(f, sug, alGuardar, pre),
  subplan: (f, sug, alGuardar) => dialogo({
    titulo: f ? `Editar subplán ${f.id_subplan}` : 'Nuevo subplán',
    texto: 'Los subplanes no se renombran (no se afecta el histórico). La clasificación (CAPITA / EVENTO) y la fuente de precio las usa la Maestra Tarifaria; sin clasificación, sus líneas salen con la alerta NT28.',
    campos: [
      ...(f ? [{ tipo: 'solo_lectura', etiqueta: 'ID / Subplán', valor: `${f.id_subplan} · ${f.nombre}` }]
        : [{ nombre: 'id_subplan', etiqueta: 'ID del subplán en Medicar', tipo: 'number', requerido: true }, { nombre: 'nombre', etiqueta: 'Nombre', requerido: true }]),
      { nombre: 'clasificacion', etiqueta: 'Clasificación del contrato', tipo: 'select', valor: f?.clasificacion ?? '', opciones: [['', '(sin parametrizar)'], ['CAPITA', 'CAPITA'], ['EVENTO', 'EVENTO']] },
      { nombre: 'fuente', etiqueta: 'Fuente de precio (base de referencia)', tipo: 'select', valor: f?.fuente ?? '',
        opciones: [['', '(ninguna)'], ['LISTADO_CAPITA', 'Listado Cápita'], ['CONTRATO_EVENTO', 'Contrato Evento'], ['LISTADO_EVENTO_SAS', 'Listado EVENTO SAS'], ['LISTADO_CONSORCIO', 'Listado Consorcio']] },
      ...(f ? [{ nombre: 'activo', etiqueta: 'Activo', tipo: 'checkbox', valor: Number(f.activo) }] : []),
    ],
    alEnviar: async (v) => { await api('subplan_guardar', { metodo: 'POST', datos: f ? { id_subplan: f.id_subplan, ...v } : v }); toast('Subplán guardado.'); alGuardar(); },
  }),
  grupo_imp: (f, sug, alGuardar) => editorGenerico('grupo_imp', f, alGuardar, {
    titulo: f ? `Editar grupo impositivo ${f.codigo}` : 'Nuevo grupo impositivo',
    texto: 'El código y la etiqueta de IVA no se modifican (se usan en la creación del ítem en SIESA).',
    campos: [
      f ? { tipo: 'solo_lectura', etiqueta: 'Código / IVA', valor: `${f.codigo} · ${f.etiqueta_iva}` } : { nombre: 'codigo', etiqueta: 'Código SIESA (4 dígitos)', requerido: true, placeholder: 'ej. 0016' },
      f ? null : { nombre: 'etiqueta_iva', etiqueta: 'Etiqueta de IVA (como se elige al crear)', requerido: true, placeholder: 'ej. SIN IVA' },
      { nombre: 'descripcion', etiqueta: 'Descripción', valor: f?.descripcion, requerido: true },
      { nombre: 'iva_medicar', etiqueta: 'IVA en Medicar (%)', tipo: 'number', valor: f?.iva_medicar, requerido: true },
    ],
  }),
  tipo_inv: (f, sug, alGuardar) => {
    if (!f && !sug.tipos_sin_inventario.length) { toast('Todos los tipos de medicamento ya tienen tipo de inventario. Cree primero el valor en el catálogo "Tipo de medicamento".', true); return; }
    editorGenerico('tipo_inv', f, alGuardar, {
      titulo: f ? `Editar tipo de inventario ${f.codigo}` : 'Nuevo tipo de inventario',
      texto: 'Relaciona un tipo de medicamento con su tipo de inventario SIESA. Código y tipo no se modifican.',
      campos: [
        f ? { tipo: 'solo_lectura', etiqueta: 'Código / Tipo', valor: `${f.codigo} · ${f.tipo_medicamento}` } : { nombre: 'codigo', etiqueta: 'Código SIESA', requerido: true, placeholder: 'ej. IN350503' },
        f ? null : { nombre: 'tipo_medicamento', etiqueta: 'Tipo de medicamento', tipo: 'select', opciones: sug.tipos_sin_inventario.map((t) => [t, t]) },
        { nombre: 'descripcion', etiqueta: 'Descripción', valor: f?.descripcion, requerido: true },
      ],
    });
  },
  rips_ffm: (f, sug, alGuardar) => editorRips('FFM', 'TABLA-FFM (Forma farmacéutica)', f, alGuardar),
  rips_umm: (f, sug, alGuardar) => editorRips('UMM', 'TABLA-UMM (Unidad de medida)', f, alGuardar),
  rips_upr: (f, sug, alGuardar) => editorRips('UPR', 'TABLA-UPR (Unidad de presentación)', f, alGuardar),
  ium: (f, sug, alGuardar) => editorGenerico('ium', f, alGuardar, {
    titulo: f ? `Editar IUM ${f.codigo}` : 'Nuevo código IUM',
    texto: 'El código IUM no se modifica.',
    campos: [
      f ? { tipo: 'solo_lectura', etiqueta: 'Código IUM', valor: f.codigo } : { nombre: 'codigo', etiqueta: 'Código IUM', requerido: true, placeholder: 'ej. 1A1000101000100' },
      { nombre: 'nombre', etiqueta: 'Nombre', valor: f?.nombre, requerido: true },
    ],
  }),
  campo_tec: (f, sug, alGuardar) => editorGenerico('campo_tec', f, alGuardar, {
    titulo: f ? `Editar campo técnico: ${f.f104_id}` : 'Nuevo campo de descripción técnica',
    texto: 'Campo que se envía a SIESA en DESCRIPCIONES_TECNICAS (f104_id). Debe corresponder a un dato del producto; el nombre no se modifica.',
    campos: [
      f ? { tipo: 'solo_lectura', etiqueta: 'Campo (f104_id)', valor: f.f104_id } : { nombre: 'f104_id', etiqueta: 'Campo (f104_id)', requerido: true },
      { nombre: 'orden', etiqueta: 'Orden', tipo: 'number', valor: f?.orden },
    ],
  }),
};

/** Editor de una tabla RIPS: la tabla queda fija según el catálogo elegido. */
function editorRips(tabla, nombre, f, alGuardar) {
  editorGenerico('rips', f ? f : null, alGuardar, {
    titulo: f ? `Editar ${nombre}: ${f.codigo}` : `Nuevo código en ${nombre}`,
    texto: 'El código no se modifica.',
    campos: [
      f ? { tipo: 'solo_lectura', etiqueta: 'Código', valor: f.codigo } : { nombre: 'codigo', etiqueta: 'Código', requerido: true },
      { nombre: 'nombre', etiqueta: 'Nombre', valor: f?.nombre, requerido: true },
    ],
    estadoCampo: 'habilitado',
    fijos: { tabla },
  });
}

/** Formulario común: agrega "Activo" al editar y envía a catalogo_guardar (la llave va del registro). */
function editorGenerico(tipo, f, alGuardar, { titulo, texto, campos, estadoCampo = 'activo', fijos = {} }) {
  dialogo({
    titulo, texto,
    campos: [...campos.filter(Boolean), f ? { nombre: 'activo', etiqueta: 'Activo', tipo: 'checkbox', valor: Number(f[estadoCampo]) } : null].filter(Boolean),
    alEnviar: async (v) => {
      const llave = f ? { codigo: f.codigo, tabla: f.tabla, f104_id: f.f104_id, etiqueta_iva: f.etiqueta_iva, tipo_medicamento: f.tipo_medicamento } : {};
      await api('catalogo_guardar', { metodo: 'POST', datos: { ...v, ...fijos, ...llave, tipo, editar: !!f, activo: f ? v.activo : true } });
      toast('Guardado.'); alGuardar();
    },
  });
}
const EXCEL_CATALOGOS = ['molecula', 'laboratorio', 'presentacion'];

/** Panel de actualización masiva por Excel: plantilla -> vista previa -> aplicar. */
function panelExcelCatalogo(tipo, titulo, alAplicar) {
  const input = h('input', { type: 'file', accept: '.xlsx' });
  const resultado = h('div');
  const msg = h('span', { class: 'nota' });
  const btnPrev = h('button', { class: 'btn sec', onclick: () => procesar(false) }, 'Previsualizar cambios');
  let archivo = null;

  async function procesar(aplicar) {
    archivo = input.files[0] || archivo;
    if (!archivo) { msg.textContent = 'Seleccione el archivo.'; return; }
    msg.textContent = aplicar ? 'Aplicando…' : 'Analizando…';
    btnPrev.disabled = true;
    try {
      const r = await subirArchivo('catalogo_excel', { tipo, modo: aplicar ? 'aplicar' : 'previsualizar' }, archivo, () => {});
      msg.textContent = '';
      if (r.aplicado) {
        toast(`${titulo}: ${r.resumen.actualizar} actualizados y ${r.resumen.nuevos} nuevos.`);
        resultado.replaceChildren(h('div', { class: 'info-caja' }, `✔ Cambios aplicados: ${r.resumen.actualizar} actualizados, ${r.resumen.nuevos} nuevos. Quedaron en auditoría.`));
        input.value = ''; archivo = null; alAplicar();
      } else pintar(r);
    } catch (e) { msg.textContent = ''; toast(e.message, true); }
    btnPrev.disabled = false;
  }

  function pintar(r) {
    const s = r.resumen;
    const bloques = [h('div', { class: 'fila-acciones' },
      chip(`${s.filas} filas`, 'gris'), chip(`${s.sin_cambios} sin cambios`, 'gris'), chip(`${s.actualizar} por actualizar`, 'info'),
      s.correcciones_codigo ? chip(`${s.correcciones_codigo} corrección(es) de código`, 'alerta') : null,
      chip(`${s.nuevos} nuevos`, 'info'), chip(`${s.errores} errores`, s.errores ? 'error' : 'ok'),
      s.por_corregir ? chip(`${s.por_corregir} datos actuales a corregir`, 'alerta') : null)];
    if (r.errores.length) {
      bloques.push(h('h3', { style: 'margin-top:8px' }, 'Errores (corrija el Excel y vuelva a cargarlo)'), tablaSimple(['Fila', 'Columna', 'Valor', 'Motivo'],
        r.errores.map((e) => [e.fila, e.columna, e.valor, e.motivo])));
    }
    if (r.cambios.length) {
      const filas = r.cambios.flatMap((c) => Object.entries(c.campos).map(([campo, v]) => [c.fila, c.llave,
        campo === 'cod_gen_audifarma' ? 'CORRECCIÓN DE CÓDIGO (identificado por Cód. Medicar)' : campo, v.antes === '' || v.antes == null ? '(vacío)' : v.antes, v.despues]));
      bloques.push(h('h3', { style: 'margin-top:8px' }, 'Cambios'), tablaSimple(['Fila', 'Código', 'Campo', 'Antes', 'Después'], filas));
    }
    if (r.avisos?.length) {
      bloques.push(h('div', { class: 'aviso-caja', style: 'margin-top:8px' }, h('strong', {}, 'Avisos (no bloquean):'),
        h('ul', { class: 'lista-avisos' }, ...r.avisos.map((a) => h('li', {}, `Fila ${a.fila} (${a.codigo}): ${a.detalle}`)))));
    }
    if (r.nuevos.length) {
      bloques.push(h('h3', { style: 'margin-top:8px' }, 'Registros nuevos'), tablaSimple(['Fila', 'Código', 'Datos'],
        r.nuevos.map((n) => [n.fila, `${n.llave} (nuevo)`, Object.entries(n.campos).map(([k, v]) => `${k}: ${v}`).join(' · ')])));
    }
    if (r.por_corregir?.length) {
      bloques.push(h('details', { style: 'margin-top:8px' },
        h('summary', { style: 'cursor:pointer;font-weight:600;color:var(--alerta)' }, `Datos actuales a corregir (${r.por_corregir.length}) — no bloquean el cargue`),
        h('p', { class: 'nota' }, 'Estos valores ya estaban así en el catálogo y no se modificaron. Corríjalos en el Excel con un valor permitido y vuelva a cargarlo.'),
        tablaSimple(['Fila', 'Código', 'Columna', 'Valor actual', 'Motivo'], r.por_corregir.map((e) => [e.fila, e.codigo, e.columna, e.valor, e.motivo]))));
    }
    const puedeAplicar = !s.errores && (s.actualizar || s.nuevos);
    bloques.push(h('div', { class: 'fila-acciones', style: 'margin-top:10px' },
      h('button', { class: 'btn primario', disabled: !puedeAplicar, onclick: async () => {
        const detalle = `Se aplicarán ${s.actualizar} actualizaciones${s.correcciones_codigo ? ` (incluye ${s.correcciones_codigo} corrección(es) de código)` : ''} y ${s.nuevos} registros nuevos. Quedará en auditoría.`;
        if (await confirmar(`Aplicar cambios en ${titulo}`, detalle, 'Aplicar cambios')) procesar(true);
      } }, 'Aplicar cambios'),
      !puedeAplicar ? h('span', { class: 'nota' }, s.errores ? 'No se puede aplicar mientras haya errores.' : 'No hay cambios para aplicar.') : null));
    resultado.replaceChildren(...bloques);
  }

  input.addEventListener('change', () => { archivo = null; resultado.replaceChildren(); });
  return h('details', { class: 'panel-excel' },
    h('summary', {}, h('span', { class: 'panel-excel-ico' }, svgIcono('subir')),
      h('span', { class: 'panel-excel-tit' }, h('strong', {}, `Actualizar ${titulo} desde Excel`),
        h('span', { class: 'nota' }, 'Valida las columnas y conserva el catálogo actual si el archivo presenta errores.')),
      h('span', { class: 'panel-excel-flecha' }, '⌄')),
    h('ol', { class: 'nota' },
      h('li', {}, 'Descargue la plantilla: contiene el catálogo actual completo.'),
      h('li', {}, 'Corrija o complete las celdas. Una celda vacía NO borra el dato actual. El código no se modifica.'),
      tipo === 'molecula' ? h('li', {}, 'Para crear un COD GEN nuevo, agregue una fila con su código.') : h('li', {}, 'Para crear un registro nuevo, agregue una fila con CODIGO vacío (se asigna el consecutivo).'),
      h('li', {}, 'Cargue el archivo y revise la vista previa antes de aplicar. Si hay un error, no se aplica nada.')),
    h('div', { class: 'fila-acciones' },
      h('a', { class: 'btn sec', href: `api.php?accion=catalogo_plantilla&tipo=${tipo}` }, '⬇ Descargar plantilla'),
      input, btnPrev, msg),
    resultado);
}

/** Tabla de resultados (vista previa): filtros por columna en el navegador y alto limitado. */
function tablaSimple(titulos, filas) {
  return tablaConFiltros(titulos, filas.map((f) => h('tr', {}, ...f.map((v) => h('td', {}, v ?? '')))), { alto: 320 });
}

/**
 * Tabla local (datos ya cargados) con filtros por columna en el encabezado (autofiltro como Excel:
 * la lista de valores sale de las filas visibles con los demás filtros).
 * opts.sinFiltro: índices de columnas sin filtro (p.ej. botones). opts.alto: alto fijo en px.
 */
function tablaConFiltros(titulos, filas, opts = {}) {
  const sinFiltro = new Set(opts.sinFiltro || []);
  const memoria = claveFiltros(titulos, opts.clave || '');
  const recordado = leerFiltros(memoria) || {};
  const seleccion = titulos.map((_, i) => (Array.isArray(recordado[i]) ? new Set(recordado[i]) : null)); // null = todos; si no, Set de valores
  const texto = (tr, i) => (tr.children[i]?.textContent || '').trim();
  let consulta = ''; // buscador general (opts.buscador): se combina con los filtros por columna
  const pasa = (tr, excepto = -1) => seleccion.every((sel, i) => i === excepto || !sel || sel.has(texto(tr, i)))
    && (!consulta || tr.textContent.toUpperCase().includes(consulta));
  const tbody = h('tbody', {}, ...filas);
  const vacio = h('tr', { hidden: true }, h('td', { colspan: titulos.length, class: 'nota' }, 'Sin resultados'));
  tbody.append(vacio);
  const filtrar = () => {
    let visibles = 0;
    for (const tr of filas) { const ok = pasa(tr); tr.hidden = !ok; if (ok) visibles++; }
    vacio.hidden = visibles > 0;
    guardarFiltros(memoria, Object.fromEntries(seleccion.map((sel, i) => [i, sel ? [...sel] : null]).filter(([, v]) => v)));
    limpiar.actualizar(seleccion.filter(Boolean).length);
    ajustarAltoTablas();
  };
  const controles = [];
  const porColumna = {};
  const filaFiltros = h('tr', {}, ...titulos.map((t, i) => {
    if (sinFiltro.has(i)) return h('th', { class: 'filtro' });
    const f = filtroExcel({
      titulo: t || `Columna ${i + 1}`,
      obtener: async (busqueda) => {
        const b = busqueda.toUpperCase();
        const valores = [...new Set(filas.filter((tr) => pasa(tr, i)).map((tr) => texto(tr, i)))]
          .filter((v) => !b || v.toUpperCase().includes(b))
          .sort((a, c) => (a === '' ? 1 : c === '' ? -1 : a.localeCompare(c, 'es', { numeric: true })));
        return { valores };
      },
      alAplicar: (sel) => { seleccion[i] = sel === null ? null : new Set(sel); filtrar(); },
    });
    controles.push(f);
    porColumna[i] = f;
    return h('th', { class: 'filtro' }, f.boton);
  }));
  const limpiar = botonBorrarFiltros(() => {
    seleccion.fill(null);
    controles.forEach((f) => f.limpiar());
    filtrar();
  });
  const buscador = opts.buscador ? h('label', { class: 'buscador' }, svgIcono('buscar'),
    h('input', { type: 'search', placeholder: opts.buscador, oninput: (ev) => { consulta = ev.target.value.trim().toUpperCase(); filtrar(); } })) : null;
  const caja = h('div', {}, h('div', { class: 'barra-tabla' }, buscador, limpiar),
    h('div', { class: `tabla-caja${opts.alto ? ' fija' : ''}`, style: opts.alto ? `max-height:${opts.alto}px` : null }, h('table', {},
      h('thead', {}, h('tr', {}, ...titulos.map((t) => h('th', {}, t))), filaFiltros), tbody)));
  // Filtro desde fuera (p.ej. un indicador): columna i = valores (null = todos)
  caja.filtrar = (i, valores) => { seleccion[i] = valores ? new Set(valores) : null; porColumna[i]?.establecer(valores); filtrar(); };
  // filtros recordados de la última vez
  if (seleccion.some(Boolean)) {
    seleccion.forEach((sel, i) => { if (sel) porColumna[i]?.establecer([...sel]); });
    for (const tr of filas) tr.hidden = !pasa(tr);
    vacio.hidden = filas.some((tr) => !tr.hidden);
    limpiar.actualizar(seleccion.filter(Boolean).length);
  }
  return caja;
}

/**
 * Catálogo de LISTA DESPLEGABLE (Tipo de medicamento, Envase, ... o las que cree el Administrador).
 * Valores: se crean, se ordenan y se activan/desactivan; no se renombran (el producto guarda el texto).
 */
function pintarLista(zona, lista, editable, alCambiar) {
  zona.append(h('div', { class: 'info-caja', style: 'margin-bottom:10px' },
    h('strong', {}, lista.nombre), lista.es_sistema == 1 ? chip('Del formato Excel', 'gris') : chip('Creada en el aplicativo', 'info'),
    lista.descripcion ? h('div', { class: 'nota' }, lista.descripcion) : null,
    h('div', { class: 'nota' }, `${lista.activos} valores activos de ${lista.total}.`)));
  const editarValor = (f) => dialogo({
    titulo: f ? `Editar valor: ${f.valor}` : `Nuevo valor en ${lista.nombre}`,
    texto: f ? 'El texto no se modifica (los medicamentos creados lo conservan). Si está mal escrito, desactívelo y cree el correcto.'
             : 'Se guarda en mayúsculas y sin espacios repetidos. Si deja el orden vacío, queda al final.',
    campos: [
      f ? { tipo: 'solo_lectura', etiqueta: 'Valor', valor: f.valor } : { nombre: 'valor', etiqueta: 'Valor', requerido: true },
      { nombre: 'orden', etiqueta: 'Orden en la lista desplegable', tipo: 'number', valor: f?.orden },
      f ? { nombre: 'activo', etiqueta: 'Activo', tipo: 'checkbox', valor: Number(f.activo) } : null,
    ].filter(Boolean),
    alEnviar: async (v) => { await api('lista_valor_guardar', { metodo: 'POST', datos: { ...v, id: f?.id, lista: lista.codigo } }); toast('Valor guardado.'); alCambiar(); },
  });
  const columnas = [
    { campo: 'valor', titulo: 'Valor' },
    { campo: 'orden', titulo: 'Orden', num: true },
    { campo: 'activo', titulo: 'Estado', valor: (f) => (Number(f.activo) ? chip('Activo', 'ok') : chip('Inactivo', 'gris')) },
  ];
  if (editable) {
    columnas.push({ titulo: 'Acciones', filtro: false, valor: (f) => h('button', { type: 'button', class: 'btn-icono', title: 'Editar', 'aria-label': 'Editar', onclick: () => editarValor(f) }, svgIcono('editar')) });
    zona.append(h('div', { class: 'fila-acciones', style: 'margin-bottom:8px' },
      h('button', { class: 'btn primario', onclick: () => editarValor(null) }, '+ Nuevo valor'),
      h('span', { class: 'nota' }, 'Solo el Administrador. No se borra: se desactiva. Cada cambio queda en auditoría.')));
  }
  return tablaPaginada({ contenedor: zona, columnas, clase: 'tabla-excel tabla-datos tabla-cat', cargar: (p) => api('catalogo', { params: { tipo: 'lista', lista: lista.codigo, ...p } }) });
}

// ------------------------------------------------------------------ correcciones SIESA / MEDICAR
/** Estado de una inconsistencia: [texto, color del chip, ícono]. */
const COR_ESTADO = {
  NO_VALIDADO: ['No validado', 'error', 'error'], PENDIENTE_CORRECCION: ['Por corregir', 'alerta', 'alerta'],
  PENDIENTE_REVISION: ['Pendiente de revisión', 'info', 'pendiente'], PENDIENTE_ACTUALIZACION: ['Pendiente de actualización', 'gris', 'reloj'],
  CORREGIDO: ['Corregido', 'ok', 'ok'], APROBADO: ['Aprobado', 'ok', 'ok'],
};
const COR_CERRADOS = ['CORREGIDO', 'APROBADO'];
const COR_CARGUE = { DESCRIPCIONES: 'Descripciones Técnicas', CREACION_ITEM: 'CREACION_ITEM', MANUAL: 'A mano', OBSERVACION: 'PLU + observación' };
const COR_BASE = { SIESA: 'Maestro SIESA', MEDICAR: 'Maestra MEDICAR' };

/**
 * Correcciones SIESA / MEDICAR (2026-10-19): la Maestra Tarifaria manda; lo que no coincide en SIESA (por MED-CUM) o en MEDICAR (por el
 * PLU de SIESA, en el subplán de cada línea) queda con su estado y se descarga: SIESA en sus archivos de cargue, MEDICAR en PLU + OBSERVACIÓN.
 */
let corSistema = 'SIESA';
async function pCorrecciones(c) {
  const gestionar = puede('CORRECCION.GESTIONAR');
  const sistema = corSistema;
  const esMed = sistema === 'MEDICAR';
  const ini = await api('cor_listar', { params: { sistema, pagina: 1, filtros: '{}' } });
  const campos = ini.campos;
  const pestanas = h('div', { class: 'cor-pestanas' }, ...['SIESA', 'MEDICAR'].map((s) => h('button', { type: 'button', class: `cor-pestana${s === sistema ? ' activa' : ''}`,
    onclick: () => { if (s === corSistema) return; corSistema = s; c.replaceChildren(); pCorrecciones(c).then(ajustarAltoTablas); } }, s)));
  const resumen = h('div');
  const t = h('div', { class: 'tarjeta' });
  c.append(pestanas, resumen, t);
  let tabla = null;
  const filtrarPor = (f) => tabla?.filtrar(f);
  const revisar = async (boton) => {
    boton.disabled = true;
    try {
      const r = await api('cor_revisar', { metodo: 'POST', datos: { sistema } });
      toast(`Revisión lista: ${miles(r.abiertas)} por corregir · ${miles(r.nuevas)} nuevas · ${miles(r.corregidas)} corregidas${r.no_validadas ? ` · ${miles(r.no_validadas)} no validadas` : ''}.`);
      tabla.refrescar();
    } catch (e) { toast(e.message, true); } finally { boton.disabled = false; }
  };
  const descargar = async (n) => {
    const filtros = tabla.filtros();
    const conFiltros = Object.keys(filtros).length > 0;
    const cuantas = conFiltros ? ' que cumplen los filtros de la tabla' : ` (${miles(n)})`;
    if (!await confirmar(`Descargar archivo${esMed ? '' : 's'} de corrección de ${sistema}`,
      (esMed ? `Se descargan las correcciones pendientes${cuantas} en un Excel con el formato de MEDICAR: PLU y OBSERVACIÓN (una fila por PLU con todo lo que hay que corregir). `
        : `Se descargan las correcciones pendientes${cuantas} en un ZIP: Descripciones Técnicas (ID MUTUAL, Cód. Audifarma, tipo y registro), `
        + 'CREACION_ITEM (grupo impositivo), la lista para corregir a mano (estado, PLU repetidos, CUM que no están) y el detalle. ')
      + `Quedan como «Pendiente de actualización» hasta que se cargue ${esMed ? 'una Maestra MEDICAR nueva' : 'un Maestro SIESA nuevo'}: si ya coinciden pasan a «Corregido».`, 'Descargar')) return;
    try {
      const r = await api('cor_exportar', { metodo: 'POST', datos: { sistema, filtros } });
      window.location.href = `api.php?accion=cor_descargar&id=${r.id}`;
      toast(`${miles(r.filas)} correcciones en ${esMed ? `${miles(r.archivos[0]?.filas)} PLU` : `${r.archivos.length} archivos`}. Queda en el historial de descargas.`);
      tabla.refrescar();
    } catch (e) { toast(e.message, true); }
  };
  const pintarResumen = (rs) => {
    const abiertas = Object.entries(rs.estados).filter(([k]) => !COR_CERRADOS.includes(k)).reduce((a, [, n]) => a + n, 0);
    const rev = rs.revision;
    const desactualizada = rev && Number(rev.carga_id) !== Number(rs.carga_vigente);
    const item = (k) => (rs.estados[k] ? h('button', { type: 'button', class: `nt-res-item r-${COR_ESTADO[k][1] === 'info' ? 'gris' : COR_ESTADO[k][1]}`, title: 'Ver solo estos',
      onclick: () => filtrarPor({ estado: [k] }) }, svgIcono(COR_ESTADO[k][2]), h('strong', {}, miles(rs.estados[k])), h('span', {}, COR_ESTADO[k][0].toLowerCase())) : null);
    const derecha = [
      h('span', { class: `nota cor-revision${desactualizada ? ' cor-desact' : ''}` }, rev ? `Revisado ${fecha(rev.creado_en)} · ${COR_BASE[sistema]} v${rev.version ?? '—'}${desactualizada ? ' (hay una versión más nueva: revise de nuevo)' : ''}` : 'Aún no se ha revisado'),
      gestionar ? h('button', { class: 'btn sec', onclick: (ev) => revisar(ev.currentTarget) }, svgIcono('recargar'), 'Revisar ahora') : null,
      gestionar ? h('button', { class: 'btn sec', onclick: () => descargasCorrecciones(sistema) }, svgIcono('carpeta'), 'Descargas') : null,
      gestionar ? h('button', { class: 'btn primario', disabled: !rs.exportables, onclick: () => descargar(rs.exportables),
        title: rs.exportables ? 'Descarga los archivos de cargue con lo pendiente (respeta los filtros de la tabla)' : 'No hay correcciones pendientes por descargar' },
      svgIcono('bajar'), `${esMed ? 'Archivo PLU + observación' : 'Archivos de cargue'} (${miles(rs.exportables)})`) : null,
    ].filter(Boolean);
    const porCampo = Object.entries(rs.campos).sort((a, b) => b[1] - a[1]);
    const menuCampos = porCampo.length ? h('details', { class: 'menu-exportar nt-reglas-menu' },
      h('summary', { class: 'btn sec nt-reglas-btn' }, svgIcono('lista'), `${porCampo.length} ${porCampo.length === 1 ? 'campo' : 'campos'} con casos`, h('span', { class: 'flecha' }, '▾')),
      h('div', { class: 'menu-lista nt-reglas-lista' },
        h('div', { class: 'nt-reglas-tit' }, 'Clic en un campo para ver sus casos'),
        ...porCampo.map(([k, n]) => h('button', { type: 'button', class: 'menu-opcion nt-regla-op cor-campo-op', onclick: () => filtrarPor({ campo: [k] }) },
          h('span', { class: 'nt-regla-txt' }, campos[k]?.nombre || k), h('span', { class: 'nota' }, esMed ? '' : COR_CARGUE[campos[k]?.cargue] || ''), h('strong', {}, miles(n)))))) : null;
    resumen.replaceChildren(h('div', { class: 'tarjeta nt-resumen' },
      h('button', { type: 'button', class: 'nt-res-total cor-total', title: 'Ver todo lo pendiente', onclick: () => filtrarPor({}) },
        h('span', { class: 'nt-res-ico reglas-ico' }, icono('correcciones')), h('strong', {}, miles(abiertas)), h('span', {}, `por corregir en ${sistema}`)),
      ...['NO_VALIDADO', 'PENDIENTE_REVISION', 'PENDIENTE_ACTUALIZACION', 'CORREGIDO', 'APROBADO'].map(item).filter(Boolean),
      h('span', { class: 'espacio' }), ...derecha,
      menuCampos ? h('div', { class: 'cor-menu-fila' }, menuCampos) : null));
  };
  tabla = tablaPaginada({ contenedor: t, clase: 'tabla-excel tabla-datos tabla-cor', clave: `cor-${sistema.toLowerCase()}`,
    cargar: (p) => api('cor_listar', { params: { sistema, ...p } }), alCargar: (r) => pintarResumen(r.resumen), columnas: [
      { campo: 'estado', titulo: 'Estado', filtro: { campo: 'estado', opciones: Object.entries(COR_ESTADO).map(([k, v]) => [k, v[0]]) },
        valor: (f) => { const e = COR_ESTADO[f.estado] || [f.estado, 'gris', 'pendiente']; return h('span', { class: `chip-estado e-${e[1]}` }, svgIcono(e[2]), e[0]); } },
      { campo: 'plu', titulo: 'PLU', valor: (f) => h('strong', {}, f.plu || '—') },
      esMed ? null : { campo: 'item', titulo: 'Ítem' },
      { campo: 'cum_facturar', titulo: 'CUM FACTURAR' },
      { campo: 'contratos', titulo: 'Contrato' },
      esMed ? { campo: 'subplan', titulo: 'Subplán' } : null,
      { campo: 'campo', titulo: 'Campo', filtro: { campo: 'campo', opciones: Object.entries(campos).map(([k, v]) => [k, v.nombre]) }, valor: (f) => campos[f.campo]?.nombre || f.campo },
      { campo: 'valor_actual', titulo: `Valor en ${sistema}`, clase: () => 'cor-actual' },
      { campo: 'valor_esperado', titulo: 'Valor correcto', clase: () => 'cor-esperado' },
      { campo: 'accion', titulo: esMed ? 'Observación' : 'Acción' },
      esMed ? null : { campo: 'cargue', titulo: 'Se carga con', filtro: { campo: 'cargue', opciones: Object.entries(COR_CARGUE) }, valor: (f) => COR_CARGUE[f.cargue] || f.cargue },
      { campo: 'detectado_en', titulo: 'Detectado', valor: (f) => fecha(f.detectado_en) },
      { campo: 'exportado_en', titulo: 'Descargado', valor: (f) => (f.exportado_en ? fecha(f.exportado_en) : '') },
    ].filter(Boolean) });
  t.onmouseover = (ev) => { const td = ev.target.closest?.('.tabla-datos td'); if (td && !td.title && td.scrollWidth > td.clientWidth) td.title = td.textContent; };
}

/** Botón «Aprobar varios PLU» para la observación NT29 de la Maestra Tarifaria (los PLU salen del texto de la regla). */
function botonAprobarVarios(f, motivo, alGuardar) {
  const plus = /\(([^)]+)\)/.exec(motivo.texto || '')?.[1] || null;
  return h('button', { type: 'button', class: 'btn sec chico btn-aprobar-varios', title: 'Este CUM sí debe tener varios PLU activos: aprobarlo como revisado (NT29 deja de salir)',
    onclick: (ev) => { ev.stopPropagation(); aprobarVariosPlu(f, alGuardar, plus); } }, svgIcono('ok'), 'Aprobar varios PLU');
}

/** PLU nuevo de SIESA «por definir» (NT29): se deja inactivo en la Maestra Tarifaria; Inconsistencias pide luego inactivarlo en SIESA. */
function botonDejarInactivo(f, alGuardar) {
  return h('button', { type: 'button', class: 'btn sec chico btn-aprobar-varios', title: `El PLU ${f.plu} sobra: dejarlo inactivo en la Maestra Tarifaria (NT29 deja de salir)`,
    onclick: async (ev) => {
      ev.stopPropagation();
      if (!await confirmar('Dejar inactivo', `El PLU ${f.plu} queda inactivo en la Maestra Tarifaria para el CUM ${f.cum_facturar}. Inconsistencias SIESA pedirá inactivarlo en SIESA.`, 'Dejar inactivo')) return;
      await api('nt_activar_masivo', { metodo: 'POST', datos: { filas: [`${f.id}|${f.plu}`], activar: false, motivo: 'PLU nuevo de SIESA: se deja inactivo (NT29)' } });
      toast(`PLU ${f.plu} queda inactivo en la Maestra Tarifaria.`); alGuardar();
    } }, svgIcono('quitar'), 'Dejar inactivo');
}

/** Aprueba que un CUM tenga varios PLU activos en SIESA: sus «Más de un PLU con el mismo CUM» pasan a «Aprobado». */
function aprobarVariosPlu(f, alGuardar, plusTxt = null) {
  dialogo({
    titulo: `Aprobar varios PLU del CUM ${f.cum_facturar}`,
    texto: `Use esto solo si el CUM SÍ debe tener varios PLU activos. Si sobra uno, inactívelo en la Maestra Tarifaria en lugar de aprobar `
      + '(Inconsistencias pide luego inactivarlo en SIESA y MEDICAR). '
      + `El CUM queda revisado: sus PLU activos en la Maestra Tarifaria (${plusTxt || 'los de hoy'}) se aceptan y NT29 deja de salir. `
      + 'Si después se activa otro PLU de este CUM, el error vuelve a salir. Queda en auditoría y se puede retirar.',
    campos: [{ nombre: 'motivo', etiqueta: 'Motivo', requerido: true, placeholder: 'Ej.: dos presentaciones del mismo CUM, ambas vigentes' }],
    aceptar: 'Aprobar',
    alEnviar: async (v) => {
      const r = await api('cor_aprobar', { metodo: 'POST', datos: { cum_facturar: f.cum_facturar, motivo: v.motivo } });
      toast(`CUM ${f.cum_facturar} aprobado con ${r.plus.length} PLU (${r.plus.join(', ')}).`);
      alGuardar();
    },
  });
}

/** CUM aprobados con varios PLU (se puede retirar la aprobación). */
async function aprobacionesVariosPlu(gestionar, alCambiar) {
  const r = await api('cor_aprobaciones');
  const dlg = $('#dialogo'); const form = $('#dialogo-form');
  dlg.onclose = null; dlg.oncancel = null;
  const quitar = async (x) => {
    if (!await confirmar('Retirar aprobación', `El CUM ${x.cum_facturar} vuelve a revisarse: si sigue con varios PLU activos en la Maestra Tarifaria, sale otra vez NT29.`, 'Retirar')) return;
    await api('cor_aprobacion_quitar', { metodo: 'POST', datos: { id: x.id } });
    toast('Aprobación retirada.'); dlg.close(); alCambiar();
  };
  const filas = r.aprobaciones.map((x) => h('tr', {}, h('td', {}, x.cum_facturar), h('td', {}, x.plus.split(',').join(', ')), h('td', {}, x.motivo),
    h('td', {}, x.usuario ?? ''), h('td', {}, fecha(x.aprobado_en)),
    h('td', {}, gestionar ? h('button', { type: 'button', class: 'btn sec chico btn-peligro', onclick: () => quitar(x) }, 'Retirar') : '')));
  form.replaceChildren(
    h('div', { class: 'form-encabezado' }, h('h2', {}, 'CUM aprobados con varios PLU'),
      h('button', { type: 'button', class: 'btn-icono cerrar-x', title: 'Cerrar (Esc)', 'aria-label': 'Cerrar', onclick: () => dlg.close() }, '✕')),
    r.aprobaciones.length ? tablaConFiltros(['CUM FACTURAR', 'PLU aprobados', 'Motivo', 'Aprobó', 'Fecha', ''], filas, { sinFiltro: [5], alto: 420 })
      : h('p', { class: 'nota' }, 'Aún no hay CUM aprobados con varios PLU. Se aprueban en la Maestra Tarifaria, en la observación NT29 (botón «Aprobar varios PLU»).'));
  form.onsubmit = () => {};
  dlg.classList.add('ancho');
  const fuera = (ev) => { if (ev.target === dlg) dlg.close(); };
  dlg.addEventListener('click', fuera);
  dlg.addEventListener('close', () => { dlg.removeEventListener('click', fuera); if (!dlg.open) dlg.classList.remove('ancho'); }, { once: true });
  if (!dlg.open) dlg.showModal();
}

/** Descargas de archivos de corrección ya hechas (se pueden volver a bajar). */
async function descargasCorrecciones(sistema) {
  const r = await api('cor_exportaciones', { params: { sistema } });
  const dlg = $('#dialogo'); const form = $('#dialogo-form');
  dlg.onclose = null; dlg.oncancel = null;
  const filas = r.exportaciones.map((x) => h('tr', {},
    h('td', {}, String(x.id)), h('td', {}, fecha(x.creado_en)), h('td', {}, x.usuario ?? ''), h('td', { class: 'num' }, miles(x.filas)),
    h('td', {}, x.version ? `v${x.version}` : ''), h('td', {}, h('span', { class: 'nota' }, x.archivos.map((a) => `${a.nombre} (${miles(a.filas)})`).join(' · '))),
    h('td', {}, h('a', { class: 'btn sec chico', href: `api.php?accion=cor_descargar&id=${x.id}` }, sistema === 'MEDICAR' ? '⬇ Excel' : '⬇ ZIP'))));
  form.replaceChildren(
    h('div', { class: 'form-encabezado' }, h('h2', {}, `Descargas de correcciones ${sistema}`),
      h('button', { type: 'button', class: 'btn-icono cerrar-x', title: 'Cerrar (Esc)', 'aria-label': 'Cerrar', onclick: () => dlg.close() }, '✕')),
    r.exportaciones.length ? tablaConFiltros(['#', 'Fecha', 'Usuario', 'Correcciones', COR_BASE[sistema], 'Archivos', ''], filas, { sinFiltro: [6], alto: 420 })
      : h('p', { class: 'nota' }, 'Aún no se han descargado archivos de corrección.'));
  form.onsubmit = () => {};
  dlg.classList.add('ancho');
  const fuera = (ev) => { if (ev.target === dlg) dlg.close(); };
  dlg.addEventListener('click', fuera);
  dlg.addEventListener('close', () => { dlg.removeEventListener('click', fuera); if (!dlg.open) dlg.classList.remove('ancho'); }, { once: true });
  if (!dlg.open) dlg.showModal();
}

/**
 * Crear / editar un COD GEN (diseño fase 10): encabezado con ✕, secciones numeradas y rejilla como el detalle del producto.
 * POS / NO POS define el subplán de sus líneas EVENTO en la Maestra Tarifaria (EVENTO POS o EVENTO NO PBS).
 */
function editarMolecula(f, sug, alGuardar, pre = {}) {
  const dlg = $('#dialogo'); const form = $('#dialogo-form');
  dlg.onclose = null; dlg.oncancel = null;
  const campo = (nombre, etiqueta, el, ayuda = '') => { if (nombre) el.name = nombre; return h('label', {}, etiqueta, el, ayuda ? h('span', { class: 'nota' }, ayuda) : null); };
  const txt = (v, attrs = {}) => { const e = h('input', { type: 'text', autocomplete: 'off', ...attrs }); e.value = v ?? ''; return e; };
  const sel = (v, ops) => { const e = h('select', {}, ...ops.map((o) => h('option', { value: o }, o))); if (v != null) e.value = v; return e; };
  const ro = (v) => h('input', { value: v ?? '', readonly: true, class: 'solo-lectura', tabindex: -1 });
  const c = {
    cod_gen_audifarma: f ? null : txt(pre.cod_gen_audifarma, { required: true, placeholder: 'ej. 4039M' }),
    descripcion_general: txt(f?.descripcion_general ?? pre.descripcion_general, { required: true }),
    codigo_medicar: f ? txt(f.codigo_medicar, { type: 'number', required: true, min: '1' }) : null,
    principio_activo: txt(f?.principio_activo, { required: true }),
    concentracion_agrupada: txt(f?.concentracion_agrupada, { required: true }),
    forma_farmaceutica: autocompletar(txt(f?.forma_farmaceutica, { required: true }), sug.forma_farmaceutica || []),
    via_administracion: autocompletar(txt(f?.via_administracion, { required: true }), sug.via_administracion || []),
    grupo_farmacologico: autocompletar(txt(f?.grupo_farmacologico, { required: true }), sug.grupo_farmacologico || []),
    pos_no_pos: sel(f?.pos_no_pos, sug.listas.pos_no_pos),
    cadena_frio: sel(f?.cadena_frio, sug.listas.cadena_frio),
    cantidad_tope: txt(f?.cantidad_tope, { type: 'number', min: '0' }),
  };
  const nombreDe = (k) => (c[k]?.matches?.('input, select') ? c[k] : c[k]?.querySelector?.('input')); // autocompletar envuelve el campo
  let activo = f ? Number(f.activo) === 1 : true;
  const estadoTxt = h('span', { class: 'nota' }, activo ? 'Activo' : 'Inactivo: no se usa al crear productos');
  const interruptor = h('button', { type: 'button', class: `interruptor${activo ? ' on' : ''}`, role: 'switch', 'aria-checked': String(activo), 'aria-label': 'Activo',
    onclick: () => { activo = !activo; interruptor.classList.toggle('on', activo); interruptor.setAttribute('aria-checked', String(activo)); estadoTxt.textContent = activo ? 'Activo' : 'Inactivo: no se usa al crear productos'; } }, h('span', {}));
  const seccion = (n, titulo, ...hijos) => h('section', { class: 'seccion-form' }, h('h3', {}, h('span', { class: 'sec-num' }, String(n)), titulo), h('div', { class: 'rejilla-form' }, ...hijos));
  // otros COD GEN con el mismo código Medicar (no se puede repetir)
  const repetido = f ? (sug.medicar_repetidos || []).find((x) => String(x.codigo) === String(f.codigo_medicar)) : null;
  const encabezado = h('div', { class: 'form-encabezado' },
    h('div', {}, h('h2', {}, f ? `Editar COD GEN ${f.cod_gen_audifarma}` : 'Nuevo COD GEN'),
      h('div', { class: 'det-sub' },
        f ? h('span', { class: 'pill-codigo' }, `Cód. Medicar ${f.codigo_medicar}`) : h('span', { class: 'pill-codigo' }, `Cód. Medicar ${sug.siguiente_medicar || '—'} (automático)`),
        f ? h('span', { class: `chip-estado e-${Number(f.activo) ? 'ok' : 'gris'}` }, svgIcono(Number(f.activo) ? 'ok' : 'pendiente'), Number(f.activo) ? 'Activo' : 'Inactivo') : null,
        h('span', { class: 'nota' }, 'Queda en auditoría. Los COD GEN no se eliminan: se inactivan.'))),
    h('button', { type: 'button', class: 'btn-icono cerrar-x', title: 'Cerrar (Esc)', 'aria-label': 'Cerrar', onclick: () => dlg.close() }, '✕'));
  const err = h('p', { class: 'error', role: 'alert' });
  form.replaceChildren(encabezado,
    h('div', { class: 'form-cuerpo' },
      repetido ? h('section', { class: 'seccion-form' }, h('div', { class: 'lista-val' }, h('div', { class: 'val-item v-error' },
        h('div', { class: 'val-cab' }, svgIcono('error'), h('strong', {}, `Código Medicar ${repetido.codigo} repetido`),
          h('span', {}, ` también lo tiene${repetido.cod_gen.length > 2 ? 'n' : ''} el COD GEN ${repetido.cod_gen.filter((x) => String(x) !== String(f.cod_gen_audifarma)).join(', ')}: asigne a cada uno su propio código.`))))) : null,
      seccion(1, 'Identificación',
        f ? campo(null, 'Código genérico Audifarma', ro(f.cod_gen_audifarma), 'No se modifica.') : campo('cod_gen_audifarma', 'Código genérico Audifarma', c.cod_gen_audifarma),
        f ? campo('codigo_medicar', 'Código Medicar (COD_GEN_SIESA)', c.codigo_medicar, 'No se puede repetir en otro COD GEN.')
          : campo(null, 'Código Medicar (COD_GEN_SIESA)', ro(`${sug.siguiente_medicar || '—'} (automático)`), 'Siguiente consecutivo libre.'),
        h('div', { class: 'ancho-triple' }, campo('descripcion_general', 'Descripción genérica', c.descripcion_general))),
      seccion(2, 'Composición',
        campo('principio_activo', 'Principio activo', c.principio_activo), campo('concentracion_agrupada', 'Concentración agrupada', c.concentracion_agrupada),
        campo(null, 'Forma farmacéutica', c.forma_farmaceutica), campo(null, 'Vía de administración', c.via_administracion), campo(null, 'Grupo farmacológico', c.grupo_farmacologico)),
      seccion(3, 'Clasificación y estado',
        h('div', { class: 'ancho-doble' }, campo('pos_no_pos', 'POS / NO POS', c.pos_no_pos, 'Define el subplán de sus líneas EVENTO: EVENTO POS o EVENTO NO PBS.')),
        campo('cadena_frio', 'Cadena de frío', c.cadena_frio), campo('cantidad_tope', 'Cantidad tope', c.cantidad_tope),
        f ? h('div', { class: 'campo-interruptor ancho-total' }, interruptor, h('strong', {}, 'Activo'), estadoTxt) : null)),
    err,
    h('div', { class: 'form-pie' }, h('span', { class: 'espacio' }),
      h('button', { class: 'btn sec', value: 'cancelar', formnovalidate: true }, 'Cancelar'),
      h('button', { class: 'btn primario', value: 'ok' }, svgIcono('ok'), f ? 'Guardar' : 'Crear COD GEN')));
  ['forma_farmaceutica', 'via_administracion', 'grupo_farmacologico'].forEach((k) => { nombreDe(k).name = k; });
  form.onsubmit = async (ev) => {
    if (ev.submitter?.value === 'cancelar') return;
    ev.preventDefault(); err.textContent = '';
    const v = {};
    for (const k of Object.keys(c)) { const e = nombreDe(k); if (e) v[k] = e.value; }
    try {
      const r = await api('molecula_guardar', { metodo: 'POST', datos: { ...v, id: f?.id, activo } });
      dlg.close();
      toast(r.aviso || 'COD GEN guardado. La Maestra Tarifaria se recalcula con el cambio.', !!r.aviso);
      alGuardar();
    } catch (e) { err.textContent = e.message; }
  };
  form.classList.add('form-producto', 'det-producto', 'editar-mol');
  dlg.classList.add('ancho', 'ancho-nt');
  const fuera = (ev) => { if (ev.target === dlg) dlg.close(); };
  dlg.addEventListener('click', fuera);
  dlg.addEventListener('close', () => {
    dlg.removeEventListener('click', fuera);
    if (!dlg.open || !form.contains(encabezado)) form.classList.remove('form-producto', 'det-producto', 'editar-mol');
    if (!dlg.open) dlg.classList.remove('ancho', 'ancho-nt');
  }, { once: true });
  if (!dlg.open) dlg.showModal();
  setTimeout(() => (f ? c.descripcion_general : c.cod_gen_audifarma).focus(), 50);
}

async function pCatalogos(c) {
  const editable = puede('CATALOGO.EDITAR');
  const sug = editable ? await api('catalogo_sugerencias') : null;
  let listas = (await api('listas')).listas;
  const sel = h('select');
  const armarSelector = (valor) => {
    sel.replaceChildren(
      ...CATS.map(([k, t]) => h('option', { value: k }, t)),
      ...listas.map((l) => h('option', { value: `lista:${l.codigo}` }, l.nombre)));
    if (valor) sel.value = valor;
  };
  armarSelector();
  const nuevoCatalogo = editable ? h('button', { class: 'btn sec', onclick: () => dialogo({
    titulo: 'Nuevo catálogo',
    texto: 'Luego de crearlo, agregue sus valores.',
    campos: [{ nombre: 'nombre', etiqueta: 'Nombre del catálogo', requerido: true, placeholder: 'ej. Tipo de almacenamiento' },
             { nombre: 'descripcion', etiqueta: 'Descripción / para qué se usa' }],
    alEnviar: async (v) => {
      const r = await api('lista_crear', { metodo: 'POST', datos: v });
      listas = (await api('listas')).listas;
      armarSelector(`lista:${r.lista.codigo}`); pintar();
      toast(`Catálogo "${r.lista.nombre}" creado. Agregue sus valores.`);
    },
  }) }, '+ Nuevo catálogo') : null;
  const zona = h('div');
  const tarjeta = h('div', { class: 'tarjeta' }, h('div', { class: 'fila-acciones', style: 'margin-bottom:12px;align-items:flex-end' },
    h('label', {}, 'Catálogo', sel), nuevoCatalogo), zona);
  c.append(tarjeta);
  const pintar = () => {
    zona.replaceChildren();
    if (sel.value.startsWith('lista:')) {
      const lista = listas.find((l) => `lista:${l.codigo}` === sel.value);
      const t = pintarLista(zona, lista, editable, async () => {
        listas = (await api('listas')).listas;
        const actual = listas.find((l) => l.codigo === lista.codigo);
        Object.assign(lista, actual);
        zona.querySelector('.info-caja .nota:last-child').textContent = `${actual.activos} valores activos de ${actual.total}.`;
        t.refrescar();
      });
      return;
    }
    const [k, , cols, cfg = {}] = CATS.find(([x]) => x === sel.value);
    const columnas = cols.map(([campo, titulo]) => ({
      campo, titulo,
      valor: ['activo', 'habilitado'].includes(campo) ? (f) => (Number(f[campo]) ? chip('Activo', 'ok') : chip('Inactivo', 'gris')) : null,
    }));
    if (editable && EDITORES[k]) {
      const refrescarSug = async () => { Object.assign(sug, await api('catalogo_sugerencias')); t.refrescar(); };
      if (EXCEL_CATALOGOS.includes(k)) zona.append(panelExcelCatalogo(k, CATS.find(([x]) => x === k)[1], refrescarSug));
      if (k === 'molecula' && sug.medicar_repetidos?.length) {
        // cada COD GEN abre su ficha para corregirlo; «Ver en la tabla» filtra todos los repetidos
        const abrir = async (cg) => {
          const r = await api('catalogo', { params: { tipo: 'molecula', pagina: 1, filtros: JSON.stringify({ cod_gen_audifarma: [String(cg)] }) } });
          if (r.filas[0]) EDITORES.molecula(r.filas[0], sug, refrescarSug); else toast(`No se encontró el COD GEN ${cg}.`, true);
        };
        const todos = sug.medicar_repetidos.flatMap((x) => x.cod_gen.map(String));
        // compacta: una línea; el detalle (código → COD GEN) en un desplegable como «reglas con casos»
        zona.append(h('div', { class: 'med-repetidos' }, svgIcono('error'),
          h('strong', {}, `${sug.medicar_repetidos.length} códigos Medicar repetidos`), h('span', { class: 'nota' }, 'no se pueden repetir: asigne a cada COD GEN su propio código'),
          h('span', { class: 'espacio' }),
          h('details', { class: 'menu-exportar nt-reglas-menu' },
            h('summary', { class: 'btn sec nt-reglas-btn' }, svgIcono('lista'), 'Ver códigos', h('span', { class: 'flecha' }, '▾')),
            h('div', { class: 'menu-lista nt-reglas-lista med-rep-lista' },
              h('div', { class: 'nt-reglas-tit' }, 'Clic en un COD GEN para abrirlo y corregir su código'),
              ...sug.medicar_repetidos.map((x) => h('div', { class: 'med-rep' }, h('span', { class: 'med-rep-cod' }, x.codigo),
                ...x.cod_gen.map((cg) => h('button', { type: 'button', class: 'menu-opcion med-rep-cg', title: `Abrir el COD GEN ${cg}`, onclick: () => abrir(cg) }, svgIcono('editar'), String(cg))))))),
          h('button', { type: 'button', class: 'btn sec nt-reglas-btn', onclick: () => t.filtrar({ cod_gen_audifarma: todos }) }, svgIcono('buscar'), `Ver los ${todos.length} en la tabla`)));
      }
      columnas.push({ titulo: 'Acciones', filtro: false, valor: (f) => h('button', { type: 'button', class: 'btn-icono', title: 'Editar', 'aria-label': 'Editar', onclick: () => EDITORES[k](f, sug, refrescarSug) }, svgIcono('editar')) });
      zona.append(h('div', { class: 'cat-nuevo' },
        h('button', { class: 'btn primario', onclick: () => EDITORES[k](null, sug, refrescarSug) }, svgIcono('mas'), 'Nuevo'),
        h('span', { class: 'nota' }, svgIcono('pendiente'), 'Solo el Administrador crea y edita. Los cambios quedan en auditoría; los registros se desactivan, no se eliminan.')));
    }
    // tabla tipo Excel: letra pequeña, una línea por fila; el texto largo se ve completo al pasar el mouse
    const t = tablaPaginada({ contenedor: zona, columnas, clase: 'tabla-excel tabla-datos tabla-cat', cargar: (p) =>
      api('catalogo', { params: { tipo: cfg.tipo || k, ...(cfg.params || {}), ...p } }) });
    zona.onmouseover = (ev) => { const td = ev.target.closest?.('.tabla-datos td'); if (td && !td.title && td.scrollWidth > td.clientWidth) td.title = td.textContent; };
  };
  sel.addEventListener('change', pintar);
  pintar();
}

async function pReglas(c) {
  // Diseño fase 10: resumen (total · error · alerta · inactivas) con filtro por ámbito y tabla tipo Excel con los colores del logo
  const todas = [];
  for (let p = 1; ; p++) { const r = await api('catalogo', { params: { tipo: 'regla', pagina: p, tam: 100 } }); todas.push(...r.filas); if (todas.length >= r.total || !r.filas.length) break; }
  const ambito = (cod) => (String(cod).startsWith('NT') ? 'Maestra Tarifaria' : 'Crear productos');
  // Administrador: crear reglas personalizadas (ámbito + campo + condición + valor) y activar / inactivar cualquier regla
  const admin = puede('PARAMETRO.ADMINISTRAR');
  const cfg = admin ? await api('reglas_config') : null;
  const recargar = () => { c.replaceChildren(); pReglas(c); };
  const editarRegla = (r) => {
    // Diseño fase 10: encabezado con ✕, secciones numeradas; ámbito y campo en dos listas (antes una sola lista larga)
    const dlg = $('#dialogo'); const form = $('#dialogo-form');
    dlg.onclose = null; dlg.oncancel = null;
    const AMB = { PRODUCTO: 'Crear productos', NT: 'Maestra Tarifaria' };
    const campo = (etiqueta, el, ayuda = '') => h('label', {}, etiqueta, el, ayuda ? h('span', { class: 'nota' }, ayuda) : null);
    const sel = (ops, v) => { const e = h('select', {}, ...ops.map(([a, b]) => h('option', { value: a }, b))); e.value = v ?? ''; return e; };
    const txt = (v, attrs = {}) => { const e = h('input', { type: 'text', autocomplete: 'off', ...attrs }); e.value = v ?? ''; return e; };
    const ambito = sel([['', '— Seleccione —'], ...Object.entries(AMB)], r?.ambito || '');
    if (r) ambito.disabled = true; // el ámbito de una regla no se cambia
    const campoSel = h('select', {});
    const pintarCampos = (v) => {
      const cs = Object.entries(cfg.campos[ambito.value] || {}).sort((a, b) => a[1].localeCompare(b[1], 'es'));
      campoSel.replaceChildren(h('option', { value: '' }, ambito.value ? '— Seleccione —' : 'Elija primero el ámbito'), ...cs.map(([k, t]) => h('option', { value: k }, t)));
      campoSel.value = v ?? ''; campoSel.disabled = !ambito.value;
    };
    ambito.onchange = () => pintarCampos('');
    pintarCampos(r?.campo || '');
    const condicion = sel([['', '— Seleccione —'], ...Object.entries(cfg.condiciones).map(([k, x]) => [k, x.nombre])], r?.condicion || '');
    const valor = txt(r?.valor, { placeholder: 'Texto, número, valores separados por ; o expresión regular' });
    const ayudaValor = h('span', { class: 'nota' });
    const pintarValor = () => {
      const pide = cfg.condiciones[condicion.value]?.valor !== false;
      valor.disabled = !pide; if (!pide) valor.value = '';
      ayudaValor.textContent = !condicion.value ? 'Según la condición.' : pide ? 'Obligatorio para esta condición.' : 'Esta condición no usa valor.';
    };
    condicion.onchange = pintarValor; pintarValor();
    const severidad = sel([['ERROR', 'Error (bloquea / marca la línea)'], ['ALERTA', 'Alerta (requiere revisión)']], r?.severidad || 'ALERTA');
    const descripcion = txt(String(r?.descripcion || '').replace(/^Maestra Tarifaria: /, ''), { placeholder: 'Ej.: El ATC no puede estar vacío' });
    const accion = txt(r?.accion_sugerida, { placeholder: 'Ej.: Completar el ATC en SIESA (MED-CODIGO ATC)' });
    const seccion = (n, titulo, ...hijos) => h('section', { class: 'seccion-form' }, h('h3', {}, h('span', { class: 'sec-num' }, String(n)), titulo), h('div', { class: 'rejilla-form' }, ...hijos));
    const encabezado = h('div', { class: 'form-encabezado' },
      h('div', {}, h('h2', {}, r ? `Editar regla ${r.codigo}` : 'Nueva regla de validación'),
        h('div', { class: 'det-sub' }, r ? h('span', { class: 'pill-codigo' }, r.codigo) : null,
          h('span', { class: 'nota' }, 'Se aplica en cada validación. Si el campo no cumple la condición, sale con la severidad elegida.'))),
      h('button', { type: 'button', class: 'btn-icono cerrar-x', title: 'Cerrar (Esc)', 'aria-label': 'Cerrar', onclick: () => dlg.close() }, '✕'));
    const err = h('p', { class: 'error', role: 'alert' });
    form.replaceChildren(encabezado,
      h('div', { class: 'form-cuerpo' },
        seccion(1, 'Qué se valida', campo('Ámbito', ambito, r ? 'No se cambia: cree otra regla para el otro ámbito.' : 'Dónde se aplica la regla.'), h('div', { class: 'ancho-doble' }, campo('Campo', campoSel))),
        seccion(2, 'Condición', campo('Condición', condicion), h('div', { class: 'ancho-doble' }, h('label', {}, 'Valor', valor, ayudaValor))),
        seccion(3, 'Resultado', h('div', { class: 'ancho-doble' }, campo('Severidad', severidad)), h('div', { class: 'ancho-triple' }, campo('Qué valida (nombre de la regla)', descripcion)),
          h('div', { class: 'ancho-total' }, campo('Cómo corregirlo', accion, 'Se muestra junto al error en la línea o en el producto.')))),
      err,
      h('div', { class: 'form-pie' }, h('span', { class: 'espacio' }),
        h('button', { class: 'btn sec', value: 'cancelar', formnovalidate: true }, 'Cancelar'),
        h('button', { class: 'btn primario', value: 'ok' }, svgIcono('ok'), r ? 'Guardar' : 'Crear regla')));
    form.onsubmit = async (ev) => {
      if (ev.submitter?.value === 'cancelar') return;
      ev.preventDefault(); err.textContent = '';
      if (!ambito.value || !campoSel.value) { err.textContent = 'Elija el ámbito y el campo.'; return; }
      if (!condicion.value) { err.textContent = 'Elija la condición.'; return; }
      if (!valor.disabled && !valor.value.trim()) { err.textContent = 'Escriba el valor de la condición.'; return; }
      if (!descripcion.value.trim()) { err.textContent = 'Escriba qué valida la regla.'; return; }
      try {
        const x = await api('regla_guardar', { metodo: 'POST', datos: { codigo: r?.codigo || '', ambito: ambito.value, campo: campoSel.value, condicion: condicion.value,
          valor: valor.value, severidad: severidad.value, descripcion: descripcion.value, accion_sugerida: accion.value } });
        dlg.close();
        toast(`Regla ${x.regla.codigo} guardada. Se aplica desde la próxima validación (Revalidar en Crear productos; la Maestra Tarifaria se recalcula sola).`); recargar();
      } catch (e) { err.textContent = e.message; }
    };
    form.classList.add('form-producto', 'det-producto', 'editar-mol');
    dlg.classList.add('ancho');
    const fuera = (ev) => { if (ev.target === dlg) dlg.close(); };
    dlg.addEventListener('click', fuera);
    dlg.addEventListener('close', () => {
      dlg.removeEventListener('click', fuera);
      if (!dlg.open || !form.contains(encabezado)) form.classList.remove('form-producto', 'det-producto', 'editar-mol');
      if (!dlg.open) dlg.classList.remove('ancho');
    }, { once: true });
    if (!dlg.open) dlg.showModal();
    setTimeout(() => (r ? condicion : ambito).focus(), 50);
  };
  const activar = async (r) => {
    const act = !Number(r.activa);
    const texto = act ? `La regla ${r.codigo} vuelve a aplicarse en todas las validaciones.`
      : `La regla ${r.codigo} deja de aplicarse${r.severidad === 'ERROR' ? ': lo que hoy bloquea podrá crearse sin esta validación' : ''}. Queda en auditoría y se puede volver a activar.`;
    if (!await confirmar(act ? `Activar ${r.codigo}` : `Inactivar ${r.codigo}`, texto, act ? 'Activar' : 'Inactivar')) return;
    await api('regla_activar', { metodo: 'POST', datos: { codigo: r.codigo, activa: act } });
    toast(act ? `Regla ${r.codigo} activa.` : `Regla ${r.codigo} inactiva.`); recargar();
  };
  const n = (fn) => todas.filter(fn).length;
  let tabla = null;
  const item = (ico, cant, texto, k, filtros) => h('button', { type: 'button', class: `nt-res-item r-${k}`, title: 'Ver solo estas reglas', onclick: () => { marcar(null); tabla?.filtrar(filtros); } },
    svgIcono(ico), h('strong', {}, miles(cant)), h('span', {}, texto));
  const ambitos = ['Crear productos', 'Maestra Tarifaria'].map((a) => h('button', { type: 'button', class: 'chip-periodo', dataset: { a }, onclick: (ev) => {
    const activo = ev.currentTarget.classList.contains('activo');
    marcar(activo ? null : a);
    tabla?.filtrar(activo ? {} : { codigo: todas.filter((r) => ambito(r.codigo) === a).map((r) => r.codigo) });
  } }, `${a} (${n((r) => ambito(r.codigo) === a)})`));
  const marcar = (a) => ambitos.forEach((b) => b.classList.toggle('activo', b.dataset.a === a));
  c.append(h('div', { class: 'tarjeta nt-resumen' },
    h('div', { class: 'nt-res-total' }, h('span', { class: 'nt-res-ico reglas-ico' }, icono('reglas')), h('strong', {}, miles(todas.length)), h('span', {}, 'reglas')),
    item('error', n((r) => r.severidad === 'ERROR' && Number(r.activa)), 'de error', 'error', { severidad: ['ERROR'], activa: ['1'] }),
    item('alerta', n((r) => r.severidad === 'ALERTA' && Number(r.activa)), 'de alerta', 'alerta', { severidad: ['ALERTA'], activa: ['1'] }),
    n((r) => !Number(r.activa)) ? item('pendiente', n((r) => !Number(r.activa)), 'inactivas', 'gris', { activa: ['0'] }) : '',
    h('span', { class: 'espacio' }), h('span', { class: 'cambios-etq' }, 'Ámbito'), h('div', { class: 'periodo-atajos' }, ...ambitos),
    admin ? h('button', { class: 'btn primario', style: 'margin-left:12px', onclick: () => editarRegla(null) }, svgIcono('mas'), 'Nueva regla') : ''));
  const t = h('div', { class: 'tarjeta' });
  c.append(t);
  tabla = tablaPaginada({ contenedor: t, clase: 'tabla-excel tabla-datos tabla-reglas', clave: 'reglas-v2',
    cargar: (p) => api('catalogo', { params: { tipo: 'regla', ...p, tam: 100 } }), columnas: [
      { campo: 'codigo', titulo: 'Código', valor: (f) => h('strong', { class: 'regla-cod' }, f.codigo) },
      { titulo: 'Ámbito', filtro: false, valor: (f) => h('span', { class: `ambito ${ambito(f.codigo) === 'Maestra Tarifaria' ? 'a-nt' : 'a-cp'}` }, ambito(f.codigo)) },
      { campo: 'severidad', titulo: 'Severidad', valor: (f) => chip(f.severidad === 'ERROR' ? 'Error' : 'Alerta', f.severidad === 'ERROR' ? 'error' : 'alerta'), filtro: { campo: 'severidad', opciones: [['ERROR', 'Error'], ['ALERTA', 'Alerta']] } },
      { campo: 'descripcion', titulo: 'Regla', valor: (f) => String(f.descripcion || '').replace(/^Maestra Tarifaria: /, '') },
      { campo: 'base_comparada', titulo: 'Base comparada' },
      { campo: 'accion_sugerida', titulo: 'Acción sugerida' },
      { campo: 'personalizada', titulo: 'Tipo', filtro: { campo: 'personalizada', opciones: [['0', 'Del sistema'], ['1', 'Personalizada']] },
        valor: (f) => (Number(f.personalizada) ? chip('Personalizada', 'info') : h('span', { class: 'nota' }, 'Del sistema')) },
      { campo: 'activa', titulo: 'Estado', filtro: 'estado', valor: (f) => (Number(f.activa) ? chip('Activa', 'ok') : chip('Inactiva', 'gris')), clase: (f) => (Number(f.activa) ? '' : 'regla-inactiva') },
      admin ? { titulo: 'Acciones', filtro: false, valor: (f) => h('div', { class: 'acciones-fila' },
        h('button', { type: 'button', class: `interruptor${Number(f.activa) ? ' on' : ''}`, role: 'switch', 'aria-checked': String(!!Number(f.activa)),
          title: Number(f.activa) ? 'Activa: clic para inactivar' : 'Inactiva: clic para activar', onclick: () => activar(f) }, h('span', {})),
        Number(f.personalizada) ? h('button', { type: 'button', class: 'btn-icono', title: 'Editar', 'aria-label': 'Editar', onclick: () => editarRegla(f) }, svgIcono('editar')) : null) } : null,
    ].filter(Boolean) });
  t.onmouseover = (ev) => { const td = ev.target.closest?.('.tabla-datos td'); if (td && !td.title && td.scrollWidth > td.clientWidth) td.title = td.textContent; };
}

/** Tarjeta de resumen de una pantalla (diseño fase 10): ícono del menú, total, indicadores y acciones a la derecha. */
function resumenPantalla(icoMenu, total, etiqueta, items = [], derecha = []) {
  return h('div', { class: 'tarjeta nt-resumen' },
    h('div', { class: 'nt-res-total' }, h('span', { class: 'nt-res-ico reglas-ico' }, icono(icoMenu)), h('strong', {}, miles(total)), h('span', {}, etiqueta)),
    ...items.filter(Boolean).map(([ico, n, texto, k, fn]) => h(fn ? 'button' : 'span', { type: fn ? 'button' : null, class: `nt-res-item r-${k}${fn ? '' : ' fijo'}`, onclick: fn || null },
      svgIcono(ico), h('strong', {}, miles(n)), h('span', {}, texto))),
    h('span', { class: 'espacio' }), ...derecha);
}

async function pUsuarios(c) {
  const r = await api('usuarios');
  const nombrePerfil = Object.fromEntries(r.perfiles.map((p) => [p.codigo, p.nombre]));
  const opcionesPerfil = r.perfiles.filter((p) => Number(p.activo)).map((p) => [p.codigo, p.nombre]);
  const editar = (u) => dialogo({
    titulo: u ? `Editar usuario: ${u.usuario}` : 'Nuevo usuario',
    campos: [
      u ? null : { nombre: 'usuario', etiqueta: 'Usuario (para ingresar)', requerido: true, ayuda: 'Letras, números y . _ - @' },
      { nombre: 'nombre', etiqueta: 'Nombre completo', valor: u?.nombre, requerido: true },
      { nombre: 'correo', etiqueta: 'Correo', tipo: 'email', valor: u?.correo },
      { nombre: 'perfiles', etiqueta: 'Perfiles', tipo: 'checks', opciones: opcionesPerfil, valor: u?.perfiles || [] },
      { nombre: 'activo', etiqueta: 'Activo', tipo: 'checkbox', valor: u ? Number(u.activo) : 1 },
      u ? null : { nombre: 'clave_temporal', etiqueta: 'Contraseña temporal', tipo: 'password', requerido: true, ayuda: 'El usuario deberá cambiarla en su primer ingreso.' },
    ].filter(Boolean),
    alEnviar: async (v) => { await api('usuario_guardar', { metodo: 'POST', datos: { ...v, id: u?.id } }); toast('Usuario guardado.'); navegar(); },
  });
  const restablecer = (u) => dialogo({
    titulo: `Restablecer contraseña: ${u.usuario}`, aceptar: 'Restablecer',
    campos: [{ nombre: 'clave_temporal', etiqueta: 'Nueva contraseña temporal', tipo: 'password', requerido: true, ayuda: 'Se pedirá cambiarla en el próximo ingreso. Comuníquela por un canal seguro.' }],
    alEnviar: async (v) => { await api('usuario_restablecer_clave', { metodo: 'POST', datos: { id: u.id, ...v } }); toast('Contraseña restablecida.'); },
  });
  let tabla = null;
  const activos = r.usuarios.filter((u) => Number(u.activo)).length;
  const temporales = r.usuarios.filter((u) => Number(u.debe_cambiar_clave)).length;
  c.append(resumenPantalla('usuarios', r.usuarios.length, 'usuarios', [
    ['ok', activos, 'activos', 'ok', () => tabla?.filtrar(4, ['Activo'])],
    r.usuarios.length - activos ? ['pendiente', r.usuarios.length - activos, 'inactivos', 'gris', () => tabla?.filtrar(4, ['Inactivo'])] : null,
    temporales ? ['llave', temporales, 'con clave temporal', 'alerta', null] : null,
  ], [h('button', { class: 'btn primario', onclick: () => editar(null) }, svgIcono('mas'), 'Nuevo usuario')]));
  const filas = r.usuarios.map((u) => h('tr', {},
    h('td', {}, h('strong', { class: 'regla-cod' }, u.usuario)), h('td', {}, u.nombre), h('td', {}, u.correo || '—'),
    h('td', {}, ...u.perfiles.map((p) => chip(nombrePerfil[p] || p, 'info'))),
    h('td', {}, Number(u.activo) ? chip('Activo', 'ok') : chip('Inactivo', 'gris')),
    h('td', {}, Number(u.debe_cambiar_clave) ? chip('Temporal', 'alerta') : 'Definida'),
    h('td', {}, fecha(u.ultimo_acceso)),
    h('td', {}, h('div', { class: 'acciones-fila' },
      h('button', { type: 'button', class: 'btn-icono', title: 'Editar', 'aria-label': 'Editar', onclick: () => editar(u) }, svgIcono('editar')),
      h('button', { type: 'button', class: 'btn-icono', title: 'Restablecer contraseña', 'aria-label': 'Restablecer contraseña', onclick: () => restablecer(u) }, svgIcono('llave'))))));
  tabla = tablaConFiltros(['Usuario', 'Nombre', 'Correo', 'Perfiles', 'Estado', 'Contraseña', 'Último acceso', 'Acciones'], filas, { sinFiltro: [7], clave: 'usuarios-v2' });
  c.append(h('div', { class: 'tarjeta historial-excel marca' }, tabla));
  ajustarAltoTablas();
}

async function pPerfiles(c) {
  const r = await api('perfiles');
  const asignado = new Set(r.asignados.map((a) => `${a.perfil_id}|${a.codigo}`));
  const tbody = h('tbody');
  let modulo = null;
  // matriz permiso × perfil, tipo Excel: banda por módulo, primera columna fija, casillas centradas
  for (const p of r.permisos) {
    if (p.modulo !== modulo) {
      modulo = p.modulo;
      tbody.append(h('tr', { class: 'fila-modulo' }, h('td', { colspan: r.perfiles.length + 1 }, r.modulos.find((m) => m.codigo === modulo)?.nombre || modulo)));
    }
    tbody.append(h('tr', {}, h('td', { title: p.codigo }, p.nombre, h('span', { class: 'permiso-cod' }, p.codigo)),
      ...r.perfiles.map((pf) => {
        const exclusivo = pf.codigo !== 'ADMIN' && r.solo_admin.includes(p.codigo);
        return h('td', { title: exclusivo ? 'Exclusivo del Administrador' : null }, h('input', { type: 'checkbox', dataset: { perfil: pf.id, permiso: p.codigo },
          checked: asignado.has(`${pf.id}|${p.codigo}`), disabled: exclusivo, 'aria-label': `${pf.nombre}: ${p.nombre}` }));
      })));
  }
  const guardar = async (pf) => {
    const permisos = [...document.querySelectorAll(`input[data-perfil="${pf.id}"]:checked`)].map((i) => i.dataset.permiso);
    try { await api('perfil_permisos_guardar', { metodo: 'POST', datos: { perfil_id: pf.id, permisos } }); toast(`Permisos de "${pf.nombre}" guardados.`); } catch (e) { toast(e.message, true); }
  };
  c.append(resumenPantalla('perfiles', r.perfiles.length, 'perfiles', [
    ['lista', r.permisos.length, 'permisos', 'gris', null], ['capas', r.modulos.length, 'módulos', 'gris', null]]));
  c.append(h('div', { class: 'tarjeta' },
    h('div', { class: 'tabla-caja' }, h('table', { class: 'matriz tabla-excel marca-matriz' },
      h('thead', {}, h('tr', {}, h('th', {}, 'Permiso'), ...r.perfiles.map((pf) => h('th', {}, pf.nombre)))),
      tbody,
      h('tfoot', {}, h('tr', {}, h('td', {}, 'Guardar cambios del perfil'), ...r.perfiles.map((pf) => h('td', {}, h('button', { class: 'btn primario chico', onclick: () => guardar(pf) }, 'Guardar')))))))));
  ajustarAltoTablas();
}

async function pParametros(c) {
  const r = await api('parametros');
  const filas = r.parametros.map((p) => h('tr', {},
    h('td', {}, h('strong', { class: 'regla-cod' }, p.clave)), h('td', { title: p.descripcion || '' }, p.descripcion || ''),
    h('td', { class: 'param-valor', title: p.valor ?? '' }, p.valor), h('td', {}, fecha(p.actualizado_en)), h('td', {}, p.actualizado_por || '—'),
    h('td', {}, h('button', { type: 'button', class: 'btn-icono', title: 'Editar', 'aria-label': 'Editar', onclick: () => dialogo({
      titulo: `Editar ${p.clave}`, texto: p.descripcion,
      campos: [{ nombre: 'valor', etiqueta: 'Valor', valor: p.valor, requerido: true }],
      alEnviar: async (v) => { await api('parametro_guardar', { metodo: 'POST', datos: { clave: p.clave, valor: v.valor } }); toast('Parámetro actualizado.'); navegar(); },
    }) }, svgIcono('editar')))));
  c.append(resumenPantalla('parametros', r.parametros.length, 'parámetros'));
  c.append(h('div', { class: 'tarjeta historial-excel marca' }, tablaConFiltros(['Parámetro', 'Descripción', 'Valor', 'Actualizado', 'Por', 'Acciones'], filas, { sinFiltro: [5], clave: 'parametros-v2' })));
  ajustarAltoTablas();
}

function pAuditoria(c) {
  let tabla = null;
  // Exportar lo filtrado a Excel, con los cambios campo por campo (antes / después)
  c.append(h('div', { class: 'tarjeta nt-resumen' },
    h('div', { class: 'nt-res-total' }, h('span', { class: 'nt-res-ico reglas-ico' }, icono('auditoria')), h('span', { class: 'nota' }, 'Filtre en el encabezado de la tabla y exporte: cada cambio sale campo por campo (antes / después).')),
    h('span', { class: 'espacio' }),
    h('button', { class: 'btn sec', onclick: () => { window.location.href = `api.php?${new URLSearchParams({ accion: 'auditoria_exportar', filtros: JSON.stringify(tabla?.filtros() || {}) })}`; } }, svgIcono('bajar'), 'Exportar a Excel')));
  const t = h('div', { class: 'tarjeta' });
  c.append(t);
  const resumen = (v) => { if (!v) return ''; const s = String(v); return s.length > 160 ? s.slice(0, 160) + '…' : s; };
  tabla = tablaPaginada({ contenedor: t, clase: 'tabla-excel tabla-datos tabla-reglas', cargar: (p) => api('auditoria', { params: p }), columnas: [
    { titulo: 'Fecha', valor: (f) => fecha(f.creado_en), filtro: { campo: 'creado_en' } }, { campo: 'usuario', titulo: 'Usuario' },
    { campo: 'accion', titulo: 'Acción', valor: (f) => h('strong', { class: 'regla-cod' }, f.accion) },
    { titulo: 'Entidad', valor: (f) => `${f.entidad || ''} ${f.entidad_id || ''}`, filtro: { campo: 'entidad' } },
    { titulo: 'Antes', valor: (f) => resumen(f.antes), filtro: { campo: 'antes' } }, { titulo: 'Después', valor: (f) => resumen(f.despues), filtro: { campo: 'despues' } }, { campo: 'ip', titulo: 'IP' },
  ] });
  t.onmouseover = (ev) => { const td = ev.target.closest?.('.tabla-datos td'); if (td && !td.title && td.scrollWidth > td.clientWidth) td.title = td.textContent; };
}

// ------------------------------------------------------------------ sesión
function mostrarLogin() {
  estado.usuario = null;
  $('#vista-app').hidden = true;
  $('#vista-login').hidden = false;
  $('#login-modo').textContent = estado.modoPrueba ? 'Modo prueba local: datos en SQLite, no en el servidor.' : '';
}
function mostrarApp() {
  $('#vista-login').hidden = true;
  $('#vista-app').hidden = false;
  $('#usuario-nombre').textContent = estado.usuario.nombre;
  $('#usuario-perfiles').textContent = estado.perfiles.join(' · ');
  $('#chip-prueba').hidden = !estado.modoPrueba;
  construirMenu();
  navegar();
}

async function iniciar() {
  const s = await api('sesion');
  Object.assign(estado, { csrf: s.csrf, usuario: s.usuario, permisos: s.permisos, perfiles: s.perfiles, modoPrueba: s.modo_prueba });
  $('#version').textContent = `v${s.version}`;
  s.usuario ? mostrarApp() : mostrarLogin();
}

// Ingreso: botón del ojo para mostrar u ocultar la contraseña
$('#ver-clave').addEventListener('click', () => {
  const c = $('#login-clave'); const ver = c.type === 'password';
  c.type = ver ? 'text' : 'password';
  $('#ver-clave').setAttribute('aria-label', ver ? 'Ocultar contraseña' : 'Mostrar contraseña');
  $('#ver-clave').title = ver ? 'Ocultar contraseña' : 'Mostrar contraseña';
});
$('#form-login').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const fd = new FormData(ev.target);
  $('#login-error').textContent = '';
  try {
    const r = await api('login', { metodo: 'POST', datos: { usuario: fd.get('usuario'), clave: fd.get('clave') } });
    Object.assign(estado, { csrf: r.csrf, usuario: r.usuario, permisos: r.permisos, perfiles: r.perfiles });
    ev.target.reset();
    mostrarApp();
  } catch (e) { $('#login-error').textContent = e.message; }
});
$('#btn-salir').addEventListener('click', async () => { olvidarFiltros(); try { const r = await api('logout', { metodo: 'POST' }); estado.csrf = r.csrf; } finally { location.hash = ''; iniciar(); } });
$('#btn-clave').addEventListener('click', () => dialogoClave(false));
$('#btn-menu').addEventListener('click', () => $('#menu').classList.toggle('abierto'));
window.addEventListener('hashchange', navegar);
iniciar().catch((e) => { document.body.prepend(h('p', { class: 'error', style: 'padding:16px' }, `No se pudo iniciar: ${e.message}`)); });

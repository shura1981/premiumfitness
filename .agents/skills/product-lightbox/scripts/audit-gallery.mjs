#!/usr/bin/env node
// Audita el contrato HTML ↔ JS ↔ CSS de la galería/lightbox de producto.
// Agnóstico del backend: el "html" puede ser cualquier plantilla (.html, .twig, .blade.php, .ejs, .vue, .jsx…).
//
// Uso:
//   node audit-gallery.mjs --html <plantilla> --js <product-gallery.js> [--css <hoja.css>] [--page <pagina-que-carga-scripts>]
// Salida: 0 sin errores, 1 con errores (los avisos no fallan).
import { readFileSync, existsSync } from 'node:fs';
import { parseArgs } from 'node:util';

const { values } = parseArgs({
  options: {
    html: { type: 'string' },
    js: { type: 'string' },
    css: { type: 'string' },
    page: { type: 'string' },
  },
});

if (!values.html || !values.js) {
  console.error('Uso: node audit-gallery.mjs --html <plantilla> --js <script.js> [--css <hoja.css>] [--page <pagina>]');
  process.exit(2);
}

const read = (path) => {
  if (!existsSync(path)) {
    console.error(`No existe: ${path}`);
    process.exit(2);
  }
  return readFileSync(path, 'utf8');
};

// Se ignoran comentarios HTML y de plantilla ({# #}, <%# %>) para no analizar texto de ayuda.
const html = read(values.html).replace(/<!--[\s\S]*?-->|\{#[\s\S]*?#\}|<%#[\s\S]*?%>/g, '');
const js = read(values.js);
const css = values.css ? read(values.css) : null;
const page = values.page ? read(values.page) : null;

const errors = [];
const warnings = [];
const unique = (list) => [...new Set(list)];
const matches = (text, regex) => [...text.matchAll(regex)].map((m) => m[1] ?? m[0]);

// 1. Atributos data-* : los que busca el JS vs. los que declara el HTML.
const attrRe = /data-(?:product-gallery|gallery-[a-z-]+)/g;
const jsAttrs = unique(matches(js, /\[(data-(?:product-gallery|gallery-[a-z-]+))[\]:=]/g));
const htmlAttrs = unique(matches(html, new RegExp(`\\b(${attrRe.source})`, 'g')));

for (const attr of jsAttrs) {
  if (!htmlAttrs.includes(attr)) errors.push(`El JS busca [${attr}] pero la plantilla no lo declara`);
}
for (const attr of htmlAttrs) {
  if (!jsAttrs.includes(attr) && attr !== 'data-gallery-modal-image') {
    warnings.push(`La plantilla declara ${attr} que el JS no usa (¿código muerto?)`);
  }
}

// 2. Elementos requeridos por initProductGallery().
for (const required of ['data-product-gallery', 'data-gallery-main', 'data-gallery-modal', 'data-gallery-modal-slider', 'data-gallery-close']) {
  if (!htmlAttrs.includes(required)) errors.push(`Falta el atributo requerido ${required} en la plantilla`);
}
if (!/<dialog\b[^>]*data-gallery-modal\b/.test(html)) {
  errors.push('data-gallery-modal debe estar en un <dialog> (el JS exige HTMLDialogElement)');
}

// 3. Estructura Swiper y zoom.
const sliders = (html.match(/class="swiper[\s"]/g) ?? []).length;
const wrappers = (html.match(/class="swiper-wrapper"/g) ?? []).length;
if (sliders !== wrappers) errors.push(`Cada .swiper necesita su .swiper-wrapper (${sliders} sliders, ${wrappers} wrappers)`);

if (!/swiper-zoom-container[^>]*>[\s\S]*?<img[^>]*data-gallery-modal-image/.test(html)) {
  errors.push('La <img> del modal debe ir dentro de .swiper-zoom-container (y llevar data-gallery-modal-image) para que funcione el zoom');
}

// 4. Accesibilidad básica.
for (const button of html.match(/<button\b[^>]*>/g) ?? []) {
  if (!button.includes('aria-label')) errors.push(`Botón sin aria-label: ${button}`);
  if (!/type=["']button["']/.test(button)) errors.push(`Botón sin type="button": ${button}`);
}
if (!/data-gallery-counter[^>]*aria-live|aria-live[^>]*data-gallery-counter/.test(html)) {
  warnings.push('El contador (data-gallery-counter) no tiene aria-live');
}
if (/<img\b(?![^>]*\balt=)/.test(html)) warnings.push('Hay <img> sin atributo alt');

// 5. Rendimiento de imágenes.
if (!/fetchpriority=["']high["']/.test(html)) warnings.push('Ninguna imagen del principal usa fetchpriority="high" (candidata a LCP)');
if (!/<img[^>]*data-gallery-modal-image[^>]*loading=["']lazy["']|<img[^>]*loading=["']lazy["'][^>]*data-gallery-modal-image/.test(html)) {
  warnings.push('Las imágenes del modal deberían ser loading="lazy" (se descargan al abrir)');
}

// 6. JS.
if (!js.includes("typeof Swiper")) errors.push("El JS debe protegerse si Swiper no cargó (typeof Swiper === 'function')");
if (!/modal\.addEventListener\(\s*['"]cancel['"]/.test(js)) {
  errors.push("Falta interceptar 'cancel' del <dialog>: Esc desincronizaría el índice con el slider principal");
}
if (!/addEventListener\(\s*['"]error['"]/.test(js)) warnings.push("No se escucha 'error' en las imágenes: un 404 dejaría el loader infinito");
if ((js.match(/zoom\.out\(\)/g) ?? []).length < 3) warnings.push('Pocas llamadas a zoom.out(): revisa que todos los caminos de navegación reseteen el zoom');
if (!/zoom\s*:\s*\{/.test(js)) errors.push('Falta la config `zoom: {…}` en el Swiper del modal (activa el módulo Zoom)');
if (!/watchSlidesProgress\s*:\s*true/.test(js) && /thumbs\s*:/.test(js)) {
  errors.push('Thumbs requiere watchSlidesProgress:true en el Swiper de miniaturas');
}
if (!/observer\s*:\s*true/.test(js)) warnings.push('Sin observer:true: el slider dentro del <dialog> cerrado puede medir 0×0');
if (!/requestAnimationFrame/.test(js)) warnings.push('No hay requestAnimationFrame al abrir: el modal puede medirse antes de tener tamaño');

// 7. CSS (opcional).
if (css) {
  const rootClass = html.match(/class="([^"]*)"[^>]*data-product-gallery|data-product-gallery[^>]*class="([^"]*)"/);
  const prefix = (rootClass?.[1] ?? rootClass?.[2] ?? '').split(/\s+/)[0];
  if (prefix) {
    const used = unique(matches(html, new RegExp(`\\b(${prefix}[a-z0-9_-]*)`, 'g')));
    for (const cls of used) {
      if (!css.includes(`.${cls}`)) warnings.push(`La clase .${cls} se usa en la plantilla pero no tiene regla en el CSS`);
    }
  }
  if (!css.includes('::backdrop')) warnings.push('El CSS no estiliza dialog::backdrop');
  if (!/\.is-loaded/.test(css)) errors.push('Falta la regla .is-loaded (la imagen del modal nace con opacity:0)');
  if (!css.includes('swiper-zoom-container')) warnings.push('El CSS no toca .swiper-zoom-container (cursor/tamaño del zoom)');
  if (!/100dvh/.test(css)) warnings.push('El modal debería usar 100dvh para móviles con barra de navegador dinámica');
  if (!/safe-area-inset/.test(css)) warnings.push('Los controles flotantes deberían respetar env(safe-area-inset-*)');
  if (!/prefers-reduced-motion/.test(css)) warnings.push('Sin @media (prefers-reduced-motion)');
  if (!/dialog\[open\]/.test(css)) warnings.push('No se bloquea el scroll de fondo (body:has(dialog[open]) { overflow:hidden })');
}

// 8. Orden de carga en la página (opcional).
if (page) {
  const swiperAt = page.search(/swiper(?:-bundle)?[^"']*\.js/);
  const galleryAt = page.indexOf(values.js.split('/').pop());
  if (swiperAt === -1) errors.push('La página no carga el JS de Swiper');
  else if (galleryAt === -1) errors.push(`La página no carga ${values.js.split('/').pop()}`);
  else if (swiperAt > galleryAt) errors.push('La página carga el script de la galería antes que Swiper');
  if (!/swiper[^"']*\.css/.test(page)) errors.push('La página no carga el CSS de Swiper');
}

console.log(`Atributos: ${jsAttrs.length} en JS, ${htmlAttrs.length} en la plantilla.`);
for (const e of errors) console.log(`ERROR  ${e}`);
for (const w of warnings) console.log(`AVISO  ${w}`);
if (!errors.length && !warnings.length) console.log('OK  Sin hallazgos.');
process.exit(errors.length ? 1 : 0);

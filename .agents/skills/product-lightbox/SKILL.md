---
name: product-lightbox
description: Experto frontend en galería de producto y lightbox (vista maximizada con zoom) para ecommerce, agnóstico del backend. Úsalo para crear, modificar o depurar una galería con Swiper (Thumbs + Zoom) y `<dialog>` nativo - zoom con rueda/pellizco/doble toque, navegación por teclado, sincronización con el slider principal, foco y accesibilidad, loader de imágenes, rendimiento (srcset, lazy, LCP), scroll lock y safe-area en móvil. Incluye plantillas portables HTML/JS/CSS y un auditor Node que valida el contrato entre marcado, JS y CSS en cualquier motor de plantillas (Twig, Blade, EJS, JSX, Vue…).
---

# Galería de producto y Lightbox (frontend)

El lightbox **no usa una librería de lightbox**: es un `<dialog>` nativo con un segundo Swiper que aporta el zoom. Es HTML + JS + CSS puro; el backend sólo tiene que entregar una lista de imágenes (ver `references/datos-backend.md`).

## Flujo de trabajo

1. **Leer** el marcado, el JS y el CSS de la galería existente (o partir de `templates/` si es nueva). El contrato entre ellos es por `data-*`, no por clases.
2. **Auditar el contrato** antes y después de editar:
   ```bash
   node .claude/skills/product-lightbox/scripts/audit-gallery.mjs \
     --html <plantilla-de-la-galeria> --js <product-gallery.js> \
     [--css <hoja.css>] [--page <plantilla-que-carga-los-scripts>]
   ```
   Sale con código 1 si hay errores; los avisos no fallan. Funciona con cualquier plantilla (comentarios HTML y `{# #}` se ignoran).
3. **Cambiar** respetando *Contrato* y *Trampas*.
4. **Subir el cache-busting** de JS/CSS con el mecanismo que use el proyecto (query `?v=`, hash de build…).
5. **Verificar en navegador** con `references/verificacion.md`; el auditor no ejecuta el JS.

## Plantillas portables

| Fichero | Contenido |
|---|---|
| `templates/gallery.html` | Marcado con placeholders `{{ }}` a sustituir por tu motor de plantillas |
| `templates/product-gallery.js` | Lógica completa; inicializa **cada** `[data-product-gallery]` de la página |
| `templates/gallery.css` | Estilos con variables `--pg-*` (único punto de personalización), sin fuente de iconos |

Para adaptarlas: renombra el prefijo `pg-` por el del proyecto (el auditor lo deduce de la clase del elemento raíz), cambia los glifos `✕ ‹ ›` por el sistema de iconos que haya, y pasa las variables `--pg-*` a los tokens de diseño del sitio.

## Librerías

- **Swiper 12 (verificado con 12.1.3), bundle global** (`Swiper`). Módulos: **Thumbs**, **Zoom**, **FreeMode**; `observer`/`resizeObserver` son parámetros del núcleo. Con el *bundle* todo viene incluido; con ESM importa `Thumbs, Zoom, FreeMode` y pásalos en `modules: [...]` de cada instancia.
- **`<dialog>` + `showModal()`**: focus trap, `Esc`, capa superior y `::backdrop` sin librería.
- No se usan Navigation, Pagination, A11y ni Keyboard de Swiper: flechas, contador, teclado y ARIA son propios.
- **No añadir** PhotoSwipe/GLightbox/Fancybox si ya hay Swiper en la página: duplica peso y comportamiento.
- Swiper se carga sólo en la ficha de producto, con `defer` y **antes** del script de la galería. Vendorizarlo (no CDN) evita un punto de fallo externo; versión y fichero deben ir bajo cache-busting.

## Arquitectura: tres instancias de Swiper

```
mainSwiper  [data-gallery-main]         slider visible; clic/Enter/Space abre el modal
   └─ thumbs: thumbsSwiper              [data-gallery-thumbs] sólo si hay >1 imagen; freeMode, slidesPerView:'auto'
modalSwiper [data-gallery-modal-slider] dentro del <dialog>; zoom {maxRatio:5, minRatio:1}
```

La fuente de verdad del índice es el `activeIndex` del slider que el usuario tiene delante:

- **Abrir** → `showModal()`; en **doble `requestAnimationFrame`**: `modalSwiper.update()`, `slideTo(mainSwiper.activeIndex, 0)`, `zoom.out()`, foco al botón cerrar.
- **Cerrar** (`syncAndCloseGallery`) → `zoom.out()`, `mainSwiper.slideTo(modalSwiper.activeIndex, 0)`, `modal.close()`, foco de vuelta al principal.
- **Cambiar de slide en el modal** → siempre `zoom.out()` antes (evento `slideChange` y `changeModalSlide`).

### Interacciones

| Entrada | Efecto |
|---|---|
| Clic / Enter / Space en el principal | Abre el modal |
| Clic en backdrop o ✕ (`[data-gallery-close]`) | Sincroniza y cierra |
| `Esc` (evento `cancel`) | `preventDefault()` + `syncAndCloseGallery()` |
| `←` / `→` (listener en `document`, sólo con `modal.open`) | Anterior / siguiente |
| Rueda (`wheel`, `passive:false`) | Zoom ±0.5 entre 1 y 5; al llegar a 1 hace `zoom.out()` |
| Doble toque / pellizco | Nativo del módulo Zoom |
| Flechas del modal | `disabled` en los extremos; sin loop |

## Contrato marcado ↔ JS

El JS localiza todo por `data-*` dentro de `[data-product-gallery]`. Renombrar uno exige cambiar ambos lados (lo comprueba el auditor).

| Atributo | Elemento | Requerido |
|---|---|---|
| `data-product-gallery` | contenedor raíz (admite varios por página) | sí |
| `data-gallery-main` | slider principal (`tabindex=0`, `role=button`) | sí |
| `data-gallery-thumbs` | slider de miniaturas | no |
| `data-gallery-modal` | `<dialog>` (debe ser `HTMLDialogElement`) | sí |
| `data-gallery-modal-slider` | slider interior del modal | sí |
| `data-gallery-close` | backdrop **y** botón ✕; el JS excluye `[aria-hidden]` para elegir el botón que recibe foco | sí |
| `data-gallery-prev` / `-next` | flechas | no (sólo con >1 imagen) |
| `data-gallery-counter` | `<p aria-live="polite">` con "n / total" | no |
| `data-gallery-modal-image` | `<img>` del modal | informativo |

Estructura Swiper obligatoria: `.swiper > .swiper-wrapper > .swiper-slide`. Para el zoom, la `<img>` del modal va **dentro de `.swiper-zoom-container`** dentro del slide.

Si falta un elemento requerido o `Swiper` no es función, la galería **no se inicializa y no lanza error**: las imágenes quedan estáticas. Es la degradación deseada, pero también el síntoma típico de un `data-*` renombrado o del script de Swiper sin cargar.

## Reglas de implementación

1. **Tres variantes por imagen**, con propósitos distintos: `thumb` (miniatura y `srcset` 480w), `src` (principal, `srcset` 1200w) y `bigSrc` (modal; la de mayor resolución, o el zoom pixela).
2. **LCP**: sólo la primera imagen del principal lleva `fetchpriority="high"`; el resto `loading="lazy"`. Las del modal son `lazy` y se descargan al abrir. Mantén `width`/`height` para evitar CLS.
3. **Sin duplicados**: si el backend mezcla colecciones (principal, secundarias, tabla nutricional…), deduplica por `bigSrc` antes de pintar.
4. **Sin HTML inyectado**: alt y URLs se escapan con el autoescape del motor de plantillas; no uses `raw`/`v-html`/`dangerouslySetInnerHTML` en la galería.
5. **JS**: sin dependencias, script clásico con `/* global Swiper */`; constantes de configuración en cabecera (`GALLERY_MAX_ZOOM`, `GALLERY_ZOOM_STEP`); funciones cortas con early return; `instanceof` para estrechar tipos del DOM.
6. **CSS**: personaliza por variables; `100dvh` para el modal; `env(safe-area-inset-*)` en controles flotantes; `body:has(dialog[open]) { overflow:hidden }` para bloquear el scroll (no lo dupliques en JS); respeta `prefers-reduced-motion` (basta una regla global si el sitio ya la tiene).
7. **Accesibilidad**: todo `<button>` con `type="button"` y `aria-label`; contador `aria-live="polite"`; el principal es `role=button` + `tabindex=0` con Enter/Space; el foco vuelve al principal al cerrar.

## Trampas conocidas

- **Swiper dentro de un `<dialog>` cerrado mide 0×0.** Por eso `observer/observeParents/resizeObserver` están activos y `openGallery` hace `update()` en doble rAF tras `showModal()`. No quites el doble rAF ni llames a `slideTo` antes.
- **Imágenes `loading="lazy"` en un diálogo cerrado** no cargan hasta abrir. El loader se oculta con `.is-loaded` en el slide; `markLoaded` corre en `load`, en `error` y si `image.complete`. Conserva los tres casos o el spinner queda infinito.
- **La imagen del modal nace con `opacity:0`** y sólo se ve con `.is-loaded`: imagen invisible = `markLoaded` no corrió.
- **`Esc` nativo cierra el `<dialog>` sin sincronizar el índice.** Se intercepta `cancel`; cualquier otra vía de cierre debe pasar por `syncAndCloseGallery`.
- **Rueda**: `passive:false` + `preventDefault()` evitan el scroll de fondo. `zoom.in(scale)` sólo funciona si el Swiper del modal tiene objeto `zoom` en su config.
- **Miniaturas**: `slidesPerView:'auto'` + estilos inline de Swiper obligan a `width: 72px !important` en la miniatura; `watchSlidesProgress:true` es requisito de Thumbs para marcar la activa (`swiper-slide-thumb-active`).
- **`pointer-events:none` en las `<img>`** es intencionado: evita el arrastre nativo y deja los gestos a Swiper.
- **Varias galerías en una página**: la plantilla las inicializa todas (una instancia por raíz). Como el modal es `showModal()`, sólo uno puede estar abierto, así que el listener global de teclado (guardado por `modal.open`) no choca.
- **Loop infinito**: no activar `loop:true` sin revisar `isBeginning/isEnd`, el contador (`activeIndex` incluye duplicados; usar `realIndex`) y la sincronización con el principal.
- **SPA (React/Vue/Angular)**: el JS espera que el DOM exista al ejecutarse. En un framework, inicializa en `onMounted`/`useEffect`/`ngAfterViewInit` y **destruye** las tres instancias (`swiper.destroy()`) y los listeners de `document` al desmontar.
- **Con una sola imagen** no se renderizan miniaturas ni flechas; el JS lo tolera (son opcionales).

## Recetas

### Cambiar zoom máximo o paso de rueda
Edita `GALLERY_MAX_ZOOM` / `GALLERY_ZOOM_STEP` (se usan en la config de Swiper y en el handler de rueda).

### Zoom siguiendo el ratón
`zoom: { …, panOnMouseMove: true }`. Revisa el cursor `grab` en `.swiper-slide-zoomed .swiper-zoom-container`.

### No ampliar más allá del tamaño real
`zoom: { …, limitToOriginalSize: true }`, útil si `bigSrc` es pequeño.

### Añadir una acción al modal (descargar, compartir)
1. Botón dentro del `<dialog>` con `data-gallery-<accion>`, `type="button"`, `aria-label` y la clase base de `close`/`nav` para heredar estilo.
2. Selector + listener en `initProductGallery`, con `event.stopPropagation()` si queda sobre el backdrop.
3. Ejecutar el auditor.

### Pinch/zoom en la imagen principal
No: el principal sólo abre el modal; el zoom pertenece a `modalSwiper`.

## Referencias

- `references/swiper-zoom.md` — API de Swiper realmente usada, con defaults del módulo Zoom.
- `references/datos-backend.md` — contrato de datos que debe entregar cualquier backend + ejemplos de bucle en Twig/Blade/EJS/JSX.
- `references/verificacion.md` — checklist manual (escritorio, móvil, casos límite, accesibilidad).
- `references/implementacion-radical-nutrition.md` — dónde vive esto en este repositorio (PHP/Slim/Twig) y sus particularidades.

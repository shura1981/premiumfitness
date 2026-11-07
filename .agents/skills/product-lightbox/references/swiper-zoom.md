# Swiper 12.1.3 — API realmente usada por la galería

Versión vendorizada en `assets/vendor/swiper/swiper-bundle.min.js` (cabecera: *Swiper 12.1.3, 24-mar-2026*). Docs oficiales: https://swiperjs.com/swiper-api

## Parámetros usados

| Instancia | Parámetros |
|---|---|
| `thumbsSwiper` | `slidesPerView:'auto'`, `spaceBetween:8`, `watchSlidesProgress:true`, `freeMode:true` |
| `mainSwiper` | `spaceBetween:0`, `thumbs:{swiper: thumbsSwiper}` (sólo si hay miniaturas) |
| `modalSwiper` | `zoom:{maxRatio:5, minRatio:1}`, `observer:true`, `observeParents:true`, `resizeObserver:true` |

`watchSlidesProgress` en las miniaturas es requisito del módulo Thumbs para marcar `swiper-slide-thumb-active`.

## Módulo Zoom

Defaults verificados en el bundle del repo:

```js
zoom: {
  enabled: false,            // pasar un objeto `zoom:{...}` lo activa
  limitToOriginalSize: false,
  maxRatio: 3,               // el proyecto usa 5
  minRatio: 1,
  panOnMouseMove: false,
  toggle: true,              // doble toque/click alterna zoom
  containerClass: 'swiper-zoom-container',
  zoomedSlideClass: 'swiper-slide-zoomed',
}
```

Estructura que Swiper espera: `.swiper-slide > .swiper-zoom-container > img`. Cualquier elemento extra dentro del contenedor (aquí el `.store-gallery-loader`) también se escala con el zoom; por eso el loader es `position:absolute; inset:0` y desaparece al cargar.

Métodos usados: `swiper.zoom.in(scale?)`, `swiper.zoom.out()`, propiedad `swiper.zoom.scale`. Clase `swiper-slide-zoomed` en el slide cuando está ampliado (el CSS la usa para el cursor `grab`).

## Otros métodos / propiedades usados

`update()`, `slideTo(index, 0)`, `slideNext()`, `slidePrev()`, `activeIndex`, `slides`, `isBeginning`, `isEnd`, evento `slideChange`.

## Por qué `zoom.out()` en cada cambio

El zoom guarda estado por slide. Si se navega con una imagen ampliada, la siguiente aparece desplazada/escalada. Todos los caminos de navegación (`changeModalSlide`, `slideChange`, `openGallery`, `syncAndCloseGallery`) lo resetean.

## Qué NO hace el módulo y se hace a mano

- Teclado (`←`/`→`): listener propio en `document`.
- Rueda como zoom: el módulo Zoom no la usa por defecto; se implementa en `modal.addEventListener('wheel', …, {passive:false})`.
- Accesibilidad (`aria-*`, roles): manual en Twig; el módulo A11y no se usa.
- Contador y estado `disabled` de flechas: `updateModalState()`.

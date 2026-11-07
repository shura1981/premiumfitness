# Implementación en este repositorio (Radical Nutrition)

Ejemplo real de la galería. La skill es agnóstica; esto sólo documenta dónde vive aquí.

| Pieza | Fichero |
|---|---|
| Marcado (Twig) | `views/store/partials/gallery.twig` (incluido por `views/store/product.twig`) |
| Carga de scripts/estilos | `views/store/product.twig`, bloques `page_styles` / `page_scripts` (Swiper `defer`, luego `product-gallery.js` `defer`) |
| JS | `assets/js/product-gallery.js` |
| CSS | `assets/css/store.css`, reglas `.store-gallery-*` (~líneas 355-470) |
| Vendor | `assets/vendor/swiper/swiper-bundle.min.{js,css}` — Swiper 12.1.3 |
| Datos | `src/Features/PublicStore/ProductImages.php` (`gallerySlides()`) y `StorePresenter.php` (`gallery.{badge,slides}`) |
| Cache-busting | constante `StorePresenter::ASSETS` (`gallery`, `css`, `swiper`); se usa como `?v={{ assets.gallery }}` |

## Particularidades locales

- **Prefijo de clases**: `store-gallery-*` (la plantilla portable usa `pg-*`).
- **Iconos**: Material Symbols Outlined (`zoom_in`, `close`, `chevron_left/right`, `pinch`, `star`).
- **Diseño**: sigue el look de la tienda (Poppins, tonos cálidos, redondeado), no el de la landing; variables `--rn-*`.
- **Orden de slides** (`ProductImages::gallerySlides`): principal → tabla nutricional → secundarias → card → instagram; dedupe por `bigSrc`; fallback `ProductImages::FALLBACK`.
- **Variantes** del JSON de BD: `thumb` = small→semiMedium→medium; `src` = big→medium→semiMedium→bigger→small; `bigSrc` = bigger→big→medium.
- **Insignia** (`store-gallery-badge`): "Más vendido" / "Nuevo" / "Promoción" según flags del producto.
- **Una galería por página**: aquí el JS sigue siendo la versión original (`document.querySelector`); la plantilla portable ya soporta varias.
- **Sin tests del JS**; `tests/E2E/PublicStore/PublicStoreTest.php` cubre sólo el HTML servido.
- El CSS global ya tiene una regla `@media (prefers-reduced-motion: reduce)` (store.css ~línea 1355) que cubre loader y transiciones del lightbox.

## Auditar esta implementación

```bash
node .claude/skills/product-lightbox/scripts/audit-gallery.mjs \
  --html views/store/partials/gallery.twig \
  --js assets/js/product-gallery.js \
  --css assets/css/store.css \
  --page views/store/product.twig
```

Tras cambiar JS o CSS, sube `gallery` / `css` en `StorePresenter::ASSETS`; `swiper` sólo si se reemplaza el vendor.

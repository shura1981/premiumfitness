# Implementación de Referencia (Angular / Frontend Moderno)

Estructura de referencia para la galería de producto y modal lightbox en la aplicación frontend (Angular / HTML5):

| Pieza | Ubicación recomendada |
|---|---|
| Componente Angular | `src/app/features/catalog/components/product-gallery/product-gallery.component.ts` |
| Template | `product-gallery.component.html` (o marcado en `templates/gallery.html`) |
| Estilos | `product-gallery.component.scss` (o `templates/gallery.css`) |
| Script Portable | `templates/product-gallery.js` |
| Librería Swiper | Swiper 11+ / 12+ vía `npm install swiper` |
| Servicio de Datos | `src/app/core/services/product.service.ts` o endpoint API Express `/api/products/:id` |

## Particularidades de Integración

- **Prefijo de clases**: `pg-*` (Product Gallery).
- **Iconos**: Material Symbols o SVG inline (`zoom_in`, `close`, `chevron_left/right`).
- **Responsive & Accesibilidad**:
  - Modal nativo `<dialog id="pg-lightbox">` con `showModal()` y `close()`.
  - Navegación por teclado (Escape, flechas izquierda/derecha).
  - Soporte para gestos táctiles (pinch-to-zoom con Swiper Zoom module).
- **Imágenes y Rendimiento**:
  - `fetchpriority="high"` en la primera imagen (LCP).
  - `loading="lazy"` en slides posteriores.
  - Formato moderno `.webp`.

## Auditar la galería

```bash
node .agents/skills/product-lightbox/scripts/audit-gallery.mjs \
  --html templates/gallery.html \
  --js templates/product-gallery.js \
  --css templates/gallery.css
```

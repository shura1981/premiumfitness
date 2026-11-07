# Contrato de datos: qué debe entregar el backend (Node.js / TypeScript)

La galería consume una **lista ordenada de slides** entregada por la API o el servicio backend:

```typescript
export interface GallerySlide {
  src: string;      // Slider principal (~1200px)
  bigSrc: string;   // Lightbox con zoom hasta 5x (≥ 2000px)
  thumb: string;    // Miniaturas y srcset 480w (~480px)
  alt: string;      // Texto alternativo descriptivo
}

export interface ProductGalleryData {
  badge?: string;
  slides: GallerySlide[];
}
```

Ejemplo JSON:
```json
[
  { "src": "/img/p1-big.webp", "bigSrc": "/img/p1-bigger.webp", "thumb": "/img/p1-small.webp", "alt": "Whey Protein 2 lb" }
]
```

| Campo | Uso | Tamaño orientativo |
|---|---|---|
| `thumb` | miniaturas y `srcset` 480w del principal | ~480 px |
| `src` | slider principal, `srcset` 1200w | ~1200 px |
| `bigSrc` | lightbox con zoom hasta 5× | ≥ 2000 px |
| `alt` | texto alternativo descriptivo | — |

Reglas para quien genere la lista en Node.js/TypeScript:

1. **Orden**: imagen principal primero (candidata a LCP).
2. **Sin duplicados**: deduplica por `bigSrc` usando `Set` o `filter`.
3. **Fallback**: si no hay imágenes, devuelve una slide con fallback por defecto.
4. **Degradación de variantes**: si falta `bigSrc`, rellenar con `src` o `thumb`.
5. **URLs válidas**: rutas absolutas (`/img/...`) o URLs HTTPS completas.
6. **Formato recomendado**: WebP.

## Bucle en Angular / HTML

**Angular (Component Template con `@for`)**:
```html
@for (slide of slides; track slide.src; let isFirst = $first) {
  <div class="swiper-slide pg-slide">
    <img class="pg-main-img" [src]="slide.src" [srcset]="slide.thumb + ' 480w, ' + slide.src + ' 1200w'"
         sizes="(min-width: 768px) 45vw, 100vw" width="900" height="900" [alt]="slide.alt" draggable="false"
         [attr.fetchpriority]="isFirst ? 'high' : null" [attr.loading]="isFirst ? null : 'lazy'">
  </div>
}
```

**HTML Vanilla / EJS**:
```html
<% slides.forEach((slide, i) => { %>
  <div class="swiper-slide pg-slide">
    <img class="pg-main-img" src="<%= slide.src %>" srcset="<%= slide.thumb %> 480w, <%= slide.src %> 1200w"
         width="900" height="900" alt="<%= slide.alt %>" draggable="false"
         <%- i === 0 ? 'fetchpriority="high"' : 'loading="lazy"' %>>
  </div>
<% }) %>
```

Repite el mismo bucle para `.pg-thumb` (usa `thumb`) y para los slides del modal (usa `bigSrc` dentro de `.swiper-zoom-container`). Renderiza miniaturas, flechas y contador **sólo si `slides.length > 1`**.

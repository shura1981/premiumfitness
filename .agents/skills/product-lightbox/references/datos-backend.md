# Contrato de datos: qué debe entregar el backend

La galería es agnóstica: sólo necesita una **lista ordenada de slides**. Cualquier backend (PHP, Node, Python, .NET…) sirve si la produce.

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

Reglas para quien genere la lista:

1. **Orden**: imagen principal primero (será la candidata a LCP).
2. **Sin duplicados**: deduplica por `bigSrc` si combinas colecciones.
3. **Fallback**: si no hay imágenes, devuelve una sola slide con una imagen por defecto; la galería nunca debe quedar vacía.
4. **Degradación de variantes**: si falta una variante, rellena con la mejor disponible (`bigSrc` ← `src` ← `thumb`) para no dejar `src` vacío.
5. **URLs válidas**: sólo rutas absolutas del sitio (`/…`) o URLs completas; descarta el resto.
6. **`alt` únicos y útiles** cuando hay varias imágenes (ej. "… — vista secundaria", "Tabla nutricional de …").
7. Formato recomendado: WebP (con fallback si el público lo exige). Ver la skill `image-webp-optimizer` para generar variantes.

## Bucle por motor de plantillas

Los atributos `data-*` y la estructura son idénticos; sólo cambia la sintaxis del bucle.

**Twig**
```twig
{% for slide in slides %}
  <div class="swiper-slide pg-slide">
    <img class="pg-main-img" src="{{ slide.src }}" srcset="{{ slide.thumb }} 480w, {{ slide.src }} 1200w"
         sizes="(min-width: 768px) 45vw, 100vw" width="900" height="900" alt="{{ slide.alt }}" draggable="false"
         {{ loop.first ? 'fetchpriority="high"' : 'loading="lazy"' }}>
  </div>
{% endfor %}
```

**Blade**
```blade
@foreach ($slides as $slide)
  <div class="swiper-slide pg-slide">
    <img class="pg-main-img" src="{{ $slide['src'] }}" srcset="{{ $slide['thumb'] }} 480w, {{ $slide['src'] }} 1200w"
         width="900" height="900" alt="{{ $slide['alt'] }}" draggable="false"
         {!! $loop->first ? 'fetchpriority="high"' : 'loading="lazy"' !!}>
  </div>
@endforeach
```

**EJS**
```ejs
<% slides.forEach((slide, i) => { %>
  <div class="swiper-slide pg-slide">
    <img class="pg-main-img" src="<%= slide.src %>" srcset="<%= slide.thumb %> 480w, <%= slide.src %> 1200w"
         width="900" height="900" alt="<%= slide.alt %>" draggable="false"
         <%- i === 0 ? 'fetchpriority="high"' : 'loading="lazy"' %>>
  </div>
<% }) %>
```

**JSX / Vue / Svelte**: renderiza el mismo marcado con `slides.map(...)`; `fetchPriority="high"` en la primera. Inicializa Swiper tras montar y destrúyelo al desmontar (ver *SPA* en SKILL.md).

Repite el mismo bucle para `.pg-thumb` (usa `thumb`) y para los slides del modal (usa `bigSrc` dentro de `.swiper-zoom-container`). Renderiza miniaturas, flechas y contador **sólo si `slides.length > 1`**.

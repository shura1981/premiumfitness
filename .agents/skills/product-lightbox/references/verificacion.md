# Verificación de la galería y el lightbox

El auditor sólo valida el contrato estático. El comportamiento (zoom, foco, teclado, gestos) se comprueba en navegador, o con un test E2E (Playwright/Cypress) si el proyecto lo tiene.

## 1. Estático

```bash
node .claude/skills/product-lightbox/scripts/audit-gallery.mjs --html <plantilla> --js <galeria.js> [--css <hoja.css>] [--page <pagina>]
node --check <galeria.js>      # sintaxis
```

Además, ejecuta los linters/tests del proyecto y renderiza la ficha de producto para comprobar que la plantilla no se rompió.

## 2. Navegador (escritorio)

Abre una ficha de producto con varias imágenes.

- [ ] Click en la imagen principal abre el modal; el foco va al botón ✕.
- [ ] El modal abre en la misma imagen que se veía en el principal.
- [ ] Rueda hacia arriba amplía hasta 5×; hacia abajo vuelve a 1× sin scroll de fondo.
- [ ] Arrastrar con la imagen ampliada la desplaza (cursor `grabbing`).
- [ ] `←`/`→` cambian de imagen y resetean el zoom; el contador se actualiza.
- [ ] Flechas del modal deshabilitadas en primera y última imagen.
- [ ] `Esc`, ✕ y click en el fondo cierran; el principal queda en la imagen en la que se cerró; el foco vuelve al principal.
- [ ] Miniaturas: la activa se resalta y al hacer click cambia el principal.
- [ ] Enter/Space sobre el principal enfocado abre el modal.

## 3. Navegador (móvil / emulación táctil)

- [ ] Swipe horizontal cambia de imagen en principal y modal.
- [ ] Pellizco y doble toque amplían; al ampliada, el arrastre mueve la imagen en vez de cambiar de slide.
- [ ] Controles respetan el notch (`safe-area-inset-*`); el modal ocupa `100dvh` con la barra del navegador visible/oculta.
- [ ] Sin scroll de la página de fondo con el modal abierto.

## 4. Casos límite

- [ ] Producto con **una sola imagen**: sin miniaturas ni flechas; no hay errores de consola.
- [ ] Producto **sin imágenes**: una slide con el fallback.
- [ ] Imagen rota (404) en el modal: el loader desaparece (`error` → `markLoaded`).
- [ ] Red lenta (throttling): spinner visible hasta cargar `bigSrc`; luego fade-in.
- [ ] Sin JS (o Swiper bloqueado): la ficha sigue mostrando las imágenes; consola sin excepciones.

## 5. Accesibilidad y rendimiento

- [ ] Tab llega al principal (`role=button`), miniaturas y, dentro del modal, sólo a sus botones (focus trap del `<dialog>`).
- [ ] Todos los botones tienen `aria-label` en español; el contador es `aria-live="polite"`.
- [ ] `prefers-reduced-motion: reduce` (DevTools → Rendering): sin transiciones largas ni spinner a velocidad normal.
- [ ] LCP: sólo la primera imagen del principal lleva `fetchpriority="high"`; las del modal no se descargan hasta abrir.

## 6. Tras cambiar código

- [ ] Subir el cache-busting del JS y/o CSS modificados (y el de Swiper sólo si se reemplaza el vendor).

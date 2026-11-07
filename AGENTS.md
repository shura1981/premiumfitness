# PremiumFitness

Aplicación de referidos, compras, comisiones y puntos para los entrenadores y clientes de PremiumFitness. El backend usa Node.js con Express y el frontend usa Angular, con áreas separadas para entrenadores y clientes.

**Estado actual:** documentación y diseño. Todavía no hay código de aplicación.

## Antes de cualquier tarea

Lee y aplica todas las reglas de `.agents/rules/`:

- `.agents/rules/specs-y-progreso.md`: se aplica siempre. Indica cómo documentar cada requerimiento en `specs/`, cuándo escribir un plan y cómo marcar el progreso.
- `.agents/rules/codebase-standards.md`: se aplica antes de escribir o modificar código.

Si se añaden reglas nuevas a esa carpeta, también se aplican.

## Documentación del proyecto

| Archivo | Contenido |
| --- | --- |
| `docs/requerimientos.md` | Requisitos originales. Ante una contradicción, prevalece este documento. |
| `docs/PRD-PremiumFitness.md` | Requisitos del producto y decisiones pendientes. |
| `docs/MVP-PremiumFitness.md` | Alcance y criterios de aceptación de la primera entrega. |
| `docs/reglas-de-negocio.md` | Reglas de puntos, comisiones, canje y dietas. |
| `docs/casos-de-uso-y-actores.md` | Actores y recorridos. |
| `docs/fases-de-desarrollo.md` | Orden de trabajo y repositorios de referencia. |
| `specs/` | Spec de cada requerimiento, con su checklist de progreso. |

## Skills

Antes de una tarea que encaje con una skill, lee su `SKILL.md` en `.agents/skills/<nombre>/`.

| Skill | Úsala para |
| --- | --- |
| `backend-testing-node-realdb` | Crear o ejecutar pruebas del backend con Vitest, Supertest y base de datos real. |
| `clean-code-js-ts` | Escribir o refactorizar JavaScript y TypeScript. |
| `frontend-design` | Diseñar interfaces, páginas y componentes web. |
| `image-optimization` | Crear scripts o endpoints que optimicen imágenes de producto con Sharp. |
| `image-webp-optimizer` | Convertir imágenes a WebP, generar tamaños responsive y aplicar lazy loading. |
| `integracion-pasarelas` | Integrar pasarelas de pago colombianas y sus webhooks. |
| `jwt-security` | Implementar autenticación con access token en memoria y refresh token en cookie `httpOnly`. |
| `product-lightbox` | Crear la galería de producto con zoom y lightbox. |
| `sms-account-verification` | Verificar teléfonos con códigos SMS y rate limiting. |

## Convenciones

- Escribe la documentación y los specs en español.
- Los commits siguen el formato del historial: `tipo: emoji Descripción en español` (por ejemplo, `feat: ✨ Agrega ...`).

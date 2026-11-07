---
description: 'Cómo documentar requerimientos y su progreso en el directorio specs/. Aplica cada vez que se pida implementar, planificar o modificar una funcionalidad.'
applyTo: 'specs/**'
---

# Specs y seguimiento de progreso

El directorio `specs/` es la fuente de trabajo para documentar cada requerimiento y su avance. Todo agente que reciba una petición de funcionalidad, cambio o diseño debe seguir estos pasos.

## 1. Mejorar el spec antes de implementar

Antes de escribir código:

1. Busca si ya existe un spec para la petición en `specs/`. Si existe, actualízalo; no crees uno duplicado.
2. Si no existe, crea `specs/<area>/<NN>-<nombre-kebab>.md`, con un número de prefijo correlativo dentro de la carpeta (como `specs/diseño/01-DER.md`).
3. Reescribe la petición del usuario como spec claro con estas secciones:
   - **Objetivo:** qué problema resuelve y para quién.
   - **Alcance:** qué incluye.
   - **Fuera de alcance:** qué no se hace en esta entrega.
   - **Criterios de aceptación:** condiciones verificables para dar la tarea por terminada.
   - **Supuestos y preguntas abiertas:** lo que no está definido. Marca cada punto como **Confirmado**, **Derivado** (propuesta que requiere validación) o **Pendiente** (decisión que falta). Es la misma clasificación que usa `docs/casos-de-uso-y-actores.md`.
4. Si el spec contradice `docs/requerimientos.md`, prevalece ese documento. Señala la diferencia en el spec en lugar de resolverla en silencio.

## 2. Añadir un plan solo si la tarea es compleja

No todos los specs llevan plan. Una tarea simple se resuelve con el spec y su checklist.

Escribe un plan cuando se cumpla al menos una de estas condiciones:

- La tarea toca varios módulos o capas (por ejemplo, base de datos, backend y frontend).
- Exige una decisión de arquitectura o de modelo de datos.
- Hay alternativas razonables entre las que elegir.
- Se divide en fases o requiere migraciones.

El plan explica el **cómo**: enfoque, decisiones y su justificación, archivos o módulos afectados, riesgos y orden de trabajo. No repite las tareas del checklist; el checklist dice **qué** hay que marcar.

Dónde va el plan:

- **Plan corto:** una sección `## Plan` dentro del spec, antes del checklist.
- **Plan largo** (más o menos una pantalla, o con subsecciones propias): un archivo hermano con el mismo nombre y sufijo `.plan.md`, enlazado desde el spec.

```
specs/compras/
├── 03-registro-de-compras.md        ← requisitos y checklist
└── 03-registro-de-compras.plan.md   ← enfoque técnico
```

No crees un directorio `planes/` aparte. El plan vive junto a su spec, y el checklist permanece siempre en el spec, nunca en el archivo de plan.

## 3. Generar el checklist

Dentro del spec, añade una sección **Checklist** con tareas en formato de casillas de Markdown:

```markdown
## Checklist

- [ ] Modelar la entidad `Compra` con sus estados
- [ ] Endpoint `POST /compras` con validación de entrada
- [ ] Pruebas de la regla de 25% para entrenadores
```

Cada tarea debe ser atómica (una acción concreta) y verificable. Ordénalas por dependencia.

## 4. Marcar el progreso

Cuando la implementación esté en curso:

- Marca una tarea como `- [x]` solo cuando esté completa y verificada (código, pruebas o revisión según corresponda).
- Marca la tarea en el mismo cambio que la implementa, no al final de la sesión.
- Si una tarea queda a medias, no la marques. Añade una nota debajo indicando qué falta.
- Si aparecen tareas nuevas, añádelas al checklist en lugar de hacerlas sin registro.
- Si una tarea se descarta, no la borres. Márcala como `- [~]` con el motivo.
- Cuando se cierren todas las casillas y se cumplan los criterios de aceptación, añade la fecha de cierre al encabezado del spec.

## 5. Mantener el spec vigente

- Si la implementación cambia el alcance acordado, actualiza el spec antes de seguir. Si existe plan, actualízalo también.
- No cambies un requisito marcado como **Confirmado** sin indicarlo explícitamente en el spec y avisar a la persona que lo pidió.
- Escribe los specs en español, como el resto de la documentación del proyecto.

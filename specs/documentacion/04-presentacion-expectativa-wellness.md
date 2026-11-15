# 04 — Presentación corta de expectativa: el negocio del wellness

**Estado:** en curso · **Creado:** 14 de noviembre de 2026

## Petición original

> En la reunión de los entrenadores hay distribuidores, por lo que no se puede mostrar ni el video ni las diapositivas: se van a molestar porque les vamos a quitar los clientes entrenadores. El encargado de la reunión me pidió un PowerPoint corto de expectativa.

Lo acordado con el encargado (audio transcrito): el título es «El negocio de 10 billones de dólares que ya empezó»; la imagen, un entrenador asesorando a su cliente en un gimnasio, con signo de pesos, caja registradora, dólares volando y flechas hacia arriba; el dato «el wellness ya mueve 6,8 billones de dólares»; y se cierra con el video que muestra solo la app de dietas.

## Objetivo

Abrir la reunión con expectativa: hay un negocio mundial enorme que ya empezó y el entrenador está en el centro. Sin hablar del programa de puntos y comisión, porque en la sala hay distribuidores.

## Alcance

- **Presentación** de 6 diapositivas (`.pptx`, 16:9), con notas del orador: `docs/presentacion-entrenadores/Expectativa-negocio-wellness.pptx`.
- La última diapositiva lleva **incrustado el video** de Nutrition & Fitness Plus (spec `documentacion/03`).
- Tres imágenes generadas para la presentación: `docs/presentacion-entrenadores/imagenes-expectativa/`.

## Fuera de alcance

- Puntos, comisión, enlaces, cupones, descuentos y el nombre PremiumFitness: no aparecen en ninguna diapositiva ni en las notas.
- Promesas de ingresos para el entrenador: las cifras son el tamaño del mercado mundial.

## Contenido

| N.º | Diapositiva | Qué dice |
| --- | --- | --- |
| 1 | El negocio de 10 billones de dólares que ya empezó | Portada con el entrenador y su cliente; hoy 6,8 billones, 9,8 en 2029 |
| 2 | El wellness ya mueve US$ 6,8 billones | Se duplicó desde 2013; crecerá 7,6 % al año hasta 2029; es el 6,1 % de la economía mundial |
| 3 | Y va camino a los 10 billones | Gráfica 2013, 2024 y 2029; +35 % desde 2019 |
| 4 | En el centro de ese negocio estás tú | El asesorado le pregunta al entrenador cómo entrenar, qué comer y qué tomar |
| 5 | Quien asesora mejor, vende más | Mejor asesoría, más asesorados, más ventas |
| 6 | La herramienta para aprovecharlo | Video de Nutrition & Fitness Plus |

## Fuentes

Las cifras se tomaron del Global Wellness Institute, no de la publicación de Instagram que sirvió de referencia:

- *Global Wellness Economy Monitor 2025* (comunicado del 19 de noviembre de 2025): 6,8 billones de dólares en 2024; el mercado se duplicó desde 2013; creció 35 % desde 2019; crecerá 7,6 % al año hasta 2029, cuando llegará a 9,8 billones; pasó del 5,7 % del PIB mundial en 2019 al 6,1 % en 2024 y se proyecta el 7,1 % en 2029.
- <https://globalwellnessinstitute.org/press-room/press-releases/the-global-wellness-economy-hits-a-record-6-8-trillion-and-is-forecast-to-reach-9-8-trillion-by-2029/>

En español, un billón es un millón de millones (*trillion* en inglés).

## Cómo se hizo

- **Imágenes:** generadas con Higgsfield (menos de 1 crédito). No son fotos de personas reales y no llevan marcas ni textos.
- **Gráfica:** nativa de PowerPoint, editable.
- **Diseño:** el tema oscuro del sistema de diseño (spec `diseño/02`).

## Criterios de aceptación

1. El título es el acordado con el encargado de la reunión.
2. Ninguna diapositiva ni nota menciona puntos, comisión, enlaces, cupones ni PremiumFitness.
3. Cada cifra coincide con la fuente y la lleva citada.
4. La presentación abre en PowerPoint y en WPS sin textos cortados, y el video se reproduce desde la última diapositiva.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| E-01 | Los «10 billones» del título son la proyección para 2029 (9,8 billones). La portada y la diapositiva 3 lo aclaran. | Derivado |
| E-02 | El valor de 2013 en la gráfica (3,4 billones) es aproximado: sale de «se duplicó desde 2013». Lo dice el pie de la diapositiva. | Derivado |
| E-03 | El video cierra con «Tu primer mes es gratis». Si alguien pregunta qué pasa después, la respuesta toca el programa de puntos. | Pendiente: decidir si se cambia el cierre del video |
| E-04 | La diapositiva 5 habla de «más ventas» en general, sin decir por dónde compra el asesorado. | Confirmado por el contexto de la reunión |

## Checklist

- [x] Verificar las cifras en el Global Wellness Institute
- [x] Generar las imágenes del entrenador con su cliente
- [x] Armar la presentación de 6 diapositivas con notas del orador
- [x] Validar el archivo `.pptx` y medir que cada texto cabe en su caja
- [x] Comprobar que el texto no menciona puntos, comisión ni PremiumFitness
- [ ] Abrir la presentación en PowerPoint o WPS y probar que el video se reproduce
  - No se pudo renderizar el `.pptx` en este equipo: la revisión se hizo con una vista previa aproximada.
- [ ] Resolver E-03

# 02 — Presentación y video para entrenadores

**Estado:** en curso · **Creado:** 14 de noviembre de 2026

## Petición original

> Genera en un documento PowerPoint una explicación con las capturas de pantalla más importantes de la app, usa el sistema de diseño. El objetivo es exponer a los entrenadores en una reunión los beneficios y cómo funcionará la app. Añade las funcionalidades de la app de Flutter del repo `dietas.nutramerican.com` y haz capturas de las features más importantes en modo móvil. PowerPoint y un video que hable de los dos repos, el sistema de puntos y la app de dietas. Es para exponer a los entrenadores y crear expectativa.

## Objetivo

Que los entrenadores entiendan en una reunión qué ganan con PremiumFitness y cómo funcionará, y que salgan con ganas de usarlo: el programa de puntos y comisión, y la app de dietas.

## Alcance

- **Presentación** de 19 diapositivas (`.pptx`, 16:9) con el tema oscuro del sistema de diseño: marino, tarjetas, oro como acento y Montserrat. Lleva notas del orador en cada diapositiva.
- **Video** de 73 segundos (1080p, con voz en español y música) para abrir o cerrar la reunión.
- **Capturas en móvil:** 11 pantallas de la app de puntos y 13 de la app de dietas.

Los archivos están en `docs/presentacion-entrenadores/`. La presentación que trata solo de la app de dietas tiene su propio spec: `documentacion/03`.

## Fuera de alcance

- Términos y condiciones del programa y fecha de lanzamiento.
- Versión en PDF de la presentación (se exporta desde PowerPoint o WPS).
- Piezas para redes sociales.

## Contenido

| Bloque | Diapositivas | Fuente |
| --- | --- | --- |
| La idea y los cuatro pasos | 1–3 | `docs/reglas-de-negocio.md` |
| Enlace, QR, cupón y descuento del cliente | 4–5 | Reglas 12 a 14 |
| Comisión, puntos y conversión | 6–9 | Reglas 1, 2, 7, 9, 16, 17 y 18 |
| Compra propia y entrenadores referidos | 10–11 | Reglas 3 a 6 |
| App de dietas: valoración, dietas, IA, consultorio | 12–16 | Repo `dietas.nutramerican.com` |
| Acceso a la app de dietas | 17 | Regla 11 y spec `integraciones/02` |
| Resumen y cierre | 18–19 | — |

**Ejemplos usados**, todos de las reglas: Bi-Pro Classic de 249.900 a 224.910 con 10%, y a 187.425 con 25%; compra de 119.000 con 15.000 de comisión y 5.950 puntos; conversión de 100.000 de comisión con 100.000 puntos vigentes, que deja 250.000 puntos.

## Guion del video

1. Entrenador: tú ya cambias la vida de tus clientes. Ahora, cada recomendación tuya también suma para ti.
2. Llega PremiumFitness. Compartes tu enlace, tu código QR o tu cupón, y tus clientes compran con diez por ciento de descuento.
3. Por cada compra de tus clientes recibes quince por ciento de comisión en dinero, y además cinco por ciento en puntos.
4. Tu comisión se paga cada mes. Y si la conviertes en puntos, recibes puntos adicionales.
5. Con tus puntos canjeas productos. Y cuando compras para ti, tienes veinticinco por ciento de descuento.
6. Invita a otros entrenadores: por cada uno, ganas cincuenta mil puntos cuando su primer cliente compre.
7. Y hay más: la app de dietas. Valoración antropométrica, composición corporal y planes de alimentación listos en minutos.
8. Informes en PDF, agenda, pagos y seguimiento de tus clientes, en tu celular, en la web o en tu computador. Tu primer mes es gratis.
9. PremiumFitness. Tu conocimiento, tus clientes, tus beneficios. Muy pronto.

## Cómo se hizo

- **Pantallas de la app de puntos:** la app todavía no tiene código, así que son maquetas hechas con los componentes del sistema de diseño (spec `diseño/02`), en tema oscuro y a 390 × 844. En la presentación y en el video van rotuladas «Diseño de la app (en desarrollo)».
- **Pantallas de la app de dietas:** capturas reales, generadas con las pruebas `ux` del repo Flutter (`test/ux_movil_test.dart` y `test/ux_pagos_movil_test.dart`). No se modificó ese repo.
- **Presentación:** `pptxgenjs`, con tema, diseños de diapositiva y secciones editables.
- **Video:** dos clips de ambiente y la voz generados con Higgsfield; las pantallas, los textos y el montaje se hicieron con Chrome y `ffmpeg`. Costó 338 créditos, más unos 7 de las ocho muestras de voz y de la segunda grabación.
- **Voz:** la primera versión usó la voz «Juan», que se rechazó por el acento. Se grabaron ocho muestras (`voces-de-prueba/`) y se eligió «Andre» (ElevenLabs, en Higgsfield). El video se remontó con esa voz el 14 de noviembre de 2026.

## Criterios de aceptación

1. Cada cifra de la presentación y del video coincide con `docs/reglas-de-negocio.md`.
2. Las pantallas de la app de puntos se distinguen de las capturas reales.
3. Ninguna pantalla muestra cédulas, teléfonos o correos de personas reales.
4. La presentación abre en PowerPoint y en WPS sin textos cortados.
5. El video se entiende sin sonido (los textos en pantalla cuentan lo mismo que la voz).

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| P-01 | La meta mensual para el acceso a dietas no se anuncia con cifra: es provisional (249.000 puntos). Va solo en las notas del orador. | Derivado |
| P-02 | Los precios de Bi-Pro Lite, Megaplex Lite y Bi-Pro Ripped en las maquetas son ilustrativos. Solo el de Bi-Pro Classic sale de las reglas. | Pendiente: los aporta el negocio |
| P-03 | Las capturas de la app de dietas muestran el nombre del registro de prueba «STEVEN REALPE» y sus medidas. Se evitaron las pantallas con cédulas, teléfonos y correos de la base de pruebas. | Pendiente: decidir si se cambia el nombre |
| P-04 | Los enlaces de las maquetas se muestran como `premiumfitness · /r/c/…` porque el dominio no está definido. | Pendiente |
| P-05 | La voz del video es «Andre», elegida por quien pidió el video entre ocho muestras. El video remontado no se escuchó antes de entregar. | Confirmado (la voz) · Pendiente: escuchar el video completo |
| P-06 | El mensaje de cierre es «Muy pronto»: no hay fecha de lanzamiento. | Derivado |
| P-07 | Se descartó un tercer clip generado porque la ropa mostraba logos parecidos a marcas deportivas reales. | Derivado |

## Checklist

- [x] Leer las reglas de negocio y el alcance de la app de dietas
- [x] Generar las capturas móviles de la app de dietas con sus pruebas `ux`
- [x] Construir las maquetas móviles de la app de puntos con el sistema de diseño
- [x] Armar la presentación de 19 diapositivas con notas del orador
- [x] Validar el archivo `.pptx` y medir que cada texto cabe en su caja
- [x] Generar la voz, la música y los clips, y montar el video
- [x] Revisar los fotogramas del video escena por escena
- [ ] Abrir la presentación en PowerPoint o WPS y revisar el ajuste visual
  - No se pudo renderizar el `.pptx` en este equipo: no hay LibreOffice instalado.
- [x] Cambiar la voz del video por la elegida («Andre») y remontarlo
- [ ] Escuchar el video completo con la voz nueva (P-05)
- [ ] Resolver P-02, P-03 y P-04

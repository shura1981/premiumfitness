# 03 — Presentación y video de Nutrition & Fitness Plus para entrenadores

**Estado:** en curso · **Creado:** 14 de noviembre de 2026

## Petición original

> Haz una PowerPoint solo de la app de dietas, vamos a hablar solo de la app de dietas. Explica bien, destaca el uso de la IA en la dieta, la funcionalidad de hacer las dietas, escanear la imagen de la tabla nutricional de un producto o alimento y que se llenen automáticamente todos los datos. La idea es generar mucha expectativa con esa app: las ventas de nuestros productos dependen de ello.

**Segunda petición (14 de noviembre de 2026):**

> Ahora haz un video solo de la app de dietas, que se llama Nutrition and Fitness Plus, con la misma voz. Destaca además los tipos de alternativas que hay para hacer la valoración: en el PowerPoint solo mostraste la antropometría, pero falta el OMRON y la valoración a distancia (dietas online cuando la asesoría es virtual). Te faltó destacar más todas las funcionalidades, el reporte del OMRON. Haz el video.

## Objetivo

Que los entrenadores salgan de la reunión con ganas de usar la app de dietas (Nutrition & Fitness Plus), porque entienden tres cosas: la app arma la dieta, la inteligencia artificial la revisa y la ajusta, y una foto de la tabla nutricional llena la ficha de un producto. Y que vean que cada dieta lleva dentro la recomendación de producto.

## Alcance

- **Presentación** de 25 diapositivas (`.pptx`, 16:9) con el tema oscuro del sistema de diseño (spec `diseño/02`) y notas del orador en cada una: `docs/presentacion-entrenadores/NutritionFitnessPlus-presentacion-entrenadores.pptx`.
  - La primera versión, de 20 diapositivas, sigue en `PremiumFitness-app-de-dietas.pptx`: se guardó desde WPS después de entregarla y no se sobrescribió.
- **Video** de 1 minuto 44 segundos (1080p, voz «Andre» y música): `docs/presentacion-entrenadores/NutritionFitnessPlus-video-entrenadores.mp4`.
- **Capturas en móvil**: 21 pantallas de la dieta y la IA en `capturas/app-dietas-ia/`, y 14 de la valoración con OMRON y la dieta online en `capturas/app-dietas-valoracion/`, junto con el informe de bioimpedancia y la dieta de ejemplo en PDF.

## Fuera de alcance

- El programa de puntos y comisión: está en la presentación del spec `documentacion/02`. Aquí solo se menciona en una diapositiva (de la dieta a la compra) y en la de acceso.
- Cambios en el repo de la app de dietas.

## Contenido

| Bloque | Diapositivas | Qué muestra |
| --- | --- | --- |
| Apertura | 1–3 | La promesa, el cambio frente al trabajo manual y las tres novedades |
| Valorar | 4–9 | Las tres formas de valorar: antropometría, báscula OMRON (con su informe de bioimpedancia en PDF) y dieta online a distancia |
| Hacer la dieta | 10–11 | Asistente (metabolismo, horario, plantillas) y dieta con metas contra menú |
| Inteligencia artificial | 12–17 | Revisión de la dieta, reemplazo de una comida, lectura de la etiqueta y control del entrenador |
| Productos | 18–22 | El producto dentro del plan, de la dieta a la compra, todas las funciones y el resto del consultorio |
| Cierre | 23–25 | Acceso (primer mes gratis), resumen y cierre |

**Ejemplo de la etiqueta:** la porción de 26 g de Bi-Pro Classic (93 kcal, 20 g de proteína, 1,2 g de carbohidratos, 0,7 g de grasa, 89 mg de sodio y 139 mg de calcio, de `test/datos/productos.md` del repo de dietas). Con la instrucción «Un scoop son 52 g», la ficha queda por scoop: 186 kcal y 40 g de proteína.

## Guion del video

1. Entrenador: hacer una dieta no debería quitarte horas. Te presentamos Nutrition and Fitness Plus: dietas con inteligencia artificial, en minutos.
2. Valoras a cada cliente como mejor te funcione: con antropometría, con la báscula Omron, o a distancia.
3. Con antropometría, registras pliegues, perímetros y diámetros, y la app calcula la composición corporal, el somatotipo y el metabolismo.
4. Con la báscula Omron, escribes la medición de bioimpedancia y la app entrega el diagnóstico al instante.
5. Y tu cliente se lleva un informe de bioimpedancia en PDF: composición corporal, índices, energía y los macronutrientes de su plan.
6. ¿Tu asesoría es virtual? Con la dieta online solo necesitas peso, estatura, edad, actividad y objetivo.
7. Desde cualquiera de las tres, el asistente arma la dieta con el horario de tu cliente y tus plantillas. Calculada al gramo.
8. La inteligencia artificial revisa la dieta y redacta las recomendaciones. Y si a tu cliente no le gusta una comida, propone otra con los mismos macros.
9. Y lo mejor: tomas una foto de la tabla nutricional, y la ficha del producto se llena sola.
10. Cada dieta lleva el producto, con su porción y su hora. Y tu cliente la recibe en PDF o por correo.
11. Además, agenda con recordatorios, cobros, pagos e informes. En tu celular, en la web o en tu computador.
12. Nutrition and Fitness Plus. Mejores dietas, en menos tiempo. Tu primer mes es gratis.

## Cómo se hizo

- **Capturas:** se copió el repo `dietas.nutramerican.com` a una carpeta temporal y allí se corrieron dos pruebas `ux` nuevas (`test/ux_ia_test.dart` y `test/ux_video_test.dart`) que recorren la app móvil en tema oscuro, a 390 × 844. El repo original no se modificó.
- **Informes en PDF:** el informe de bioimpedancia y la dieta los generó la propia app (`ReporteBioimpedancia` y `ReportePaquete`) con la medición y la dieta de la cliente de ejemplo. El nombre del nutricionista («Carlos Ramírez») es inventado.
- **Video:** la narración se grabó con la voz «Andre» en Higgsfield (unos 6 créditos); los dos clips de ambiente y la música son los del video del spec `documentacion/02`; el montaje se hizo con Chrome y `ffmpeg`.
- **Cliente de ejemplo:** «Laura Gómez», creada para la prueba. No hay datos de personas reales.
- **Respuestas de la IA:** simuladas. La prueba sustituye la llamada a Gemini por una respuesta fija (cuatro recomendaciones, una propuesta de desayuno y la lectura de la etiqueta). Las pantallas, los cálculos y el flujo son los de la app.
- **Etiqueta:** una imagen de ejemplo hecha para la demostración, con los valores de la ficha del producto. No es la foto de un empaque real.
- **Retoque:** en dos capturas se dibujó la flecha «→» que la fuente de las pruebas no trae; en el teléfono sí se ve.
- **Presentación:** `pptxgenjs`, con el mismo tema, diseños y medición de textos que el spec `documentacion/02`.

## Criterios de aceptación

1. La presentación trata solo de la app de dietas y destaca las tres funciones pedidas: hacer la dieta, la IA en la dieta y la lectura de la etiqueta.
2. Cada afirmación sobre la app corresponde a una función que existe en el repo de dietas.
3. Las diapositivas con respuestas de IA simuladas lo indican en el pie de las capturas y en las notas.
4. Ninguna pantalla muestra datos de personas reales.
5. La meta mensual de puntos para el acceso no se anuncia con cifra.
6. La presentación abre en PowerPoint y en WPS sin textos cortados.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| D-01 | Las funciones de IA dependen de que la organización las tenga habilitadas y de la conexión a internet. Va en las notas del orador. | Derivado |
| D-02 | La revisión de la dieta puede tardar hasta 150 segundos, según la propia app. En una demostración en vivo conviene tener una dieta ya generada. | Derivado |
| D-03 | La diapositiva «Sin la app» describe el trabajo manual habitual (hojas de cálculo, sumar macros, digitar tablas). No sale de un estudio. | Derivado |
| D-04 | La etiqueta de ejemplo usa los valores de Bi-Pro Classic. Falta decidir si se cambia por la foto del empaque real. | Pendiente |
| D-05 | El acceso (30 días gratis, meta mensual o 50.000 puntos) es el de la regla 11 y el spec `integraciones/02`; la meta es provisional. | Derivado |
| D-07 | El video dura 1:44, más que el primero (1:13), para que quepan las tres formas de valorar y el informe OMRON. | Derivado |
| D-08 | El video no se escuchó antes de entregar: falta revisar la pronunciación de «Nutrition and Fitness Plus» y de «Omron». | Pendiente |
| D-06 | Algunas plantillas de menú de la app nombran marcas de terceros (por ejemplo, un pan). Aparecen en dos capturas tal como están en la app. | Pendiente: decidir si se cambian en las plantillas |

## Checklist

- [x] Revisar en el repo de dietas las funciones de IA y el asistente de dietas
- [x] Generar las capturas móviles de la dieta, la revisión, el reemplazo de comida y la lectura de la etiqueta
- [x] Armar la presentación de 20 diapositivas con notas del orador
- [x] Capturar la valoración con OMRON y la dieta online, y generar el informe de bioimpedancia y la dieta en PDF
- [x] Ampliar la presentación a 25 diapositivas: tres formas de valorar, OMRON, informe OMRON, dieta online y todas las funciones
- [x] Grabar la narración con la voz «Andre» y montar el video de Nutrition & Fitness Plus
- [x] Revisar un fotograma de cada escena del video
- [x] Validar el archivo `.pptx` y medir que cada texto cabe en su caja
- [x] Revisar la vista previa de las diapositivas
- [ ] Abrir la presentación en PowerPoint o WPS y revisar el ajuste visual
  - No se pudo renderizar el `.pptx` en este equipo: no hay LibreOffice instalado. La revisión se hizo con una vista previa aproximada.
- [ ] Escuchar el video completo (D-08)
- [ ] Resolver D-04 y D-06

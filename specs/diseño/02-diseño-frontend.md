# 02 — Diseño del frontend: app web y admin

**Estado:** en curso · **Creado:** 10 de noviembre de 2026 · **Revisado:** 11 de noviembre de 2026

## Petición original

> Ahora vamos a especificar el diseño del frontend.
>
> Decisiones (10 nov 2026):
> - Usar la identidad de Nutramerican.
> - Dirección visual: premium sobria.
> - El admin es un fork de `admin-radical-nutrition`.
> - Entregable: spec más mockups.

**Revisión del 11 nov 2026:**

> El diseño de la app de dietas, desarrollada en Flutter, es el que quiero para el frontend de este repo. Analiza su sistema de diseño en los dos temas y genera el sistema de diseño con Claude Design, para tenerlo listo cuando se haga el frontend.

Esto **reemplaza la dirección visual confirmada el 10 nov** («negro y marfil, editorial», con Bodoni Moda y Poppins). Se conserva la identidad de Nutramerican (S-01), el admin como fork de radical (S-03) y el entregable (spec más mockups); los mockups del 10 nov siguen la dirección anterior y hay que rehacerlos.

## Objetivo

Definir cómo se ve y cómo se navega la app web de PremiumFitness (PWA Angular para clientes y entrenadores) antes de codificar: sistema de diseño, navegación, pantallas y sus estados. También se definen los cambios del admin respecto a radical.

## Alcance

- **App web:** sistema de diseño (tokens, tipografía, componentes), navegación por rol, pantallas del MVP y sus estados, accesibilidad, PWA y textos.
- **Admin:** qué se hereda de `admin-radical-nutrition` y qué pantallas se añaden.
- **Mockups** navegables de las pantallas clave en móvil.

## Fuera de alcance

- La app de dietas (Flutter, externa).
- Las pantallas de dietas dentro de la app web: solo hay un acceso que abre la app de dietas.
- Las fotografías y textos finales del catálogo.

## Referencias

| Proyecto | Qué se toma |
| --- | --- |
| `dietas_automaticas_flutter` (Flutter, app de dietas) | **Fuente del sistema de diseño** desde el 11 nov: tokens (`lib/config/nutramerican.dart`), tema claro y oscuro (`lib/config/app_theme.dart`), widgets compartidos y las pruebas de capturas `ux`. Logos en `assets/images/` y Montserrat en `assets/fonts/montserrat/`. |
| `gifts.nutramerican.com` (Angular 22 + Tailwind 4) | Solo los **nombres** de los tokens (roles de Material 3 en `:root` y `html.dark`, `@custom-variant dark`) y la estructura `core/` y `features/`. Sus valores, Poppins y el dorado como relleno ya no se usan. |
| `frontend-radical-nutrition` | Galería de producto con Swiper (skill `product-lightbox`). |
| `join.nutramerican.com` | Experiencia de comisiones y onboarding, y el service worker de la PWA. |
| `admin-radical-nutrition` | Base del admin (fork): Tailwind 4 y ApexCharts. |

## Dirección visual: la de la app de dietas

**Revisión del 11 nov 2026:** reemplaza «negro y marfil, editorial» (10 nov). El frontend toma el sistema de diseño de la **app de dietas** (Flutter, repositorio `dietas_automaticas_flutter`), que es el sistema **Nutramerican Pharma** aplicado sobre Material 3. Las fuentes analizadas son `lib/config/nutramerican.dart` (tokens), `lib/config/app_theme.dart` (tema claro y oscuro, móvil y escritorio) y las capturas de `test/ux_movil_test.dart`.

**Idea:** una interfaz plana y clara. Tarjetas opacas con borde fino sobre un fondo de un solo color, botones en píldora, campos de 12 px y un único color de acento. Sin degradados, sin vidrio, sin líneas horizontales y casi sin sombras. En claro, azul sobre blanco y gris; en oscuro, azul marino con el oro de la marca como acento.

**Los dos temas:**

| | Claro | Oscuro |
| --- | --- | --- |
| Fondo | Gris casi blanco `#f5f7fa` | Azul marino `#0b1220` |
| Tarjetas | Blanco, con borde `#e4e7ec` | Marino de panel `#131c2e`, con borde `#303847` |
| Campos | Blanco, borde `#91959e` (corregido, S-12) | Marino profundo `#070c16`, borde `#636975` (corregido, S-12) |
| Acento (por defecto) | Azul Nutramerican `#0071e3` | Oro Nutramerican `#f2c94c` |
| Texto del botón principal | Blanco | Tinta `#0b0c0f` |
| Logo | Negro | Dorado |

**Reglas de uso (heredadas de la app de dietas):**

1. **Tema plano.** El fondo es de un solo color y los paneles son `Card` opacas con borde fino de 1 px. El rol `surface` es el color del fondo.
2. **Un solo acento, elegible.** Tiñe el botón principal, la selección, los enlaces, el foco y los avatares, y cada persona lo elige en Cuenta (ver «Acento»). `tertiary` repite a `primary`: no hay segundo color de marca. El único rojo de marca es el del badge de descuento.
3. **Sin líneas horizontales.** Los divisores del tema son transparentes, la pestaña activa va en una píldora y el antetítulo no lleva filete. Filas y bloques se separan por el aire.
4. **Estados con texto o icono.** Éxito, aviso, información y error nunca van solo en color, y no se usan como series de un gráfico.
5. **Sin colores sueltos.** Las pantallas usan tokens; nada de hexadecimales ni `Colors.*`.
6. **Contraste calculado.** El tema ajusta el acento, los estados y los textos hasta llegar a 4,5:1 sobre el fondo en el que se escriben, con cualquier acento.

### Tokens de color

Los **nombres** siguen siendo los de `gifts` (roles de Material 3 en minúsculas con guiones, en `:root` y `html.dark`), para compartir componentes entre proyectos. Los **valores** son los del tema de la app de dietas con el acento por defecto, resueltos por el propio tema. La columna «Rol Flutter» permite pasar de un lado al otro.

| Token | Claro | Oscuro | Rol Flutter | Uso |
| --- | --- | --- | --- | --- |
| `--background` | `#f5f7fa` | `#0b1220` | `surface` | Fondo de la app |
| `--surface` | `#ffffff` | `#131c2e` | `cardTheme.color` | Tarjetas, listas y barras |
| `--surface-container-lowest` | `#ffffff` | `#070c16` | `surfaceContainerLowest` | Relleno de campos |
| `--surface-container-high` | `#edeff3` | `#1a2540` | `surfaceContainerHigh` | Diálogos, menús y chips sin seleccionar |
| `--surface-container-highest` | `#e4e7ec` | `#364057` | `surfaceContainerHighest` | Pistas de progreso e interruptores |
| `--on-surface` | `#0b0c0f` | `#ffffff` | `onSurface` | Texto principal |
| `--on-surface-variant` | `#626875` | `#c9ced9` | `onSurfaceVariant` | Texto secundario |
| `--outline` | `#808182` | `#8a8e94` | `outline` | Bordes de botones de contorno y casillas |
| `--outline-variant` | `#e4e7ec` | `#303847` | `outlineVariant` | Borde de tarjetas |
| `--field-border` | `#91959e` | `#636975` | `lineaControl`, corregido | Borde de los campos en reposo, a 3:1 (S-12) |
| `--primary` | `#0066cf` | `#f2c94c` | `primary`, corregido | Enlaces, texto e iconos de acento, foco (S-12) |
| `--on-primary` | `#ffffff` | `#0b0c0f` | `onPrimary` | Texto sobre `--primary` |
| `--primary-container` | `#e0edf9` | `#3b3622` | `primaryContainer` | Pestaña activa, avatares y selección |
| `--action` | `#0071e3` | `#f2c94c` | `TemaMarca.accion` | Relleno del botón principal |
| `--on-action` | `#ffffff` | `#0b0c0f` | `TemaMarca.sobreAccion` | Texto del botón principal |
| `--error` | `#b3261e` | `#e76157` | `error` | Errores |
| `--error-container` | `#f6e5e4` | `#381f24` | `errorContainer` | Aviso de error |
| `--discount` | `#d00b14` | `#f53d45` | Sin equivalente | Texto del badge de descuento, a 4,5:1 |
| `--discount-container` | `#fde2e3` | `#3b0c16` | Sin equivalente | Fondo del badge: el rojo de Nutramerican (`#f20d17`) al 12% en claro y al 22% en oscuro |
| `--success` | `#1d7a4d` | `#269f64` | `AppSemanticColors.success` | Estados positivos |
| `--warning` | `#9e6400` | `#c77d00` | `AppSemanticColors.warning` | Puntos por vencer y avisos |
| `--info` | `#006ede` | `#388fe9` | `AppSemanticColors.info` | Información |

La lista completa (61 colores, con `secondary`, `inverse-*`, los contenedores de estado y las seis series de gráficos) está en el sistema de diseño de Claude Design. Se retiran `--gold-line` y `--sale` de la propuesta anterior. Los valores marcados «corregido» difieren de la app de dietas (S-12).

**Temas:**

- Claro, oscuro y sistema. Por defecto se sigue el del sistema (`prefers-color-scheme`) y la persona puede cambiarlo en Cuenta. La elección se guarda en `localStorage`.
- El tema se aplica con la clase `html.dark`, como en gifts.
- Si el sistema no indica preferencia, el predeterminado es el oscuro.

**Acento elegible (S-10, confirmado el 11 nov 2026):** cada persona elige su color de acento en **Cuenta**, como en la app de dietas: seis colores (Nutramerican, azul, esmeralda, índigo, cereza y océano) y uno personalizado con el selector de color. El de por defecto es azul en claro y oro en oscuro.

- **Qué cambia:** solo `primary`, `on-primary`, `primary-container`, `secondary-container`, `tertiary`, `action`, `on-action`, `action-hover`, `inverse-primary` y `outlined-foreground`. Éxito, aviso, información, error y descuento no cambian.
- **Legibilidad:** el tema deriva esos tokens del color elegido y lo ajusta hasta 4,5:1 sobre el fondo y sobre su contenedor; el texto del botón es tinta o blanco, el que más contraste dé. El sistema de diseño trae la función (`accent.derive`), validada contra los 256 valores de la app de dietas.
- **Guardado:** en el dispositivo, como el tema (`localStorage`): el id si es de la lista y `#RRGGBB` si es personalizado. Si lo guardado ya no existe, se usa el de la marca.
- **Pruebas:** una por acento y modo, también con colores extremos, como `test/tema_test.dart` de la app de dietas.

### Tipografía

Dos variantes, como en la app de dietas:

| Variante | Familia | Pesos | Dónde |
| --- | --- | --- | --- |
| **Móvil** | La del sistema (`system-ui`: Roboto en Android, San Francisco en iOS) | 400 en texto; 600 en títulos, cifras y énfasis. Nunca más de 600 | **Toda la app web (PWA).** Es la de la app móvil de dietas |
| **Escritorio** | Montserrat, la única familia de Nutramerican | 400 a 900; 800 y 900 en titulares y cifras | Solo el admin, si se adopta (S-05) |

Montserrat en 800 y 900 se veía pesada y partía los textos en el teléfono: por eso la web usa la variante móvil en todos los anchos.

| Estilo (variante móvil) | Tamaño / interlineado | Peso | Uso |
| --- | --- | --- | --- |
| `headline-medium` | 28 / 36 | 600 | Cifra grande de saldo (puntos y comisión) |
| `headline-small` | 24 / 32 | 600 | Título de pantalla |
| `title-large` | 22 / 28 | 600 | Título de hoja y de diálogo |
| `title-medium` | 16 / 24 | 600 | Título de tarjeta y de fila |
| `title-small` | 14 / 20 | 600 | Pestañas y subtítulos |
| `body-large` | 16 / 24 | 400 | Texto de lectura y valor de los campos |
| `body-medium` | 14 / 20 | 400 | Texto general |
| `body-small` | 12 / 16 | 400 | Texto secundario y ayudas |
| `label-large` | 14 / 20 | 600 | Etiquetas |
| botón | 13 / 18 (+0,2 de interletrado) | 600 | Rótulo de botón, en mayúsculas |
| antetítulo | 12 / 16 (+0,8 de interletrado) | 600 | Título de sección, en mayúsculas y en el acento |
| `label-small` | 11 / 16 | 500 | Etiquetas de la barra inferior |

Las cifras llevan números tabulares.

### Espaciado, formas y elevación

- **Espaciado:** escala del sistema (5,6, 8, 11,2, 12, 14,4, 17,6, 21,6, 24, 32, 40 y 60 px). En pantallas móviles dominan 8, 12, 16 y 24. Margen lateral de 16 px; altura de controles de 52 px; formularios de 440 px como máximo.
- **Radios:** 12 px en campos, 19,2 px en tarjetas, 24 px en diálogos, y **píldora** (999 px) en botones, chips y pestañas.
- **Elevación:** ninguna en reposo. Las capas se separan con superficies y bordes de 1 px. El botón relleno sube a 1 en hover; el diálogo lleva elevación 3.
- **Movimiento:** una sola transición, de 180 ms con `ease`. La onda al pulsar (`InkRipple`) es la confirmación del toque. Con `prefers-reduced-motion` no hay animaciones. Las capas de estado son de 8 % (hover), 10 % (foco) y 12 % (pulsado).

### Componentes

**Heredados de la app de dietas:**

| Componente | Descripción |
| --- | --- |
| Botón principal | Relleno `--action`, píldora de 52 px, rótulo en mayúsculas 13/600. Uno por pantalla. |
| Botón secundario y de texto | Contorno de 1 px (`--outline`) o solo texto en `--primary`, también en píldora. |
| Campo de texto | Relleno `--surface-container-lowest`, borde `--field-border` de 1 px, radio de 12 px y 52 px de alto. En foco, borde de 2 px en `--primary`. El rótulo flota a 12/700. |
| Tarjeta (`Modulo`) | `--surface`, radio de 19,2 px, borde de 1 px `--outline-variant`, relleno de 16 px en móvil. |
| Antetítulo (`Kicker`) | Título de sección en mayúsculas, 12/600, en el acento. Sin filete. |
| Fila con avatar | Círculo `--primary-container` con las iniciales, título, dos líneas de detalle y chevron. |
| Barra inferior | `NavigationBar` de Material 3: ícono con trazo, relleno al estar activo, píldora `--primary-container` como indicador y rótulo de 11 px. |
| Riel lateral | `NavigationRail` desde 600 px, con la misma píldora. |
| Chip | Píldora con borde de 1 px; seleccionado, en `--primary-container`. |
| Pestañas y botones segmentados | Píldora activa en `--primary-container`, pestañas 14/600. |
| Aviso de error | `--error-container`, ícono de error y texto `--on-surface`, radio de 12 px. |
| Hoja inferior y diálogo | Hoja de 560 px como máximo con `SafeArea` inferior; diálogo de 24 px de radio sobre `--surface-container-high` en oscuro. |
| Selector de acento | Seis colores y «Personalizado», en píldoras de 52 px; el elegido lleva borde de 2 px y un visto. Vive en Cuenta, junto al tema. |
| Toast | Flotante, `--inverse-surface` y radio de 12 px. |
| Tabla de datos | Cabecera en `--surface-container`, filas de 34 a 40 px (admin). |

**Añadidos por PremiumFitness** (la app de dietas no los tiene; se construyen con las mismas piezas):

| Componente | Descripción |
| --- | --- |
| Tarjeta de saldo | Tarjeta con antetítulo («PUNTOS VIGENTES»), cifra `headline-medium` en 600, línea de detalle («12.000 vencen el 3 dic») y acción opcional. Sustituye a la «tarjeta de socio» negra. |
| Bloque de precio | **Regla 14:** chip rojo tonal `−10%` (texto `--discount` sobre `--discount-container`; el rojo de Nutramerican con opacidad), precio con descuento y precio completo tachado al lado. Sin entrenador asociado, solo el precio completo (S-09). |
| Tarjeta de producto | Imagen cuadrada WebP sobre `--surface-container-high`, nombre de dos líneas, bloque de precio y botón «Agregar». |
| Fila de movimiento | Título, fecha y cifra a la derecha (`+` y `−`). Sin filetes. |
| Chip de estado | Pedido, lote y referido. Siempre con icono y texto, en los colores de estado. |
| Tarjeta de enlace | Enlace del entrenador con «Copiar», «WhatsApp», «Compartir» y «Ver QR». |
| Esqueletos y estado vacío | Forma de la tarjeta en `--surface-container-high`, sin spinners; una frase y una acción, sin ilustraciones. |
| Guía de instalación iOS | Spec `integraciones/01`. |

## Navegación

**Compacto** (< 600 px): barra inferior con 4 o 5 destinos. **Medio y expandido** (≥ 600 px): riel lateral con los mismos destinos; desde 840 px, listas y detalle una al lado de la otra. Son los cortes de la app de dietas (`kAnchoTableta` y `kAnchoListaYFicha`); los destinos mantienen su estado al cambiar de uno a otro.

| Rol | Destinos de la barra inferior |
| --- | --- |
| Cliente | Tienda · Pedidos · Carrito · Cuenta |
| Entrenador | Inicio · Tienda · Referidos · Puntos · Cuenta |

El carrito del entrenador se abre desde la Tienda, y el acceso a la app de dietas está en Inicio.

## Pantallas

### Acceso (común)

| Pantalla | Contenido | Estados |
| --- | --- | --- |
| Login | Logo, celular, contraseña, «Olvidé mi contraseña» y «Crear cuenta». Si se llegó por un enlace, aparece «Te invitó: <nombre del entrenador>». | Error de credenciales, cuenta pendiente de verificar (lleva a verificación) y bloqueo por intentos (429). |
| Registro | Nombre completo, tipo y número de documento, celular, correo y contraseña. Al llegar por `/r/t/<id>`, el rol es entrenador y se elige el cupón (validando que esté libre). | Celular ya registrado y cupón ocupado. |
| Verificación | Seis casillas para el código, reenvío con cuenta regresiva y celular enmascarado. | Código inválido, intentos agotados y reenvío bloqueado. |
| Recuperación | Celular → código → nueva contraseña. | Igual que la verificación. |
| Enlace no válido | Mensaje y acceso al login sin entrenador. | Entrenador inactivo o id inválido. |

### Comunes (cliente y entrenador)

| Pantalla | Contenido |
| --- | --- |
| Tienda | Buscador, categorías en chips y cuadrícula de tarjetas de producto (2 columnas en móvil). Los precios ya llegan según el rol desde el backend. |
| Detalle de producto | Galería (Swiper con zoom), presentaciones del grupo (225 g / 900 g), bloque de precio, descripción, uso, tabla nutricional y botón «Agregar». |
| Carrito | Líneas con cantidad, precio con descuento y completo, subtotal, descuento, envío e IVA incluido. Total destacado. |
| Checkout | Dirección (las de `addresses`), resumen y método de pago. Botón «Pagar». |
| Resultado del pago | Aprobado (número de pedido), pendiente (PSE o Addi: «te avisaremos») o rechazado (reintentar). |
| Pedidos | Lista con estado, fecha y total. |
| Detalle del pedido | Línea de tiempo del estado (`status_orders`), transportadora y guía con enlace de rastreo, y productos. |
| Cuenta | Perfil, direcciones, notificaciones (activar push o guía iOS), apariencia (tema claro, oscuro o del sistema, y color de acento), instalar la app y cerrar sesión. |

### Cliente

| Pantalla | Contenido |
| --- | --- |
| Asociar cupón | Solo para un cliente sin entrenador (regla 13). Campo del cupón y explicación del beneficio («10% en todo el catálogo»). Una vez asociado, la pantalla desaparece. |
| Tienda sin entrenador | Precios completos y una tarjeta discreta: «¿Tienes el cupón de tu entrenador?». |

### Entrenador

| Pantalla | Contenido | Spec |
| --- | --- | --- |
| Inicio | Tarjeta de saldo: puntos vigentes (cifra grande) y próximos vencimientos. Debajo, puntos por clientes (acumulado y vigentes), puntos por referidos (vigentes y pendientes), comisión del mes y fecha del cierre, últimos movimientos y acceso a dietas con su estado. | `negocio/03`, `integraciones/02` |
| Referidos | Pestañas «Mis clientes» y «Entrenadores». Tarjeta de enlace para clientes (`/r/c/`) y para entrenadores (`/r/t/`), con QR. En «Entrenadores», cada B aparece con su estado: pendiente, activo con remanente, usado o vencido. | `negocio/01`, `negocio/02` |
| Puntos | Saldo vigente, filtros por origen (clientes, referidos, conversión), lista de movimientos y detalle de lote (origen, fecha, inicial, remanente y vencimiento). Botón «Canjear productos». | `negocio/03` |
| Comisión | Saldo del mes sin liquidar, fecha del corte, abonos por pedido, conversiones y liquidaciones anteriores con su estado (liquidada o pagada) y «Ver comprobante». Botón «Convertir a puntos». | `negocio/04` |
| Conversión | Hoja inferior: comisión, puntos vigentes, adicional (con el tope), puntos que recibirá, saldo resultante y vencimiento, más el aviso de que esa comisión no se pagará en dinero. Botón «Convertir». | `negocio/04` |
| Canje | Tienda en modo canje: precios completos **en puntos**, saldo arriba y hoja de confirmación con el saldo antes y después. No se mezcla con dinero. | CU-12 |
| Compra propia | La misma tienda con su 25%, marcada como «Precio entrenador». | CU-13 |

## Textos y formatos

- **Tono:** español de Colombia, tuteo, frases cortas. Se nombran las cosas como en el negocio: «puntos», «comisión», «cupón», «enlace». Nunca «bono».
- **Botones y antetítulos:** rótulos de botón en MAYÚSCULAS (13/600), como en la app de dietas («AGREGAR», «CONVERTIR»); el resto del texto, en minúsculas.
- **Dinero:** `$224.910`, sin decimales en pantalla (los decimales solo en reportes).
- **Puntos:** `11.246 pts`.
- **Fechas:** `9 nov 2026` y, en listas, «hoy» o «ayer».
- **Errores:** dicen qué pasó y qué hacer («El código venció. Pide uno nuevo»), sin códigos técnicos.

## Accesibilidad

- Contraste AA (4,5:1) en todos los textos de los dos temas y con cualquier acento: el tema ajusta el acento y los estados para lograrlo. El oro (`#f2c94c`) solo se usa sobre fondos oscuros (da 1,6:1 sobre blanco). **Dos pares de la app de dietas no llegaban** y aquí van corregidos (S-12): el borde de los campos en reposo (1,8:1 en claro y 2,3:1 en oscuro; ahora 3:1) y el rótulo de la pestaña activa (4,0 a 4,4:1; ahora 4,5:1 con cualquier acento).
- Áreas táctiles de al menos 44 × 44 px.
- Foco visible: borde de 2 px en el acento en los campos y capa de estado de 10 % en botones y filas.
- Etiquetas en todos los campos, y errores asociados con `aria-describedby`.
- Los estados nunca dependen solo del color: chips con icono y texto.
- Se respeta `prefers-reduced-motion`.

## PWA y rendimiento

- Manifest con `display: standalone`, `name` «PremiumFitness», `theme_color` `#0b1220` (oscuro) y `#f5f7fa` (claro, con `media` en la metaetiqueta), iconos de 192 y 512 px, `apple-touch-icon` de 180 px y `maskable`.
- Service worker de Angular para la carga y `firebase-messaging-sw.js` para push (spec `integraciones/01`).
- Imágenes WebP con tamaños responsivos y carga diferida (skill `image-webp-optimizer`). `NgOptimizedImage` en las tarjetas.
- El área del entrenador se carga aparte (spec `arquitectura/02`).

## Admin (fork de `admin-radical-nutrition`)

- **Se hereda tal cual:** el diseño y las pantallas de radical (pedidos, toma y liberación por agente, SAP, guía, estados, reportes con ApexCharts, catálogo, productos y SAP).
- **Se actualiza:** Angular 22, el logo y los tokens de color de PremiumFitness. El admin usa el tema claro por defecto porque es una herramienta de oficina.
- **Pantallas nuevas:**
  - **Cierre mensual:** períodos, totales, descarga del Excel (solo con sesión), reenvío del correo y, por cada liquidación, **registrar el pago** con fecha, referencia y comprobante (spec `negocio/05` y `negocio/04`).
  - **Entrenadores:** lista, detalle con saldos (spec `negocio/03`) y referidos.
  - **Estrategias por producto:** `product_discount_rules`, con vigencia.
  - **Ajustes:** cancelaciones y devoluciones con su efecto en puntos y comisión (CU-13).
- **Se quita:** las pantallas de la tienda pública y del saldo a favor retail, si existen en el fork.

## Sistema de diseño en Claude Design

**Enlace:** https://claude.ai/artifact/UQfMn8AZZV7xsKVTvzxsWB (privado; para que otros lo vean hay que compartirlo desde el menú Share).

Se generó el 11 nov 2026 a partir de `dietas_automaticas_flutter` y deja listo lo que el frontend necesita:

- **Tokens** de los dos temas a partir de los valores que resuelve `AppTheme.lightMovil()` y `darkMovil()`, con las correcciones de S-12 (61 colores, dos variantes de tipografía, espaciado, radios, sombras, medidas de diseño y transiciones), con el uso de cada uno.
- **21 componentes** con vista previa en claro y oscuro y guía de uso: los de la app de dietas (botón, campo, tarjeta, fila, chip, avisos, barras de navegación, pestañas, hojas, tabla) y los añadidos de PremiumFitness (tarjeta de saldo, bloque de precio, tarjeta de producto, fila de movimiento, tarjeta de enlace, chip de estado).
- **Guías:** marca y contenido, temas y paso a Angular con Tailwind 4 (CSS listo para `:root` y `html.dark`), navegación y pantallas, y dinero, puntos y estados.
- **Activos:** logos de Nutramerican Pharma (negro, dorado, blanco e ícono) y Montserrat con su licencia.

Incluye el selector de acento, el badge de descuento rojo tonal y las correcciones de contraste (S-06, S-09, S-10 y S-12). Queda por resolver S-05 y rehacer los mockups con este sistema.

## Mockups

**Obsoletos desde el 11 nov 2026:** los mockups siguientes siguen la dirección anterior (negro y marfil, Bodoni Moda) y hay que rehacerlos con el sistema de la app de dietas (ver checklist).

Canvas navegable con las pantallas clave en móvil, en tema oscuro, y la tienda, el inicio del entrenador y la conversión también en tema claro: login, tienda con descuentos, detalle de producto, inicio del entrenador, conversión, referidos y detalle del pedido.

**Enlace:** https://claude.ai/artifact/U2Hf5mziwyKnoJjzr35j3m (privado; para que otros lo vean hay que compartirlo desde el menú Share).

Los datos de Bipro (249.900 → 224.910) y de la conversión (100.000 + 100.000 → 250.000) salen de las reglas de negocio. Los demás productos, precios y nombres son ilustrativos.

## Criterios de aceptación

1. Los tokens tienen los mismos nombres que gifts, y la app funciona en tema claro y oscuro.
2. El bloque de precio cumple la regla 14: badge, precio con descuento y precio completo tachado. Sin entrenador, solo el precio completo.
3. El acento es un solo color (azul en claro y oro en oscuro por defecto, o el que elija la persona) y no hay degradados, líneas horizontales ni colores sueltos fuera de los tokens. El único rojo de marca es el del badge de descuento.
4. Cada pantalla del MVP (§4) tiene definidos sus estados: carga, vacío y error.
5. Contraste AA verificado en ambos temas, áreas táctiles de 44 px y foco visible.
6. Los mockups de las pantallas clave están aprobados antes de codificar.
7. Con cada acento de la lista y con colores personalizados extremos, en claro y oscuro: texto a 4,5:1, `primary` a 4,5:1 sobre `primary-container`, texto del botón a 4,5:1 sobre `action` y borde de campos a 3:1. La elección se conserva al recargar y al cambiar de tema.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| S-01 | Identidad de Nutramerican (logos y paleta), con el nombre PremiumFitness. Desde el 11 nov la tipografía ya no es Poppins (S-05). | Confirmado (10 nov 2026) |
| S-02 | Dirección visual de la app de dietas: plana, con un solo acento (azul en claro, oro sobre marino en oscuro). **Reemplaza** la dirección editorial en negro y marfil confirmada el 10 nov, por petición del producto. | Confirmado (11 nov 2026) |
| S-03 | El admin es un fork de `admin-radical-nutrition`. | Confirmado (10 nov 2026) |
| S-04 | El tema oscuro es el predeterminado si el sistema no indica preferencia; el admin usa el claro. | Derivado |
| S-05 | La web usa la variante móvil de la app de dietas en todos los anchos: letra del sistema con pesos de hasta 600. Montserrat queda para el logo y, si se adopta, el admin. Reemplaza a Bodoni Moda y Poppins. | Derivado: requiere aprobación |
| S-06 | La marca es la palabra PremiumFitness con el logo de Nutramerican («by Nutramerican»); no hay logo propio. | Confirmado (11 nov 2026) |
| S-07 | Fotografías y textos reales del catálogo. | Pendiente: los aporta el negocio |
| S-08 | Cortes de ancho de la app de dietas: barra inferior por debajo de 600 px, riel lateral desde 600 px y lista con detalle desde 840 px. | Derivado |
| S-09 | El badge de descuento (regla 14) es rojo con opacidad: el rojo de Nutramerican al 12% (claro) o 22% (oscuro) como fondo y su tono legible como texto. La app de dietas no tiene color de descuento. | Confirmado (11 nov 2026) |
| S-10 | El selector de acento (seis colores más uno personalizado) entra en el MVP, en Cuenta, como en la app de dietas. Reemplaza la propuesta de dejarlo fuera. | Confirmado (11 nov 2026) |
| S-11 | Los rótulos de botón van en mayúsculas, como en la app de dietas. | Derivado |
| S-12 | La web usa los valores corregidos de dos pares de la app de dietas que no llegan a los mínimos (borde de campos a 3:1 y pestaña activa a 4,5:1), y se pide la misma corrección a esa app para que ambas coincidan. | Derivado: el pedido de la corrección salió de la petición del 11 nov |

## Checklist

- [x] Revisar los frontends de referencia (gifts, radical, admin-radical y join)
- [x] Definir la dirección visual, los tokens, la tipografía y los componentes (dirección del 10 nov, reemplazada el 11 nov)
- [x] Definir la navegación por rol y las pantallas con sus estados
- [x] Definir los cambios del admin respecto a radical
- [x] Publicar los mockups de las pantallas clave y enlazarlos aquí (con la dirección del 10 nov: obsoletos)
- [x] Analizar el sistema de diseño de la app de dietas en los dos temas: tokens, tema claro y oscuro con valores resueltos, tipografía, componentes y capturas
- [x] Generar el sistema de diseño en Claude Design y enlazarlo aquí (11 nov 2026)
- [ ] Rehacer los mockups con el sistema de la app de dietas
- [ ] Aprobar los mockups y resolver S-05
- [ ] Que la app de dietas aplique las dos correcciones de contraste (S-12)

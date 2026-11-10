# 02 — Diseño del frontend: app web y admin

**Estado:** en curso · **Creado:** 10 de noviembre de 2026

## Petición original

> Ahora vamos a especificar el diseño del frontend.
>
> Decisiones (10 nov 2026):
> - Usar la identidad de Nutramerican.
> - Dirección visual: premium sobria.
> - El admin es un fork de `admin-radical-nutrition`.
> - Entregable: spec más mockups.

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
| `gifts.nutramerican.com` (Angular 22 + Tailwind 4) | Tokens CSS con nombres de Material 3 en `:root` y `html.dark`, la paleta de Nutramerican, Poppins y el logo en dorado y negro (`public/images/logo-dorado.webp` y `logo-negro.webp`). Estructura `core/` y `features/`. |
| `frontend-radical-nutrition` | Galería de producto con Swiper (skill `product-lightbox`). |
| `join.nutramerican.com` | Experiencia de comisiones y onboarding, y el service worker de la PWA. |
| `admin-radical-nutrition` | Base del admin (fork): Tailwind 4 y ApexCharts. |

## Dirección visual: negro y marfil, editorial

**Revisión del 10 nov 2026:** la primera propuesta usaba el dorado como relleno (botones, cifras, chips) y producto la encontró recargada. Esta versión lo reduce a filetes y detalles.

**Idea:** una tienda de suplementos con el tono de una revista o de una marca de lujo. Negro profundo y marfil, mucho espacio, líneas finas y cifras grandes en una serif de alto contraste. El color casi no aparece: la elegancia viene de la tipografía, el espacio y las proporciones.

**Lo memorable:** la «tarjeta de socio» del entrenador. Es negra en los dos temas, con un filete dorado de 1 px y el saldo de puntos en Bodoni Moda, grande y en marfil.

**Reglas de uso del color:**

1. **Marfil y negro** hacen todo el trabajo: fondo, texto y botón principal (marfil sobre negro en oscuro, negro sobre marfil en claro).
2. **Dorado:** **solo** el logo, el filete de 1 px de la tarjeta de socio y el punto de la navegación activa. Nunca rellenos, botones ni textos.
3. **Rojo de Nutramerican**, más profundo: **solo** el badge de descuento del catálogo (regla 14), pequeño y de esquinas casi rectas. Para errores hay otro rojo, el de `--error`.
4. **Verde y ámbar:** solo en estados (entregado, puntos por vencer) y siempre acompañados de texto.

## Sistema de diseño

### Tokens de color

Se usan **los mismos nombres de tokens que gifts**, para compartir componentes entre proyectos. Tailwind 4 los expone en `@theme`.

| Token | Claro | Oscuro (por defecto) | Uso |
| --- | --- | --- | --- |
| `--background` | `#f7f4ef` | `#0c0c0c` | Fondo de la app |
| `--surface` | `#ffffff` | `#151515` | Tarjetas y hojas |
| `--surface-container` | `#efebe5` | `#1b1a19` | Fondos de imagen y campos |
| `--on-surface` | `#121212` | `#f2ece3` | Texto principal (marfil en oscuro) |
| `--on-surface-variant` | `#5f5952` | `#a8a097` | Texto secundario |
| `--outline-variant` | `#e4ded5` | `#2a2826` | Filetes y bordes |
| `--primary` | `#121212` | `#f2ece3` | Botón principal y navegación activa |
| `--on-primary` | `#f7f4ef` | `#0c0c0c` | Texto del botón principal |
| `--gold-line` | `#b8973e` | `#b8973e` | Filete de la tarjeta de socio y punto de la navegación activa |
| `--sale` | `#a12d27` | `#a12d27` | Fondo del badge de descuento, con texto blanco |
| `--selection` | `#15803d` | `#4ade80` | Estados positivos |
| `--warning` | `#a16207` | `#e0b252` | Puntos por vencer y avisos |
| `--error` | `#c62828` | `#ff6b6b` | Errores |

**Temas:**

- El tema oscuro es el predeterminado si el sistema no indica preferencia.
- Se respeta `prefers-color-scheme`, y el usuario puede cambiarlo en Cuenta. La elección se guarda en `localStorage`.
- El tema se aplica con la clase `html.dark`, como en gifts.

### Tipografía

| Rol | Fuente | Uso |
| --- | --- | --- |
| Cifras y títulos | **Bodoni Moda** 400 y 500 (serif de alto contraste) | Saldo de puntos, comisión, precio del detalle de producto y título de cada pantalla |
| Interfaz y texto | **Poppins** 400, 500 y 600 (la de Nutramerican) | Todo lo demás |
| Etiquetas | Poppins 500, 11 px, mayúsculas, espaciado de 0,16 em | «PUNTOS VIGENTES», «COMISIÓN DEL MES» |

| Estilo | Tamaño |
| --- | --- |
| Cifra héroe | Bodoni Moda, 52/52 px |
| Título de pantalla | Bodoni Moda, 30/34 px |
| Título de sección | Poppins 600, 16/22 px |
| Texto | Poppins, 15/22 px |
| Texto secundario | Poppins, 13/18 px |

### Espaciado, formas y elevación

- **Espaciado:** escala de 4 px; el margen lateral en móvil es de 20 px.
- **Radios:**
  - 12 px en campos y botones;
  - 20 px en tarjetas;
  - 28 px en hojas inferiores.

  Son más contenidos que los de gifts (2–3 rem) para mantener el tono sobrio.
- **Elevación:** casi sin sombras. Las capas se separan con superficies y bordes finos (`--outline-variant`). Solo las hojas inferiores y los toasts tienen sombra suave.
- **Movimiento:**
  - entre 150 y 250 ms, con `ease-out`;
  - la cifra de puntos cuenta desde cero al entrar al dashboard, una sola vez;
  - con `prefers-reduced-motion` no hay animaciones.

### Componentes

| Componente | Descripción |
| --- | --- |
| Botón principal | Fondo `--primary` (marfil en oscuro, negro en claro), alto de 50 px y esquinas de 10 px. Uno por pantalla. |
| Botón secundario | Borde fino y fondo transparente. |
| Bloque de precio | **Regla 14:** badge pequeño `-10%` (o el porcentaje del producto) en `--sale`, precio con descuento y precio completo tachado al lado. Sin entrenador asociado, solo se ve el precio completo. |
| Tarjeta de producto | Imagen cuadrada WebP sobre `--surface-container`, nombre de dos líneas como máximo, bloque de precio y botón «Agregar». |
| Tarjeta de socio | Negra en ambos temas, con filete `--gold-line` de 1 px, etiqueta, cifra héroe en Bodoni Moda marfil y línea de detalle (por ejemplo, «12.000 vencen el 3 dic»). |
| Fila de movimiento | Título, fecha y cifra a la derecha (`+` y `−` en el color del texto), separadas por filetes. |
| Chip de estado | Pedido (aprobado, en proceso, enviado, entregado), lote (disponible, usado, vencido) y referido (pendiente, activo). Color e icono, nunca solo color. |
| Hoja inferior de confirmación | Para conversión, canje y cierre de sesión: resumen, aviso y botón de confirmar. |
| Tarjeta de enlace | Enlace del entrenador con botones «Copiar», «WhatsApp», «Compartir» (Web Share API) y «Ver QR». |
| Esqueletos | Mientras carga, la forma de la tarjeta en `--surface-container`, sin spinners. |
| Estado vacío | Una frase y una acción, sin ilustraciones genéricas. |
| Toast | Confirmaciones breves, abajo y por encima de la navegación. |
| Guía de instalación iOS | Spec `integraciones/01`: pasos de «Agregar a pantalla de inicio» cuando la app no está instalada en un iPhone. |

## Navegación

**Móvil:** barra inferior con 4 o 5 destinos. **Escritorio** (≥ 1024 px): barra superior con los mismos destinos y el contenido centrado a 1.200 px como máximo.

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
| Cuenta | Perfil, direcciones, notificaciones (activar push o guía iOS), tema, instalar la app y cerrar sesión. |

### Cliente

| Pantalla | Contenido |
| --- | --- |
| Asociar cupón | Solo para un cliente sin entrenador (regla 13). Campo del cupón y explicación del beneficio («10% en todo el catálogo»). Una vez asociado, la pantalla desaparece. |
| Tienda sin entrenador | Precios completos y una tarjeta discreta: «¿Tienes el cupón de tu entrenador?». |

### Entrenador

| Pantalla | Contenido | Spec |
| --- | --- | --- |
| Inicio | Tarjeta de socio: puntos vigentes (cifra héroe) y próximos vencimientos. Debajo, puntos por clientes (acumulado y vigentes), puntos por referidos (vigentes y pendientes), comisión del mes y fecha del cierre, últimos movimientos y acceso a dietas con su estado. | `negocio/03`, `integraciones/02` |
| Referidos | Pestañas «Mis clientes» y «Entrenadores». Tarjeta de enlace para clientes (`/r/c/`) y para entrenadores (`/r/t/`), con QR. En «Entrenadores», cada B aparece con su estado: pendiente, activo con remanente, usado o vencido. | `negocio/01`, `negocio/02` |
| Puntos | Saldo vigente, filtros por origen (clientes, referidos, conversión), lista de movimientos y detalle de lote (origen, fecha, inicial, remanente y vencimiento). Botón «Canjear productos». | `negocio/03` |
| Comisión | Saldo del mes sin liquidar, fecha del corte, abonos por pedido, conversiones y liquidaciones anteriores con su estado (liquidada o pagada) y «Ver comprobante». Botón «Convertir a puntos». | `negocio/04` |
| Conversión | Hoja inferior: comisión, puntos vigentes, adicional (con el tope), puntos que recibirá, saldo resultante y vencimiento, más el aviso de que esa comisión no se pagará en dinero. Botón «Convertir». | `negocio/04` |
| Canje | Tienda en modo canje: precios completos **en puntos**, saldo arriba y hoja de confirmación con el saldo antes y después. No se mezcla con dinero. | CU-12 |
| Compra propia | La misma tienda con su 25%, marcada como «Precio entrenador». | CU-13 |

## Textos y formatos

- **Tono:** español de Colombia, tuteo, frases cortas. Se nombran las cosas como en el negocio: «puntos», «comisión», «cupón», «enlace». Nunca «bono».
- **Dinero:** `$224.910`, sin decimales en pantalla (los decimales solo en reportes).
- **Puntos:** `11.246 pts`.
- **Fechas:** `9 nov 2026` y, en listas, «hoy» o «ayer».
- **Errores:** dicen qué pasó y qué hacer («El código venció. Pide uno nuevo»), sin códigos técnicos.

## Accesibilidad

- Contraste AA en todos los textos. El dorado no se usa en texto.
- Áreas táctiles de al menos 44 × 44 px.
- Foco visible de 2 px en el color del texto.
- Etiquetas en todos los campos, y errores asociados con `aria-describedby`.
- Los estados nunca dependen solo del color: chips con icono y texto.
- Se respeta `prefers-reduced-motion`.

## PWA y rendimiento

- Manifest con `display: standalone`, `name` «PremiumFitness», `theme_color` `#0f0f0f`, iconos de 192 y 512 px, `apple-touch-icon` de 180 px y `maskable`.
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

## Mockups

Canvas navegable con las pantallas clave en móvil, en tema oscuro, y la tienda, el inicio del entrenador y la conversión también en tema claro: login, tienda con descuentos, detalle de producto, inicio del entrenador, conversión, referidos y detalle del pedido.

**Enlace:** https://claude.ai/artifact/U2Hf5mziwyKnoJjzr35j3m (privado; para que otros lo vean hay que compartirlo desde el menú Share).

Los datos de Bipro (249.900 → 224.910) y de la conversión (100.000 + 100.000 → 250.000) salen de las reglas de negocio. Los demás productos, precios y nombres son ilustrativos.

## Criterios de aceptación

1. Los tokens tienen los mismos nombres que gifts, y la app funciona en tema claro y oscuro.
2. El bloque de precio cumple la regla 14: badge, precio con descuento y precio completo tachado. Sin entrenador, solo el precio completo.
3. El dorado aparece solo en el logo, el filete de la tarjeta de socio y el punto de la navegación activa.
4. Cada pantalla del MVP (§4) tiene definidos sus estados: carga, vacío y error.
5. Contraste AA verificado en ambos temas, áreas táctiles de 44 px y foco visible.
6. Los mockups de las pantallas clave están aprobados antes de codificar.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| S-01 | Identidad de Nutramerican (logo dorado y negro, paleta y Poppins), con el nombre PremiumFitness. | Confirmado (10 nov 2026) |
| S-02 | Dirección elegante y editorial: negro y marfil, dorado solo en filetes y logo, y rojo solo en el badge de descuento. Reemplaza la primera propuesta, que usaba el dorado como relleno. | Confirmado (10 nov 2026) |
| S-03 | El admin es un fork de `admin-radical-nutrition`. | Confirmado (10 nov 2026) |
| S-04 | El tema oscuro es el predeterminado si el sistema no indica preferencia; el admin usa el claro. | Derivado |
| S-05 | Bodoni Moda para cifras y títulos, con Poppins para la interfaz. Reemplaza a Michroma. | Derivado: requiere aprobación |
| S-06 | Cómo se muestra la marca: la palabra PremiumFitness con el logo de Nutramerican («by Nutramerican»), o un logo propio. | Pendiente |
| S-07 | Fotografías y textos reales del catálogo. | Pendiente: los aporta el negocio |

## Checklist

- [x] Revisar los frontends de referencia (gifts, radical, admin-radical y join)
- [x] Definir la dirección visual, los tokens, la tipografía y los componentes
- [x] Definir la navegación por rol y las pantallas con sus estados
- [x] Definir los cambios del admin respecto a radical
- [x] Publicar los mockups de las pantallas clave y enlazarlos aquí
- [ ] Aprobar los mockups y resolver S-05 y S-06

# 01 — Catálogo desde la tabla de productos de la intranet

**Estado:** diseño aprobado, sin implementar · **Creado:** 15 de noviembre de 2026

## Petición original

> Ya tengo una fuente de datos con los productos, que es `tb_productos_megaplexstars` de la base `crisenri_intranet`: es la tabla que alimenta la página web de la empresa. No creo que sea buena idea crear otra y tener varias fuentes de datos que mantener. En esta app los productos con el campo `promo = 1` no entran. El descuento es el que da la app, así que el descuento del producto tampoco se muestra. Se puede usar un patrón de diseño para armar una interfaz que devuelva siempre la misma estructura.

Precisiones de la misma conversación:

- La tabla tiene los campos `sap_code` y `sap_name`.
- El nombre del producto es el campo `producto`.
- Todos los productos tienen IVA del 19 %, el de Colombia, salvo Nutra C. El IVA se calcula al momento de la compra y se guarda en el pedido.
- Los combos tienen su propio código y nombre SAP. Todo lo que tenga `promo = 1` no entra.

## Objetivo

Que la app venda los productos del mismo maestro que usa la web de la empresa, sin una segunda tabla que haya que mantener a mano. Para quien programa la app, el catálogo debe ser siempre la misma estructura, venga de donde venga.

## Alcance

- Leer el catálogo de `crisenri_intranet.tb_productos_megaplexstars`, solo lectura.
- Una interfaz `ProductCatalog` que devuelve siempre objetos `CatalogProduct`, y un adaptador que lee la tabla de la intranet.
- Filtros del catálogo de la app: productos activos y sin promoción.
- No leer ni exponer el descuento ni el precio anterior del producto.
- Caché del listado y de la ficha, sin caché en carrito, checkout y canje.
- IVA por línea al comprar: 19 % por defecto y 0 % para los productos exentos.
- Copia en el pedido de los datos del producto en el momento de la compra.

El enfoque técnico, con el código de la interfaz, está en [01-catalogo-desde-la-intranet.plan.md](01-catalogo-desde-la-intranet.plan.md).

## Fuera de alcance

- Crear, editar o desactivar productos: se sigue haciendo desde la web de la empresa.
- Escribir en `tb_productos_megaplexstars` o en cualquier tabla de la intranet.
- Las reglas de descuento y comisión por producto (`product_discount_rules`, spec `negocio/01`), salvo su llave, que pasa a ser el código de la intranet.
- Las pantallas del catálogo (spec `diseño/02`).
- El inventario por bodega: lo sigue resolviendo SAP con `city_warehouses`.

## Criterios de aceptación

1. La base de PremiumFitness no tiene tabla de productos, categorías, grupos ni artículos SAP. El catálogo se lee de `tb_productos_megaplexstars`.
2. Ningún producto con `promo = 1` o `activo = 0` aparece en el listado, la ficha, el carrito, el checkout ni el canje.
3. Ninguna respuesta de la API incluye `descuento`, `old_price`, `sap_code` ni `sap_name`. Los datos de SAP solo los ve el admin.
4. El nombre que ve el usuario es el campo `producto`.
5. El checkout y el canje leen precio y disponibilidad de la fuente, sin caché, antes de cobrar o descontar puntos.
6. Cada línea del pedido guarda código, nombre, precio, `sap_code`, tasa de IVA, base e IVA. Si la web cambia el producto después, el pedido no cambia.
7. El IVA cuadra con los ejemplos: una línea de 119.000 tiene base 100.000 e IVA 19.000; una línea de Nutra C de 119.000 tiene base 119.000 e IVA 0.
8. Las consultas usan PDO con parámetros, el nombre de la base se valida al arrancar y el usuario de base de datos de PremiumFitness solo tiene `SELECT` sobre la tabla.
9. `composer run lint` y las pruebas pasan, con la tabla creada en la base de pruebas.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| C-01 | La fuente del catálogo es `crisenri_intranet.tb_productos_megaplexstars`. No se crea una tabla propia. | Confirmado (15 nov 2026) |
| C-02 | El nombre del producto es `producto`. | Confirmado (15 nov 2026) |
| C-03 | Los productos con `promo = 1` no entran. `promo` es `NOT NULL` con valor por defecto 0. | Confirmado (15 nov 2026) |
| C-04 | Solo entran los productos con `activo = 1` (la columna admite NULL), con nombre y con `valor` mayor que 0. | Derivado |
| C-05 | `descuento` y `old_price` no se leen: el descuento lo da la app. | Confirmado (15 nov 2026) |
| C-06 | `sap_code` y `sap_name` de la tabla reemplazan a `sap_products` y `product_sap_map` de radical. Cada producto, combos incluidos, tiene su propio artículo SAP. `sap_code` es `NOT NULL`: el valor 0 significa que no tiene artículo y llega como `sapCode` nulo. | Confirmado (15 nov 2026); el manejo del 0 es Derivado |
| C-07 | `grupo` reemplaza a `product_groups` y `product_group_map`: las presentaciones de un producto son las que comparten `grupo`. | Derivado |
| C-08 | `category` (texto) reemplaza a la tabla `categories`. | Derivado |
| C-09 | Todos los productos tienen IVA del 19 % salvo Nutra C, que no tiene. `valor` ya incluye el IVA. | Confirmado (15 nov 2026) |
| C-10 | La tasa es `VAT_PERCENTAGE` y los exentos se identifican por `codigo` en `VAT_EXEMPT_PRODUCT_CODES`, no por el nombre. La tabla de la intranet no tiene columna de IVA y no se le agrega. | Derivado |
| C-11 | Nutra C es el `codigo` 103040 (artículo SAP 103040): `VAT_EXEMPT_PRODUCT_CODES=103040`. Sus variantes con `promo = 1` («PROMO NUTRA-C…») no entran. | Confirmado (15 nov 2026) |
| C-12 | La estructura de la tabla se confirmó en la base con `SHOW COLUMNS` el 15 nov 2026 (42 columnas). Antes se había deducido del código que la lee (`nutramerican.com`, `melonn` y la skill `ficha-producto`). | Confirmado (15 nov 2026) |
| C-13 | PremiumFitness está en el mismo servidor MariaDB que la intranet y la lee con el nombre de la base delante, como `tb_empleados` (S-31 del DER). | Derivado |
| C-14 | El listado y la ficha se guardan en caché `PRODUCT_CATALOG_CACHE_SECONDS`. Un cambio de precio en la web tarda como máximo ese tiempo en verse, pero nunca afecta el cobro, que lee la fuente. | Derivado |
| C-15 | `agotado` llega como `isOutOfStock`. Mostrarlo marcado u ocultarlo se decide en las pantallas (spec `diseño/02`). Un producto agotado no se puede comprar ni canjear. | Pendiente (pantallas); no comprable es Derivado |
| C-17 | `activo`, `promo`, `agotado`, `nuevo` y `callcenter` son `bit(1)`. PDO las entrega como bytes (`"\x00"` o `"\x01"`) y en PHP `(bool) "\x00"` es verdadero, así que se leen como número (`agotado + 0`). | Derivado |
| C-16 | Los tramos por cantidad de radical (`products.discount_list`) no se usan: en PremiumFitness el descuento depende del rol. Resuelve S-30 del DER. | Derivado |

No hay contradicción con `docs/requerimientos.md`, que no dice de dónde sale el catálogo. Sobre el IVA, la regla 7 pide «discriminar el IVA de cada producto»: este spec lo cumple con la tasa por código.

## Checklist

- [x] Revisar la estructura de `tb_productos_megaplexstars` en los proyectos que la usan
- [x] Definir la interfaz, el adaptador, la caché y el IVA (plan)
- [x] Actualizar el DER a v0.15: catálogo externo, sin `products`, `categories`, `product_groups`, `product_group_map`, `sap_products` ni `product_sap_map`
- [x] Ajustar el spec `negocio/05` y los estándares de código al nuevo origen del IVA y del catálogo
- [x] Confirmar la estructura de la tabla en la base (C-12)
- [x] Obtener el código de Nutra C: 103040 (C-11)
- [ ] Crear el usuario de base de datos con `SELECT` sobre `tb_productos_megaplexstars`
  - Hecho en local (`scripts/reset-db.sh`), con una prueba de que la app no puede escribir en la tabla. Falta crearlo en el hosting al desplegar.
- [x] Validar `INTRANET_DB_NAME`, `PRODUCT_CATALOG_CACHE_SECONDS`, `VAT_PERCENTAGE` y `VAT_EXEMPT_PRODUCT_CODES` en `load/bootstrap.php`
- [x] Implementar `CatalogProduct`, `ProductImages` y `ProductContent`
  - `imageswebp` y `tablasjpg` son un JSON con una URL por tamaño (`small`, `medium`, `semiMedium`, `big`, `bigger` y `seo`), confirmado en el código de la web (`api_MegaplexStar`). La galería de la ficha son la imagen del producto y su tabla nutricional.
- [x] Implementar `IntranetProductCatalog`
- [x] Implementar `CachedProductCatalog`
- [x] Implementar `VatPolicy` con las pruebas de 119.000 y de Nutra C (`tests/Unit/DiscountRule/PricingTest.php`)
- [x] Crear la tabla en la base de pruebas con los tipos reales y probar los filtros (`promo = 1`, `activo = 0` o NULL, `valor` 0), las columnas `bit(1)` leídas como número, `sap_code = 0` y los campos ocultos (`tests/E2E/Product/CatalogApiTest.php`)
- [x] Endpoints del catálogo: listado, ficha y presentaciones
- [x] Copiar en `orders_items` código, nombre, precio, `sap_code` e IVA al comprar (`tests/E2E/Checkout/CheckoutTest.php`)
- [x] Retirar o adaptar al hacer el fork las features `Product`, `Category`, `ProductGroup` y `SapProduct` de radical

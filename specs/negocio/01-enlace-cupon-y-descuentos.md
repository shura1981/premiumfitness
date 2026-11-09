# 01 — Enlace del entrenador, cupón, descuentos configurables y base de los puntos

**Estado:** en curso · **Creado:** 9 de noviembre de 2026

## Petición original

> Lo del QR es realmente un enlace con el id del entrenador en base64; el entrenador puede compartir ese link en sus redes sociales o por WhatsApp, no necesariamente un QR. El cliente puede registrarse sin QR ni enlace y luego añadir el nombre del cupón. Si se registra con QR o enlace, todo el portafolio aparece con el descuento aplicado, con el badge del porcentaje, el valor con descuento y tachado el valor real.
>
> 1. El enlace lleva al login; si está registrado pasa el guard y va al dashboard de la app de cliente. Si el cliente sigue el link, se guarda el id del entrenador que referencia el cupón y ve el catálogo con el descuento.
> 2. Si el cliente ingresa por el link y se asocia el id del entrenador, ya tiene cupón asociado y no se muestra el input del cupón.
> 3. No se deben hardcodear los descuentos: el sistema debe soportar estrategias como dar a ciertos productos un descuento extra con el cupón y restar ese porcentaje de la comisión. Ejemplo: a Nutra Balance se le da un 5% más (15% de descuento) y la comisión del entrenador es solo del 10%.
> 4. El cliente puede comprar sin cupón.
> 5. El enlace que un entrenador comparte con otro entrenador va a otra app: entrenadores y clientes usan apps distintas (subdominios).
>
> Los puntos se calculan con IVA incluido. Solo se discrimina el IVA para la comisión.

## Objetivo

Alinear las reglas de negocio, los casos de uso y el modelo de datos con el funcionamiento real del referido: el enlace del entrenador, el cupón y los descuentos y comisiones configurables por producto. También se corrige la base de los puntos.

## Alcance

- `docs/reglas-de-negocio.md`: base de los puntos con IVA; enlace y cupón; descuentos configurables; apps separadas.
- `docs/casos-de-uso-y-actores.md`: reglas, CU-02, CU-03, CU-04, CU-05, CU-06 y CU-07; nuevo caso para asociar el cupón; decisiones pendientes.
- Las menciones de «puntos sin IVA» en el PRD, el MVP y las fases de desarrollo.
- El DER (`specs/diseño/01-DER.md` y `scripts/der/generar_der.py`):
  - un cupón por entrenador;
  - vínculo cliente → entrenador por enlace o por cupón;
  - reglas de descuento y comisión por producto;
  - comisión y puntos por línea;
  - atribución mediante `orders_coupons`.

## Fuera de alcance

- Redibujar `docs/img/reglas-de-negocio.png` y `.svg`, y el diagrama de actores. Queda en el checklist.
- Pantallas de las apps de cliente y entrenador.
- Administración de las reglas de descuento (quién las crea y desde dónde).

## Criterios de aceptación

1. Ningún documento indica que los puntos se calculan sin IVA. La comisión sigue calculándose sin IVA.
2. Los documentos describen el enlace (id del entrenador en base64) y tratan el QR como otra forma de presentarlo.
3. Está documentado que un cliente sin entrenador puede asociar uno escribiendo su cupón, y que un cliente ya asociado no ve el campo del cupón.
4. Los porcentajes (10%, 25%, 5% y 15%) aparecen como valores por defecto configurables, con excepciones por producto que ajustan el descuento y la comisión.
5. El DER permite calcular descuento, comisión y puntos por línea con el porcentaje vigente de cada producto, y atribuir el pedido al entrenador del cupón.
6. Cada diferencia con `docs/requerimientos.md` está señalada.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| S-01 | El QR y el enlace son lo mismo: el QR codifica el enlace con el id del entrenador en base64. Hay un enlace para la app de clientes y otro para la app de entrenadores. | Confirmado |
| S-02 | Cada entrenador tiene un cupón con nombre único, que el cliente puede escribir si se registró sin enlace. | Confirmado |
| S-03 | Un cliente asociado a un entrenador no ve el campo del cupón y no puede cambiar de entrenador por sí mismo. | Confirmado |
| S-04 | El cliente sin cupón compra a precio completo y no genera recompensas. | Confirmado |
| S-05 | Los porcentajes por defecto (10% cliente, 25% entrenador, 5% puntos y 15% comisión) se guardan como configuración versionada. Cada producto puede tener una regla vigente que cambie el descuento con cupón y la comisión. | Confirmado |
| S-06 | La regla por producto también admite un descuento propio para la compra del entrenador. Si un valor es nulo, se usa el valor por defecto. | Derivado |
| S-07 | Los puntos son el 5% de lo que pagó el cliente: valor con el descuento aplicado y con IVA incluido. Difiere del ejemplo de `requerimientos.md` (249.900 → 12.500 sobre el precio completo). | Confirmado (9 nov 2026) |
| S-08 | Base64 no protege el id: cualquiera puede decodificarlo y armar el enlace de otro entrenador. Se acepta porque el enlace solo atribuye ventas. | Derivado |
| S-09 | Un cliente ya registrado y sin entrenador que abre el enlace de un entrenador queda asociado a él al iniciar sesión. | Confirmado (9 nov 2026) |
| S-10 | El entrenador B solo queda referido por A si se registra desde el enlace o el QR de A. No hay cupón para entrenadores. | Confirmado (9 nov 2026) |

**Diferencias con `docs/requerimientos.md`:**

- **Base de los puntos:** `requerimientos.md` dice «5% del valor de la compra», con IVA según su ejemplo, pero calcula sobre el precio completo (249.900 → 12.500). Producto confirmó que la base es lo pagado con descuento y con IVA. La regla 7 de `reglas-de-negocio.md`, que decía «antes de IVA», queda corregida.
- **Enlace y cupón:** `requerimientos.md` habla solo de QR y cookie, no de enlaces ni cupones. Se registran como decisiones de producto.
- **Porcentajes:** `requerimientos.md` los da como valores fijos. Pasan a ser valores por defecto configurables, con excepciones por producto, por decisión de producto.

## Plan

1. Actualizar `docs/reglas-de-negocio.md` y señalar que su diagrama quedó desactualizado.
2. Actualizar `docs/casos-de-uso-y-actores.md`, y las menciones de puntos sin IVA en el PRD, el MVP y las fases.
3. Cambiar el modelo de datos:
   - `trainers.coupon_code` reemplaza a `referral_codes`;
   - `client_referrals` pasa a tener un único entrenador por cliente y registra el origen del vínculo (enlace o cupón);
   - la nueva tabla `product_discount_rules` guarda las excepciones por producto;
   - `orders_items` registra por línea el porcentaje y el monto de la comisión, y los puntos;
   - `orders_coupons` guarda el entrenador del cupón y reemplaza a `orders.attributed_trainer_id`.
4. Regenerar el DER y actualizar `specs/diseño/01-DER.md`.

## Checklist

- [x] Actualizar `docs/reglas-de-negocio.md`
- [x] Actualizar reglas, interpretación de importes, casos de uso y decisiones pendientes en `docs/casos-de-uso-y-actores.md`
- [x] Corregir las menciones de puntos sin IVA en PRD, MVP y fases de desarrollo
- [x] Cambiar el modelo: cupón del entrenador, vínculo del cliente, reglas por producto, comisión y puntos por línea, atribución por `orders_coupons`
- [x] Regenerar el DER y actualizar `specs/diseño/01-DER.md`
- [ ] Redibujar `docs/img/reglas-de-negocio.png` y `.svg` con la base de puntos con IVA y las reglas nuevas
- [ ] Revisar en el PRD y el MVP las menciones al QR y a la cookie para que reflejen el enlace y el cupón
- [x] Resolver S-07, S-09 y S-10

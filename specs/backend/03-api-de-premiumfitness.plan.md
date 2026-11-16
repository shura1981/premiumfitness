# Plan — API de PremiumFitness: contratos

Spec: [03-api-de-premiumfitness.md](03-api-de-premiumfitness.md).

Este archivo es el contrato que comparten el backend, la app web y el admin. Reemplaza los contratos provisionales del plan `frontend/01` (F-05). Si algo cambia al implementar, se cambia primero aquí.

## Convenciones

- **Base:** `https://premiumfitness-api.nutramerican.com/api` (en local, `http://localhost:8000/api`). JSON en UTF-8.
- **Sesión:** `Authorization: Bearer <access_token>` y la cookie de renovación con `withCredentials` (spec `seguridad/01`).
- **Dinero:** número en pesos. Los precios y los totales que se cobran son enteros; la base sin IVA, el IVA y la comisión pueden traer centavos (la app los redondea al mostrarlos). **Puntos:** entero. La app no calcula: pinta lo que llega.
- **Nombres:** `full_name` se guarda y se devuelve como lo escribió la persona, sin espacios de más. Las ciudades vienen de la tabla de radical, en mayúsculas y sin tildes.
- **Fechas:** ISO 8601 con zona (`2026-11-15T10:30:00-05:00`). Solo día: `2026-11-15`.
- **Listas:** `{ "data": [...] }`. Con paginación: `{ "data": [...], "total": n, "page": 1, "per_page": 20 }` y parámetros `page` y `per_page`.
- **Errores:** `{ "message": "texto para mostrar", "code": "clave_estable", "errors": { "campo": "texto" } }`. `errors` solo en 422.

| Estado | Cuándo |
| --- | --- |
| 401 | Sin token, token vencido o sesión cerrada. La app intenta renovar una vez. |
| 403 | El rol o la app no pueden usar el endpoint, o la cuenta no puede operar (`code`). |
| 404 | No existe o no es de quien consulta. |
| 409 | Conflicto: dato repetido u operación ya hecha (`code`). |
| 422 | Datos inválidos, con `errors` por campo. |
| 429 | Demasiados intentos, con el header `Retry-After`. |

## App web — sin sesión

| Método y ruta | Cuerpo | Respuesta |
| --- | --- | --- |
| `POST /auth/login` | `{ phone, password, role, referral? }` (`role`: `client` en `/entrar`, `trainer` en `/coach/entrar`) | 200 `Session` y cookie `pf_rt_web`. 401 `invalid_credentials`. 403 `wrong_area` (con `role` de la cuenta) si la cuenta es del otro rol, `phone_not_verified` (con `phone`) o `account_suspended`. |
| `POST /auth/refresh` | — | 200 `Session` y cookie nueva. 401 si no hay sesión. |
| `POST /auth/logout` | — | 204; borra la cookie. |
| `POST /auth/register` | `{ full_name, document_type_id, document_id, phone, email, password, role, coupon_code? }` | 201 `{ phone, expires_in }` y envía el código. 409 `phone_taken`, `email_taken` o `document_taken`. Si el celular tiene una cuenta sin verificar, la actualiza y reenvía el código. |
| `POST /auth/verify-phone` | `{ phone, code, referral? }` | 200 `Session` y cookie. 422 `invalid_code`. |
| `POST /auth/resend-code` | `{ phone }` | 200 `{ expires_in }`. |
| `POST /auth/forgot-password` | `{ phone }` | 200 `{ expires_in }` siempre, exista o no el celular. |
| `POST /auth/verify-reset-code` | `{ phone, code }` | 200 `{ reset_token, expires_in }`. 422 `invalid_code`. |
| `POST /auth/reset-password` | `{ reset_token, password }` | 204; cierra todas las sesiones. 422 `invalid_token`. |
| `GET /document-types` | — | `{ data: [{ id, name }] }` |
| `GET /cities/search?q=` | — | `{ data: [{ id, name, department }] }`, máximo 20; primero la coincidencia exacta, luego las que empiezan por el texto |
| `GET /referrals/trainers/:id` | — | `{ full_name }`; 404 si el enlace no es válido. `:id` es el id en base64. |

- `phone`: 10 dígitos, empieza por 3. `password`: 8 a 128 caracteres. `code`: 6 dígitos. `role`: `client` o `trainer`.
- `referral`: `{ kind: "c" | "t", id: "<base64>" }`, el enlace guardado. El vínculo se crea cuando la cuenta queda activa (al verificar el celular o al iniciar sesión), no al registrarse. Un enlace inválido no impide entrar: se ignora.
- `expires_in` de los códigos: segundos de validez (300).
- `coupon_code` (opcional, solo entrenadores): el cupón que elige, de 4 a 20 letras o números. Si no lo envía, se genera con su nombre. 409 `coupon_taken` si ya lo usa otro.
- El login de una cuenta sin verificar (403 `phone_not_verified`) **no** envía un código: la app llama a `resend-code`.

```ts
interface Session { access_token: string; expires_in: number; user: User }
interface User {
  id: number; full_name: string; phone: string; role: 'client' | 'trainer';
  has_trainer: boolean; trainer_name: string | null;
}
```

## App web — con sesión (cliente o entrenador)

### Cuenta

| Método y ruta | Cuerpo | Respuesta |
| --- | --- | --- |
| `GET /account` | — | `Profile` |
| `PUT /account` | `{ full_name, email }` | `Profile`. 409 `email_taken`. |
| `PUT /auth/password` | `{ current_password, password }` | 200 `Session` y cookie nueva; cierra las demás sesiones. 422 si la actual no coincide. Va bajo `/auth` porque la cookie solo viaja a esa ruta. |
| `GET /account/addresses` | — | `{ data: Address[] }` |
| `POST /account/addresses` | `AddressInput` | 201 `Address` |
| `PUT /account/addresses/:id` | `AddressInput` | `Address` |
| `DELETE /account/addresses/:id` | — | 204 |
| `PATCH /account/addresses/:id/main` | — | 204 |
| `GET /localities?city_id=` | — | `{ data: [{ id, name }] }` (solo las ciudades que las tienen) |
| `POST /account/push-devices` | `{ token, platform: "web" }` | 204 |
| `DELETE /account/push-devices` | `{ token }` | 204 |
| `POST /client/coupon` | `{ coupon_code }` | `User`. Solo un cliente sin entrenador. 404 `coupon_not_found`; 409 `already_linked`. |

```ts
interface Profile extends User {
  email: string; document_type_id: number; document_type_name: string; document_id: string;
}
interface Address {
  id: number; name: string; address: string; city_id: number; city_name: string;
  department_name: string; locality_id: number | null; locality_name: string | null; is_main: boolean;
}
interface AddressInput { name: string; address: string; city_id: number; locality_id?: number | null }
```

### Catálogo

| Método y ruta | Respuesta |
| --- | --- |
| `GET /products?category=` | `{ data: Product[], categories: string[] }` |
| `GET /products/:code` | `ProductDetail`; 404 si no se vende en la app |

```ts
interface Product {
  code: number; name: string; category: string; presentation: string | null; image: string | null;
  price: number;              // precio completo, con IVA
  final_price: number;        // precio para quien consulta; sin descuento, igual a price
  discount_percentage: number;
  is_out_of_stock: boolean; is_new: boolean;
  redemption_points: number;  // costo del canje: el precio completo (1 punto = 1 peso)
}
interface ProductDetail extends Product {
  summary: string | null;
  images: string[];                                   // URL absolutas, la principal primero
  sections: { title: string; html: string }[];        // ficha: descripción, modo de uso…
  presentations: { code: number; presentation: string | null; final_price: number; is_out_of_stock: boolean }[];
}
```

`sections[].html` es contenido de la intranet: la app lo muestra saneado (`[innerHTML]` de Angular).

### Compra

| Método y ruta | Cuerpo | Respuesta |
| --- | --- | --- |
| `POST /checkout/preview` | `{ items: [{ code, quantity }] }` | `CheckoutPreview` |
| `GET /checkout/payment-gateways` | — | `{ data: [{ id, name, description }] }` |
| `POST /checkout` | `{ items, address_id, payment_gateway, notes? }` | 201 `CheckoutResult`. 409 `out_of_stock` o `price_changed`. |
| `GET /orders` | — | `{ data: OrderSummary[] }`, los propios |
| `GET /orders/:id` | — | `OrderDetail`; 404 si no es de quien consulta |
| `POST /orders/:id/pay` | — | `CheckoutResult` de un pedido propio que sigue pendiente, con el valor guardado. 409 `order_not_payable` si ya se pagó, es un canje o tiene más de un día. |

```ts
interface CheckoutPreview {
  items: {
    code: number; name: string; image: string | null; presentation: string | null; quantity: number;
    unit_price: number; final_unit_price: number; discount_percentage: number; line_total: number;
    available: boolean;                                // false: agotado o ya no se vende
  }[];
  subtotal_full: number; discount_amount: number; tax_amount: number; shipping_amount: number; total: number;
  can_checkout: boolean; message: string | null;
}
interface CheckoutResult {
  order_id: number;
  payment:
    | { gateway: 'bold'; bold: { order_id: string; amount: number; currency: 'COP'; api_key: string;
        integrity_signature: string; description: string; redirection_url: string } }
    | { gateway: 'addi' | 'sistecredito'; redirect_url: string };
}
type OrderStatus = 'pendiente' | 'aprobado' | 'en_proceso' | 'enviado' | 'entregado' | 'rechazado' | 'cancelado';
interface OrderSummary {
  id: number; created_at: string; status: OrderStatus; total: number; items_count: number; first_item: string;
  is_redemption: boolean; redemption_points: number | null;
}
interface OrderDetail extends OrderSummary {
  payment_gateway: string | null;
  items: {
    code: number; name: string; image: string | null; quantity: number;
    unit_price: number; final_unit_price: number; discount_percentage: number; line_total: number;
    redemption_points: number | null;
  }[];
  subtotal_full: number; discount_amount: number; tax_amount: number; shipping_amount: number;
  address: { name: string; address: string; city_name: string; department_name: string } | null;
  tracking: { carrier: string; number: string; url: string | null } | null;
  timeline: { status: OrderStatus; label: string; at: string | null; done: boolean }[];
}
```

- El pago con Bold usa el botón de Bold en la app (`bold`), con los datos firmados por el backend. Al volver, la app abre `/pago/resultado?pedido=<id>` y consulta `GET /orders/:id` hasta que el estado deje de ser `pendiente`.
- El pedido se aprueba por el webhook de la pasarela, nunca por la app.
- `payment_gateway` solo admite `bold` (Addi y Sistecrédito están deshabilitadas, spec `backend/03`, P-02).
- `total` incluye el IVA y el envío; `tax_amount` es informativo («incluye $X de IVA»).
- En un canje, `total` es 0, `redemption_points` es el total de puntos y `items[].redemption_points` es el de la línea completa (precio × cantidad). Su línea de tiempo empieza en «Canje confirmado», sin pasos de pago. Los importes en dinero de un canje son informativos (el precio completo): la app muestra los puntos.

## App web — entrenador (`role = trainer`; 403 para un cliente)

| Método y ruta | Cuerpo | Respuesta |
| --- | --- | --- |
| `GET /trainer/dashboard` | — | `TrainerDashboard` |
| `GET /trainer/referrals` | — | `TrainerReferrals` |
| `GET /trainer/points?source=` | — | `TrainerPoints` |
| `GET /trainer/commission` | — | `TrainerCommission` |
| `GET /trainer/commission/conversion-preview` | — | `ConversionPreview`. 409 `conversion_blocked` o `nothing_to_convert`. |
| `POST /trainer/commission/convert` | `{ idempotency_key }` | `ConversionPreview` con lo aplicado. Repetir la clave devuelve lo mismo. |
| `GET /trainer/commission/settlements/:id/receipt` | — | El comprobante (PDF o imagen); 404 si no es suyo. Pide el token: la app lo descarga como archivo (blob), no con un enlace. `receipt_url` de la liquidación es esta ruta, relativa a la base de la API. |
| `GET /trainer/diet-access` | — | `DietAccess` |
| `POST /trainer/diet-access/redeem` | — | `DietAccess`. Hay un solo canje por mes: repetirlo no cobra de nuevo. 409 `not_enough_points` o `already_active`. |
| `POST /trainer/redemptions/preview` | `{ items: [{ code, quantity }] }` | `RedemptionPreview` |
| `POST /trainer/redemptions` | `{ items, address_id, idempotency_key }` | 201 `{ order_id }`. 409 `not_enough_points` u `out_of_stock`. |

`TrainerDashboard`, `TrainerReferrals`, `TrainerPoints`, `TrainerCommission`, `ConversionPreview` y `DietAccess` son los de `premiumfitness-web/src/app/core/api/models.ts` y no cambian.

```ts
interface RedemptionPreview {
  items: { code: number; name: string; image: string | null; quantity: number; points: number; line_points: number; available: boolean }[];
  points_total: number; points_available: number;
  points_remaining: number | null;   // lo que le quedaría; null si no le alcanza
  enough: boolean; message: string | null;
}
```

## Admin (`/admin/*`, token con `app = admin`)

### Sesión

| Método y ruta | Cuerpo | Respuesta |
| --- | --- | --- |
| `POST /admin/auth/login` | `{ email, password }` | 200 `AdminSession` y cookie `pf_rt_admin` |
| `POST /admin/auth/refresh` | — | 200 `AdminSession`; 401 a las 12 horas del login |
| `POST /admin/auth/logout` | — | 204 |

```ts
interface AdminSession { access_token: string; expires_in: number; user: Agent }
interface Agent {
  id_empleado: number; id_usuario: number; usuario: string; email: string; cargo: string;
  departamento: string | null; foto: string | null; celular: string | null; sede: string | null;
}
```

**Cargos:** pedidos, agentes y reportes los usa cualquier agente con sesión. Entrenadores, estrategias, cierre mensual y registrar ajustes exigen uno de los cargos de `ADMIN_MANAGER_ROLES` (ROOT, GERENTE COMERCIAL y ADMINISTRADOR); a los demás responden 403. Los ajustes de un pedido los ve quien ve el pedido.

Pedidos, agentes y reportes (`/admin/orders`, `/admin/agents` y `/admin/reports/*`) siguen como en radical, sin los de saldo a favor (`/admin/reports/credit-balances/*`).

### Entrenadores

| Método y ruta | Cuerpo | Respuesta |
| --- | --- | --- |
| `GET /admin/trainers?search=&status=&page=&per_page=` | — | Lista paginada de `TrainerRow` |
| `GET /admin/trainers/:id` | — | `TrainerDetail` |
| `PATCH /admin/trainers/:id/status` | `{ status: "active" \| "suspended", reason }` | `TrainerRow`. `reason` de 5 a 500 caracteres. 409 `phone_not_verified` si la cuenta está `pending`: solo se activa verificando el celular. Suspender cierra sus sesiones. |

```ts
interface TrainerRow {
  id: number; full_name: string; phone: string; email: string; document_id: string; coupon_code: string;
  status: 'pending' | 'active' | 'suspended'; joined_at: string;
  clients_count: number; points_available: number; commission_balance: number;
}
interface TrainerDetail extends TrainerRow {
  referrer: { id: number; full_name: string } | null;
  status_reason: string | null; status_changed_at: string | null;   // último cambio de estado hecho en el admin
  points: { available: number; client_available: number; referral_available: number; referral_pending: number };
  clients: { id: number; full_name: string; phone: string; since: string; orders_count: number; total_paid: number }[];
  referred_trainers: { id: number; full_name: string; status: 'pendiente' | 'activo' | 'usado' | 'vencido'; points: number; registered_at: string }[];
  movements: { id: string; title: string; date: string; amount: number; kind: 'points' | 'money' }[];
  settlements: { id: number; period_id: number; period: string; total_amount: number; status: 'closed' | 'paid'; due_date: string; paid_at: string | null; has_receipt: boolean; is_overdue: boolean }[];
}
```

### Estrategias por producto

| Método y ruta | Cuerpo | Respuesta |
| --- | --- | --- |
| `GET /admin/discount-rules?active=1\|0` | — | `{ data: DiscountRule[] }`; sin `active`, todas |
| `POST /admin/discount-rules` | `DiscountRuleInput` | 201 `DiscountRule` |
| `PUT /admin/discount-rules/:id` | `DiscountRuleInput` | `DiscountRule`. No cambia el producto. 409 `rule_inactive` si está desactivada. |
| `DELETE /admin/discount-rules/:id` | — | 204 (la desactiva; no borra el histórico) |
| `GET /admin/products?search=` | — | `{ data: [{ code, name, presentation, price }] }`, para elegir el producto |
| `GET /admin/settings/business` | — | Valores por defecto: `{ client_discount_percentage, trainer_discount_percentage, commission_percentage, client_purchase_points_percentage }` |

```ts
interface DiscountRuleInput {
  product_sku: number;
  client_discount_percentage: number | null;    // null = valor por defecto
  trainer_discount_percentage: number | null;
  commission_percentage: number | null;
  valid_from: string; valid_to: string | null;
}
interface DiscountRule extends DiscountRuleInput { id: number; product_name: string; is_active: boolean; created_at: string }
```

- `is_active` es falso solo cuando alguien la desactivó; que esté vigente lo dicen `valid_from` y `valid_to`.
- `valid_from` y `valid_to` son instantes (fecha y hora con zona). El admin elige días y envía el inicio del primero y el final del último (`…T00:00:00-05:00` y `…T23:59:59-05:00`). `valid_to` debe ser posterior a `valid_from`.
- `has_report` del cierre es falso si el Excel no se generó al cerrar; pedir el reporte lo genera.
- Al menos un porcentaje debe venir. Los porcentajes van de 0 a 100, con hasta dos decimales.
- Si un producto tiene varias estrategias vigentes, aplica la de `valid_from` más reciente.

### Cierre mensual y pago de comisión

| Método y ruta | Cuerpo | Respuesta |
| --- | --- | --- |
| `GET /admin/commission/periods` | — | `{ data: CommissionPeriod[] }`, el más reciente primero |
| `GET /admin/commission/periods/:id` | — | `CommissionPeriod & { settlements: Settlement[] }` |
| `GET /admin/commission/periods/:id/report` | — | El Excel del cierre |
| `POST /admin/commission/settlements/:id/pay` | `multipart/form-data`: `payment_reference` (máx. 100), `paid_at` (`AAAA-MM-DD`, no futura) y `receipt` (PDF, JPG, PNG o WebP, máx. 5 MB; el tipo se decide por el contenido) | `Settlement`. 409 `already_paid`. |
| `POST /admin/commission/settlements/:id/payment` | Igual; `receipt` es opcional | `Settlement`. Corrige un pago ya registrado; el anterior queda en la auditoría. 409 `not_paid`. Es `POST` porque PHP solo lee un formulario con archivo en un `POST`. |
| `GET /admin/commission/settlements/:id/receipt` | — | El comprobante |

```ts
interface CommissionPeriod {
  id: number; period: string; period_label: string; period_start: string; period_end: string; closed_at: string;
  trainers_count: number; orders_count: number; total_amount: number; paid_count: number; paid_amount: number;
  due_date: string | null; report_sent_at: string | null; has_report: boolean;
}
interface Settlement {
  id: number; trainer_id: number; trainer_name: string; document_id: string; phone: string;
  orders_count: number; commission_amount: number; conversions_amount: number; adjustments_amount: number;
  total_amount: number; due_date: string; status: 'closed' | 'paid';
  /** Pasó el día de pago y no tiene pago registrado. */
  is_overdue: boolean;
  /** AAAA-MM-DD */
  paid_at: string | null; payment_reference: string | null; has_receipt: boolean; paid_by: string | null;
}
```

El cierre lo dispara el cron (`POST /cron-job/commission/close`, día 1 a la 01:00), no el admin. Solo hay liquidación para quien tiene saldo positivo; un saldo en cero o negativo pasa al mes siguiente. `trainers_count` es el número de liquidaciones. `due_date` del período es nulo si no tuvo liquidaciones.

### Ajustes

| Método y ruta | Cuerpo | Respuesta |
| --- | --- | --- |
| `GET /admin/orders/:id/adjustments` | — | `{ data: Adjustment[], preview: AdjustmentEffect, can_adjust: boolean }` |
| `POST /admin/orders/:id/adjustments/preview` | `{ type, items? }` | `AdjustmentEffect` de ese ajuste, sin aplicarlo |
| `POST /admin/orders/:id/adjustments` | `{ type, reason, items? }` | 201 `Adjustment`. 409 `already_adjusted` u `order_not_approved`. |

```ts
type AdjustmentType = 'cancellation' | 'full_return' | 'partial_return';
interface AdjustmentEffect { commission_amount: number; points: number; trainer_name: string | null }
interface Adjustment {
  id: number; type: AdjustmentType; reason: string; agent_name: string; created_at: string;
  effect: AdjustmentEffect;                     // lo que se revirtió al entrenador (valores negativos)
}
```

- `items` solo en `partial_return`: `[{ item_id, quantity }]`, con cantidad entre 1 y la comprada.
- `preview` del `GET` es lo que revertiría una cancelación total hoy. Los valores son negativos cuando se le quitan al entrenador y positivos cuando se le devuelven (canje cancelado).
- Un pedido admite un solo ajuste. `can_adjust` es falso si ya lo tiene o si no está pagado.
- De los puntos solo se retira lo que quede en el lote de ese pedido; lo ya usado no genera deuda.
- Un canje solo admite `cancellation`: devuelve los puntos en un lote nuevo.

## Servidor a servidor y crons

| Método y ruta | Credencial | Qué hace |
| --- | --- | --- |
| `POST /integrations/diet-access` | `X-Api-Key` | Spec `integraciones/02` |
| `POST /cron-job/payment-event-jobs/process` | `X-Cron-Job-Token` | Procesa los eventos de pago (radical) |
| `POST /cron-job/push/send` | `X-Cron-Job-Token` | Envía las push pendientes |
| `POST /cron-job/commission/close` | `X-Cron-Job-Token` | Cierre mensual; 409 si el período ya existe |
| `POST /cron-job/points/expire` | `X-Cron-Job-Token` | Vence lotes y avisa de los que están por vencer |
| `POST /cron-job/diet-access/notify` | `X-Cron-Job-Token` | Avisa del acceso a dietas por vencer o desactivado (diario) |
| `POST /cron-job/auth/cleanup` | `X-Cron-Job-Token` | Borra refresh tokens y códigos vencidos |
| `POST /payment-gateway/webhook/bold` | Firma de Bold | Aviso de pago: aprueba el pedido si lo pagado cubre su valor |

## Rutas de radical que se retiran

La API de radical dejaba sin sesión el CRUD de usuarios, direcciones, productos, categorías, grupos, productos SAP, tipos de documento, ciudades y bodegas, y con cualquier sesión permitía ver y cambiar pedidos y saldos de otros. En PremiumFitness:

- Todo `/api` exige sesión, salvo la tabla «sin sesión», los webhooks de pago, la integración de dietas y los crons (cada uno con su credencial).
- Se retiran: `/users*` (lo reemplazan el registro y `/account`), `/addresses*`, `/retail/*`, `/credit-balances*`, `/categories*`, `/product-groups*`, `/sap-products*`, `/city-warehouses*`, la escritura de `/products`, `/document-types`, `/cities` y `/localities`, los cambios de estado, SAP y guía de `/orders` (quedan solo en `/admin/orders`), la tienda pública HTML y `/reset-password`.
- Cada consulta de la app web filtra por el usuario del token; el id nunca llega por la URL.

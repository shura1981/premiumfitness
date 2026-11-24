# Plan — Frontends: app web y admin

Spec: [01-apps-web-y-admin.md](01-apps-web-y-admin.md).

## Decisiones

| Decisión | Por qué |
| --- | --- |
| La app web es un proyecto nuevo; el admin es un fork | La web tiene una estructura propia (un área por rol, spec `arquitectura/02`). El admin de radical ya hace pedidos, SAP, guías y reportes contra una API que el backend hereda. |
| Tailwind 4 con los tokens en `src/styles.css` | Es lo que usan `gifts` y los frontends de radical, y el sistema de diseño trae el CSS listo para `:root` y `html.dark`. |
| Componentes propios en `shared/ui`, sin librería de componentes | El sistema de diseño define 21 componentes pequeños; una librería (Material) traería estilos que habría que deshacer. |
| Estado con signals y servicios; sin NgRx | El estado es poco: sesión, tema y carrito. |
| Sin zone.js | Es el valor por defecto de Angular 22 y lo que usan los frontends de radical. |
| API simulada con un interceptor que solo existe en desarrollo | Las pantallas se construyen contra los contratos de los specs, y pasar a la API real es cambiar el entorno. `environment.ts` de producción no lo importa, así que no llega al build. |
| Iconos como SVG generados | Un script copia de `@material-symbols/svg-400` solo los iconos usados a `icons.generated.ts`. |

## Estructura de `premiumfitness-web`

```
src/app/
├── core/
│   ├── auth/        # AuthService, authInterceptor, guards (sesión, invitado y rol) y modelos
│   ├── api/         # URL base de la API
│   ├── theme/       # ThemeService y derivación del acento
│   ├── format/      # dinero, puntos y fechas
│   ├── cart/        # estado del carrito (lo usan la tienda, el carrito y la navegación)
│   ├── referral/    # id del enlace /r/c o /r/t hasta el registro o el login
│   └── mock/        # API simulada (solo desarrollo)
├── shared/ui/       # componentes del sistema de diseño (pf-button, pf-card, pf-icon…)
├── layout/          # shell con la navegación por rol
├── features/
│   ├── auth/        # entrar, registro, verificación, recuperación y enlaces
│   ├── catalog/     # tienda y detalle
│   ├── cart/        # carrito y checkout
│   ├── orders/      # pedidos y seguimiento
│   ├── account/     # cuenta y apariencia
│   ├── client/      # cupón del cliente
│   └── trainer/     # inicio, referidos, puntos, comisión, conversión y canje
└── app.routes.ts
```

- Alias: `@core/*`, `@shared/*`, `@layout/*`, `@features/*` y `@env/*`.
- **Aislamiento:** `features/*` solo importa de `core/` y `shared/`; `shared/` solo de `core/`; `core/` de nadie. Lo impone `eslint-plugin-boundaries`.
- Cada feature se carga con `loadChildren`. El área del entrenador (`features/trainer`) lleva `canMatch` con el rol `trainer`: un cliente no descarga su código.

## Rutas

La app tiene dos áreas con el mismo `Shell` y las mismas pantallas compartidas (spec `seguridad/01`, S-08). Cada área declara su rol con `provideArea()` en la ruta, y las pantallas arman sus enlaces con el pipe `area`, que antepone `/coach` en el área del entrenador.

| Cliente | Entrenador | Pantalla |
| --- | --- | --- |
| `/entrar` | `/coach/entrar` | Login (solo acepta cuentas de su rol) |
| `/registro` | `/coach/registro` | Registro (el del entrenador pide su cupón) |
| `/verificar` | `/coach/verificar` | Código SMS |
| `/recuperar` | `/coach/recuperar` | Recuperar la contraseña |
| `/tienda`, `/producto/:code` | `/coach/tienda`, `/coach/producto/:code` | Tienda y detalle |
| `/carrito`, `/pagar`, `/pago/resultado/<pasarela>` | `/coach/carrito`, `/coach/pagar`, `/coach/pago/resultado/<pasarela>` | Compra (la respuesta tiene una dirección por pasarela: `bold`, `addi`, `sistecredito`) |
| `/pedidos`, `/pedidos/:id` | `/coach/pedidos`, `/coach/pedidos/:id` | Pedidos |
| `/cuenta/**` | `/coach/cuenta/**` | Cuenta |
| `/cupon` | — | Asociar cupón (cliente sin entrenador) |
| — | `/coach` | Inicio |
| — | `/coach/referidos`, `/coach/puntos`, `/coach/comision`, `/coach/canje` | Área del entrenador |

- `/r/c/:id` lleva al registro o login de clientes y `/r/t/:id` al de entrenadores.
- Al entrar, el cliente va a `/tienda` y el entrenador a `/coach`.
- Quien abre una ruta del otro rol va a la suya: un entrenador en `/pedidos/5` pasa a `/coach/pedidos/5`, y un cliente en `/coach/...` a la raíz. Así los enlaces de pago y de push sirven aunque lleguen a la ruta equivocada.
- El backend arma con `/coach` la vuelta del pago de Bold y las rutas de los push del entrenador.

## Contratos provisionales de la API (F-05)

Todas las rutas cuelgan de `environment.apiUrl`. El access token va en `Authorization: Bearer` y la cookie de renovación con `withCredentials`.

| Método y ruta | Devuelve |
| --- | --- |
| `POST /api/auth/login` `{ phone, password, role, referral? }` | `{ access_token, expires_in, user }` |
| `POST /api/auth/refresh` | Igual que el login; 401 si no hay sesión |
| `POST /api/auth/logout` | 204 |
| `GET /api/referrals/trainers/:id` | `{ full_name }` del entrenador del enlace; 404 si no es válido |
| `GET /api/products?category=` | `{ data: Product[], categories: string[] }`, con los precios ya calculados para el rol |
| `GET /api/orders` | `{ data: OrderSummary[] }` |
| `POST /api/client/coupon` `{ coupon_code }` | El usuario actualizado |
| `GET /api/trainer/dashboard` | Saldos de puntos y comisión, vencimientos, últimos movimientos y estado de dietas |
| `GET /api/trainer/referrals` | Cupón, los dos enlaces, clientes y entrenadores referidos |
| `GET /api/trainer/points?source=` | Saldo vigente y lotes |
| `GET /api/trainer/commission` | Saldo sin liquidar, corte, movimientos y liquidaciones |
| `GET /api/trainer/commission/conversion-preview` | Comisión, puntos vigentes, adicional, total y vencimiento |
| `POST /api/trainer/commission/convert` `{ idempotency_key }` | El resultado de la conversión |
| `GET /api/trainer/diet-access` | Spec `integraciones/02` |

`user`: `{ id, full_name, phone, role: 'client' | 'trainer', has_trainer, trainer_name? }`.

`Product`: `{ code, name, category, presentation, image, price, final_price, discount_percentage, is_out_of_stock, is_new }`. `final_price` y `discount_percentage` los calcula el backend según el rol y las reglas por producto; sin descuento, `final_price = price` y `discount_percentage = 0`.

## Admin

1. `git clone` local de `admin-radical-nutrition` a `premiumfitness-admin`; el remoto pasa a llamarse `radical`.
2. `ng update` a Angular 22 y sus dependencias; build y pruebas.
3. Nombre, logo, tokens de color y entornos de PremiumFitness.
4. Después: quitar lo que no aplica y añadir las pantallas nuevas del checklist.

## Riesgos

| Riesgo | Mitigación |
| --- | --- |
| Node 25 no está soportado por Angular 22 | Solo afecta a la compilación; si una herramienta falla, se cambia la versión en `.nvmrc`. |
| Los contratos simulados no coinciden con el backend | Están en un solo lugar (`core/mock` y los modelos de cada feature) y se ajustan al definir el backend. |
| El fork del admin arrastra pantallas que no aplican | Se quitan después de compilar, con el checklist. |

## Orden de trabajo

1. Crear y configurar `premiumfitness-web`; en paralelo, el fork del admin.
2. Tokens, componentes, tema, formato y sesión, con pruebas.
3. Navegación y pantallas de la primera entrega con la API simulada.
4. Verificar build, lint, pruebas y capturas en los dos temas.
5. Pantallas restantes, por el orden del checklist.

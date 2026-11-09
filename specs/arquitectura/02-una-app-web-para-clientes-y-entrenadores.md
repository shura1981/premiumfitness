# 02 — Una sola app web para clientes y entrenadores

**Estado:** en curso · **Creado:** 9 de noviembre de 2026

## Petición original

> ¿Por qué tener dos apps de entrenadores y clientes? Solo la de dietas es externa; en las dos se puede comprar, solo que en la de entrenadores hay más lógica.
>
> Sí, entonces serían tres apps, dos web y una multiplataforma (Flutter): la de clientes y entrenadores (PWA Angular) y la del admin (call center, reportes y más).

## Objetivo

Reducir el código duplicado y la operación al unir en una sola PWA a clientes y entrenadores, con un área para cada rol.

## Decisión

PremiumFitness tiene **tres apps**:

| App | Tecnología | Usuarios | Valor de `app` en el token |
| --- | --- | --- | --- |
| **Web** | PWA Angular | Clientes y entrenadores, con un área por rol | `web` |
| **Admin** | Angular | Agentes del call center (`tb_empleados`): pedidos, SAP, envíos y reportes | `admin` |
| **Dietas** | Flutter (multiplataforma), externa | Entrenadores | `diets` |

**Cambio respecto a lo confirmado antes:** el 9 nov 2026 se había confirmado que clientes y entrenadores usaban apps distintas, cada una en su subdominio (regla 12; S-01 del spec `negocio/01`). Esta decisión del mismo día lo reemplaza.

## Alcance

- Reglas, casos de uso, PRD, specs, DER y estándares que hablaban de dos apps.

## Fuera de alcance

- El diseño de las pantallas de cada área.

## Reglas

1. **Áreas:** la app web tiene una parte común (catálogo con precios, carrito, checkout, pedidos, seguimiento, perfil, direcciones y autenticación) y un área de entrenador que se carga aparte (lazy loading) solo si el rol es `trainer`: puntos, comisión, conversión, canje, referidos, enlaces y QR.
2. **Precios:** el backend devuelve los precios según el rol y las estrategias por producto. La app solo los pinta.
3. **Seguridad:** el backend exige el rol en cada endpoint del entrenador. Los guards de Angular solo ocultan pantallas.
4. **Enlaces:** los dos enlaces del entrenador apuntan a rutas de la misma app y llevan su id en base64:
   - `/r/c/<id>`: registra o asocia a un cliente con ese entrenador.
   - `/r/t/<id>`: registra a un entrenador B referido por A.
5. **Registro:** en la app web, quien llega por `/r/t/<id>` o elige «soy entrenador» se registra como `trainer`. Cualquier otro registro es `client`. Una cuenta tiene un solo rol.
6. **Cuenta ya registrada:**
   - un **cliente** que abre `/r/c/<id>` sin entrenador queda asociado al iniciar sesión (regla 13);
   - un **cliente** que abre `/r/t/<id>` recibe un aviso de que su cuenta es de cliente y no se convierte en entrenador;
   - un **entrenador** que abre cualquiera de los dos enlaces ve el aviso de que el enlace no le aplica.
7. **Sesión:** una cookie `pf_rt_web` para la app web y `pf_rt_admin` para el admin. La app de dietas guarda el refresh token en el almacenamiento seguro del dispositivo.

## Estructura de la app web

Angular con componentes standalone y rutas de carga diferida (`loadChildren`), sin `NgModule`.

```
src/app/
├── core/          # auth, interceptor del token, guards por rol, config de Firebase
├── shared/        # componentes y pipes comunes (tarjeta de producto, precio con descuento…)
├── features/
│   ├── catalog/   # común: catálogo y detalle de producto
│   ├── cart/      # común: carrito y checkout
│   ├── orders/    # común: pedidos y seguimiento
│   ├── account/   # común: perfil, direcciones y notificaciones
│   ├── client/    # área del cliente: asociar cupón
│   └── trainer/   # área del entrenador: puntos, comisión, conversión, canje, referidos, enlaces y QR
└── app.routes.ts  # /trainer/** con loadChildren y guard de rol trainer
```

**Reglas de aislamiento:**

1. **Carga aparte:** cada feature se carga con `loadChildren` y genera su propio archivo JavaScript. El área `trainer/` solo se descarga si el rol es `trainer`.
2. **Dependencias:** una feature **no importa nada de otra feature**; solo de `core/` y `shared/`. Si dos features necesitan lo mismo, ese código se sube a `shared/`.
3. **Lint:** la regla 2 se impone con `eslint-plugin-boundaries`. El build falla si una feature importa de otra.
4. **Acceso:** las rutas de `trainer/` tienen un guard por rol. El backend exige `role = trainer` en sus endpoints.
5. **Pruebas:** `core/` y `shared/` afectan a clientes y entrenadores, así que todo cambio ahí lleva pruebas.

## Criterios de aceptación

1. **Enlaces:** un cliente y un entrenador entran a la misma URL y cada uno ve el área de su rol.
2. **Seguridad:** un cliente no puede llamar a un endpoint de entrenador aunque fuerce la ruta en el navegador (403).
3. **Referido de entrenador:** `/r/t/<id>` registra a B como entrenador referido por A, y `/r/c/<id>` registra o asocia a un cliente.
4. **Tokens:** un token `web` no sirve en `/api/admin/*` ni en `/api/diets/*`.
5. **Carga:** un cliente no descarga el archivo JavaScript del área `trainer/`. Se comprueba en la pestaña Red del navegador.
6. **Lint:** un import de `features/trainer` dentro de `features/client`, o al revés, hace fallar el lint.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| S-01 | Tres apps: web (clientes y entrenadores), admin y dietas. | Confirmado (9 nov 2026) |
| S-02 | Rutas `/r/c/<id>` y `/r/t/<id>` para los enlaces. | Derivado |
| S-03 | Los casos de cuenta ya registrada de la regla 6. | Derivado |
| S-04 | Subdominios: uno para la app web y otro para el admin. | Derivado: los nombres se definen al desplegar |

## Checklist

- [x] Actualizar la regla 12, RN-01, RN-02, los actores y los casos de enlace en `docs/casos-de-uso-y-actores.md`
- [x] Marcar el cambio en S-01 del spec `negocio/01` y en S-09 del DER
- [x] Actualizar el spec de seguridad (cookies, claim `app` y rutas), el de push y el de dietas
- [x] Cambiar los enums `app` del DER (`refresh_tokens`, `push_devices` y `push_notifications`)
- [x] Actualizar AGENTS.md, `codebase-standards.md`, el PRD y el spec `arquitectura/01`
- [x] Definir la estructura de la app web y las reglas de aislamiento entre features
- [ ] Configurar `eslint-plugin-boundaries` al crear el proyecto Angular

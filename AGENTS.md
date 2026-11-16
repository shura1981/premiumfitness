# PremiumFitness

Aplicación de referidos, compras, comisiones y puntos para los entrenadores y clientes de PremiumFitness. El backend es un **fork de `backend-radical-nutrition`** (PHP 8.5, Slim 4, MariaDB, Pest y PHPStan), desplegado en el mismo hosting. Hay tres apps: la **web** (PWA Angular para clientes y entrenadores, con un área por rol), el **admin** (Angular: call center, SAP, envíos y reportes) y **dietas** (Flutter, externa).

**Estado actual (16 nov 2026):** el backend está en este repo, a partir del fork de radical (spec `specs/backend/01-fork-base-del-backend.md`), con las reglas de PremiumFitness implementadas y probadas: autenticación, catálogo de la intranet, referidos, compras con descuento e IVA, puntos, comisión y cierre mensual, canje, acceso a dietas, push y los endpoints del admin. Lo que falta y las decisiones pendientes están en el checklist de `specs/backend/03-api-de-premiumfitness.md`; el contrato de la API, en su `.plan.md`. Los frontends viven en otros repos: `/home/steven/Proyectos/node/angular/premiumfitness-web` y `premiumfitness-admin` (spec `specs/frontend/01-apps-web-y-admin.md`).

## Cómo correr el backend en local

```bash
composer install
scripts/reset-db.sh        # recrea la base local con docs/database/schema/crisenri_premiumfitness.sql
composer run start         # API en http://localhost:8000
composer run lint          # PHPStan
composer run test          # Pest, contra la base local
```

- El `.env` local no se versiona: parte de `.env.example`. La base local es propia de este proyecto; no uses credenciales de otros.
- **Base:** un solo archivo, `docs/database/schema/crisenri_premiumfitness.sql` (sin migraciones: la app aún no ha salido). En el hosting se importa desde phpMyAdmin; en local, la base vive en el MariaDB del sistema y `scripts/reset-db.sh` la recrea con `DB_USER`/`DB_PASS` del `.env`, como en radical. Un cambio de esquema se hace en ese archivo.
- `scripts/reset-db.sh` borra y recrea la base: solo corre si `DB_HOST` es local.
- `crisenri_intranet` es la base real de la intranet (también en local) y solo se lee: la app la nombra con `INTRANET_DB_NAME`. Las pruebas usan su propia base, `crisenri_intranet_pruebas`, con la estructura de la real (`CREATE TABLE … LIKE`) y los datos de `tests/fixtures/intranet-pruebas.sql`; nunca escriben en `crisenri_intranet`.
- El admin en local entra con un empleado real de `tb_empleados`. Los agentes `agente.prueba@premiumfitness.local` / `admin-local-123` solo existen en la base de las pruebas.
- En local los SMS no se envían: con `SMS_DRIVER=log` el código queda en `logs/sms-dev.log`. Las pruebas usan `SMS_DRIVER=memory`.
- Para probar una compra sin pagar de verdad: `php scripts/dev-pagar-pedido.php <id del pedido>` envía al backend local el aviso de pago aprobado de Bold, firmado. Solo corre en local.
- Para recorrer la app con datos: `php scripts/dev-sembrar-demo.php` crea, con la API local, un entrenador demo (celular `3009000001`, contraseña en la constante `PASSWORD` del script) con clientes, compras pagadas, un entrenador referido activo, una conversión, un canje y un pedido sin pagar. Solo corre en local y necesita el backend en marcha y `SMS_DRIVER=log`.
- Las pruebas escriben en la base local y no la limpian. Si algo queda raro, `scripts/reset-db.sh` la recrea.
- Las apps: `npm run start:api` en `premiumfitness-web` (puerto 4200) y en `premiumfitness-admin` (puerto 4201) usan este backend; `npm start` usa su API simulada.
- Toda ruta nueva de `/api` lleva su credencial; si es pública a propósito, se añade a la lista de `tests/E2E/Security/RoutesRequireCredentialsTest.php`.

## Antes de cualquier tarea

Lee y aplica todas las reglas de `.agents/rules/`:

- `.agents/rules/specs-y-progreso.md`: se aplica siempre. Indica cómo documentar cada requerimiento en `specs/`, cuándo escribir un plan y cómo marcar el progreso.
- `.agents/rules/codebase-standards.md`: se aplica antes de escribir o modificar código.
- `.agents/rules/agent-skills.md`: se aplica al crear o modificar una skill.

Si se añaden reglas nuevas a esa carpeta, también se aplican.

## Documentación del proyecto

| Archivo | Contenido |
| --- | --- |
| `docs/requerimientos.md` | Requisitos originales. Ante una contradicción, prevalece este documento. |
| `docs/PRD-PremiumFitness.md` | Requisitos del producto y decisiones pendientes. |
| `docs/MVP-PremiumFitness.md` | Alcance y criterios de aceptación de la primera entrega. |
| `docs/reglas-de-negocio.md` | Reglas de puntos, comisiones, canje y dietas. |
| `docs/casos-de-uso-y-actores.md` | Actores y recorridos. |
| `docs/fases-de-desarrollo.md` | Orden de trabajo y repositorios de referencia. |
| `specs/` | Spec de cada requerimiento, con su checklist de progreso. |

## Skills

Antes de una tarea que encaje con una skill, lee su `SKILL.md` en `.agents/skills/<nombre>/`.

| Skill | Úsala para |
| --- | --- |
| `backend-testing-pest-realdb` | Crear o ejecutar pruebas del backend Slim 4 + PHP 8.5 con Pest y base de datos real. |
| `clean-code-php` | Escribir o refactorizar PHP limpio y moderno. |
| `php85-coding` | Escribir código PHP 8.5 con su sintaxis nueva. |
| `slim-php85-scaffold` | Generar módulos o proyectos Slim 4 + PHP 8.5 con Vertical Slicing. |
| `clean-code-js-ts` | Escribir o refactorizar JavaScript y TypeScript (frontend). |
| `frontend-design` | Diseñar interfaces, páginas y componentes web. |
| `image-optimization` | Crear scripts o endpoints que optimicen imágenes de producto. |
| `image-webp-optimizer` | Convertir imágenes a WebP, generar tamaños responsive y aplicar lazy loading. |
| `integracion-pasarelas` | Integrar pasarelas de pago colombianas y sus webhooks. |
| `jwt-security` | Implementar autenticación con access token en memoria y refresh token en cookie `httpOnly`. |
| `product-lightbox` | Crear la galería de producto con zoom y lightbox. |
| `sms-account-verification` | Verificar teléfonos con códigos SMS y rate limiting. |
| `readme-blueprint-generator` | Generar el README del repositorio a partir de su documentación. |

Las skills son una copia de las de `backend-radical-nutrition`, del que este repositorio es un fork.

## Convenciones

- Escribe la documentación y los specs en español.
- Los commits siguen el formato del historial: `tipo: emoji Descripción en español` (por ejemplo, `feat: ✨ Agrega ...`).

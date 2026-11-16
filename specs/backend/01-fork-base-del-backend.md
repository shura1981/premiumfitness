# 01 — Backend: fork base de radical

**Estado:** fork base terminado · **Creado:** 15 de noviembre de 2026 · **Cerrado:** 15 de noviembre de 2026

## Petición original

> Termina los frontends y luego el backend.

El backend es un fork de `backend-radical-nutrition` (spec `arquitectura/01`) y vive en este repositorio.

## Objetivo

Tener en este repo el backend de radical funcionando tal cual (misma API, mismas pruebas), con el nombre y la configuración de PremiumFitness y sin los accesos inseguros. Es la base sobre la que se construyen después las reglas propias: referidos, catálogo de la intranet, comisión, puntos, canje y acceso a dietas.

## Alcance

- Copiar el código versionado de radical: `app/`, `src/`, `load/`, `tests/`, `views/`, `assets/`, `cron-jobs/`, scripts, `index.php`, `server.php`, `.htaccess`, `composer.json`, `composer.lock`, `phpunit.xml` y `.env.example`, más su esquema, migraciones y datos de prueba (`docs/database/`).
- Nombre del proyecto, base de datos y variables de entorno de PremiumFitness.
- Una base de datos local propia para desarrollo y pruebas.
- Seguridad mínima del fork (spec `arquitectura/01`, S-05): sin `login-with-credential`, sin autologin por QR, y `storage/` protegido.
- `composer run lint` y `composer run test` pasando.

## Fuera de alcance

- Las reglas de negocio de PremiumFitness y los cambios del DER: cada una tiene su spec y entra después (ver «Lo que sigue»).
- Quitar las features de radical que PremiumFitness no usa (tienda pública, checkout retail, saldo a favor): se retiran al adaptar cada parte, para no romper las pruebas del fork.
- Desplegar en el hosting.

## Criterios de aceptación

1. `composer install`, `composer run lint` (PHPStan) y `composer run test` (Pest) terminan sin errores en este repo.
2. `POST /api/auth/login-with-credential` y `GET /qr` no existen: responden 404 o 405 y no emiten tokens.
3. `storage/`, `app/`, `src/`, `load/`, `vendor/` y `tests/` no se sirven por web, y `storage/private/` no entra a git.
4. El repo no contiene secretos: ni `.env`, ni credenciales de radical.
5. La base de datos local es propia de PremiumFitness; no se usan credenciales de otros proyectos.
6. La documentación de reglas y skills del repo sigue siendo la de PremiumFitness.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| B-01 | El backend vive en la raíz de este repo, junto a `docs/` y `specs/`. | Derivado: `codebase-standards.md` describe `src/`, `app/` y `load/` en este repo |
| B-02 | Se copia la rama de trabajo actual de radical (`add_bold_pay_system`, con la pasarela Bold), no `main`. | Derivado: es la más reciente; confirmar |
| B-03 | No se conserva el historial de git de radical: se copian los archivos. Este repo ya tiene su propio historial. | Derivado |
| B-04 | La base de datos se llama `crisenri_premiumfitness`, con el prefijo del hosting, como `crisenri_radical_nutrition`. | Derivado |
| B-05 | En local, la base vive en el MariaDB del sistema (11.8, el que usa phpMyAdmin) y ya tiene `crisenri_intranet`, que solo se lee. `scripts/reset-db.sh` entra con `DB_USER`/`DB_PASS` del `.env`, como en radical. En el hosting el esquema se importa desde phpMyAdmin con un solo `.sql`. | Confirmado (16 nov 2026, por el usuario) |
| B-06 | Se añade `DB_PORT` (opcional, 3306 por defecto) a la configuración de la base de datos; radical no lo tiene. | Derivado |
| B-08 | La sesión de la base de datos se fija en hora de Colombia (`SET time_zone = '-05:00'`), igual que PHP. Radical dependía de la hora del servidor: con la base en UTC, los códigos de verificación nacían vencidos. | Derivado |
| B-09 | Quedan sin uso, a la espera de su spec: `SapClientService` (consulta del socio de negocio en SAP, S-28 del DER) y las features de tienda pública, checkout retail y saldo a favor. | Derivado |
| B-10 | `firebase/php-jwt` 6 tiene un aviso de seguridad de severidad baja (CVE-2025-45769, claves débiles); se corrige en la versión 7, que cambia de versión mayor. El proyecto ya exige secretos de 32 caracteres o más. Actualizado a la 7.2.1 el 15 nov 2026, con la autenticación: `composer audit` no reporta avisos. | Confirmado |
| B-07 | Las pantallas HTML de radical (tienda pública, `views/`) y `assets/` se copian y se retiran después, al adaptar cada parte. | Derivado |

## Plan

1. **Copia:** `git archive` de la rama de radical, para llevar solo archivos versionados (sin `.env`, `vendor/`, `dist/`, `logs/` ni `cache/`). No se copian sus reglas de agentes, su README, sus specs ni sus documentos de producto. `scripts/` de radical convive con `scripts/der/`.
2. **Base de datos local:** MariaDB del sistema con el esquema de `docs/database/schema/crisenri_premiumfitness.sql`; `scripts/reset-db.sh` la recrea.
3. **Configuración:** `.env.example` con los nombres de PremiumFitness; `.env` local con valores de desarrollo (no se versiona).
4. **Seguridad:** quitar las dos rutas y sus pruebas; `.htaccess` y `.gitignore` con `storage/`.
5. **Verificación:** PHPStan y Pest. Lo que falle por datos de radical se corrige en el fork, no en radical.

## Resultado (15 de noviembre de 2026)

- **Copiado:** 379 archivos versionados de radical (rama `add_bold_pay_system`, commit `afa7e47`), sin secretos.
- **Pruebas:** `composer run test` da 236 pruebas pasadas y 1 omitida (heredada: pide el usuario con id 1). `composer run lint` no da errores.
- **Seguridad:** `POST /api/auth/login-with-credential` y `GET /qr` responden 404, con una prueba de regresión que antes fallaba (devolvían un token y una redirección). Tampoco se sirven ya las apps Angular desde el backend.
- **Dependencias:** se actualizaron Slim (4.15.3) y `symfony/cache` (8.1.11) por dos avisos de seguridad de severidad media. Queda uno de severidad baja (B-10).
- **Base local:** se montó primero en un contenedor Docker; el 16 nov 2026 pasó al MariaDB del sistema (B-05).

## Lo que sigue (cada punto, con su spec)

| Orden | Trabajo | Spec |
| --- | --- | --- |
| 1 | Migraciones del DER: tablas nuevas y columnas añadidas. **Hecho** (spec `backend/02`) | `diseño/01` |
| 2 | Autenticación: celular y contraseña, refresh token en cookie, roles y códigos SMS. **Hecho** (spec `backend/03`) | `seguridad/01` |
| 3 | Catálogo desde la intranet y retiro de `products`, categorías, grupos y SAP | `catalogo/01` |
| 4 | Entrenadores, cupón, enlaces y referidos | `negocio/01`, `negocio/02` |
| 5 | Compras con descuento por rol, IVA por línea y recompensas al aprobar el pago | `negocio/01`, `catalogo/01` |
| 6 | Puntos: lotes, FIFO y vencimiento; saldos del entrenador | `negocio/03` |
| 7 | Comisión: movimientos, conversión, cierre mensual y pago | `negocio/04`, `negocio/05` |
| 8 | Canje con puntos | CU-12 |
| 9 | Acceso a la app de dietas | `integraciones/02` |
| 10 | Notificaciones push | `integraciones/01` |
| 11 | Admin: entrenadores, estrategias, cierre y ajustes | `frontend/01` |

Los contratos que hoy usa la app web con su API simulada (`specs/frontend/01`, plan) son la referencia para los endpoints de los puntos 2 a 9.

## Checklist

- [x] Copiar el código versionado de radical a este repo
- [x] Unir `.gitignore` y `.htaccess` (con `storage/` y `tests/` bloqueados)
- [x] Renombrar el proyecto en `composer.json` y ajustar `.env.example`
- [x] Añadir `DB_PORT` a la configuración de la base de datos
- [x] Crear la base de datos local y cargar el esquema, las migraciones y los datos de prueba
- [x] `composer install`
- [x] Un solo esquema, `docs/database/schema/crisenri_premiumfitness.sql`, en lugar del volcado de radical y sus ocho migraciones; `scripts/reset-db.sh` como el de radical (B-05). Verificado en un MariaDB 11.8 desechable: reimportado da la misma estructura y datos; Pest 429 + 2 omitidas, dos veces
- [x] Las consultas nombran la intranet con `INTRANET_DB_NAME` (`IntranetTables`), y las pruebas usan `crisenri_intranet_pruebas`: verificado que `crisenri_intranet` queda idéntica (CHECKSUM) tras dos corridas
- [x] Crear `crisenri_premiumfitness` en el MariaDB del sistema (31 tablas) y correr las pruebas allí: 430 pasan y 1 se omite (la de permisos, porque el usuario local los tiene todos), dos veces; `crisenri_intranet` idéntica antes y después (CHECKSUM de `tb_empleados` y `tb_productos_megaplexstars`)
- [x] Quitar `docs/database/seed/dev-user-addresses.sql` (heredado de radical, con datos personales y sin uso): la base local queda vacía, con solo los datos de referencia
- [x] Quitar `login-with-credential` y el autologin por QR, con sus pruebas
- [x] `composer run lint` sin errores
- [x] `composer run test` sin fallos
- [x] Actualizar AGENTS.md y `codebase-standards.md` (ya hay código) y cerrar las dos casillas del spec `arquitectura/01`

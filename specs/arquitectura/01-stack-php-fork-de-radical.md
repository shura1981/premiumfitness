# 01 — Stack: fork de backend-radical-nutrition en PHP 8.5

**Estado:** en curso · **Creado:** 9 de noviembre de 2026

## Petición original

> Trae las skills que vamos a usar, como vamos a hacer un fork de ese repo, y elimina las de Node.js, ya que no vamos a usar ese stack en este repo.
>
> (Confirmado después: traer las reglas de radical y corregir las menciones a Node.)

## Objetivo

Dejar la documentación y las reglas de los agentes alineadas con la decisión de construir PremiumFitness como un fork de `backend-radical-nutrition`. Así ningún agente propone código en Node.js ni Express.

## Alcance

- Skills: copia exacta de las de radical, sin las de Node.
- Reglas: `codebase-standards.md` basado en el de radical, conservando las reglas propias de PremiumFitness; `agent-skills.md` de radical.
- Menciones al stack en AGENTS.md, el PRD, las fases de desarrollo, el spec del DER y los specs de seguridad y negocio.
- Borrar el `.nvmrc`.

## Fuera de alcance

- Crear el fork y copiar el código. Es la primera tarea de codificación.
- Decidir qué features de radical se eliminan del fork (por ejemplo, la tienda pública).
- El stack del frontend: siguen siendo apps Angular.

## Criterios de aceptación

1. Ningún documento de reglas, PRD, fases o specs dice que el backend es Node.js o Express. Las menciones históricas quedan marcadas como reemplazadas.
2. `codebase-standards.md` describe PHP 8.5, Slim 4, PDO, Pest y PHPStan, y conserva:
   - las constantes de negocio en variables de entorno;
   - la autenticación del spec `seguridad/01`;
   - el cron externo.
3. `.agents/skills/` solo tiene skills de radical, más `readme-blueprint-generator`.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| S-01 | El backend es PHP 8.5 + Slim 4 + MariaDB, como radical, desplegado en el mismo hosting (cPanel con LiteSpeed y Apache). | Confirmado (9 nov 2026) |
| S-02 | El frontend son tres apps: web (PWA Angular para clientes y entrenadores), admin (Angular) y dietas (Flutter). Antes decía apps separadas para clientes y entrenadores; lo cambió el spec `arquitectura/02`. | Confirmado |
| S-03 | Los crons usan `CronJobTokenMiddleware` y `CRON_JOB_TOKEN` de radical (header `X-Cron-Job-Token`, comparación con `hash_equals`). Reemplaza al `COMMISSION_CLOSE_TOKEN` propuesto en el spec `negocio/05`. | Derivado |
| S-04 | El rate limiting usa `RateLimitMiddleware` de radical (Symfony RateLimiter con almacenamiento en disco). El disco es compartido por todos los procesos de LiteSpeed, así que los límites no se duplican. Hay que añadir límites por celular, además de por IP. | Derivado |
| S-05 | Al hacer el fork, hay que desactivar el endpoint `login-with-credential` y el autologin por QR de radical, porque entregan tokens sin contraseña (spec `seguridad/01`). | Derivado |
| S-06 | `database-schema.md` de radical no se trae. La fuente del esquema es el DER (`specs/diseño/01-DER.md` y `scripts/der/generar_der.py`). | Derivado |
| S-07 | Radical envía los SMS con Relax Global (`SMS_API_URL`, `SMS_API_TOKEN`). Es el candidato natural para D-10. | Pendiente de confirmar |

**Diferencia con la documentación anterior:** el PRD y las fases decían «Node.js con Express». Lo reemplaza esta decisión del usuario del 9 nov 2026. `docs/requerimientos.md` no fija el stack.

## Checklist

- [x] Copiar las skills de radical y borrar las de Node
- [x] Actualizar la tabla de skills de AGENTS.md
- [x] Reemplazar `codebase-standards.md` por la versión basada en radical, con las reglas propias de PremiumFitness
- [x] Traer `agent-skills.md` de radical, ajustado a `.agents/skills/`
- [x] Corregir las menciones a Node en AGENTS.md, el PRD, las fases, el spec del DER y los specs de seguridad y negocio
- [x] Borrar `.nvmrc`
- [ ] Crear el fork y desactivar `login-with-credential` y el autologin por QR (primera tarea de codificación)
- [ ] Al crear el fork, añadir `storage` a las carpetas bloqueadas del `.htaccess` y al `.gitignore` (`storage/private/`)
- [ ] Confirmar el proveedor de SMS (S-07)

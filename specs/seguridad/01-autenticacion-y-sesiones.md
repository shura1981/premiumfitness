# 01 — Autenticación y sesiones

**Estado:** en curso · **Creado:** 9 de noviembre de 2026

## Petición original

> ¿Y el sistema de autenticación cómo lo tienes definido?
>
> Decisiones (9 nov 2026):
> - Login con celular y contraseña. El SMS solo verifica el celular al registrarse y sirve para recuperar la contraseña.
> - Un rol por cuenta: cliente o entrenador, nunca ambos.
> - La sesión dura 30 días.

## Objetivo

Definir cómo se registran, inician sesión, mantienen la sesión y recuperan el acceso los clientes, los entrenadores y los agentes del admin. La autenticación debe ser segura en un hosting compartido y con dos apps en subdominios distintos.

## Alcance

- Registro con verificación del celular por SMS.
- Login con celular y contraseña en la app de clientes y en la de entrenadores.
- Sesión con access token en memoria y refresh token rotativo en cookie.
- Recuperación de la contraseña por SMS.
- Logout, revocación y cuenta suspendida.
- Login del admin con `tb_empleados`.
- Límites de intentos.

## Fuera de alcance

- Elegir el proveedor de SMS (D-10).
- Login con redes sociales y segundo factor para clientes y entrenadores.
- Cambiar cómo la intranet guarda las contraseñas de `tb_empleados`.

## Reglas

1. **Un rol por cuenta:**
   - el celular (`users.num_cell`) es único;
   - una cuenta es `client` o `trainer`, nunca ambas;
   - el entrenador compra en su propia app con su descuento.
2. **Registro:**
   - se crea la cuenta con `status='pending'` y se envía un código SMS;
   - al verificarlo se guarda `phone_verified_at` y la cuenta pasa a `active`;
   - la app de clientes solo registra clientes y la de entrenadores solo registra entrenadores;
   - el enlace de referido (id del entrenador en base64) se procesa al terminar el registro o el login (reglas 12 y 13).
3. **Login:**
   - con celular y contraseña;
   - la app de clientes solo acepta cuentas `client` y la de entrenadores solo cuentas `trainer`. Si no coincide, el mensaje indica cuál es la app correcta;
   - una cuenta `pending` recibe la opción de verificar el celular;
   - una cuenta `suspended` no entra.
4. **Contraseña:**
   - mínimo 8 caracteres;
   - hash con Argon2id o bcrypt, que se actualiza si cambia el algoritmo (como `PasswordHasher` de radical);
   - nunca se compara en texto plano.
5. **Sesión:**
   - **Access token:** JWT de 15 minutos que vive solo en memoria. Lleva `sub`, `role` y `app`, y se rechaza en otra app.
   - **Refresh token:** dura 30 días, va en una cookie `httpOnly`, `Secure` y `SameSite=Strict`, y la cookie solo se envía a la ruta de auth de su app. Se rota en cada uso.
   - **Reutilización:** si se usa un refresh token ya rotado, se revoca toda su familia de sesiones.
6. **Una cookie por app:**

   | App | Cookie | Ruta |
   | --- | --- | --- |
   | Clientes | `pf_rt_client` | `/api/client/auth` |
   | Entrenadores | `pf_rt_trainer` | `/api/trainer/auth` |
   | Admin | `pf_rt_admin` | `/api/admin/auth` |

   La app de dietas (Flutter, app `diets`) es nativa: no usa cookie, guarda el refresh token en `flutter_secure_storage` y lo envía en el cuerpo de `/api/diets/auth/refresh` (spec `integraciones/02`).

   Las apps están en subdominios del mismo dominio, así que `SameSite=Strict` funciona. CORS solo acepta los orígenes de `CORS_ALLOWED_ORIGINS`, con credenciales.
7. **Recuperación:** se pide un código SMS para recuperar la contraseña. Al verificarlo se emite un token de un solo uso de 10 minutos (`password_reset_tokens`) para fijar la nueva contraseña. Al cambiarla se revocan todas las sesiones.
8. **Códigos SMS:**
   - 6 dígitos, válidos 5 minutos;
   - se guardan como hash y se comparan en tiempo constante;
   - máximo 5 intentos por código;
   - un código nuevo invalida el anterior;
   - nunca se devuelven por la API ni se escriben en los logs.
9. **Límites:**
   - **Login:** 5 por minuto por IP y 10 fallos en 15 minutos por celular.
   - **Envío de códigos:** 3 por minuto y 10 al día por celular.
   - **Verificación:** 5 por minuto por IP.

   Los contadores nunca viven en la memoria del proceso, porque LiteSpeed levanta varios procesos (spec `negocio/05`). Se usa `RateLimitMiddleware` de radical, que guarda los contadores en disco, compartido por todos los procesos.
10. **Logout:** revoca la familia de la sesión y borra la cookie.
11. **Admin:**
    - inicia sesión con correo y contraseña de `tb_empleados`;
    - la sesión dura 12 horas y no se renueva más allá de eso;
    - aplica los mismos límites de intentos.

    `tb_empleados` no se toca: PremiumFitness solo la lee para el login y los JOIN, sin migrarla, escribir en ella ni cambiar sus contraseñas (S-06).
12. **Crons:** se autentican con `CronJobTokenMiddleware` de radical (header `X-Cron-Job-Token` contra `CRON_JOB_TOKEN`), nunca con un JWT ni con el secreto del login.

## Lo que no se copia de radical

| Radical | Riesgo | En PremiumFitness |
| --- | --- | --- |
| `POST /api/auth/login-with-credential` ([ApiRoute.php:99](/home/steven/Proyectos/php/backend-radical-nutrition/app/Routes/ApiRoute.php)) entrega un JWT con solo el `user_id`, sin contraseña ni código | Cualquiera entra a cualquier cuenta probando ids consecutivos | No existe ningún login sin contraseña ni código |
| Un solo JWT de 1 hora en el cuerpo de la respuesta, sin refresh ni revocación | Si el front lo guarda en `localStorage`, un XSS roba la sesión, y no hay forma de cerrarla | Access token en memoria más refresh token rotativo en cookie `httpOnly` |
| Código de 4 dígitos, limitado solo por IP | Se puede adivinar repartiendo intentos entre varias IP | 6 dígitos, intentos por código y límite por celular |
| Admin compara la contraseña en texto plano si no tiene hash | Exposición de credenciales de la intranet | `tb_empleados` no se toca: se verifica igual que radical, con límite de intentos y sesión de 12 horas |

## Criterios de aceptación

1. **Login y app correcta:** un cliente entra a la app de clientes con celular y contraseña. Su token es rechazado por la API de entrenadores, y viceversa.
2. **Registro duplicado:** no se puede registrar dos cuentas con el mismo celular.
3. **Cuenta sin verificar:** una cuenta `pending` no accede al dashboard hasta verificar el celular.
4. **Recarga de la app:** al recargar se recupera la sesión con el refresh token sin pedir login. Después de 30 días sin uso se pide login.
5. **Robo de token:** reutilizar un refresh token ya rotado revoca la familia, y la siguiente renovación falla.
6. **Cambio de contraseña:** cambiar o recuperar la contraseña cierra todas las sesiones.
7. **Códigos y límites:** el sexto intento con un código falla aunque el código sea correcto. Superar los límites de envío de códigos o de login responde 429.
8. **Sin puertas traseras:** ningún endpoint emite tokens sin contraseña o sin un código verificado.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| S-01 | Login con celular y contraseña; el SMS solo verifica el celular y recupera la contraseña. | Confirmado (9 nov 2026) |
| S-02 | Un rol por cuenta y `num_cell` único. Cierra la parte de roles de D-05 y la S-06 del DER. | Confirmado (9 nov 2026) |
| S-03 | Refresh de 30 días con rotación, y access token de 15 minutos. | Confirmado (9 nov 2026); cambia los 7 días de `codebase-standards.md` |
| S-04 | El correo sigue siendo obligatorio (columna de radical) para notificaciones y reportes, pero no sirve para iniciar sesión. | Derivado |
| S-05 | La sesión del admin dura 12 horas, sin renovación más allá. | Derivado |
| S-06 | `tb_empleados` no se toca. PremiumFitness solo la lee (login del admin y JOIN con `agent_id`) y verifica las contraseñas tal como las guarda la intranet. | Confirmado (9 nov 2026) |
| S-07 | Proveedor de SMS. | Pendiente (D-10) |

## Plan

- **Modelo de datos:**
  - `users.num_cell` pasa a ser único.
  - `verification_codes` cambia `code` a `char(64)` (el hash) y añade `purpose` (`phone_verification` o `password_reset`), `destination` y `attempts`.
  - `password_reset_tokens` guarda el token de un solo uso que sale de verificar el código (como hash).
  - **Nueva tabla `refresh_tokens`:**
    - `user_id` o `agent_id`, y `app` (`client`, `trainer` o `admin`);
    - `family_id` y `token_hash`, que es único;
    - `expires_at`, `revoked_at` y `replaced_by_id`;
    - `ip`, `user_agent` y `created_at`.
- **Código:** se parte de `Auth` de radical y de las plantillas PHP de las skills `jwt-security` y `sms-account-verification`. El rate limiting usa `RateLimitMiddleware`, con límites por IP y por celular.
- **Rutas:** se agrupan por app (`/api/client/auth/*`, `/api/trainer/auth/*` y `/api/admin/auth/*`) para limitar la cookie a su ruta.
- **Limpieza:** un proceso nocturno, disparado por el cron del hosting, borra los refresh tokens vencidos hace más de 30 días y los códigos usados o vencidos.

## Checklist

- [x] Revisar la autenticación de radical, api-comisiones y las skills del proyecto
- [x] Definir el login, el rol por cuenta y la duración de la sesión
- [x] Añadir `refresh_tokens`, ajustar `verification_codes` y hacer único `users.num_cell` en `scripts/der/generar_der.py`, y regenerar el DER
- [x] Actualizar `.agents/rules/codebase-standards.md` (30 días, cookies por app y variables de entorno)
- [x] Actualizar el PRD (RF-01 y D-05), CU-01 y los supuestos del DER
- [ ] Elegir el proveedor de SMS (D-10)
- [~] Decidir qué hacer con las contraseñas en texto plano de `tb_empleados` (S-06): descartado, `tb_empleados` no se toca (9 nov 2026)

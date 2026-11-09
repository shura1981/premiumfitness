# 01 — Notificaciones push con Firebase

**Estado:** en curso · **Creado:** 9 de noviembre de 2026

## Petición original

> Aún nos falta aclarar: servidor de notificaciones push con Firebase.

## Objetivo

Enviar notificaciones push a la app web (PWA Angular para clientes y entrenadores) y a la app de dietas (Flutter) cuando ocurre un evento de negocio. El envío no debe frenar la operación que lo origina ni duplicarse en el hosting compartido.

## Contexto encontrado

- Las APIs actuales de la empresa (por ejemplo `htdocs/public/www.elitenutritiongroup.com/api_eliteN/api/webservice.php:2043`) envían a `https://fcm.googleapis.com/fcm/send` con la «server key». **Google retiró ese API legacy en 2024**, así que no se puede copiar.
- Radical tiene la columna `users.token_push_notification`, que guarda un solo token por usuario, pero no tiene código de envío.
- Radical ya incluye `firebase/php-jwt`, que firma con RS256 lo que necesita la cuenta de servicio de Firebase. Las peticiones HTTP se hacen con `CurlHttpClient`.

## Alcance

- Envío con **FCM HTTP v1** desde el backend PHP. No hay un servidor aparte: el «servidor de notificaciones» es una feature `Notification` del backend más un cron.
- Registro de los dispositivos de cada usuario y app.
- Cola de envío (outbox) con reintentos.
- Lista de eventos que notifican.

## Fuera de alcance

- Notificaciones al admin del call center.
- Campañas o mensajes masivos de marketing.
- Preferencias por tipo de notificación (silenciar algunas). Queda para después.

## Reglas

1. **Credenciales:** la cuenta de servicio de Firebase (JSON) se guarda fuera de la carpeta pública (`FIREBASE_CREDENTIALS_PATH`). El backend firma un JWT RS256, lo cambia por un access token de OAuth2 en `oauth2.googleapis.com/token` y lo guarda en caché unos 55 minutos con Symfony Cache.
2. **Envío:** `POST https://fcm.googleapis.com/v1/projects/{FIREBASE_PROJECT_ID}/messages:send`, un mensaje por dispositivo, con `CurlHttpClient` y un timeout de 10 segundos.
3. **Outbox:**
   - El evento de negocio inserta la notificación en `push_notifications` **dentro de su misma transacción** y no llama a Firebase.
   - Un cron del hosting, cada minuto, envía las pendientes.
   - Si falla, reintenta hasta `PUSH_MAX_ATTEMPTS` veces con espera creciente.
   - `source_key`, que es único, evita duplicar una notificación por el mismo evento.
4. **Dispositivos:**
   - Cada app registra su token después del login (`POST /api/push-devices` en la web, `POST /api/diets/push-devices` en dietas) y lo borra al cerrar sesión.
   - Un usuario puede tener varios dispositivos y apps.
   - Si FCM responde `UNREGISTERED` o `INVALID_ARGUMENT` para un token, se borra el dispositivo.
5. **Destino:** cada notificación indica la app destino (`web` o `diets`), y solo se envía a los dispositivos de esa app.
6. **Contenido:** título y texto cortos, sin datos sensibles (no montos de otros usuarios ni datos de clientes). El campo `data` lleva la ruta a abrir en la app (`route`) y el id del recurso.
7. **Web push en iPhone:** solo funciona con la PWA instalada en la pantalla de inicio (iOS 16.4 o posterior). La app debe pedir el permiso después de una acción del usuario, nunca al abrir.

## Eventos que notifican

| Destino | Evento | Origen |
| --- | --- | --- |
| Entrenador | Un cliente se asoció a él (por enlace o cupón) | CU-03, CU-20 |
| Entrenador | Compra aprobada de un cliente: puntos y comisión ganados | CU-05 |
| Entrenador | Un entrenador B se registró con su enlace | CU-07 |
| Entrenador | Se activaron sus 50.000 puntos por referido | CU-08 |
| Entrenador | Puntos que vencen en `PUSH_EXPIRY_NOTICE_DAYS` días | Proceso nocturno de vencimiento |
| Entrenador | Cierre del mes: comisión liquidada | CU-21 |
| Entrenador (app de dietas) | El acceso a dietas vence en 3 días o se desactivó | Spec `integraciones/02` |
| Cliente | Pedido aprobado | CU-04 |
| Cliente | Pedido enviado, con la guía | CU-18 |

La lista es **Derivada** y requiere validación de producto.

## Criterios de aceptación

1. Aprobar una compra crea las notificaciones en la misma transacción. Si Firebase está caído, la compra se aprueba igual y la notificación se reintenta.
2. Procesar dos veces el mismo evento no crea dos notificaciones.
3. Un token que FCM marca como no registrado se borra y no se reintenta.
4. Ninguna petición HTTP del usuario espera a Firebase.
5. El código no usa `fcm/send` ni la server key legacy.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| S-01 | No hay un servidor de push aparte: el backend PHP envía con FCM HTTP v1 desde un cron. | Derivado |
| S-02 | Un solo proyecto de Firebase, con una app web (clientes y entrenadores) y las apps Android e iOS de dietas. El admin no recibe push. | Pendiente: proyecto dedicado «PremiumFitness» o reutilizar `elitenutritiongroup-9385a` |
| S-03 | `users.token_push_notification` de radical se conserva sin usar: los tokens viven en `push_devices`, porque un usuario puede tener varios dispositivos y apps. | Derivado |
| S-04 | La lista de eventos de la tabla. | Derivado: requiere validación |
| S-05 | El cron de envío corre cada minuto. Si el hosting no lo permite, la latencia de la notificación será la del intervalo mínimo del cron. | Derivado |

## Plan

- **Modelo de datos:**
  - `push_devices`: `id`, `user_id`, `app` (`web` o `diets`), `platform` (`web`, `android` o `ios`), `token` (único), `created_at` y `last_seen_at`.
  - `push_notifications`: `id`, `user_id`, `app`, `type`, `title`, `body`, `data` (JSON), `status` (`pending`, `sent` o `failed`), `attempts`, `last_error`, `source_key` (único), `created_at`, `sent_at`.
  - Índice `(status, created_at)` para el cron.
- **Código:**
  - `src/Infrastructure/Notification/FcmPushChannel.php`, junto a los canales de email y SMS de radical.
  - Feature `Notification` con el registro de dispositivos y el job de envío.
- **Variables de entorno:** `FIREBASE_PROJECT_ID`, `FIREBASE_CREDENTIALS_PATH`, `PUSH_MAX_ATTEMPTS=5` y `PUSH_EXPIRY_NOTICE_DAYS=7`.
- **Frontends:**
  - Angular usa el SDK web de Firebase con `firebase-messaging-sw.js` y la VAPID key del proyecto.
  - Flutter usa `firebase_messaging`.

## Checklist

- [x] Revisar cómo envían push hoy los proyectos de la empresa
- [x] Definir el envío con FCM HTTP v1, la outbox y los dispositivos
- [x] Añadir `push_devices` y `push_notifications` al DER
- [x] Añadir las variables de entorno a `.agents/rules/codebase-standards.md`
- [ ] Validar la lista de eventos con producto (S-04)
- [ ] Crear el proyecto de Firebase y la cuenta de servicio (S-02)

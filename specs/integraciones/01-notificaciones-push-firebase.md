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
7. **Web push en iPhone:** tiene restricciones propias. Ver la sección «iPhone y Safari».

## iPhone y Safari

En iPhone y iPad las notificaciones web tienen más restricciones que en Android. Todos los navegadores de iOS usan el motor de Safari, así que estas restricciones aplican también a Chrome en iPhone.

### Restricciones

| Restricción | Consecuencia para PremiumFitness |
| --- | --- |
| Solo funcionan desde **iOS 16.4** y **solo con la PWA agregada a la pantalla de inicio**. Una pestaña de Safari no puede suscribirse. Desde iOS 26, cualquier sitio agregado a la pantalla de inicio con «Abrir como app web» (activado por defecto) cuenta como PWA. | Antes de pedir el permiso, el usuario debe instalar la app. |
| iOS **no muestra un aviso de instalación**: no existe `beforeinstallprompt`. | La app debe enseñar cómo instalarla: Compartir → «Agregar a pantalla de inicio». |
| El permiso **solo se puede pedir tras un toque del usuario** (por ejemplo, un botón «Activar notificaciones»). Si lo rechaza, la web no puede volver a pedirlo. | Pedir el permiso en un momento con sentido, nunca al abrir. Si lo rechazó, explicar cómo activarlo en Ajustes → Notificaciones → PremiumFitness. |
| **Safari y la app instalada no comparten almacenamiento** (cookies ni `localStorage`). | La sesión y el id del enlace del entrenador guardados en Safari no pasan a la app instalada: hay que iniciar sesión otra vez dentro de ella. |
| Cada push **tiene que mostrar una notificación visible**. Si el service worker recibe pushes y no muestra nada, Safari puede retirar la suscripción. | No enviar pushes silenciosos (solo datos) a la web. Todo push lleva título y texto. |
| La suscripción pertenece a la app instalada. Si el usuario la borra de la pantalla de inicio o cambia de teléfono, el token deja de servir. | Registrar el token en cada inicio de la app y borrar los que FCM marque como inválidos (regla 4). |
| Las notificaciones pueden llegar con retraso o agrupadas por los modos de concentración y el resumen programado de iOS. | No usar push para nada urgente ni que dependa de la hora exacta, como los códigos SMS. |

### Recomendaciones

1. **Orden en iPhone:** primero registrarse y asociarse al entrenador **en Safari** (el enlace `/r/c/<id>` o `/r/t/<id>` funciona ahí). Después instalar la app y, dentro de ella, iniciar sesión y activar las notificaciones. Así el vínculo con el entrenador queda guardado en el backend y no se pierde por el almacenamiento separado.
2. **Guía de instalación:** si la app detecta iOS y que no está instalada (`navigator.standalone !== true` y no está en `display-mode: standalone`), muestra un aviso con los pasos de instalación en lugar del botón de activar notificaciones.
3. **Manifest:** `display: "standalone"`, `name` y `short_name` «PremiumFitness», `start_url` dentro del alcance de la app, e iconos con `apple-touch-icon` de 180 × 180 px. Sin `display: standalone`, iOS anterior al 26 no la trata como app.
4. **Service worker de FCM:** `firebase-messaging-sw.js` en la raíz del alcance de la app, servido por HTTPS. En su evento `push` llama siempre a `showNotification`.
5. **Payload:** usar el bloque `webpush` del mensaje de FCM HTTP v1, con `notification` (título y texto) y `fcm_options.link` hacia la ruta de la app. Así la notificación se ve aunque el service worker no alcance a procesarla.
6. **Badge:** se puede usar la Badging API (`navigator.setAppBadge`), disponible en la app instalada, para mostrar pendientes en el icono.
7. **Pruebas:** en un iPhone real, con iOS 16.4 o posterior, con la app instalada. El simulador y una pestaña de Safari no sirven para probar push.
8. **Plan B:** las notificaciones son un complemento. El estado de los pedidos, los puntos y el acceso a dietas siempre se ven al abrir la app. Para avisos críticos en iPhone, la app de dietas (Flutter, nativa) usa APNs a través de FCM, sin estas restricciones.

### Pendiente de verificar

- iOS 18.4 añadió **Declarative Web Push**, que muestra la notificación desde un JSON sin ejecutar el service worker. No está confirmado que FCM HTTP v1 envíe ese formato. Mientras tanto se usa el flujo clásico con service worker (recomendaciones 4 y 5), que funciona desde iOS 16.4.

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

## Proyecto de Firebase

Creado el 9 nov 2026 con la cuenta de la consola de Nutramerican, en el plan Spark (gratis). Google Analytics, Gemini y el programa de desarrolladores quedaron desactivados.

| Dato | Valor |
| --- | --- |
| Nombre | PremiumFitness |
| ID del proyecto (`FIREBASE_PROJECT_ID`) | `premiumfitness-12ca6` |
| Número del proyecto / ID del remitente | `743385984575` |
| API de FCM V1 | Habilitada |
| API de FCM heredada | Inhabilitada |
| App web `premiumfitness-web` (`appId`) | `1:743385984575:web:5313b81ffce001e5a6928a` |
| Clave pública VAPID (certificado push web) | `BMpxiOlbihgKfwDyZc3nubRiP-LTf2q0AYUT9bQONGJBeTpjf7dlvcaO99SHwQzoN7T7aiHsfxraDWDfCqW604M` |
| Cuenta de servicio del backend | `premiumfitness-fcm-sender@premiumfitness-12ca6.iam.gserviceaccount.com`, con **solo** el rol «Administrador de la API de Firebase Cloud Messaging» |

**Dónde va cada dato:**
- La configuración web completa (incluida la `apiKey`) y la clave VAPID van en el `environment` de la app Angular. Son públicas por diseño, pero la `apiKey` se restringe por dominio en Google Cloud.
- La clave privada de la cuenta de servicio **no** se guarda en este repositorio. Va al servidor, en `FIREBASE_CREDENTIALS_PATH`.

## Pendientes que el agente no puede hacer

Tareas de este proyecto que requieren acceso o datos que el agente no tiene. Las hace el equipo en el momento indicado.

| Tarea | Por qué no la hace el agente | Cuándo |
| --- | --- | --- |
| Copiar `firebase-fcm-sender.json` al hosting, fuera de `public_html` y con permisos 600, y configurar `FIREBASE_PROJECT_ID` y `FIREBASE_CREDENTIALS_PATH` en el `.env` del servidor | No tiene acceso al hosting (cPanel) | Al desplegar el fork |
| Restringir la `apiKey` web por dominio en Google Cloud → Credenciales («Browser key (auto created by Firebase)»): `https://<subdominio-app>/*` y `http://localhost:4200/*` | Falta definir el subdominio de la app web | Cuando se defina el subdominio. El agente puede hacerlo si se le da |
| Registrar las apps Android e iOS de dietas en el proyecto | Faltan el `applicationId` y el bundle ID definitivos de la app Flutter | Al integrar la app de dietas |
| Crear la clave APNs (.p8) en Apple Developer (Keys, con «Apple Push Notifications service») y subirla en Cloud Messaging → Configuración de apps de Apple | Requiere la cuenta de Apple Developer de la empresa | Antes de probar push en iOS en la app de dietas |
| Probar el flujo de instalación y push en un iPhone real (S-06) | Requiere un dispositivo físico | Con la app web desplegada en HTTPS |

## Criterios de aceptación

1. Aprobar una compra crea las notificaciones en la misma transacción. Si Firebase está caído, la compra se aprueba igual y la notificación se reintenta.
2. Procesar dos veces el mismo evento no crea dos notificaciones.
3. Un token que FCM marca como no registrado se borra y no se reintenta.
4. Ninguna petición HTTP del usuario espera a Firebase.
5. El código no usa `fcm/send` ni la server key legacy.
6. En un iPhone con iOS 16.4 o posterior y la app instalada, el usuario activa las notificaciones con un botón y recibe el push de una compra aprobada. En Safari sin instalar, la app muestra la guía de instalación en lugar del botón.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| S-01 | No hay un servidor de push aparte: el backend PHP envía con FCM HTTP v1 desde un cron. | Derivado |
| S-02 | Proyecto de Firebase dedicado **PremiumFitness**, con una app web (clientes y entrenadores) y, más adelante, las apps Android e iOS de dietas. El admin no recibe push. No se reutiliza `elitenutritiongroup-9385a` porque tiene datos de otros sistemas y otra marca. | Confirmado (9 nov 2026) |
| S-03 | `users.token_push_notification` de radical se conserva sin usar: los tokens viven en `push_devices`, porque un usuario puede tener varios dispositivos y apps. | Derivado |
| S-04 | La lista de eventos de la tabla. | Derivado: requiere validación |
| S-05 | El cron de envío corre cada minuto. Si el hosting no lo permite, la latencia de la notificación será la del intervalo mínimo del cron. | Derivado |
| S-06 | Restricciones de iPhone y Safari según la documentación disponible en noviembre de 2026: push desde iOS 16.4 solo con la PWA instalada; desde iOS 26 cualquier sitio abierto como app web; Declarative Web Push desde iOS 18.4. | Derivado: verificar en un iPhone real y en las notas de WebKit |

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
- [x] Documentar las restricciones y recomendaciones para iPhone y Safari
- [ ] Probar el flujo de instalación y push en un iPhone real (S-06)
- [ ] Validar la lista de eventos con producto (S-04)
- [x] Crear el proyecto de Firebase PremiumFitness, la app web y el certificado VAPID (S-02)
- [x] Crear la cuenta de servicio `premiumfitness-fcm-sender` con solo el rol de FCM
- [x] Generar la clave JSON de la cuenta de servicio (9 nov 2026). Está en la máquina de desarrollo, en `~/.config/premiumfitness/firebase-fcm-sender.json` (permisos 600, fuera del repo).
- [ ] Copiar la clave al hosting, fuera de `public_html`, y apuntar `FIREBASE_CREDENTIALS_PATH` a ella (al desplegar el fork)
- [ ] Restringir la `apiKey` web por dominio
- [ ] Registrar las apps Android e iOS de dietas y subir la clave APNs (.p8) de Apple

# Variables de Entorno — Bold, Addi, Sistecrédito & ePayco

Todas las variables se definen en el archivo `.env` de la API intermediaria o del backend y se cargan con `vlucas/phpdotenv`. Acceso en PHP: `$_ENV['NOMBRE_VARIABLE']` (siempre dentro de métodos fábrica `fromEnv()`).

---

## Bold (Botón de Pagos) {#bold}

| Variable | Ejemplo de valor | Descripción |
|----------|-----------------|-------------|
| `BOLD_IDENTITY_KEY` | `xxxxxxxxxxxxxxxx` | Llave pública de identidad del comercio en Bold. Se envía al frontend como `apiKey` dentro de `BoldCheckout` y en el header `Authorization: x-api-key {BOLD_IDENTITY_KEY}` al consultar el API de notificaciones. |
| `BOLD_SECRET_KEY` | `xxxxxxxxxxxxxxxx` | Llave secreta del comercio en Bold. **Nunca se expone al frontend**. Se usa para calcular `integritySignature` (`sha256(orderId . amount . currency . secretKey)`) y para verificar `x-bold-signature` (`hash_hmac('sha256', base64_encode($rawBody), $secretKey)`). |
| `BOLD_URL_NOTIFICATIONS` | `https://integrations.api.bold.co/payments/webhook/notifications` | Endpoint base de Bold para consultar notificaciones de pago por referencia externa (`?is_external_reference=true`) o por `payment_id`. |
| `BOLD_URL_RESPONSE` | `https://tienda.com/response/bold` | URL base de retorno (`redirectionUrl`) a la que Bold redirige al usuario al finalizar el pago. |

### Headers hacia el API de Notificaciones de Bold

```
Authorization: x-api-key {BOLD_IDENTITY_KEY}
Accept: application/json
```

> **Importante:** El prefijo en el header `Authorization` para Bold es literalmente `x-api-key ` seguido de `BOLD_IDENTITY_KEY` (no `Bearer`).

---

## Sistecrédito (Credinet) {#sistecredito}

| Variable | Ejemplo de valor | Descripción |
|----------|-----------------|-------------|
| `URL_POST_CREATE_ORDER` | `https://api.credinet.co/pay/create` | Endpoint para crear una orden de pago en Credinet |
| `URL_GET_QUERY_TRANSACTION` | `https://api.credinet.co/pay/GetTransactionResponse` | Endpoint para consultar el estado de una transacción |
| `SUBSCRIPTION_KEY` | `xxxxxxxxxxxxxxxx` | Clave de suscripción de API Management (`Ocp-Apim-Subscription-Key`) |
| `APPLICATION_KEY` | `xxxxxxxxxxxxxxxx` | Identificador de la aplicación en Credinet (`ApplicationKey`) |
| `APPLICATION_TOKEN` | `xxxxxxxxxxxxxxxx` | Token de autenticación de la aplicación (`ApplicationToken`) |
| `URL_RESPONSE_SISTECREDITO` | `https://tienda.com/response-sistecredito/index.html` | URL de redirección post-pago — flujo e-commerce normal (`mode == "1"`) |
| `URL_RESPONSE_SISTECREDITO_LINKPAGO` | `https://pagos.tienda.com/response-sistecredito/index.html` | URL de redirección post-pago — flujo link de pago (`mode != "1"`) |
| `URL_CONFIRMATION_SITECREDITO` | `https://api.tudominio.com/confirmation_sistecredito.php` | Webhook server-to-server de Credinet (callback POST) |
| `TIMEOUT_CURL_TOKEN` | `20` | Timeout en segundos para todas las llamadas cURL (conexión + ejecución) |

### Headers hacia Credinet (construidos en el controller)

```
SCOrigen: Production
country: co
Content-Type: application/json
Ocp-Apim-Subscription-Key: {SUBSCRIPTION_KEY}
ApplicationKey: {APPLICATION_KEY}
ApplicationToken: {APPLICATION_TOKEN}
SCLocation: 0,0
```

> **Importante:** Los 3 headers de autenticación (`Ocp-Apim-Subscription-Key`, `ApplicationKey`, `ApplicationToken`) son **obligatorios**. La ausencia de cualquiera de ellos causa rechazo 401 por parte de Credinet.

---

## Addi {#addi}

### Producción

| Variable | Ejemplo de valor | Descripción |
|----------|-----------------|-------------|
| `ADDI_AUDIENCE` | `https://api.addi.com` | Audience para el token OAuth 2.0 |
| `GRANT_TYPE` | `client_credentials` | Tipo de grant OAuth 2.0 (siempre `client_credentials`) |
| `CLIENT_ID` | `xxxxxxxxxxxxxxxx` | Client ID de la aplicación OAuth registrada en Addi |
| `CLIENT_SECRET` | `xxxxxxxxxxxxxxxx` | Client Secret OAuth |
| `URL_GET_TOKEN` | `https://auth.addi.com/oauth/token` | Endpoint Auth0 para obtener el Bearer token |
| `URL_GET_REDIRECTION` | `https://api.addi.com/v1/online-applications` | Endpoint para crear la aplicación de crédito y obtener URL |
| `ALLI_SLUG` | `nombre-comercio-ecommerce` | Slug del comercio registrado en Addi (referencial, no se usa directamente en el código) |
| `TIMEOUT_CURL_TOKEN` | `20` | Compartida con Sistecrédito — timeout cURL en segundos |

### Staging / Testing

| Variable | Ejemplo de valor | Descripción |
|----------|-----------------|-------------|
| `ADDI_AUDIENCE_TEST` | `https://api.staging.addi.com` | Audience para staging |
| `CLIENT_ID_TEST` | `xxxxxxxxxxxxxxxx` | Client ID de staging |
| `CLIENT_SECRET_TEST` | `xxxxxxxxxxxxxxxx` | Client Secret de staging |
| `URL_GET_TOKEN_TEST` | `https://auth.addi-staging.com/oauth/token` | Auth endpoint de staging |
| `URL_GET_REDIRECTION_TEST` | `https://api.addi-staging.com/v1/online-applications` | API endpoint de staging |

> **Nota:** Las variables `_TEST` deben cargarse explícitamente en el controller cuando se implemente el switch staging/producción. Por defecto solo se consumen las variables de producción.

### URLs de Addi (van en el body del request, NO en `.env`)

Las URLs de callback y redirección de Addi se envían **dentro del body** de cada request, en el campo `allyUrlRedirection`. Son definidas por el e-commerce en cada petición:

```json
"allyUrlRedirection": {
  "logoUrl": "https://tienda.com/logo.png",
  "callbackUrl": "https://api.tudominio.com/confirmation_addi.php",
  "redirectionUrl": "https://tienda.com/response-addy/index.html?id_order={orderId}"
}
```

---

## ePayco {#epayco}

| Variable | Ejemplo de valor | Descripción |
|----------|-----------------|-------------|
| `P_CUST_ID_CLIENTE` | `89235` | ID del cliente en ePayco. Se codifica en Base64 y se envía en el header `EntityClientId` al obtener el token |
| `PUBLIC_KEY` | `xxxxxxxxxxxxxxxx` | Llave pública ePayco. Se combina con `PRIVATE_KEY` para generar `Authorization: Basic base64(PUBLIC_KEY:PRIVATE_KEY)` al autenticar |
| `PRIVATE_KEY` | `xxxxxxxxxxxxxxxx` | Llave privada ePayco. Combinada con `PUBLIC_KEY` en formato `base64(public:private)` |
| `AUTHENTICATION_BASIC` | `xxxxxxxxxxxxxxxx` | Token Base64 fijo para proteger los endpoints de la API interna. Validado por el middleware `BasicAuth` contra el header `Authorization` de cada request entrante |
| `LOCALHOST` | `localhost` | Host de MySQL |
| `NAME_DB` | `crisenri_intranet` | Nombre de la base de datos (tabla `tb_visitas`) |
| `USER_DB` | `usuario_db` | Usuario de MySQL |
| `PASS_DB` | `contraseña_db` | Contraseña de MySQL |

### Caché del token Bearer

El token Bearer se almacena en `json/token/token_epayco.json`:

```json
{
  "token": "eyJhbGciOiJSUzI1NiIsInR5cCI...",
  "timestamp": 1713898800
}
```

TTL configurado: **900 segundos (15 minutos)**. Pasado ese tiempo se solicita un nuevo token.

### Header EntityClientId — formato exacto

```
EntityClientId: 'base64(P_CUST_ID_CLIENTE)'
```

Las comillas simples son **literales** e intencionales. El header debe construirse así:

```php
'EntityClientId: \'' . base64_encode($_ENV['P_CUST_ID_CLIENTE']) . '\''
```

### Headers hacia apify.epayco.co (al obtener token)

```
Authorization: Basic base64(PUBLIC_KEY:PRIVATE_KEY)
EntityClientId: 'base64(P_CUST_ID_CLIENTE)'
Content-Type: application/json
```

### Headers hacia apify.epayco.co (demás endpoints)

```
Authorization: Bearer {token_cacheado}
Content-Type: application/json
```

---

## Variable Compartida: Middleware de Autenticación

| Variable | Descripción |
|----------|-------------|
| `KEY_HEADER` | Token estático Base64 que el e-commerce debe enviar en el header `Authorization` de cada request a la API intermediaria (Addi/Sistecredito). No es OAuth ni JWT — es un secreto compartido fijo. |
| `AUTHENTICATION_BASIC` | Equivalente de `KEY_HEADER` en el microservicio ePayco. Mismo patrón pero con nombre de variable diferente. |

```php
// El cliente debe incluir en cada request:
// Authorization: {valor_de_KEY_HEADER}  ← para Addi/Sistecredito
// Authorization: {valor_de_AUTHENTICATION_BASIC}  ← para ePayco

// Validación en BasicAuth middleware (ePayco):
if ($_SERVER['HTTP_AUTHORIZATION'] !== $_ENV['AUTHENTICATION_BASIC']) {
    // Responder 401
}
```

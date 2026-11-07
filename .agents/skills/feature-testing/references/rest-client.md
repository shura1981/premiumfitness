# Colección manual con REST Client

La extensión [REST Client](https://marketplace.visualstudio.com/items?itemName=humao.rest-client) (`humao.rest-client`) ejecuta peticiones escritas en archivos `.http` desde VS Code: encima de cada una aparece **Send Request**. Con eso la colección vive versionada junto al código, organizada por feature, y los cuerpos de respuesta quedan guardados al lado como documentación del contrato.

## Contenido

1. Estructura y anatomía de un `request.http`
2. `{{$dotenv}}`: cómo resuelve y qué va ahí
3. Token: tres estrategias
4. Variables útiles: `@prompt`, `@note` y las dinámicas
5. `responses/`: guardar y sanear
6. Varios entornos sin exponer secretos
7. Setup del repo y checklist

## 1. Estructura y anatomía

```
tests/http/<feature>/
├── request.http
└── responses/
    ├── list-200.json
    ├── create-201.json
    ├── create-400.json
    └── 401.json
```

```http
### ===========================
### Items Endpoints (protegidos)
### ===========================
@token = {{$dotenv TEST_TOKEN}}

### Listar items por rango de fechas
GET {{$dotenv HOST}}/api/items?fechaI=2026-01-01&fechaF=2026-01-31
Authorization: Bearer {{token}}

###

### Crear item
POST {{$dotenv HOST}}/api/items
Content-Type: application/json
Authorization: Bearer {{token}}

{
  "name": "Tornillo",
  "quantity": 10
}

###

### Eliminar item
# @note
DELETE {{$dotenv HOST}}/api/items/1
Authorization: Bearer {{token}}
```

- **Un `request.http` por feature**, con el mismo nombre de carpeta que la feature en `src/`.
- `###` separa peticiones; el texto que le sigue es el título. Deja un `###` vacío entre peticiones para que se lean como bloques.
- `@var = valor` define una variable de archivo; se usa como `{{var}}`. Las variables de archivo pueden tomar su valor de `$dotenv`, pero nunca contener un secreto literal.
- Una línea en blanco separa headers y body. Comentarios con `#` o `//`.
- Encabezado `(públicos)` o `(protegidos)` para saber de un vistazo si hace falta token.
- Una petición por caso que valga la pena repetir: el happy path y los errores que quieras ver a mano (`400` con un body incompleto, por ejemplo). Lo exhaustivo es trabajo de los tests automáticos.

## 2. `{{$dotenv VAR}}`

Lee `VAR` del archivo `.env`. REST Client lo busca desde la carpeta del `.http` hacia arriba, así que el `.env` de la raíz del repo sirve para `tests/http/<feature>/request.http` y no hace falta copiarlo dentro de `tests/`. Si una variable no se resuelve, el editor la marca y la petición sale con el placeholder en vez del valor: revisa el nombre y que exista en tu `.env`.

**Va en `$dotenv`:**

| Qué | Variable típica |
| --- | --------------- |
| URL base (sin `/` final) | `HOST` |
| Credenciales de prueba | `TEST_EMAIL`, `TEST_PASSWORD` |
| Token ya emitido | `TEST_TOKEN` |
| API keys / claves compartidas | `TEST_API_KEY`, `KEY` |
| Datos personales del usuario de prueba (documento, teléfono, nombre real) | `TEST_DOC`, `TEST_CELL` |
| IDs que solo existen en tu DB local y cambian entre máquinas | `TEST_ORDER_ID` (opcional) |

**Puede ir literal:** IDs y fechas de ejemplo, códigos de producto ficticios, cuerpos con datos inventados. Si dudas si un dato es sensible, va en `$dotenv`.

Cada variable usada se documenta en `.env.example` con un valor de mentira, en un bloque propio:

```bash
# App
HOST=http://localhost:3000

# Test (REST Client). Solo para tests/http; la app no las lee
TEST_EMAIL=test@example.com
TEST_PASSWORD=tu_password_de_prueba
TEST_TOKEN=pegar_token_del_login
```

`{{$processEnv VAR}}` lee una variable del sistema operativo en lugar del `.env`. Sirve en CI o cuando el secreto viene de un gestor (`op run`, `doppler run`), con las mismas reglas.

## 3. Token: tres estrategias

**a) `TEST_TOKEN` en `.env` (la de batch-record).** Ejecutas el login de `tests/http/auth/request.http`, copias el `token` de la respuesta a `TEST_TOKEN` y todas las features lo usan con `@token = {{$dotenv TEST_TOKEN}}`. Es simple y funciona entre archivos. El token vive en un archivo ignorado y expira solo.

**b) Encadenar el login en el mismo archivo.** Las *request variables* leen la respuesta de una petición con nombre. El token no se guarda en ningún lado, pero solo funciona dentro del mismo `.http` y después de haber enviado el login al menos una vez:

```http
# @name login
POST {{$dotenv HOST}}/api/auth/login
Content-Type: application/json

{
  "email": "{{$dotenv TEST_EMAIL}}",
  "password": "{{$dotenv TEST_PASSWORD}}"
}

###
@token = {{login.response.body.$.token}}
```

**c) Script que firma un token de desarrollo.** Útil si el login depende de un servicio externo. Un `scripts/dev-token.ts` que lea el secreto del `.env` y firme con la función de producción, expuesto como `npm run token`. El resultado se pega en `TEST_TOKEN`. Nunca lo hagas contra el secreto de producción.

## 4. Variables útiles

- **`# @prompt nombre Descripción`** antes de una petición: REST Client pide el valor al enviarla y no lo guarda. Sirve para algo que no quieres ni en el `.env` (la contraseña de un usuario real en un ambiente compartido, un OTP).
  ```http
  # @prompt otp Código recibido por correo
  POST {{$dotenv HOST}}/api/auth/verify
  Content-Type: application/json

  { "otp": "{{otp}}" }
  ```
- **`# @note`**: pide confirmación antes de enviar. Ponlo en todo `DELETE` y en escrituras con efectos difíciles de deshacer.
- **Dinámicas** para datos únicos sin inventarlos a mano: `{{$guid}}`, `{{$randomInt 1 1000}}`, `{{$timestamp}}`, `{{$datetime iso8601}}`. Por ejemplo, `"email": "qa+{{$timestamp}}@example.com"` en un registro repetible.

## 5. `responses/`: guardar y sanear

El directorio `responses/` guarda cuerpos reales o representativos de cada caso. Sirve para ver el contrato sin levantar nada, para armar los fakes de los tests y para que el frontend sepa qué esperar.

**Nombres:** `<accion>-<status>.json` en kebab-case (`list-200.json`, `create-201.json`, `create-400.json`, `by-date-200.json`). Los errores comunes a toda la feature van sin acción (`401.json`, `403.json`). Si una respuesta no es JSON (un PDF), no se guarda el binario: anota el `Content-Type` en el título de la petición.

**Cómo guardar:** envía la petición y, en el panel de respuesta, usa **Save Response Body** (icono arriba a la derecha) apuntando a `tests/http/<feature>/responses/<accion>-<status>.json`. Después pásale el formatter del repo.

**Sanear antes de commitear (obligatorio):**

| Dato | Cómo queda |
| ---- | ---------- |
| JWT / tokens de sesión | Truncado: `"eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."` |
| Hashes de contraseña, API keys, firmas | Fuera, o `"<redacted>"` |
| Correo, documento, teléfono, nombre de personas reales | Ficticios: `test@example.com`, `"1234567890"`, `"3001234567"` |
| Listas largas | Dos o tres elementos representativos |
| Firmas en base64, imágenes | Truncadas: `"data:image/png;base64,iVBOR..."` |

Si la API devuelve un campo que no debería (un `password`, un hash), lo que corresponde es arreglar la API y agregar un test que lo verifique, no solo borrarlo del JSON.

## 6. Varios entornos sin exponer secretos

Lo más simple: un `.env` por máquina o entorno, y cambiar `HOST` cuando haga falta. Si se necesita alternar entre `local` y `staging` desde el selector de entornos de REST Client, que `settings.json` guarde **nombres de variables**, no valores, y usar el prefijo `%`:

```jsonc
// .vscode/settings.json (versionado: sin valores sensibles)
"rest-client.environmentVariables": {
	"local": { "hostVar": "HOST", "tokenVar": "TEST_TOKEN" },
	"staging": { "hostVar": "HOST_STAGING", "tokenVar": "TEST_TOKEN_STAGING" }
}
```

```http
@token = {{$dotenv %tokenVar}}
GET {{$dotenv %hostVar}}/api/items
Authorization: Bearer {{token}}
```

`%hostVar` se resuelve primero en el entorno activo (`HOST_STAGING`) y luego se busca ese nombre en el `.env`. Contra producción, solo lecturas y solo si el usuario lo pide.

## 7. Setup del repo y checklist

```jsonc
// .vscode/extensions.json
{ "recommendations": ["humao.rest-client"] }
```

`.gitignore` debe tener `.env` (y `.env.*` excepto `!.env.example` si hay variantes).

Antes de commitear cambios en `tests/http/`:

- [ ] Ningún host, token, contraseña ni dato personal literal en los `.http`.
- [ ] Cada `{{$dotenv VAR}}` tiene su línea en `.env.example`.
- [ ] `DELETE` y escrituras delicadas con `# @note`.
- [ ] Respuestas nuevas nombradas `<accion>-<status>.json`, saneadas y formateadas.
- [ ] `.env` ignorado y sin trackear.
- [ ] `bash <skill>/scripts/audit-http.sh` en verde.

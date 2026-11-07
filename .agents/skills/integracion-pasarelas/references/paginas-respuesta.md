# Páginas de Respuesta de Pasarelas

Documentación completa para replicar las páginas HTML de respuesta de cada pasarela.  
Las plantillas listas para usar están en `templates/paginas-respuesta/`.

---

## Estructura Común a las Páginas de Respuesta

Todas las páginas de respuesta comparten:

- HTML + Bootstrap 4 vía MDB (`https://nutramerican.com/css/mdb.min.css` / `mdb.min.js`)
- Fuente Lato (CDN absoluto a `pagos.nutramerican.com/response-addy/fuentes/`)
- Font Awesome 5.15.3 (CDN absoluto a `pagos.nutramerican.com/response-addy/fuentes/`)
- jQuery 3.1.1 (CDN `cdnjs.cloudflare.com`)
- Imágenes de sellos de seguridad y otros assets **incluidos localmente** en `assets/` junto a las plantillas (portables, no dependen de URLs externas)
- `meta name="robots" content="noindex, nofollow"` — no indexar

### Assets incluidos en la skill

```
templates/paginas-respuesta/
  assets/
    favicon.ico                          Favicon del sitio
    deportistas.webp                     Banner de cabecera (ePayco)
    sellos/
      comodo.png / comodo.webp           Sello Comodo SSL
      intel-mcafee1.png / .webp          Sello Intel McAfee
      CamaraColombianadeCE.png / .webp   Sello Cámara Colombiana de CE
  response-bold.html
  response-addi.html
  response-sistecredito.html
  response-epayco.html
```

> Todas las plantillas usan rutas relativas `./assets/...` — se pueden desplegar copiando la carpeta `paginas-respuesta/` completa a cualquier servidor sin cambiar nada.

### Tabla HTML de datos de la transacción

```html
<table class="table table-bordered">
  <tbody>
    <tr><td>Referencia</td>  <td id="referencia"></td></tr>
    <tr><td>Fecha</td>       <td id="fecha"></td></tr>
    <tr><td>Respuesta</td>   <td id="respuesta"></td></tr>
    <tr><td>Motivo</td>      <td id="motivo"></td></tr>
    <tr><td>Banco</td>       <td id="banco"></td></tr>
    <tr><td>Recibo</td>      <td id="recibo"></td></tr>
    <tr><td>Total</td>       <td id="total"></td></tr>
  </tbody>
</table>
```

El JS rellena cada `id` con el campo correspondiente de la API.

### Helper JS — leer query param

```js
function getQueryParam(param) {
  var foundValue = null;
  location.search.substr(1).split('&').some(function (item) {
    var kv = item.split('=');
    if (kv[0] === param) { foundValue = kv[1]; return true; }
    return false;
  });
  return foundValue;
}
```

### Formatear moneda

```js
const formatter = new Intl.NumberFormat('en-US', {
  style: 'currency', currency: 'USD', minimumFractionDigits: 0
});
$("#total").text(formatter.format(response.data.x_amount) + ' ' + response.data.x_currency_code);
```

---

## Página de Respuesta: Bold

**Archivo:** `response-bold/index.html` (o `/response/bold`)  
**Plantilla:** `templates/paginas-respuesta/response-bold.html`

### Query params de entrada

Cuando Bold redirige al navegador hacia `redirectionUrl`, el propio comercio incluye `?orderId={id}` y Bold añade sus parámetros nativos:

| Parámetro | Fuente | Ejemplo |
|-----------|--------|---------|
| `orderId` | Definido por el backend al armar `redirectionUrl` | `100452` |
| `bold-order-id` | Añadido automáticamente por Bold | `100452` |
| `bold-tx-status` | Añadido automáticamente por Bold (`approved`, `rejected`, `pending`) | `approved` |

En JavaScript se recomienda resolver el ID de orden con fallback:
```js
const id_order = getQueryParam("orderId") || getQueryParam("bold-order-id");
const boldTxStatus = getQueryParam("bold-tx-status");
```

### Endpoint de consulta de estado

```
GET /api/state-transaction?id_visita={id_order}
```

> **Fallback inmediato con `bold-tx-status`**: Si el usuario llega a la página de respuesta milisegundos antes de que Bold entregue el webhook al backend, `state-transaction` puede no tener aún `payment_state_json` o el webhook procesado. En ese caso, la página puede mostrar el estado preliminar según `bold-tx-status` (`approved`, `rejected`, `pending`) o consultar el endpoint de conciliación de Bold.

### Mapeo de campos de respuesta → DOM

Idéntico al formato canónico `x_*` usado por Addi y Sistecrédito (`x_id_invoice`, `x_transaction_date`, `x_response`, `x_response_reason_text`, `x_bank_name`, `x_transaction_id`, `x_amount`, `x_currency_code`).

---

## Página de Respuesta: Addi

**Archivo:** `response-addy/index.html`  
**Plantilla:** `templates/paginas-respuesta/response-addi.html`

### Query param de entrada

| Parámetro | Fuente |
|-----------|--------|
| `id_order` | Enviado por Addi en la URL de redirección (`allyUrlRedirection`) |

### Endpoint de consulta de estado

```
GET /api/state-transaction?id_visita={id_order}
```

> Este endpoint intermedio ya normaliza la respuesta al formato canónico `x_*`.

### Mapeo de campos de respuesta → DOM

| Campo en `response.data` | ID del DOM | Descripción |
|--------------------------|------------|-------------|
| `x_id_invoice`           | `#referencia` | Referencia/orden del e-commerce |
| `x_transaction_date`     | `#fecha`      | Fecha y hora de la transacción |
| `x_response`             | `#respuesta`  | Texto del estado (APROBADA, RECHAZADO…) |
| `x_response_reason_text` | `#motivo`     | Descripción del motivo |
| `x_bank_name`            | `#banco`      | Entidad financiera |
| `x_transaction_id`       | `#recibo`     | ID de transacción en la pasarela |
| `x_amount` + `x_currency_code` | `#total` | Monto formateado |
| `x_cod_response`         | — (lógica JS) | `1`=Aprobada, `2`=Rechazada, `3`=Pendiente, `4`=Fallida |

### Lógica de evento de compra (GTM datalayer)

Si `x_cod_response == 1` se dispara el evento de compra:

```js
function eventPurchase(id_invoice) {
  fetch(`/api/products-shopping?id_visita=${id_invoice}`)
    .then(r => r.json())
    .then(data => {
      import('./datalayer/push_purchase.min.js?v=2').then(module => {
        module.push(data, id_invoice);
      });
    });
}
```

> El módulo `push_purchase.min.js` empuja el evento `purchase` al dataLayer de GTM.

### Imágenes y assets

Todos los assets (sellos de seguridad, favicon, banner) están incluidos en `assets/` dentro de la propia carpeta de plantillas. Las rutas en el HTML son relativas (`./assets/sellos/comodo.webp`, etc.).

| Archivo en `assets/` | Descripción |
|----------------------|-------------|
| `favicon.ico` | Favicon del sitio |
| `sellos/comodo.webp` / `.png` | Sello Comodo SSL |
| `sellos/intel-mcafee1.webp` / `.png` | Sello Intel McAfee |
| `sellos/CamaraColombianadeCE.webp` / `.png` | Sello Cámara Colombiana de CE |

---

## Página de Respuesta: Sistecrédito

**Archivo:** `response-sistecredito/index.html`  
**Plantilla:** `templates/paginas-respuesta/response-sistecredito.html`

### Diferencias con Addi

| Aspecto | Addi | Sistecrédito |
|---------|------|-------------|
| Query param | `id_order` | `orderId` |
| Título `<title>` | `RESPUESTA DE LA TRANSACCIÓN ADDI` | `RESPUESTA DE LA TRANSACCIÓN SISTECRÉDITO` |

> Sistecrédito **reutiliza** los assets de `response-addy/` mediante URLs absolutas porque no tiene su propia carpeta de fuentes ni imágenes.

### Endpoint de consulta de estado

```
GET /api/state-transaction?id_visita={orderId}
```

### Mapeo de campos → DOM

Idéntico al de Addi. Ver tabla anterior.

### Lógica de evento de compra

Idéntica a Addi, salvo que el módulo se importa con URL absoluta:

```js
import('https://pagos.nutramerican.com/response-addy/datalayer/push_purchase.min.js?v=2')
```

### Imágenes y assets

Todos los assets están en `assets/` junto a las plantillas — rutas relativas `./assets/sellos/...`.

| Archivo en `assets/` | Descripción |
|----------------------|-------------|
| `favicon.ico` | Favicon del sitio |
| `sellos/comodo.webp` / `.png` | Sello Comodo SSL |
| `sellos/intel-mcafee1.webp` / `.png` | Sello Intel McAfee |
| `sellos/CamaraColombianadeCE.webp` / `.png` | Sello Cámara Colombiana de CE |

---

## Página de Respuesta: ePayco

**Archivo:** `checkout/respuesta.html`  
**Plantilla:** `templates/paginas-respuesta/response-epayco.html`

### Diferencias con Addi / Sistecrédito

| Aspecto | Addi / Sistecrédito | ePayco |
|---------|---------------------|--------|
| Query param | `id_order` / `orderId` | `ref_payco` |
| Endpoint consulta | API backend (`/api/state-transaction`) | **Directo a ePayco**: `https://secure.epayco.co/validation/v1/reference/{ref_payco}` |
| Campos en respuesta | Estructura canónica `x_*` | Estructura nativa ePayco `x_*` anidada en `response.data` |
| Header banner | — | Imagen `./assets/deportistas.webp` |
| Footer sellos | 3 sellos + footer azul | Solo badge ePayco + sin footer |

### Query param de entrada

| Parámetro | Fuente |
|-----------|--------|
| `ref_payco` | Agregado por ePayco en la URL de redirección `response` |

### Endpoint de consulta (API pública ePayco)

```
GET https://secure.epayco.co/validation/v1/reference/{ref_payco}
```

No requiere autenticación — es un endpoint público.

### Mapeo de campos de respuesta → DOM

| Campo en `response.data` | ID del DOM | Descripción |
|--------------------------|------------|-------------|
| `x_id_invoice`           | `#referencia` | Referencia de la orden |
| `x_transaction_date`     | `#fecha`      | Fecha de la transacción |
| `x_response`             | `#respuesta`  | Texto del estado |
| `x_response_reason_text` | `#motivo`     | Motivo del resultado |
| `x_bank_name`            | `#banco`      | Banco o entidad |
| `x_transaction_id`       | `#recibo`     | Número de recibo |
| `x_amount` + `x_currency_code` | `#total` | Monto formateado |
| `x_cod_response`         | — (lógica JS) | `1`=Aprobada, `2`=Rechazada, `3`=Pendiente, `4`=Fallida |

> `x_approval_code` se bindea a `#autorizacion` en el JS, pero ese campo no está en la tabla HTML.  
> Si se necesita mostrarlo, agregar `<tr><td>Autorización</td><td id="autorizacion"></td></tr>`.

### Imágenes específicas de ePayco

| Ruta en `assets/` | Descripción |
|-------------------|-------------|
| `./assets/deportistas.webp` | Banner de cabecera (incluido localmente) |
| `https://369969691f476073508a-60bf0867add971908d4f26a64519c2aa.ssl.cf5.rackcdn.com/btns/epayco/pagos_procesados_por_epayco_260px.png` | Badge oficial de ePayco (CDN ePayco — no cambiar) |

---

## Guía: Crear una Página de Respuesta para una Nueva Pasarela

1. **Copiar** la plantilla más similar: `templates/paginas-respuesta/response-addi.html`.
2. **Cambiar el `<title>`** y las meta tags OG.
3. **Identificar el query param** que la pasarela añade a la URL de redirección y actualizar `getQueryParam(...)`.
4. **Apuntar al endpoint correcto**: API intermediaria o API pública de la pasarela.
5. **Mapear los campos** de la respuesta JSON a los IDs del DOM.
6. Si la pasarela usa el endpoint intermedio normalizado (`state-transaction`), el mapeo es idéntico al de Addi/Sistecrédito.
7. Si se llama directamente a la pasarela, ajustar los nombres de campo en el JS.
8. **Decidir si llevar GTM/FB Pixel**: añadirlos manualmente si el flujo de conversión lo requiere. Las plantillas base no los incluyen.

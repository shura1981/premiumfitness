# Integración Frontend de Pasarelas

Esta guía detalla cómo se debe consumir la respuesta de la API intermediaria (Backend PHP) desde una aplicación Frontend (como Angular, React o Vanilla JS) para cada una de las pasarelas.

## 0. Payload de Solicitud (Frontend -> Backend)

Independientemente de la pasarela que elija el usuario (Bold, Addi, Sistecrédito o ePayco), el Frontend siempre se comunica con el Backend a través de un **único endpoint unificado** (ej. `POST /api/checkout/finalize`). El frontend NO interactúa directamente con la pasarela para crear la orden, solo le dice al backend qué pasarela eligió el usuario mediante el campo `payment_method`.

### Ejemplo del Payload enviado por el Frontend
```json
{
  "items": [
    {
      "sku": "1001",
      "product_sku": 1001,
      "quantity": 2,
      "price": 150000,
      "lineTotal": 300000
    }
  ],
  "address_id": 12,
  "payment_method": "bold", // Valores posibles: "bold", "addi", "sistecredito", "epayco"
  "shipping": 0,
  "balance_applied": 0,
  "balance_id": null,
  "order_notes": "Dejar en portería"
}
```

El backend toma este payload, crea la orden en la base de datos local y, dependiendo del `payment_method`, se comunica con la pasarela correspondiente para devolver al frontend las instrucciones de inicialización (Redirección o Script On-Page).

---

## 1. Addi y Sistecrédito (Redirección HTTP)

Estas pasarelas funcionan bajo un modelo de redirección directa. El Backend se comunica con la pasarela y devuelve una URL (sea generada desde un HTTP 302 como en Addi, o extraída del JSON de respuesta como en Sistecrédito).

### Payload típico del Backend
```json
{
  "gateway": {
    "type": "redirect",
    "redirect_url": "https://api.credinet.co/pay/..." // o la URL de Addi
  },
  "order": {
    "order_id": 12345
  }
}
```

### Implementación en el Frontend
El frontend simplemente debe redirigir al usuario utilizando `window.location.assign()`.

```typescript
// Angular / TypeScript example
async function handleSuccessfulCheckout(response: any): Promise<void> {
  if (response.gateway.type === 'redirect') {
    // Redirigir directamente al entorno de Addi o Sistecrédito
    window.location.assign(response.gateway.redirect_url);
    return;
  }
}
```

---

## 2. Bold (Botón de Pagos `BoldCheckout`)

Bold utiliza el script oficial `boldPaymentButton.js`, el cual expone el constructor global `window.BoldCheckout`. El backend calcula el hash de integridad SHA-256 (`integritySignature`) con `BOLD_SECRET_KEY` y devuelve el objeto `checkout` listo para instanciar en el navegador.

### Paso 1: Inyectar el script base
En tu `index.html` o plantilla base (Twig/Blade/HTML):

```html
<script defer type="text/javascript" src="https://checkout.bold.co/library/boldPaymentButton.js"></script>
```

### Paso 2: Declarar los Tipos Globales (TypeScript)

```typescript
interface BoldCheckoutInstance {
  open(): void;
}

export interface BoldCheckoutOptions {
  orderId: string;
  currency: string;
  amount: string;
  apiKey: string;
  integritySignature: string;
  description: string;
  redirectionUrl: string;
  renderMode?: 'embedded' | 'redirect';
  customerData?: string;   // JSON stringificado: JSON.stringify({ email, fullName, phone, dialCode, documentNumber, documentType })
  billingAddress?: string; // JSON stringificado: JSON.stringify({ address, city, state, country })
}

type BoldCheckoutConstructor = new (options: BoldCheckoutOptions) => BoldCheckoutInstance;

declare global {
  interface Window {
    BoldCheckout?: BoldCheckoutConstructor;
  }
}
```

### Paso 3: Payload típico del Backend
El backend retorna `type: "bold_checkout"` con la configuración firmada:

```json
{
  "gateway": {
    "type": "bold_checkout",
    "gateway": "bold",
    "hash": "a1b2c3d4...",
    "checkout": {
      "orderId": "12345",
      "currency": "COP",
      "amount": "150000",
      "apiKey": "BOLD_IDENTITY_KEY",
      "integritySignature": "a1b2c3d4...",
      "description": "Compra pedido #12345",
      "redirectionUrl": "https://tudominio.com/response/bold?orderId=12345",
      "renderMode": "embedded",
      "customerData": "{\"email\":\"cliente@correo.com\",\"fullName\":\"Juan Perez\",\"phone\":\"3001234567\",\"dialCode\":\"+57\",\"documentNumber\":\"1020304050\",\"documentType\":\"CC\"}",
      "billingAddress": "{\"address\":\"Calle 10 # 20-30\",\"city\":\"Cali\",\"state\":\"Valle del Cauca\",\"country\":\"CO\"}"
    }
  },
  "order": {
    "order_id": 12345
  }
}
```

### Paso 4: Abrir el Checkout de Bold en el Frontend

```typescript
function openBoldCheckout(gateway: { checkout: BoldCheckoutOptions }): void {
  const BoldCheckoutCtor = window.BoldCheckout;

  if (typeof BoldCheckoutCtor !== 'function') {
    console.error('El script de Bold (boldPaymentButton.js) no cargó correctamente.');
    return;
  }

  const checkout = new BoldCheckoutCtor({
    orderId: String(gateway.checkout.orderId),
    currency: gateway.checkout.currency || 'COP',
    amount: String(gateway.checkout.amount),
    apiKey: gateway.checkout.apiKey,
    integritySignature: gateway.checkout.integritySignature,
    description: gateway.checkout.description,
    redirectionUrl: gateway.checkout.redirectionUrl,
    renderMode: gateway.checkout.renderMode || 'embedded',
    customerData:
      typeof gateway.checkout.customerData === 'string'
        ? gateway.checkout.customerData
        : JSON.stringify(gateway.checkout.customerData ?? {}),
    billingAddress:
      typeof gateway.checkout.billingAddress === 'string'
        ? gateway.checkout.billingAddress
        : JSON.stringify(gateway.checkout.billingAddress ?? {}),
  });

  checkout.open();
}
```

### Reglas críticas de `BoldCheckout` en Frontend
- **`customerData` y `billingAddress` deben ser strings JSON** (`JSON.stringify(...)`). Si se pasan como objetos planos o si el backend no los serializó, conviene normalizarlos con `typeof val === 'string' ? val : JSON.stringify(val)`.
- **`amount` y `orderId` deben ser strings de enteros positivos sin decimales** y coincidir carácter por carácter con los valores usados por el backend para firmar `integritySignature`.
- Al finalizar el pago, Bold redirige automáticamente a `redirectionUrl` añadiendo `?bold-order-id={orderId}&bold-tx-status={approved|rejected|pending}`.

---

## 3. ePayco (Checkout On-Page script — Legacy)

Para ePayco, la integración es diferente. El backend crea una **Sesión de Checkout** y devuelve un identificador de sesión y opciones de configuración, pero es el Frontend el que debe abrir el modal/script de pago nativo de ePayco.

### Paso 1: Inyectar el script base
En tu `index.html` (o `_document.tsx` en Next.js), debes incluir el script oficial de checkout V2 de ePayco:

```html
<script defer type="text/javascript" src="https://checkout.epayco.co/checkout-v2.js"></script>
```

### Paso 2: Declarar los Tipos Globales (TypeScript)
Para evitar errores de compilación, declara la variable global `window.ePayco`.

```typescript
interface EpaycoCheckoutInstance {
  onCreated(callback: () => void): void;
  onErrors(callback: (error: unknown) => void): void;
  onClosed(callback: () => void): void;
  open(): void;
}

interface EpaycoCheckoutGlobal {
  checkout: {
    configure(options: any): EpaycoCheckoutInstance;
  };
}

declare global {
  interface Window {
    ePayco?: EpaycoCheckoutGlobal;
  }
}
```

### Paso 3: Payload típico del Backend
El backend debe retornar el objeto `checkout` configurado con la sesión (el parámetro `sessionId` que retornó la API de ePayco):

```json
{
  "gateway": {
    "type": "epayco_checkout",
    "checkout": {
      "sessionId": "1234abcd-...",
      "key": "PUBLIC_KEY_EPAYCO"
    }
  },
  "order": {
    "order_id": 12345
  }
}
```

### Paso 4: Abrir el Checkout en el Frontend
Se captura la respuesta y se inicializa la ventana modal.

```typescript
function openEpaycoCheckout(gateway: any, orderId: number): void {
  const epayco = window.ePayco;

  if (!epayco) {
    console.error('El script de ePayco no cargó correctamente.');
    // Hacer un fallback, por ejemplo redirigir a un historial de órdenes
    // ya que la orden sí se creó en el backend.
    return;
  }

  // Se configura la pasarela usando el sessionId entregado por el backend
  // y se establecen opciones visuales y de entorno.
  const checkout = epayco.checkout.configure({
    sessionId: gateway.checkout.sessionId,
    type: "onpage", // "onpage" | "standard"
    test: true      // Test: true | false (Debe cambiarse en Producción)
  });

  checkout.onCreated(() => {
    console.info('Modal de ePayco instanciado y creado en el DOM.');
  });

  checkout.onErrors((error) => {
    console.error('Error al intentar abrir el modal de ePayco:', error);
    alert('La pasarela no está disponible en este momento. Intenta nuevamente.');
  });

  checkout.onClosed(() => {
    console.info('El usuario cerró la ventana modal de ePayco sin terminar o al finalizar.');
    // Opcional: recargar el estado de la orden desde el backend
  });

  // Finalmente, abrir el modal de pago
  checkout.open();
}
```

### Nota Importante sobre ePayco
Al usar el script on-page con `sessionId`, el modal de pago se abre encima de la tienda (en iframe o popup). Una vez el usuario paga, el propio script de ePayco se encarga de redirigirlo a la `url_response` o `url_confirmation` (Páginas de Respuesta) que fueron configuradas desde el Backend al momento de crear el `sessionId`. El frontend no tiene que hacer más nada tras llamar a `.open()`.

# Estructura Canónica de Resultado de Pago (Node.js / TypeScript)

Todas las pasarelas (Bold, Addi, Sistecrédito, ePayco) deben normalizar sus respuestas a esta estructura tipada unificada antes de persistir o actualizar el estado del pedido en la base de datos.

---

## Interfaz TypeScript `CanonicalPaymentResult`

```typescript
export interface PaymentDetails {
  forma_pago: string;             // "TARJETA DE CREDITO", "ADDI", "SISTECREDITO", "PSE", "NEQUI", etc.
  str_ticketID: string;           // ID o referencia del ticket
  int_codigo_servicio?: string;
  int_codigo_banco?: string;
  str_nombre_banco?: string;
  str_codigo_transacción: string; // "AD", "SC", "BOLD", etc.
  str_numero_tarjeta: string;     // Enmascarado o "TARJETA ADDI"
  int_cuotas: number;             // Cuotas diferidas (default 1)
}

export interface PaymentSummary {
  int_ped_numero: string;         // ID externo generado por la pasarela
  int_n_pago: string | number;    // ID interno del pedido / factura
  int_pago_terminado: string;     // Código de aprobación
  int_estado_pago: number;        // 1=aprobada, 2=rechazada, 3=pendiente, 4=error, 6=reversada
  str_estado_pago: string;        // "APROBADA" | "PAGO RECHAZADO" | "PENDIENTE POR CR" | "Reversada"
  str_transaction_state: string;
  x_motivo: string;               // Razón o detalle devuelto por la pasarela
  dbl_valor_pagado: number;       // Monto efectivamente pagado
  dbl_total_pago: number;
  dbl_valor_iva_pagado: string;
  str_descripcion: string;
  str_id_cliente: string;
  str_nombre: string;
  str_apellido: string;
  str_telefono: string;
  str_email: string;
  ip: string;
  str_campo1?: string;
  str_campo2?: string;
  str_campo3?: string;
  str_campo4?: string;
  dat_fecha: string;              // "YYYY-MM-DD HH:mm:ss"
  int_id_forma_pago: string;      // "40"=Sistecrédito/Bold, "100"=Addi
  datos_pago: PaymentDetails;
}

export interface CanonicalPaymentResult {
  int_estado: number;
  int_error: string;
  str_detalle: string;
  int_cantidad_pagos: number;
  validado: number;
  datos_pagador: any;
  datos_envio: any;
  str_res_pago: PaymentSummary;
}
```

---

## Valores por Pasarela

| Campo | Bold | Sistecrédito | Addi | ePayco |
|---|---|---|---|---|
| `int_id_forma_pago` | `"40"` | `"40"` | `"100"` | código custom |
| `forma_pago` | `"TARJETA DE CREDITO"` / `"PSE"` / `"NEQUI"` / `"BANCOLOMBIA"` / `"QR"` | `"SISTECREDITO"` | `"ADDI"` | franquicia ("Visa", "PSE"…) |
| `str_codigo_transacción` | igual a `forma_pago` | `"SC"` | `"AD"` | código corto franquicia |
| `str_numero_tarjeta` | `data.card.masked_pan` (o `"N/A"`) | `"TARJETA SISTECREDITO"` | `"TARJETA ADDI"` | número enmascarado |

---

## Funciones Mapper Reutilizables en TypeScript

### `normalizePaymentStatus()`

```typescript
export interface NormalizedStatus {
  code: number;
  response: string;
  reason: string;
}

export function normalizePaymentStatus(
  gateway: 'bold' | 'epayco' | 'addi' | 'sistecredito',
  rawStatus: string | number
): NormalizedStatus {
  const map: Record<string, Record<string | number, NormalizedStatus>> = {
    bold: {
      SALE_APPROVED: { code: 1, response: 'APROBADA', reason: 'Venta aprobada por Bold' },
      SALE_REJECTED: { code: 2, response: 'PAGO RECHAZADO', reason: 'Venta rechazada por Bold' },
      VOID_APPROVED: { code: 6, response: 'Reversada', reason: 'Anulación aprobada por Bold' },
    },
    epayco: {
      1: { code: 1, response: 'APROBADA', reason: 'Transacción aprobada' },
      2: { code: 2, response: 'PAGO RECHAZADO', reason: 'Transacción rechazada' },
      3: { code: 3, response: 'PENDIENTE POR CR', reason: 'Pendiente de confirmación' },
      4: { code: 4, response: 'ERROR CR', reason: 'Error en la transacción' },
      6: { code: 6, response: 'Reversada', reason: 'Transacción reversada' },
      7: { code: 7, response: 'Retenida', reason: 'Retenida' },
      8: { code: 8, response: 'Iniciada', reason: 'Iniciada' },
      9: { code: 9, response: 'Expirada', reason: 'Tiempo expirado' },
      10: { code: 10, response: 'Abandonada', reason: 'Abandonada por el cliente' },
      11: { code: 11, response: 'Cancelada', reason: 'Cancelada por el cliente' },
      12: { code: 12, response: 'Antifraude', reason: 'Retenida por antifraude' },
    },
    addi: {
      APPROVED: { code: 1, response: 'APROBADA', reason: 'Crédito aprobado al 100%' },
      REJECTED: { code: 2, response: 'PAGO RECHAZADO', reason: 'Crédito rechazado por ADDI' },
      PENDING: { code: 3, response: 'PENDIENTE POR CR', reason: 'En proceso de validación' },
      INTERNAL_ERROR: { code: 4, response: 'ERROR CR', reason: 'Error interno en ADDI' },
      ABANDONED: { code: 10, response: 'Abandonada', reason: 'Tiempo máximo superado' },
      DECLINED: { code: 11, response: 'Cancelada', reason: 'Declinada por el cliente' },
    },
    sistecredito: {
      Approved: { code: 1, response: 'APROBADA', reason: 'Crédito aprobado' },
      Rejected: { code: 2, response: 'PAGO RECHAZADO', reason: 'Cliente no aprobado para crédito' },
      Pending: { code: 3, response: 'PENDIENTE POR CR', reason: 'En proceso de validación' },
      Failed: { code: 4, response: 'ERROR CR', reason: 'Error en plataforma Sistecrédito' },
      PendingForPaymentMethod: { code: 10, response: 'PENDIENTE POR MÉTODO DE PAGO', reason: 'En validación de método de pago' },
      Cancelled: { code: 11, response: 'Cancelada', reason: 'Declinada por el cliente' },
    },
  };

  const key = gateway === 'epayco' ? Number(rawStatus) : String(rawStatus);
  return (
    map[gateway]?.[key] || {
      code: 0,
      response: 'DESCONOCIDO',
      reason: String(rawStatus),
    }
  );
}
```

> **Importante para Sistecrédito:** Los estados usan **PascalCase** (`Approved`, `Rejected`, `Pending`…).

---

### `buildPaymentResult()`

```typescript
export interface BuildPaymentResultOptions {
  savedRecord: any;
  status: NormalizedStatus;
  amount: number;
  transactionId: string;
  franchise: string;
  txCode: string;
  extras?: {
    errorCode?: string;
    approvalCode?: string;
    ip?: string;
    ticketId?: string;
    serviceCode?: string;
    bankCode?: string;
    bankName?: string;
    cardNumber?: string;
    quotas?: number;
    paymentMethodId?: string;
    date?: string;
  };
}

export function buildPaymentResult(options: BuildPaymentResultOptions): CanonicalPaymentResult {
  const { savedRecord, status, amount, transactionId, franchise, txCode, extras = {} } = options;
  const p = savedRecord.datos_pagador || {};

  return {
    int_estado: 1,
    int_error: extras.errorCode || '00',
    str_detalle: 'Se encontraron pagos',
    int_cantidad_pagos: 1,
    validado: 0,
    datos_pagador: p,
    datos_envio: savedRecord.datos_envio || {},
    str_res_pago: {
      int_ped_numero: transactionId,
      int_n_pago: p.str_id_pago || '',
      int_pago_terminado: extras.approvalCode || '',
      int_estado_pago: status.code,
      str_estado_pago: status.response,
      str_transaction_state: status.response,
      x_motivo: status.reason,
      dbl_valor_pagado: amount,
      dbl_total_pago: p.flt_total_con_iva || amount,
      dbl_valor_iva_pagado: '0',
      str_descripcion: p.str_descripcion_pago || '',
      str_id_cliente: p.str_id_cliente || '',
      str_nombre: p.str_nombre_cliente || '',
      str_apellido: p.str_apellido_cliente || '',
      str_telefono: p.str_telefono_cliente || '',
      str_email: p.str_email || '',
      ip: extras.ip || '0.0.0.0',
      str_campo1: p.str_opcional1 || '',
      str_campo2: p.str_opcional2 || '',
      str_campo3: p.str_opcional3 || '',
      str_campo4: p.str_opcional4 || '',
      dat_fecha: extras.date || new Date().toISOString().replace('T', ' ').substring(0, 19),
      int_id_forma_pago: extras.paymentMethodId || '40',
      datos_pago: {
        forma_pago: franchise,
        str_ticketID: extras.ticketId || transactionId,
        int_codigo_servicio: extras.serviceCode || '',
        int_codigo_banco: extras.bankCode || franchise,
        str_nombre_banco: extras.bankName || franchise,
        str_codigo_transacción: txCode,
        str_numero_tarjeta: extras.cardNumber || `TARJETA ${franchise.toUpperCase()}`,
        int_cuotas: extras.quotas || 1,
      },
    },
  };
}
```

---

## Reglas de Idempotencia (para Express Webhooks)

```typescript
// Verificar antes de procesar si la orden ya está aprobada:
if (order.status === 'APROBADA') {
  res.status(200).json({ status: true, message: 'Ya procesado' });
  return;
}
```

# Estructura Canónica de Resultado de Pago

Todas las pasarelas (ePayco, Addi, Sistecrédito) deben producir este array unificado antes de persistir en base de datos.

---

## El Array `$result`

```php
$result = [
    "int_estado"         => 1,
    "int_error"          => "00",             // código de error bruto de la pasarela
    "str_detalle"        => "Se encontraron pagos",
    "int_cantidad_pagos" => 1,
    "validado"           => 0,
    "datos_pagador"      => $savedRecord->datos_pagador,   // del registro existente en DB
    "datos_envio"        => $savedRecord->datos_envio,
    "str_res_pago"       => [
        "int_ped_numero"      => $transactionId,   // ID externo de la pasarela
        "int_n_pago"          => $invoiceId,       // ID interno del pedido/factura
        "int_pago_terminado"  => $approvalCode,
        "int_estado_pago"     => $codResponse,     // int: 1=aprobada, 2=rechazada, 3=pendiente, 4=error
        "str_estado_pago"     => $response,        // "APROBADA" | "PAGO RECHAZADO" | etc.
        "str_transaction_state" => $response,
        "x_motivo"            => $reasonText,
        "dbl_valor_pagado"    => $amount,          // float
        "dbl_total_pago"      => $totalAmount,
        "dbl_valor_iva_pagado"=> "0",
        "str_descripcion"     => $description,
        "str_id_cliente"      => $customerId,
        "str_nombre"          => $customerName,
        "str_apellido"        => $customerLastname,
        "str_telefono"        => $customerPhone,
        "str_email"           => $customerEmail,
        "ip"                  => $customerIp,
        "str_campo1"          => $extra1 ?? "",
        "str_campo2"          => $extra2 ?? "",
        "str_campo3"          => $extra3 ?? "",
        "str_campo4"          => $extra4 ?? "",
        "dat_fecha"           => $transactionDate, // "Y-m-d H:i:s"
        "int_id_forma_pago"   => $paymentMethodId, // 40=Sistecredito, 100=Addi
        "datos_pago"          => [
            "forma_pago"             => $franchise,      // "ADDI", "SISTECREDITO", "Visa", "PSE"…
            "str_ticketID"           => $ticketId,
            "int_codigo_servicio"    => $serviceCode,
            "int_codigo_banco"       => $bankCode,
            "str_nombre_banco"       => $bankName,
            "str_codigo_transacción" => $txCode,         // "AD", "SC", o código de franquicia ePayco
            "str_numero_tarjeta"     => $cardNumber,     // enmascarado o "TARJETA ADDI"
            "int_cuotas"             => $quotas          // int, default 1
        ]
    ]
];
```

---

## Valores por Pasarela

| Campo | Bold | Sistecrédito | Addi | ePayco |
|-------|------|-------------|------|--------|
| `int_id_forma_pago` | `"40"` | `"40"` | `"100"` | código custom |
| `forma_pago` | `"TARJETA DE CREDITO"` / `"PSE"` / `"NEQUI"` / `"BANCOLOMBIA"` / `"QR"` | `"SISTECREDITO"` | `"ADDI"` | franquicia ("Visa", "PSE"…) |
| `str_codigo_transacción` | igual a `forma_pago` | `"SC"` | `"AD"` | código corto franquicia |
| `str_numero_tarjeta` | `data.card.masked_pan` (o `"N/A"`) | `"TARJETA SISTECREDITO"` | `"TARJETA ADDI"` | número enmascarado |

---

## Funciones Mapper Reutilizables

### `normalizePaymentStatus()`

```php
/**
 * Mapea el estado bruto de la pasarela al código/texto/razón canónico.
 *
 * @param string $gateway  'bold' | 'epayco' | 'addi' | 'sistecredito'
 * @param mixed  $rawStatus  Valor de estado bruto del payload del webhook
 * @return array{code: int, response: string, reason: string}
 */
function normalizePaymentStatus(string $gateway, $rawStatus): array
{
    $map = [
        'bold' => [
            'SALE_APPROVED' => ['code' => 1, 'response' => 'APROBADA',       'reason' => 'Venta aprobada por Bold'],
            'SALE_REJECTED' => ['code' => 2, 'response' => 'PAGO RECHAZADO', 'reason' => 'Venta rechazada por Bold'],
            'VOID_APPROVED' => ['code' => 6, 'response' => 'Reversada',      'reason' => 'Anulación aprobada por Bold'],
            // Nota: 'VOID_REJECTED' se maneja como No-Op en el webhook/conciliador (conserva el estado previo)
        ],
        'epayco' => [
            1  => ['code' => 1,  'response' => 'APROBADA',                      'reason' => 'Transacción aprobada'],
            2  => ['code' => 2,  'response' => 'PAGO RECHAZADO',                'reason' => 'Transacción rechazada'],
            3  => ['code' => 3,  'response' => 'PENDIENTE POR CR',              'reason' => 'Pendiente de confirmación'],
            4  => ['code' => 4,  'response' => 'ERROR CR',                      'reason' => 'Error en la transacción'],
            6  => ['code' => 6,  'response' => 'Reversada',                     'reason' => 'Transacción reversada'],
            7  => ['code' => 7,  'response' => 'Retenida',                      'reason' => 'Retenida'],
            8  => ['code' => 8,  'response' => 'Iniciada',                      'reason' => 'Iniciada'],
            9  => ['code' => 9,  'response' => 'Expirada',                      'reason' => 'Tiempo expirado'],
            10 => ['code' => 10, 'response' => 'Abandonada',                    'reason' => 'Abandonada por el cliente'],
            11 => ['code' => 11, 'response' => 'Cancelada',                     'reason' => 'Cancelada por el cliente'],
            12 => ['code' => 12, 'response' => 'Antifraude',                    'reason' => 'Retenida por antifraude'],
        ],
        'addi' => [
            'APPROVED'       => ['code' => 1,  'response' => 'APROBADA',         'reason' => 'Crédito aprobado al 100%'],
            'REJECTED'       => ['code' => 2,  'response' => 'PAGO RECHAZADO',   'reason' => 'Crédito rechazado por ADDI'],
            'PENDING'        => ['code' => 3,  'response' => 'PENDIENTE POR CR', 'reason' => 'En proceso de validación'],
            'INTERNAL_ERROR' => ['code' => 4,  'response' => 'ERROR CR',         'reason' => 'Error interno en ADDI'],
            'ABANDONED'      => ['code' => 10, 'response' => 'Abandonada',       'reason' => 'Tiempo máximo superado'],
            'DECLINED'       => ['code' => 11, 'response' => 'Cancelada',        'reason' => 'Declinada por el cliente'],
        ],
        'sistecredito' => [
            'Approved'               => ['code' => 1,  'response' => 'APROBADA',                      'reason' => 'Crédito aprobado'],
            'Rejected'               => ['code' => 2,  'response' => 'PAGO RECHAZADO',                'reason' => 'Cliente no aprobado para crédito'],
            'Pending'                => ['code' => 3,  'response' => 'PENDIENTE POR CR',              'reason' => 'En proceso de validación'],
            'Failed'                 => ['code' => 4,  'response' => 'ERROR CR',                      'reason' => 'Error en plataforma Sistecrédito'],
            'PendingForPaymentMethod'=> ['code' => 10, 'response' => 'PENDIENTE POR MÉTODO DE PAGO', 'reason' => 'En validación de método de pago'],
            'Cancelled'              => ['code' => 11, 'response' => 'Cancelada',                    'reason' => 'Declinada por el cliente'],
        ],
    ];

    $key = ($gateway === 'epayco') ? (int) $rawStatus : $rawStatus;
    // Estados desconocidos pasan tal cual — no asumir fallback
    return $map[$gateway][$key] ?? [
        'code'     => 0,
        'response' => 'DESCONOCIDO',
        'reason'   => (string) $rawStatus,
    ];
}
```

> **Importante para Sistecrédito:** Los estados usan **PascalCase** (`Approved`, `Rejected`, `Pending`…). El mapper diferencia mayúsculas; `"approved"` o `"APPROVED"` no coincidirán.

---

### `buildPaymentResult()`

```php
/**
 * Construye el array $result canónico desde datos normalizados.
 * Funciona igual para todas las pasarelas.
 *
 * @param object $savedRecord   Registro del pedido existente (decodificado de DB)
 * @param array  $status        Salida de normalizePaymentStatus()
 * @param float  $amount        Monto de la transacción
 * @param string $transactionId ID externo de la pasarela
 * @param string $franchise     Etiqueta del método de pago ("ADDI", "SISTECREDITO", "Visa"…)
 * @param string $txCode        Código corto de la pasarela ("AD", "SC", código franquicia)
 * @param array  $extras        Overrides opcionales: error_code, ip, ticket_id, service_code,
 *                              bank_code, bank_name, card_number, quotas, payment_method_id,
 *                              approval_code, date
 */
function buildPaymentResult(
    object $savedRecord,
    array  $status,
    float  $amount,
    string $transactionId,
    string $franchise,
    string $txCode,
    array  $extras = []
): array {
    $p = $savedRecord->datos_pagador;
    return [
        "int_estado"         => 1,
        "int_error"          => $extras['error_code'] ?? "00",
        "str_detalle"        => "Se encontraron pagos",
        "int_cantidad_pagos" => 1,
        "validado"           => 0,
        "datos_pagador"      => $p,
        "datos_envio"        => $savedRecord->datos_envio,
        "str_res_pago"       => [
            "int_ped_numero"        => $transactionId,
            "int_n_pago"            => $p->str_id_pago ?? "",
            "int_pago_terminado"    => $extras['approval_code'] ?? "",
            "int_estado_pago"       => $status['code'],
            "str_estado_pago"       => $status['response'],
            "str_transaction_state" => $status['response'],
            "x_motivo"              => $status['reason'],
            "dbl_valor_pagado"      => $amount,
            "dbl_total_pago"        => $p->flt_total_con_iva ?? $amount,
            "dbl_valor_iva_pagado"  => "0",
            "str_descripcion"       => $p->str_descripcion_pago ?? "",
            "str_id_cliente"        => $p->str_id_cliente ?? "",
            "str_nombre"            => $p->str_nombre_cliente ?? "",
            "str_apellido"          => $p->str_apellido_cliente ?? "",
            "str_telefono"          => $p->str_telefono_cliente ?? "",
            "str_email"             => $p->str_email ?? "",
            "ip"                    => $extras['ip'] ?? "0.0.0.0",
            "str_campo1"            => $p->str_opcional1 ?? "",
            "str_campo2"            => $p->str_opcional2 ?? "",
            "str_campo3"            => $p->str_opcional3 ?? "",
            "str_campo4"            => $p->str_opcional4 ?? "",
            "dat_fecha"             => $extras['date'] ?? date("Y-m-d H:i:s"),
            "int_id_forma_pago"     => $extras['payment_method_id'] ?? "40",
            "datos_pago"            => [
                "forma_pago"             => $franchise,
                "str_ticketID"           => $extras['ticket_id'] ?? $transactionId,
                "int_codigo_servicio"    => $extras['service_code'] ?? "",
                "int_codigo_banco"       => $extras['bank_code'] ?? $franchise,
                "str_nombre_banco"       => $extras['bank_name'] ?? $franchise,
                "str_codigo_transacción" => $txCode,
                "str_numero_tarjeta"     => $extras['card_number'] ?? ("TARJETA " . strtoupper($franchise)),
                "int_cuotas"             => $extras['quotas'] ?? 1
            ]
        ]
    ];
}
```

---

## Reglas de Idempotencia (para webhooks)

```php
// Verificar antes de procesar:
if (($savedRecord->str_res_pago->str_estado_pago ?? null) === 'APROBADA') {
    http_response_code(200);
    echo json_encode(['status' => true, 'message' => 'Ya procesado']);
    exit;
}
```

> Siempre verificar contra `str_estado_pago === 'APROBADA'` en el registro persistido.  
> Las pasarelas pueden enviar el mismo webhook más de una vez ante errores de red.

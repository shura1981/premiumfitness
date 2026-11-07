<?php

declare(strict_types=1);

/**
 * Plantilla: Webhook de Confirmación para Bold (Botón de Pagos)
 *
 * Características clave:
 * 1. Verificación obligatoria de firma `x-bold-signature` mediante HMAC-SHA256
 *    sobre `base64_encode($rawBody)` con `BOLD_SECRET_KEY`.
 * 2. Manejo explícito de eventos:
 *    - `SALE_APPROVED` -> Aprobada (`x_cod_response = 1`)
 *    - `SALE_REJECTED` -> Rechazada (`x_cod_response = 2`)
 *    - `VOID_APPROVED` -> Anulación aprobada / Reversada (`x_cod_response = 6` o `2`)
 *    - `VOID_REJECTED` -> Anulación rechazada: No-op (`200 OK`), conserva el estado actual de la orden
 * 3. Mapeo de medios de pago (`CARD`, `CARD_WEB`, `BOLD_TAP`, `PSE`, `NEQUI`, `BOTON_BANCOLOMBIA`, `QR`).
 *
 * Variables de entorno requeridas:
 * - BOLD_SECRET_KEY : Llave secreta del comercio para verificar `x-bold-signature`
 */

final readonly class BoldWebhookHandler
{
    public function __construct(
        private string $secretKey,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            secretKey: (string) ($_ENV['BOLD_SECRET_KEY'] ?? ''),
        );
    }

    /**
     * Verifica la firma `x-bold-signature` sobre el cuerpo crudo exacto de la petición.
     *
     * Fórmula oficial Bold:
     *   $encoded = base64_encode($rawBody);
     *   $hashed  = hash_hmac('sha256', $encoded, $secretKey);
     */
    public function verifySignature(string $rawBody, string $signatureHeader): bool
    {
        $received = strtolower(trim($signatureHeader));
        if ($received === '' || $this->secretKey === '') {
            return false;
        }

        $encoded = base64_encode($rawBody);
        $expected = hash_hmac('sha256', $encoded, $this->secretKey);

        return hash_equals($expected, $received);
    }

    /**
     * Indica si el evento corresponde a una anulación rechazada (`VOID_REJECTED`),
     * la cual debe responder `200 OK` sin modificar el estado previo de la compra.
     *
     * @param array<string, mixed> $payload
     */
    public function isVoidRejected(array $payload): bool
    {
        return strtoupper(trim((string) ($payload['type'] ?? ''))) === 'VOID_REJECTED';
    }

    /**
     * Procesa el payload del webhook de Bold y construye la estructura canónica normalizada.
     *
     * @param array<string, mixed> $payload
     * @return array{
     *   order_id: int,
     *   payment_id: string,
     *   event_type: string,
     *   canonical: array<string, mixed>
     * }
     */
    public function parseWebhookPayload(array $payload): array
    {
        $eventType = strtoupper(trim((string) ($payload['type'] ?? '')));
        if (!in_array($eventType, ['SALE_APPROVED', 'SALE_REJECTED', 'VOID_APPROVED'], true)) {
            throw new InvalidArgumentException("Evento de Bold no procesable para cambio de estado: {$eventType}");
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $amountData = is_array($data['amount'] ?? null) ? $data['amount'] : [];
        $cardData = is_array($data['card'] ?? null) ? $data['card'] : [];

        $reference = trim((string) ($metadata['reference'] ?? ''));
        if ($reference === '' || !ctype_digit($reference) || (int) $reference <= 0) {
            throw new InvalidArgumentException('Falta `data.metadata.reference` válido en el webhook de Bold.');
        }

        $paymentId = trim((string) ($data['payment_id'] ?? $payload['subject'] ?? ''));
        if ($paymentId === '') {
            throw new InvalidArgumentException('Falta `data.payment_id` en el webhook de Bold.');
        }

        $currency = strtoupper(trim((string) ($amountData['currency'] ?? 'COP')));
        if ($currency !== '' && $currency !== 'COP') {
            throw new InvalidArgumentException("Moneda no soportada en webhook de Bold: {$currency}");
        }

        $totalAmount = (float) ($amountData['total'] ?? 0);
        $paymentMethod = strtoupper(trim((string) ($data['payment_method'] ?? 'BOLD')));
        $cardBrand = trim((string) ($cardData['brand'] ?? $cardData['franchise'] ?? ''));

        [$codResponse, $responseText] = match ($eventType) {
            'SALE_APPROVED' => [1, 'APROBADA'],
            'SALE_REJECTED' => [2, 'PAGO RECHAZADO'],
            'VOID_APPROVED' => [6, 'Reversada'],
        };

        $franchise = $this->resolveFranchise($paymentMethod, $cardBrand);
        $formaPagoErp = $this->resolveFormaPagoErp($paymentMethod);
        $transactionDate = (string) ($data['created_at'] ?? date('Y-m-d H:i:s'));

        return [
            'order_id' => (int) $reference,
            'payment_id' => $paymentId,
            'event_type' => $eventType,
            'canonical' => [
                'x_cust_id_cliente' => (string) ($data['merchant_id'] ?? 'BOLD'),
                'x_ref_payco' => (int) $reference,
                'x_id_factura' => (int) $reference,
                'x_id_invoice' => $reference,
                'x_description' => 'Compra online Bold #' . $reference,
                'x_amount' => $totalAmount,
                'x_amount_country' => $totalAmount,
                'x_amount_ok' => $totalAmount,
                'x_tax' => 0,
                'x_amount_base' => 0,
                'x_currency_code' => 'COP',
                'x_bank_name' => $paymentMethod,
                'x_cardnumber' => (string) ($cardData['masked_pan'] ?? 'N/A'),
                'x_quotas' => (int) ($cardData['installments'] ?? 1),
                'x_respuesta' => $responseText,
                'x_response' => $responseText,
                'x_approval_code' => (string) ($data['approval_number'] ?? '000000'),
                'x_transaction_id' => $paymentId,
                'x_fecha_transaccion' => $transactionDate,
                'x_transaction_date' => $transactionDate,
                'x_cod_respuesta' => $codResponse,
                'x_cod_response' => $codResponse,
                'x_cod_transaction_state' => $codResponse,
                'x_transaction_state' => $responseText,
                'x_response_reason_text' => (string) ($data['bold_code'] ?? $eventType),
                'x_errorcode' => (string) ($data['bold_code'] ?? '00'),
                'x_franchise' => $franchise,
                'x_customer_email' => (string) ($data['payer_email'] ?? ''),
                'x_signature' => (string) ($payload['id'] ?? $paymentId),
                // Campos legacy ERP (opcionales según proyecto)
                'int_id_forma_pago' => '40',
                'str_valor_total' => (string) $totalAmount,
                'int_ped_numero' => $paymentId,
                'int_estado_pago' => (string) $codResponse,
                'str_id_clave' => $reference,
                'str_ticketID' => $paymentId,
                'str_codigo_transacción' => $formaPagoErp,
                'forma_pago' => $formaPagoErp,
            ],
        ];
    }

    private function resolveFranchise(string $paymentMethod, string $cardBrand): string
    {
        return match ($paymentMethod) {
            'CARD', 'CARD_WEB', 'BOLD_TAP' => $cardBrand !== '' ? $cardBrand : 'CARD',
            'BOTON_BANCOLOMBIA' => 'BANCOLOMBIA',
            'PSE' => 'PSE',
            'NEQUI' => 'NEQUI',
            'QR' => 'QR',
            default => $paymentMethod !== '' ? $paymentMethod : 'BOLD',
        };
    }

    private function resolveFormaPagoErp(string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'CARD', 'CARD_WEB', 'BOLD_TAP' => 'TARJETA DE CREDITO',
            'BOTON_BANCOLOMBIA' => 'BANCOLOMBIA',
            'PSE' => 'PSE',
            'NEQUI' => 'NEQUI',
            'QR' => 'QR',
            default => 'BOLD',
        };
    }
}

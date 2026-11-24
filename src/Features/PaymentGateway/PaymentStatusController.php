<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway;

use App\Features\Order\OrderStatusMapper;
use App\Infrastructure\Http\JsonResponder;
use App\Shared\Exceptions\ApiException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/payments/{pf-n} — el resultado de un intento de pago, para la página de respuesta de la app
 * (spec integraciones/03), como `state-transaction` en el sitio de Nutramerican: la app consulta una vez
 * con el id de pago y muestra. Solo informa: el estado lo cambia el webhook de la pasarela.
 */
final class PaymentStatusController
{
    use JsonResponder;

    private const array GATEWAYS = ['bold' => 'Bold', 'addi' => 'Addi', 'sistecredito' => 'Sistecrédito'];

    /** @param int|string $reference `TypedArgsStrategy` convierte a número un parámetro numérico («863»). */
    public function show(Request $request, Response $response, int|string $reference): Response
    {
        $reference = (string) $reference;
        $claims  = $request->getAttribute('jwt_claims');
        $userId  = is_object($claims) ? (int) ($claims->sub ?? 0) : 0;
        $attempt = new PaymentAttemptRepository()->findForUser($reference, $userId)
            ?? throw ApiException::notFound('No encontramos ese pago.');

        $gateway = (string) $attempt['gateway'];

        return $this->json($response, [
            'reference'      => PaymentAttemptRepository::PREFIX . $attempt['id'],
            'order_id'       => (int) $attempt['order_id'],
            'gateway'        => $gateway,
            'gateway_name'   => self::GATEWAYS[$gateway] ?? $gateway,
            // El estado del pedido, como lo muestra la app: lo decide el webhook.
            'status'         => OrderStatusMapper::forUser(
                $attempt['order_status'] === null ? null : (string) $attempt['order_status'],
                $attempt['approved_at'] !== null,
                (int) $attempt['sap_order_code'] > 0,
                trim((string) $attempt['tracking_number']) !== '',
            ),
            // Lo que reportó la pasarela para este intento; null mientras no avisa.
            'gateway_status' => $attempt['status'] === null ? null : (string) $attempt['status'],
            'transaction_id' => $attempt['transaction_id'] === null ? null : (string) $attempt['transaction_id'],
            'payment_method' => $attempt['payment_method'] === null ? null : (string) $attempt['payment_method'],
            'amount'         => (float) ($attempt['paid_amount'] ?? $attempt['amount']),
            'created_at'     => OrderStatusMapper::iso((string) $attempt['created_at']),
            'notified_at'    => OrderStatusMapper::iso($attempt['notified_at'] === null ? null : (string) $attempt['notified_at']),
        ]);
    }
}

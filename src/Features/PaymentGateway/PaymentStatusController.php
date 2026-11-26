<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway;

use App\Features\Order\OrderStatusMapper;
use App\Features\Order\UserOrderRepository;
use App\Infrastructure\Http\JsonResponder;
use App\Shared\Exceptions\ApiException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/payments/{pf-N} — el estado del pago de un pedido, para la página de respuesta de la app
 * (spec integraciones/03), como `state-transaction` en el sitio de Nutramerican: la app consulta una vez
 * con el id de pago y muestra. Lee el pedido (`orders.payment_code`): el estado y lo que mandó la
 * pasarela los guardó el webhook, que es lo único que los cambia.
 */
final class PaymentStatusController
{
    use JsonResponder;

    private const array GATEWAYS = ['bold' => 'Bold', 'addi' => 'Addi', 'sistecredito' => 'Sistecrédito'];

    /** @param int|string $reference `TypedArgsStrategy` convierte a número un parámetro numérico («863»). */
    public function show(Request $request, Response $response, int|string $reference): Response
    {
        $claims = $request->getAttribute('jwt_claims');
        $userId = is_object($claims) ? (int) ($claims->sub ?? 0) : 0;
        $order  = new UserOrderRepository()->findByPaymentCode($userId, (string) $reference)
            ?? throw ApiException::notFound('No encontramos ese pago.');

        $gateway     = (string) $order['payment_gateway'];
        $status      = $order['status_value'] === null ? null : (string) $order['status_value'];
        $transaction = $order['payment_reference'] === null ? null : (string) $order['payment_reference'];

        return $this->json($response, [
            'reference'      => (string) $order['payment_code'],
            'order_id'       => (int) $order['order_id'],
            'gateway'        => $gateway,
            'gateway_name'   => self::GATEWAYS[$gateway] ?? $gateway,
            // El estado del pedido, como lo muestra la app.
            'status'         => OrderStatusMapper::forUser(
                $status,
                $order['approved_at'] !== null,
                (int) $order['sap_order_code'] > 0,
                trim((string) $order['tracking_number']) !== '',
            ),
            // El estado que dejó el webhook; null mientras la pasarela no avisa.
            'gateway_status' => $order['payment_state_json'] === null ? null : $status,
            // Número de la transacción en la pasarela; se guarda al aprobar como `<pasarela>:<número>`.
            'transaction_id' => $transaction === null ? null : preg_replace('/\A[a-z]+:/', '', $transaction),
            'amount'         => (float) $order['amount'],
            'created_at'     => OrderStatusMapper::iso((string) $order['created_at']),
            'approved_at'    => OrderStatusMapper::iso($order['approved_at'] === null ? null : (string) $order['approved_at']),
        ]);
    }
}

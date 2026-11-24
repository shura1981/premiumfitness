<?php

declare(strict_types=1);

namespace App\Features\Checkout;

use App\Infrastructure\Http\JsonResponder;
use App\Shared\Exceptions\ApiException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 *   POST /api/checkout/preview            → la compra calculada, sin crear nada
 *   GET  /api/checkout/payment-gateways   → pasarelas habilitadas
 *   POST /api/checkout                    → crea el pedido y devuelve el pago firmado
 */
class CheckoutController
{
    use JsonResponder;

    private readonly CheckoutService $checkout;

    public function __construct()
    {
        $this->checkout = new CheckoutService();
    }

    public function preview(Request $request, Response $response): Response
    {
        $body      = (array) ($request->getParsedBody() ?? []);
        $addressId = filter_var($body['address_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $this->json($response, $this->checkout->preview(
            self::userId($request),
            $body['items'] ?? null,
            $addressId === false ? null : $addressId,
        )->toArray());
    }

    public function gateways(Request $request, Response $response): Response
    {
        return $this->json($response, ['data' => CheckoutService::GATEWAYS]);
    }

    public function place(Request $request, Response $response): Response
    {
        $body  = (array) ($request->getParsedBody() ?? []);
        $notes = trim((string) ($body['notes'] ?? ''));

        $result = $this->checkout->place(
            self::userId($request),
            $body['items'] ?? null,
            (int) $body['address_id'],
            (string) $body['payment_gateway'],
            $notes === '' ? null : $notes,
        );

        return $this->json($response, $result, 201);
    }

    private static function userId(Request $request): int
    {
        $claims = $request->getAttribute('jwt_claims');
        $userId = is_object($claims) ? (int) ($claims->sub ?? 0) : 0;

        return $userId > 0 ? $userId : throw ApiException::unauthorized();
    }
}

<?php

declare(strict_types=1);

namespace App\Features\Order;

use App\Features\Product\ProductImages;
use App\Infrastructure\Http\JsonResponder;
use App\Shared\Exceptions\ApiException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Pedidos de quien tiene la sesión. Un pedido ajeno responde 404, igual que uno que no existe.
 *
 *   GET /api/orders        → `OrderSummary[]`
 *   GET /api/orders/{id}   → `OrderDetail`
 */
class UserOrderController
{
    use JsonResponder;

    private readonly UserOrderRepository $orders;

    public function __construct()
    {
        $this->orders = new UserOrderRepository();
    }

    public function list(Request $request, Response $response): Response
    {
        return $this->json($response, ['data' => array_map(self::summary(...), $this->orders->allOf(self::userId($request)))]);
    }

    public function show(Request $request, Response $response, int $id): Response
    {
        $order = $this->orders->findOf(self::userId($request), $id);

        if ($order === null) {
            throw ApiException::notFound('No encontramos ese pedido.');
        }

        $items   = $this->orders->itemsOf($id);
        $summary = self::summary($order + [
            'items_count' => array_sum(array_map(static fn (array $item): int => (int) $item['quantity'], $items)),
            'first_item'  => $items[0]['product_name'] ?? '',
        ]);

        return $this->json($response, $summary + [
            'payment_gateway' => $order['payment_gateway'],
            'items'           => array_map(self::item(...), $items),
            'subtotal_full'   => (float) $order['subtotal_full'],
            'discount_amount' => (float) $order['discount_amount'],
            'tax_amount'      => (float) $order['tax_amount'],
            'shipping_amount' => (float) $order['shipping_amount'],
            'address'         => $order['address'] === null ? null : [
                'name'            => (string) $order['address_name'],
                'address'         => (string) $order['address'],
                'city_name'       => (string) $order['city_name'],
                'department_name' => (string) $order['department_name'],
            ],
            'tracking'        => empty($order['tracking_number']) ? null : [
                'carrier' => (string) ($order['carrier_name'] ?? ''),
                'number'  => (string) $order['tracking_number'],
                'url'     => null,
            ],
            'timeline'        => OrderStatusMapper::timeline($summary['status'], $order['created_at'], $order['approved_at'], $summary['is_redemption']),
        ]);
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed> Contrato `OrderSummary`.
     */
    private static function summary(array $order): array
    {
        $isRedemption = $order['order_type'] === 'redemption';

        return [
            'id'                => (int) $order['order_id'],
            'created_at'        => OrderStatusMapper::iso($order['created_at']),
            'status'            => OrderStatusMapper::forUser(
                $order['status_value'],
                $order['approved_at'] !== null,
                !empty($order['sap_order_code']),
                !empty($order['tracking_number']),
            ),
            'total'             => (float) $order['amount'],
            'items_count'       => (int) $order['items_count'],
            'first_item'        => (string) ($order['first_item'] ?? ''),
            'is_redemption'     => $isRedemption,
            'redemption_points' => $isRedemption ? (int) $order['redemption_points'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function item(array $item): array
    {
        return [
            'code'                => (int) $item['product_sku'],
            'name'                => (string) $item['product_name'],
            'image'               => ProductImages::fromJson($item['imageswebp'])->card(),
            'quantity'            => (int) $item['quantity'],
            'unit_price'          => (float) $item['product_full_price'],
            'final_unit_price'    => (float) $item['unit_price'],
            'discount_percentage' => (float) $item['discount_percentage'],
            'line_total'          => (float) $item['line_total'],
            'redemption_points'   => $item['redemption_points'] === null ? null : (int) $item['redemption_points'],
        ];
    }

    private static function userId(Request $request): int
    {
        $claims = $request->getAttribute('jwt_claims');
        $userId = is_object($claims) ? (int) ($claims->sub ?? 0) : 0;

        return $userId > 0 ? $userId : throw ApiException::unauthorized();
    }
}

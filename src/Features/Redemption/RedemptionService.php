<?php

declare(strict_types=1);

namespace App\Features\Redemption;

use App\Features\Account\AddressBook;
use App\Features\Checkout\CartInput;
use App\Features\Notification\PushOutbox;
use App\Features\Order\StatusOrderRepository;
use App\Features\Order\VatPolicy;
use App\Features\PaymentGateway\Normalizer\PaymentStatus;
use App\Features\Point\NotEnoughPointsException;
use App\Features\Point\PointLotRepository;
use App\Features\Point\PointSpender;
use App\Features\Product\CatalogProduct;
use App\Features\Product\IntranetProductCatalog;
use App\Features\Product\ProductCatalog;
use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;
use PDO;
use Throwable;

/**
 * Canje de productos con puntos (CU-12; spec negocio/04, reglas 2 y 3):
 *   - solo con puntos, a precio completo (1 punto = 1 peso), sin descuento;
 *   - no acepta comisión ni pago mixto, y no pasa por la pasarela;
 *   - consume los lotes por FIFO y no genera comisión ni puntos.
 * El pedido queda aprobado al instante y sigue el mismo camino del call center que una compra.
 */
class RedemptionService
{
    private const int NOTES_MAX_LENGTH = 500;

    private readonly ProductCatalog $catalog;
    private readonly PointLotRepository $lots;
    private readonly AddressBook $addresses;
    private readonly VatPolicy $vat;

    public function __construct()
    {
        // La fuente del catálogo, sin caché: se descuenta el precio vigente.
        $this->catalog   = IntranetProductCatalog::fromEnv();
        $this->lots      = new PointLotRepository();
        $this->addresses = new AddressBook();
        $this->vat       = VatPolicy::fromEnv();
    }

    /** @return array<string, mixed> Contrato `RedemptionPreview`. */
    public function preview(int $trainerId, mixed $items): array
    {
        return $this->quote($trainerId, CartInput::normalize($items))['preview'];
    }

    /**
     * @return int El id del pedido de canje.
     * @throws ApiException
     */
    public function redeem(int $trainerId, mixed $items, int $addressId, string $idempotencyKey, ?string $notes = null): int
    {
        $items     = CartInput::normalize($items);
        $reference = 'redeem:' . $idempotencyKey;

        if ($this->addresses->find($trainerId, $addressId) === null) {
            throw ApiException::invalid('Elige una dirección de entrega.', errors: ['address_id' => 'Elige una de tus direcciones.']);
        }

        // Las mismas notas para la entrega que en una compra (`CheckoutValidator`).
        if ($notes !== null && mb_strlen($notes) > self::NOTES_MAX_LENGTH) {
            throw ApiException::invalid('La nota puede tener hasta 500 caracteres.', errors: ['notes' => 'La nota puede tener hasta 500 caracteres.']);
        }

        $pdo = DB::getInstance();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('SELECT user_id FROM trainers WHERE user_id = ? FOR UPDATE')->execute([$trainerId]);

            // La misma solicitud repetida devuelve el canje ya hecho, sin descontar otra vez.
            $existing = $pdo->prepare('SELECT order_id, user_id FROM orders WHERE payment_reference = ?');
            $existing->execute([$reference]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);

            if ($row !== false) {
                $pdo->commit();

                return (int) $row['user_id'] === $trainerId
                    ? (int) $row['order_id']
                    : throw ApiException::conflict('idempotency_key_reused', 'No pudimos completar el canje. Inténtalo de nuevo.');
            }

            $quote = $this->quote($trainerId, $items);

            if (!$quote['preview']['items'] || in_array(false, array_column($quote['preview']['items'], 'available'), true)) {
                throw ApiException::conflict('out_of_stock', 'Hay productos que ya no están disponibles. Quítalos para continuar.');
            }

            $orderId = $this->createOrder($pdo, $trainerId, $addressId, $reference, $quote['lines'], $notes);

            try {
                PointSpender::spend($trainerId, $quote['preview']['points_total'], 'redemption', "redemption:{$orderId}", ['order_id' => $orderId]);
            } catch (NotEnoughPointsException) {
                throw ApiException::conflict('not_enough_points', 'No tienes puntos suficientes para este canje.');
            }

            PushOutbox::enqueue(
                $trainerId,
                'redemption_created',
                'Canje recibido',
                sprintf('Canjeaste %s puntos. Estamos preparando tu pedido.', number_format($quote['preview']['points_total'], 0, ',', '.')),
                ['route' => '/coach/pedidos/' . $orderId, 'order_id' => $orderId],
                "redemption:{$orderId}",
            );

            $pdo->commit();

            return $orderId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * @param list<array{code:int, quantity:int}> $items
     * @return array{preview: array<string, mixed>, lines: list<array{product:CatalogProduct, quantity:int}>}
     */
    private function quote(int $trainerId, array $items): array
    {
        $products = $this->catalog->findByCodes(array_column($items, 'code'));
        $lines    = [];
        $rows     = [];
        $total    = 0;

        foreach ($items as $item) {
            $product   = $products[$item['code']] ?? null;
            $available = $product !== null && $product->isPurchasable();
            $points    = $product?->price ?? 0;

            $rows[] = [
                'code'        => $item['code'],
                'name'        => $product->name ?? 'Producto no disponible',
                'image'       => $product?->images->card(),
                'quantity'    => $item['quantity'],
                'points'      => $points,
                'line_points' => $available ? $points * $item['quantity'] : 0,
                'available'   => $available,
            ];

            if ($available) {
                $total  += $points * $item['quantity'];
                $lines[] = ['product' => $product, 'quantity' => $item['quantity']];
            }
        }

        $balance     = $this->lots->availablePoints($trainerId);
        $unavailable = count($rows) - count($lines);

        return [
            'lines'   => $lines,
            'preview' => [
                'items'            => $rows,
                'points_total'     => $total,
                'points_available' => $balance,
                // Lo que le quedaría; null si no le alcanza.
                'points_remaining' => $balance >= $total ? $balance - $total : null,
                'enough'           => $total > 0 && $balance >= $total && $unavailable === 0,
                'message'          => match (true) {
                    $unavailable > 0   => 'Hay productos que ya no están disponibles. Quítalos para continuar.',
                    $balance < $total  => 'Te faltan ' . number_format($total - $balance, 0, ',', '.') . ' puntos para este canje.',
                    default            => null,
                },
            ],
        ];
    }

    /** @param list<array{product:CatalogProduct, quantity:int}> $lines */
    private function createOrder(PDO $pdo, int $trainerId, int $addressId, string $reference, array $lines, ?string $notes): int
    {
        $points = 0;
        $base   = 0.0;
        $tax    = 0.0;
        $rows   = [];

        foreach ($lines as $line) {
            $linePoints = $line['product']->price * $line['quantity'];
            $split      = $this->vat->split($line['product']->code, (float) $linePoints);
            $points    += $linePoints;
            $base      += $split['base'];
            $tax       += $split['tax'];
            $rows[]     = [$line['product'], $line['quantity'], $linePoints, $split];
        }

        $pdo->prepare(
            "INSERT INTO orders
                (user_id, order_type, amount, subtotal_full, discount_amount, tax_base, tax_amount, shipping_amount,
                 redemption_points, payment_gateway, payment_reference, address_id, order_notes, status_id, approved_at)
             VALUES (?, 'redemption', 0, ?, 0, ?, ?, 0, ?, NULL, ?, ?, ?, ?, NOW())"
        )->execute([
            $trainerId, $points, round($base, 2), round($tax, 2), $points, $reference, $addressId, $notes,
            new StatusOrderRepository()->findOrCreateIdByValue(PaymentStatus::TEXT_APROBADA),
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $item = $pdo->prepare(
            'INSERT INTO orders_items
                (order_id, product_sku, product_name, product_full_price, sap_code, quantity, unit_price,
                 discount_percentage, line_total, tax_rate, tax_base, tax_amount, redemption_points)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)'
        );

        foreach ($rows as [$product, $quantity, $linePoints, $split]) {
            $item->execute([
                $orderId, $product->code, $product->name, $product->price, $product->sapCode, $quantity, $product->price,
                $linePoints, $split['rate'], $split['base'], $split['tax'], $linePoints,
            ]);
        }

        return $orderId;
    }
}

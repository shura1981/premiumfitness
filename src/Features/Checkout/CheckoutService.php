<?php

declare(strict_types=1);

namespace App\Features\Checkout;

use App\Features\Account\AddressBook;
use App\Features\DiscountRule\Buyer;
use App\Features\DiscountRule\DiscountPolicy;
use App\Features\DiscountRule\DiscountRuleRepository;
use App\Features\Order\VatPolicy;
use App\Features\Product\IntranetProductCatalog;
use App\Features\Shipping\Destination;
use App\Features\Shipping\Shipping;
use App\Features\Trainer\TrainerRepository;
use App\Infrastructure\Config\BusinessConfig;
use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;
use PDO;

/**
 * Compra de la app web (contrato: specs/backend/03-api-de-premiumfitness.plan.md).
 *
 * La app envía solo códigos y cantidades. Precios, descuento, IVA, envío y el valor que se cobra
 * los calcula el backend con el catálogo vigente, y es el backend quien firma el pago: la app no
 * puede cambiar lo que se cobra.
 */
class CheckoutService
{
    /** Pasarelas habilitadas. El call center confirma los pagos de cada una en su plataforma. */
    public const array GATEWAYS = [
        ['id' => 'bold', 'name' => 'Bold', 'description' => 'Tarjeta, PSE, Nequi y más medios de pago.'],
        ['id' => 'addi', 'name' => 'Addi', 'description' => 'Paga a cuotas con Addi.'],
        ['id' => 'sistecredito', 'name' => 'Sistecrédito', 'description' => 'Paga a cuotas con tu crédito de Sistecrédito.'],
    ];

    private readonly QuoteCalculator $calculator;
    private readonly PurchaseRepository $purchases;
    private readonly AddressBook $addresses;
    private readonly TrainerRepository $trainers;
    private readonly BusinessConfig $business;
    private readonly Shipping $shipping;

    public function __construct()
    {
        $this->business   = BusinessConfig::fromEnv();
        // Siempre la fuente del catálogo, sin caché: se cobra el precio vigente.
        $this->calculator = new QuoteCalculator(
            IntranetProductCatalog::fromEnv(),
            new DiscountRuleRepository(),
            new DiscountPolicy($this->business),
            VatPolicy::fromEnv(),
            $this->business,
            $this->shipping = Shipping::fromEnv(),
        );
        $this->purchases  = new PurchaseRepository();
        $this->addresses  = new AddressBook();
        $this->trainers   = new TrainerRepository();
    }

    /**
     * @param int|null $addressId Dirección elegida: con ella se calcula el flete.
     * @throws ApiException 422 si la dirección no es suya.
     */
    public function preview(int $userId, mixed $items, ?int $addressId = null): Quote
    {
        $to = $addressId === null ? null : $this->destination($userId, $addressId);

        return $this->calculator->quote($this->buyer($userId), CartInput::normalize($items), $to);
    }

    /**
     * Crea el pedido y devuelve los datos del pago, firmados.
     *
     * @return array<string, mixed> Contrato `CheckoutResult`.
     * @throws ApiException
     */
    public function place(int $userId, mixed $items, int $addressId, string $gateway, ?string $notes): array
    {
        $buyer = $this->buyer($userId);
        $quote = $this->calculator->quote($buyer, CartInput::normalize($items), $this->destination($userId, $addressId));

        if (!$quote->shippingAvailable) {
            throw ApiException::conflict('shipping_unavailable', (string) $quote->message);
        }

        if (!$quote->canCheckout) {
            throw ApiException::conflict('out_of_stock', $quote->message ?? 'Hay productos que ya no están disponibles.');
        }

        $orderId = $this->purchases->create(
            $userId,
            $buyer->isTrainer() ? 'trainer_purchase' : 'client_purchase',
            $quote,
            $addressId,
            $gateway,
            $notes,
            $this->coupon($quote),
        );

        return ['order_id' => $orderId, 'payment' => $this->payment($orderId, $buyer)];
    }

    /**
     * Datos de pago de un pedido propio que sigue sin pagar. El valor es el que quedó guardado al
     * crearlo: no se recalcula ni se toma de la app.
     *
     * @return array<string, mixed> Contrato `CheckoutResult`.
     * @throws ApiException 404 si no es suyo; 409 si ya no se puede pagar
     */
    public function payAgain(int $userId, int $orderId): array
    {
        $stmt = DB::getInstance()->prepare(
            "SELECT approved_at, order_type, created_at FROM orders WHERE order_id = ? AND user_id = ?"
        );
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($order === false) {
            throw ApiException::notFound('No encontramos ese pedido.');
        }

        if ($order['approved_at'] !== null) {
            throw ApiException::conflict('order_not_payable', 'Este pedido ya está pagado.');
        }

        // Pasado un día el precio pudo cambiar: se hace una compra nueva.
        if ($order['order_type'] === 'redemption' || strtotime((string) $order['created_at']) < strtotime('-1 day')) {
            throw ApiException::conflict('order_not_payable', 'Este pedido ya no se puede pagar. Haz la compra de nuevo.');
        }

        return ['order_id' => $orderId, 'payment' => $this->payment($orderId, $this->buyer($userId))];
    }

    /** @throws ApiException */
    /** Ciudad de una dirección del usuario; una dirección ajena «no existe». */
    private function destination(int $userId, int $addressId): Destination
    {
        $address = $this->addresses->find($userId, $addressId);
        $to      = $address === null ? null : $this->shipping->destination((int) $address['city_id']);

        return $to ?? throw ApiException::invalid('Elige una dirección de entrega.', errors: ['address_id' => 'Elige una de tus direcciones.']);
    }

    private function buyer(int $userId): Buyer
    {
        return Buyer::fromUserId($userId) ?? throw ApiException::unauthorized();
    }

    /** @return array{code:string, discount_percentage:float}|null */
    private function coupon(Quote $quote): ?array
    {
        if ($quote->trainerId === null) {
            return null;
        }

        $trainer = $this->trainers->findCoupon($quote->trainerId);

        return $trainer === null
            ? null
            : ['code' => $trainer, 'discount_percentage' => $this->business->clientDiscountPercentage];
    }

    /**
     * Datos de la pasarela del pedido, con lo que quedó guardado en él. La vuelta del pago abre el
     * área de quien compra: la raíz para el cliente y `/coach` para el entrenador (spec seguridad/01, S-08).
     *
     * @return array<string, mixed>
     * @throws ApiException
     */
    private function payment(int $orderId, Buyer $buyer): array
    {
        $area = $buyer->isTrainer() ? '/coach' : '';

        return (new GatewayPayment($this->business))
            ->start($orderId, $this->business->webAppUrl . $area . '/pago/resultado?pedido=' . $orderId);
    }
}

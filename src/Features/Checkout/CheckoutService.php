<?php

declare(strict_types=1);

namespace App\Features\Checkout;

use App\Features\Account\AddressBook;
use App\Features\DiscountRule\Buyer;
use App\Features\DiscountRule\DiscountPolicy;
use App\Features\DiscountRule\DiscountRuleRepository;
use App\Features\Order\VatPolicy;
use App\Features\PaymentGateway\Gateways\Bold\BoldCheckoutService;
use App\Features\Product\IntranetProductCatalog;
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
    /** Pasarelas habilitadas. Addi y Sistecrédito de radical quedan fuera hasta poder verificar sus avisos de pago. */
    public const array GATEWAYS = [
        ['id' => 'bold', 'name' => 'Bold', 'description' => 'Tarjeta, PSE, Nequi y más medios de pago.'],
    ];

    private readonly QuoteCalculator $calculator;
    private readonly PurchaseRepository $purchases;
    private readonly AddressBook $addresses;
    private readonly TrainerRepository $trainers;
    private readonly BusinessConfig $business;

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
        );
        $this->purchases  = new PurchaseRepository();
        $this->addresses  = new AddressBook();
        $this->trainers   = new TrainerRepository();
    }

    public function preview(int $userId, mixed $items): Quote
    {
        return $this->calculator->quote($this->buyer($userId), CartInput::normalize($items));
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
        $quote = $this->calculator->quote($buyer, CartInput::normalize($items));

        if (!$quote->canCheckout) {
            throw ApiException::conflict('out_of_stock', $quote->message ?? 'Hay productos que ya no están disponibles.');
        }

        if ($this->addresses->find($userId, $addressId) === null) {
            throw ApiException::invalid('Elige una dirección de entrega.', errors: ['address_id' => 'Elige una de tus direcciones.']);
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

        return ['order_id' => $orderId, 'payment' => $this->boldPayment($orderId, $quote->total)];
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
            "SELECT amount, approved_at, order_type, created_at FROM orders WHERE order_id = ? AND user_id = ?"
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

        return ['order_id' => $orderId, 'payment' => $this->boldPayment($orderId, (int) round((float) $order['amount']))];
    }

    /** @throws ApiException */
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
     * Datos del botón de Bold. La firma de integridad cubre el pedido, el valor y la moneda:
     * Bold rechaza el pago si la app cambia cualquiera de los tres.
     *
     * @return array<string, mixed>
     */
    private function boldPayment(int $orderId, int $amount): array
    {
        $config = BoldCheckoutService::fromEnv()->buildCheckoutConfig([
            'order_id'        => (string) $orderId,
            'amount'          => $amount,
            'currency'        => 'COP',
            'description'     => "Pedido PremiumFitness #{$orderId}",
            'redirection_url' => $this->business->webAppUrl . '/pago/resultado?pedido=' . $orderId,
        ]);

        return [
            'gateway' => 'bold',
            'bold'    => [
                'order_id'            => (string) $config['orderId'],
                'amount'              => (int) $config['amount'],
                'currency'            => (string) $config['currency'],
                'api_key'             => (string) $config['apiKey'],
                'integrity_signature' => (string) $config['integritySignature'],
                'description'         => (string) $config['description'],
                'redirection_url'     => (string) $config['redirectionUrl'],
            ],
        ];
    }
}

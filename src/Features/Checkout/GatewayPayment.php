<?php

declare(strict_types=1);

namespace App\Features\Checkout;

use App\Features\PaymentGateway\Gateways\Addi\AddiCheckoutService;
use App\Features\PaymentGateway\Gateways\Bold\BoldCheckoutService;
use App\Features\PaymentGateway\Gateways\Sistecredito\SistecreditoCheckoutService;
use App\Features\PaymentGateway\PaymentAttemptRepository;
use App\Infrastructure\Config\BusinessConfig;
use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;
use PDO;
use Throwable;

/**
 * Datos para abrir la pasarela de un pedido, armados con lo que quedó guardado en él: el valor,
 * los productos, quien compra y la dirección. La app nunca envía precios.
 *
 * - Bold: los datos del botón, con la firma de integridad.
 * - Addi y Sistecrédito: la URL de su página de pago, que la app abre.
 *
 * Cada llamada es un intento de pago con su propio id, `pf-<consecutivo>` (spec backend/03, P-24):
 * es la referencia que recibe la pasarela y con la que su webhook encuentra el pedido. En todas, el
 * pedido lo aprueba el webhook, nunca la vuelta del usuario.
 */
final class GatewayPayment
{
    private const string CURRENCY = 'COP';
    private const string COUNTRY = 'CO';
    private const string CALLING_CODE = '+57';
    private const string BRAND = 'PremiumFitness';

    /** `document_types` de la base → tipo de documento de las pasarelas. */
    private const array DOCUMENT_TYPES = [1 => 'CC', 2 => 'NIT', 3 => 'CE', 4 => 'TI'];

    private const array NAMES = ['bold' => 'Bold', 'addi' => 'Addi', 'sistecredito' => 'Sistecrédito'];

    public function __construct(
        private readonly BusinessConfig $business,
        private readonly PaymentAttemptRepository $attempts = new PaymentAttemptRepository(),
    ) {}

    /**
     * @param string $returnUrl A donde vuelve el usuario después de pagar (`/pago/resultado?pedido=`).
     * @return array<string, mixed> El `payment` del contrato `CheckoutResult`.
     * @throws ApiException 502 si la pasarela no entrega su página de pago.
     */
    public function start(int $orderId, string $returnUrl): array
    {
        $order     = $this->order($orderId);
        $gateway   = (string) $order['payment_gateway'];
        $reference = $this->attempts->create($orderId, $gateway, (float) $order['amount']);

        if ($gateway === 'bold') {
            return $this->bold($order, $reference, $returnUrl);
        }

        try {
            $url = match ($gateway) {
                'addi'         => AddiCheckoutService::fromEnv()->obtainRedirectUrl(
                    $this->addiPayload($order, $reference, $this->items($orderId), $returnUrl)
                ),
                'sistecredito' => SistecreditoCheckoutService::fromEnv()->createOrder(
                    self::sistecreditoPayload($order, $reference, $returnUrl)
                )['paymentRedirectUrl'],
                default        => throw new \LogicException("Pasarela no soportada: {$gateway}"),
            };
        } catch (Throwable) {
            $name = self::NAMES[$gateway] ?? $gateway;
            throw new ApiException(
                502,
                'payment_unavailable',
                "No pudimos abrir el pago con {$name}. Tu pedido quedó pendiente: puedes pagarlo desde tus pedidos.",
            );
        }

        return ['gateway' => $gateway, 'redirect_url' => $url];
    }

    /**
     * Cuerpo de la solicitud de crédito de Addi. Los importes van como texto con decimal, como en su
     * documentación; la URL del webhook la pone `AddiCheckoutService`.
     *
     * @param array<string, mixed>       $order
     * @param string                     $reference Id del intento de pago (`pf-<id>`).
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function addiPayload(array $order, string $reference, array $items, string $returnUrl): array
    {
        [$firstName, $lastName] = self::splitName((string) $order['full_name']);
        $address = [
            'lineOne' => (string) ($order['address'] ?? ''),
            'city'    => (string) ($order['city_name'] ?? ''),
            'country' => self::COUNTRY,
        ];

        return [
            'orderId'             => $reference,
            'totalAmount'         => self::decimal((float) $order['amount']),
            'shippingAmount'      => self::decimal((float) $order['shipping_amount']),
            'totalTaxesAmount'    => self::decimal((float) $order['tax_amount']),
            'currency'            => self::CURRENCY,
            'allyTransactionDate' => date(DATE_ATOM),
            'items'               => array_map(
                static fn (array $item): array => [
                    'sku'       => (string) $item['product_sku'],
                    'name'      => (string) $item['product_name'],
                    'quantity'  => (string) $item['quantity'],
                    'unitPrice' => (int) round((float) $item['unit_price']),
                    'tax'       => (int) round((float) $item['tax_amount']),
                    'category'  => 'nutrition',
                    'brand'     => self::BRAND,
                ],
                $items,
            ),
            'client' => [
                'idType'               => self::documentType($order),
                'idNumber'             => (string) $order['document_id'],
                'firstName'            => $firstName,
                'lastName'             => $lastName,
                'email'                => (string) $order['email'],
                'cellphone'            => preg_replace('/\D+/', '', (string) $order['num_cell']) ?? '',
                'cellphoneCountryCode' => self::CALLING_CODE,
                'address'              => $address,
            ],
            'shippingAddress'    => $address,
            'billingAddress'     => $address,
            'allyUrlRedirection' => [
                'logoUrl'        => $this->business->webAppUrl . '/icons/icon-512.png',
                'redirectionUrl' => $returnUrl,
            ],
        ];
    }

    /**
     * Datos de la orden de Sistecrédito (Credinet); el resto del cuerpo lo pone su servicio.
     *
     * @param array<string, mixed> $order
     * @param string               $reference Id del intento de pago (`pf-<id>`).
     * @return array{invoice: string, description: string, value: int, docType: string, document: string, urlResponse: string}
     */
    public static function sistecreditoPayload(array $order, string $reference, string $returnUrl): array
    {
        return [
            'invoice'     => $reference,
            'description' => 'Pedido ' . self::BRAND . ' #' . $order['order_id'],
            'value'       => (int) round((float) $order['amount']),
            'docType'     => self::documentType($order),
            'document'    => (string) $order['document_id'],
            'urlResponse' => $returnUrl,
        ];
    }

    /**
     * Datos del botón de Bold. La firma de integridad cubre el pedido, el valor y la moneda:
     * Bold rechaza el pago si la app cambia cualquiera de los tres.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function bold(array $order, string $reference, string $returnUrl): array
    {
        $orderId = (int) $order['order_id'];
        $config  = BoldCheckoutService::fromEnv()->buildCheckoutConfig([
            'order_id'        => $reference,
            'amount'          => (int) round((float) $order['amount']),
            'currency'        => self::CURRENCY,
            'description'     => 'Pedido ' . self::BRAND . " #{$orderId}",
            'redirection_url' => $returnUrl,
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

    /** @return array<string, mixed> */
    private function order(int $orderId): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT o.order_id, o.amount, o.shipping_amount, o.tax_amount, o.payment_gateway,
                    u.full_name, u.email, u.num_cell, u.document_id, u.document_type_id,
                    a.address, c.name AS city_name
             FROM orders o
             JOIN users u ON u.id = o.user_id
             LEFT JOIN addresses a ON a.id = o.address_id
             LEFT JOIN cities c ON c.id = a.city_id
             WHERE o.order_id = ?'
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw ApiException::notFound('No encontramos ese pedido.');
        }

        /** @var array<string, mixed> $row */
        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function items(int $orderId): array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT product_sku, product_name, quantity, unit_price, tax_amount
             FROM orders_items WHERE order_id = ? ORDER BY item_id'
        );
        $stmt->execute([$orderId]);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $order */
    private static function documentType(array $order): string
    {
        return self::DOCUMENT_TYPES[(int) $order['document_type_id']] ?? 'CC';
    }

    /** @return array{0: string, 1: string} */
    private static function splitName(string $fullName): array
    {
        $parts     = preg_split('/\s+/', trim($fullName)) ?: [];
        $firstName = $parts[0] ?? '';
        $lastName  = trim(implode(' ', array_slice($parts, 1)));

        return [$firstName, $lastName !== '' ? $lastName : $firstName];
    }

    private static function decimal(float $amount): string
    {
        return number_format($amount, 1, '.', '');
    }
}

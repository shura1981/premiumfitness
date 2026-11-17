<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Atajos de las pruebas de compra: peticiones reales contra la app.
 */
final class Shop
{
    /** Dirección del usuario. Devuelve su id. */
    public static function address(int $userId): int
    {
        $pdo = Seed::pdo();
        $pdo->prepare("INSERT INTO addresses (city_id, address, address_name) VALUES ((SELECT MIN(id) FROM cities), 'CL 1 2 3', 'CASA')")->execute();
        $addressId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO address_user_map (user_id, address_id, is_main) VALUES (?, ?, 1)')->execute([$userId, $addressId]);

        return $addressId;
    }

    /** @param list<array{code:int, quantity:int}> $items */
    public static function preview(int $userId, array $items): ResponseInterface
    {
        return AppFactory::dispatch(AppFactory::request('POST', '/api/checkout/preview', ['items' => $items], JwtFactory::authHeader($userId)));
    }

    /** @param list<array{code:int, quantity:int}> $items */
    public static function checkout(int $userId, array $items, ?int $addressId = null, string $gateway = 'bold'): ResponseInterface
    {
        return AppFactory::dispatch(AppFactory::request(
            'POST',
            '/api/checkout',
            ['items' => $items, 'address_id' => $addressId ?? self::address($userId), 'payment_gateway' => $gateway],
            JwtFactory::authHeader($userId),
        ));
    }

    /** Compra y devuelve el id del pedido creado. */
    public static function order(int $userId, array $items): int
    {
        $response = self::checkout($userId, $items);

        if ($response->getStatusCode() !== 201) {
            throw new \RuntimeException('Checkout falló: ' . $response->getBody());
        }

        return (int) AppFactory::json($response)['order_id'];
    }

    /**
     * Aviso de pago de Bold, firmado como lo firma Bold.
     *
     * @return array<string, mixed> Cuerpo de la respuesta del webhook.
     */
    public static function boldWebhook(int $orderId, float $amount, ?string $paymentId = null, string $type = 'SALE_APPROVED', bool $validSignature = true, ?string $reference = null): array
    {
        $raw = json_encode([
            'id'   => 'evt-' . bin2hex(random_bytes(6)),
            'type' => $type,
            'data' => [
                'payment_id' => $paymentId ?? 'PAY-' . bin2hex(random_bytes(8)),
                'amount'     => ['total' => $amount, 'currency' => 'COP'],
                'metadata'   => ['reference' => $reference ?? self::reference($orderId)],
            ],
        ], JSON_THROW_ON_ERROR);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payment-gateway/webhook/bold', ['REMOTE_ADDR' => AppFactory::randomIp()])
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('x-bold-signature', $validSignature ? BoldTestSigner::fromEnv()->sign($raw) : str_repeat('0', 64))
            ->withBody((new StreamFactory())->createStream($raw));

        return (array) AppFactory::json(AppFactory::dispatch($request));
    }

    /**
     * Aviso de una pasarela sin firma (Addi o Sistecrédito), con la forma que envía cada una.
     *
     * @return array<string, mixed> Cuerpo de la respuesta del webhook.
     */
    public static function gatewayWebhook(string $gateway, int $orderId, float $amount, string $status, ?string $reference = null): array
    {
        $reference ??= self::reference($orderId);
        $payload = $gateway === 'addi'
            ? ['orderId' => $reference, 'applicationId' => 'APP-' . bin2hex(random_bytes(6)), 'status' => $status, 'approvedAmount' => $amount]
            : ['Invoice' => $reference, 'Value' => $amount, 'Currency' => 'COP',
               'PaymentMethodResponse' => ['TransactionId' => 'SC-' . bin2hex(random_bytes(6)), 'StatusResponse' => $status]];

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', "/api/payment-gateway/webhook/{$gateway}", ['REMOTE_ADDR' => AppFactory::randomIp()])
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode($payload, JSON_THROW_ON_ERROR)));

        return (array) AppFactory::json(AppFactory::dispatch($request));
    }

    /** Paga un pedido completo: avisa a Bold por su valor. */
    public static function pay(int $orderId): array
    {
        $stmt = Seed::pdo()->prepare('SELECT amount FROM orders WHERE order_id = ?');
        $stmt->execute([$orderId]);

        return self::boldWebhook($orderId, (float) $stmt->fetchColumn());
    }

    /** Referencia del último intento de pago del pedido (`pf-<id>`), la que recibe la pasarela. */
    public static function reference(int $orderId): string
    {
        $stmt = Seed::pdo()->prepare('SELECT MAX(id) FROM payment_attempts WHERE order_id = ?');
        $stmt->execute([$orderId]);
        $id = $stmt->fetchColumn();

        if ($id === null || $id === false) {
            throw new \RuntimeException("El pedido {$orderId} no tiene intentos de pago.");
        }

        return 'pf-' . $id;
    }

    /** @return array<string, mixed> */
    public static function orderRow(int $orderId): array
    {
        $stmt = Seed::pdo()->prepare('SELECT o.*, s.value AS status_value FROM orders o LEFT JOIN status_orders s ON s.status_id = o.status_id WHERE o.order_id = ?');
        $stmt->execute([$orderId]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function rows(string $sql, array $params = []): array
    {
        $stmt = Seed::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}

<?php

declare(strict_types=1);

use App\Features\Order\OrderRepository;
use App\Features\PaymentGateway\Normalizer\PaymentStatus;
use App\Features\User\UserRepository;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\Support\AppFactory;
use Tests\Support\BoldTestSigner;
use Tests\Support\JwtFactory;
use Tests\Support\Seed;

// `POST /api/payment-gateway/consume/bold` de radical no existe aquí: firmaba el valor que enviara
// la app. El pago lo firma `POST /api/checkout` con el valor del pedido (tests/E2E/Checkout).
it('no expone el endpoint de radical que firmaba el valor enviado por la app', function (): void {
    $response = AppFactory::dispatch(AppFactory::request(
        'POST',
        '/api/payment-gateway/consume/bold',
        body: ['order_id' => 1001, 'amount' => 1],
        headers: JwtFactory::authHeader(Seed::user()),
    ));

    expect($response->getStatusCode())->toBeIn([404, 405]);
});

it('rejects POST /api/payment-gateway/webhook/bold with invalid signature', function (): void {
    $response = AppFactory::dispatch(AppFactory::request(
        'POST',
        '/api/payment-gateway/webhook/bold',
        body: [
            'type' => 'SALE_APPROVED',
            'data' => [
                'payment_id' => 'BOLD-TX-INVALID',
                'amount' => ['total' => 100000, 'currency' => 'COP'],
                'metadata' => ['reference' => '1'],
            ],
        ],
        headers: ['x-bold-signature' => 'invalid-hmac-signature'],
    ));

    $body = AppFactory::json($response);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['ok'] ?? null)->toBeFalse()
        ->and($body['error'] ?? null)->toBe('invalid_signature');
});

it('treats signed VOID_REJECTED Bold webhook as a no-op and approves order on signed SALE_APPROVED', function (): void {
    $userId  = Seed::user();
    $orderId = Seed::order($userId, amount: 185000);
    $signer  = BoldTestSigner::fromEnv();

    try {
        $voidRejectedRaw = json_encode([
            'type' => 'VOID_REJECTED',
            'data' => [
                'payment_id' => 'BOLD-TX-VOID-REJ',
                'amount' => ['total' => 185000, 'currency' => 'COP'],
                'metadata' => ['reference' => (string) $orderId],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $voidReq = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payment-gateway/webhook/bold')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('x-bold-signature', $signer->sign($voidRejectedRaw))
            ->withBody((new StreamFactory())->createStream($voidRejectedRaw));

        $voidRes  = AppFactory::dispatch($voidReq);
        $voidBody = AppFactory::json($voidRes);

        $approvedRaw = json_encode([
            'type' => 'SALE_APPROVED',
            'data' => [
                'payment_id' => 'BOLD-TX-OK-' . $orderId,
                'amount' => ['total' => 185000, 'currency' => 'COP'],
                'metadata' => ['reference' => (string) $orderId],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $approvedReq = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payment-gateway/webhook/bold')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('x-bold-signature', $signer->sign($approvedRaw))
            ->withBody((new StreamFactory())->createStream($approvedRaw));

        $approvedRes  = AppFactory::dispatch($approvedReq);
        $approvedBody = AppFactory::json($approvedRes);
        $persisted    = (new OrderRepository())->findById($orderId);

        expect($voidRes->getStatusCode())->toBe(200)
            ->and($voidBody['ok'] ?? null)->toBeTrue()
            ->and($voidBody['processed'] ?? null)->toBeFalse()
            ->and($voidBody['reason'] ?? null)->toBe('void_rejected')
            ->and($approvedRes->getStatusCode())->toBe(200)
            ->and($approvedBody['ok'] ?? null)->toBeTrue()
            ->and($approvedBody['processed'] ?? null)->toBeTrue()
            ->and($persisted)->not->toBeFalse()
            ->and($persisted->status_value)->toBe(PaymentStatus::TEXT_APROBADA)
            ->and($persisted->payment_state_json)->toContain('BOLD-TX-OK-' . $orderId);
    } finally {
        Seed::pdo()->prepare('DELETE FROM payment_event_jobs WHERE order_id = ?')->execute([$orderId]);
        (new OrderRepository())->delete($orderId);
        (new UserRepository())->delete($userId);
    }
});

it('renders GET /response/bold HTML response page with orderId and status', function (): void {
    $response = AppFactory::dispatch(AppFactory::request(
        'GET',
        '/response/bold?orderId=7890&bold-tx-status=approved',
    ));
    $html = (string) $response->getBody();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getHeaderLine('Content-Type'))->toContain('text/html')
        ->and($html)->toContain('Bold')
        ->and($html)->toContain('7890');
});


<?php

declare(strict_types=1);

use App\Features\PaymentGateway\Logging\PaymentGatewayLogService;
use App\Shared\Exceptions\HttpRequestException;

it('writes only error consume logs with sanitized payload data', function (): void {
    $directory = sys_get_temp_dir() . '/payment-gateway-log-' . bin2hex(random_bytes(6));
    $logs = new PaymentGatewayLogService($directory);

    $logs->consumeFailed(
        'bold',
        'unit_test',
        456,
        new HttpRequestException('Gateway rejected request', 401, '{"token":"secret","message":"invalid"}'),
        35.456,
        [
            'payload' => [
                'Authorization' => 'Bearer secret-token',
                'private_key' => 'secret',
                'invoice' => '456',
                'billing' => [
                    'email' => 'cliente@example.com',
                    'mobilePhone' => '3001234567',
                    'numberDoc' => '1234567890',
                ],
                'redirect_url' => 'https://checkout.example.com/pay?token=abc123',
            ],
        ]
    );

    $entry = readPaymentGatewayErrorLogEntry($directory);

    expect($entry['channel'])->toBe('payment_gateway_consume')
        ->and($entry['level'])->toBe('error')
        ->and($entry['stage'])->toBe('failed')
        ->and($entry['gateway'])->toBe('bold')
        ->and($entry['source'])->toBe('unit_test')
        ->and($entry['order_id'])->toBe(456)
        ->and($entry['duration_ms'])->toBe(35.46)
        ->and($entry['error']['type'])->toBe(HttpRequestException::class)
        ->and($entry['error']['status_code'])->toBe(401)
        ->and($entry['error']['payload']['token'])->toBe('[redacted]')
        ->and($entry['context']['payload']['Authorization'])->toBe('[redacted]')
        ->and($entry['context']['payload']['private_key'])->toBe('[redacted]')
        ->and($entry['context']['payload']['invoice'])->toBe('456')
        ->and($entry['context']['payload']['billing']['email'])->toBe('cl***@example.com')
        ->and($entry['context']['payload']['billing']['mobilePhone'])->toBe('******4567')
        ->and($entry['context']['payload']['billing']['numberDoc'])->toBe('******7890')
        ->and($entry['context']['payload']['redirect_url'])->toBe('https://checkout.example.com/pay?...');

    removePaymentGatewayLogDirectory($directory);
});

/**
 * @return array<string, mixed>
 */
function readPaymentGatewayErrorLogEntry(string $directory): array
{
    $file = $directory . '/errors-' . date('Y-m-d') . '.log';

    expect(is_file($file))->toBeTrue();

    /** @var array<string, mixed> $entry */
    $entry = json_decode(trim((string) file_get_contents($file)), true, 512, JSON_THROW_ON_ERROR);

    return $entry;
}

function removePaymentGatewayLogDirectory(string $directory): void
{
    foreach (glob($directory . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    if (is_dir($directory)) {
        rmdir($directory);
    }
}

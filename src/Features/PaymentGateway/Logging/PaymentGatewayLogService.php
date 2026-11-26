<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Logging;

use App\Shared\Exceptions\HttpRequestException;
use Throwable;

final readonly class PaymentGatewayLogService
{
    private const string CHANNEL_CONSUME = 'payment_gateway_consume';
    private const int MAX_STRING_LENGTH = 1200;

    /** @var list<string> */
    private const array SECRET_KEYS = [
        'authorization',
        'token',
        'access_token',
        'refresh_token',
        'client_secret',
        'secret',
        'password',
        'applicationtoken',
        'application_token',
        'applicationkey',
        'application_key',
        'ocp-apim-subscription-key',
        'subscription_key',
        'private_key',
        'p_key',
        'key',
    ];

    /** @var list<string> */
    private const array EMAIL_KEYS = ['email', 'correo'];

    /** @var list<string> */
    private const array PHONE_KEYS = ['phone', 'cellphone', 'mobilephone', 'mobile_phone', 'num_cell', 'telefono'];

    /** @var list<string> */
    private const array DOCUMENT_KEYS = ['document', 'idnumber', 'id_number', 'numberdoc', 'number_doc', 'document_id'];

    public function __construct(private string $logDirectory) {}

    public static function default(): self
    {
        return new self(dirname(__DIR__, 4) . '/logs/payment-gateway');
    }

    /**
     * @param array<string, mixed> $context
     */
    public function consumeFailed(string $gateway, string $source, ?int $orderId, Throwable $error, float $durationMs, array $context = []): void
    {
        $this->write('failed', $gateway, $source, $orderId, [
            'duration_ms' => $this->roundedDuration($durationMs),
            'error' => $this->errorContext($error),
            'context' => $context,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $stage, string $gateway, string $source, ?int $orderId, array $context): void
    {
        try {
            if (!is_dir($this->logDirectory)) {
                mkdir($this->logDirectory, 0775, true);
            }

            $entry = [
                'timestamp' => date(DATE_ATOM),
                'channel' => self::CHANNEL_CONSUME,
                'level' => 'error',
                'stage' => $stage,
                'gateway' => $gateway,
                'source' => $source,
                'order_id' => $orderId,
                ...$this->sanitize($context),
            ];

            file_put_contents(
                $this->logDirectory . '/errors-' . date('Y-m-d') . '.log',
                json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (Throwable $e) {
            error_log('Payment gateway log failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function errorContext(Throwable $error): array
    {
        $context = [
            'type' => $error::class,
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
        ];

        if ($error instanceof HttpRequestException) {
            $context['status_code'] = $error->statusCode();
            $context['payload'] = $this->decodeExternalPayload($error->errorPayload());
        }

        return $context;
    }

    private function roundedDuration(float $durationMs): float
    {
        return round($durationMs, 2);
    }

    private function decodeExternalPayload(?string $payload): mixed
    {
        if ($payload === null || trim($payload) === '') {
            return null;
        }

        $decoded = json_decode($payload, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $payload;
    }

    private function sanitize(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $itemKey => $itemValue) {
                $sanitized[$itemKey] = $this->sanitize($itemValue, is_string($itemKey) ? $itemKey : null);
            }

            return $sanitized;
        }

        if (!is_scalar($value) && $value !== null) {
            return get_debug_type($value);
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        $text = (string) $value;
        $normalizedKey = $this->normalizeKey($key);

        if ($this->isSecretKey($normalizedKey)) {
            return '[redacted]';
        }

        if ($this->matchesAnyKey($normalizedKey, self::EMAIL_KEYS) || filter_var($text, FILTER_VALIDATE_EMAIL) !== false) {
            return $this->maskEmail($text);
        }

        if ($this->matchesAnyKey($normalizedKey, self::PHONE_KEYS) || $this->matchesAnyKey($normalizedKey, self::DOCUMENT_KEYS)) {
            return $this->maskTail($text);
        }

        if (str_contains($normalizedKey, 'url')) {
            return $this->sanitizeUrl($text);
        }

        return $this->truncate($text);
    }

    private function normalizeKey(?string $key): string
    {
        return strtolower(str_replace(['-', ' '], '_', $key ?? ''));
    }

    private function isSecretKey(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        foreach (self::SECRET_KEYS as $secretKey) {
            if ($key === $secretKey || str_contains($key, $secretKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $needles
     */
    private function matchesAnyKey(string $key, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return $this->truncate($email);
        }

        [$name, $domain] = explode('@', $email, 2);
        $prefix = substr($name, 0, 2);

        return ($prefix !== '' ? $prefix : '**') . '***@' . $domain;
    }

    private function maskTail(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return '[masked]';
        }

        return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }

    private function sanitizeUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'])) {
            return $this->truncate($url);
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?...' : '';

        return $this->truncate($scheme . $parts['host'] . $path . $query);
    }

    private function truncate(string $value): string
    {
        if (strlen($value) <= self::MAX_STRING_LENGTH) {
            return $value;
        }

        return substr($value, 0, self::MAX_STRING_LENGTH) . '...';
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Infrastructure\Http\CurlHttpClient;

/**
 * Canal de notificación por SMS vía Relax Global API.
 *
 * Requiere en .env:
 *   SMS_API_URL   — https://backend.relax.global/api/sms/
 *   SMS_API_TOKEN — Bearer token de autenticación
 */
class SmsNotificationChannel implements NotificationChannelInterface
{
    private readonly string $apiUrl;
    private readonly string $apiToken;

    private function __construct(string $apiUrl, string $apiToken)
    {
        $this->apiUrl   = $apiUrl;
        $this->apiToken = $apiToken;
    }

    public static function fromEnv(): self
    {
        return new self(
            $_ENV['SMS_API_URL'],
            $_ENV['SMS_API_TOKEN'],
        );
    }

    public function send(string $recipient, string $subject, string $message): void
    {
        if (trim($recipient) === '') {
            throw new \InvalidArgumentException('No se puede enviar SMS: número de celular vacío.');
        }

        $http = new CurlHttpClient();

        $http->request($this->apiUrl, [
            'method'  => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiToken,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'payload' => [
                'name'          => $subject,
                'type'          => 'fast',
                'message'       => $message,
                'api'           => true,
                'transactional' => true,
                'category'      => 'informative',
                'phones'        => [
                    [
                        'indicative'  => '57',
                        'cell_phone'  => $recipient,
                    ],
                ],
            ],
        ]);
    }
}

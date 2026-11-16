<?php

declare(strict_types=1);

namespace App\Features\Notification;

use App\Infrastructure\Cache\CacheService;
use App\Infrastructure\Http\CurlHttpClient;
use Firebase\JWT\JWT;
use RuntimeException;

/**
 * Envío de push con FCM HTTP v1 (spec integraciones/01): cuenta de servicio, JWT RS256 y access
 * token de OAuth2 en caché. Nunca el API legacy `fcm/send`, que Google retiró.
 */
class FcmPushChannel
{
    private const string TOKEN_URL       = 'https://oauth2.googleapis.com/token';
    private const string SCOPE           = 'https://www.googleapis.com/auth/firebase.messaging';
    private const int    TOKEN_CACHE_TTL = 3300; // 55 minutos
    private const int    TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly string $projectId,
        private readonly string $credentialsPath,
        private readonly string $webAppUrl,
        private readonly CurlHttpClient $http = new CurlHttpClient(),
    ) {}

    public static function fromEnv(): self
    {
        $path = (string) $_ENV['FIREBASE_CREDENTIALS_PATH'];

        return new self(
            projectId: (string) $_ENV['FIREBASE_PROJECT_ID'],
            credentialsPath: str_starts_with($path, '/') ? $path : __DIR__ . '/../../../' . $path,
            webAppUrl: rtrim((string) $_ENV['WEB_APP_URL'], '/'),
        );
    }

    /** ¿Están las credenciales de Firebase en el servidor? Sin ellas no se intenta enviar. */
    public function isConfigured(): bool
    {
        return $this->projectId !== '' && is_file($this->credentialsPath);
    }

    /**
     * Envía una notificación a un dispositivo. Todo push lleva título y texto visibles (iOS retira
     * la suscripción a quien envía pushes silenciosos).
     *
     * @param array<string, mixed> $data Ruta de la app (`route`) e id del recurso.
     * @throws \App\Shared\Exceptions\HttpRequestException si FCM rechaza el mensaje
     */
    public function send(string $deviceToken, string $title, string $body, array $data): void
    {
        $route = is_string($data['route'] ?? null) ? $data['route'] : '/';

        $this->http->request("https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send", [
            'method'  => 'POST',
            'headers' => ['Authorization' => 'Bearer ' . $this->accessToken(), 'Content-Type' => 'application/json'],
            'payload' => [
                'message' => [
                    'token'        => $deviceToken,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data'         => array_map(strval(...), $data),
                    'webpush'      => [
                        'notification' => ['title' => $title, 'body' => $body, 'icon' => $this->webAppUrl . '/icons/icon-192.png'],
                        'fcm_options'  => ['link' => $this->webAppUrl . $route],
                    ],
                ],
            ],
            'timeout' => self::TIMEOUT_SECONDS,
        ]);
    }

    private function accessToken(): string
    {
        return CacheService::fromEnv()->remember('fcm.access_token.' . md5($this->credentialsPath), self::TOKEN_CACHE_TTL, function (): string {
            /** @var array{client_email?:string, private_key?:string} $account */
            $account = json_decode((string) file_get_contents($this->credentialsPath), true, 512, JSON_THROW_ON_ERROR);

            if (!isset($account['client_email'], $account['private_key'])) {
                throw new RuntimeException('Firebase credentials file is not a service account.');
            }

            $now       = time();
            $assertion = JWT::encode(
                ['iss' => $account['client_email'], 'scope' => self::SCOPE, 'aud' => self::TOKEN_URL, 'iat' => $now, 'exp' => $now + 3600],
                $account['private_key'],
                'RS256',
            );

            $response = $this->http->request(self::TOKEN_URL, [
                'method'  => 'POST',
                'payload' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            /** @var array{access_token?:string} $decoded */
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            return $decoded['access_token'] ?? throw new RuntimeException('Google did not return an access token.');
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Infrastructure\Http\CurlHttpClient;

/**
 * Cliente HTTP unificado para el gateway de WebSockets (socket.elitenutapp.com).
 *
 * Gestiona dos proyectos configurados vía env vars:
 *   - `premiumfitness`       — emite eventos dirigidos por `socket_id` al cliente final.
 *   - `premiumfitness-admin` — hace broadcast a todos los admins conectados.
 *
 * Requiere en .env:
 *   SOCKET_NOTIFICATION_URL          — host base (ej. https://socket.elitenutapp.com)
 *   SOCKET_NOTIFICATION_TOKEN        — alias de SOCKET_GATEWAY_KEY_CLIENT (compat)
 *   SOCKET_GATEWAY_PROJECT_ID_CLIENT — ej. premiumfitness
 *   SOCKET_GATEWAY_PROJECT_ID_ADMIN  — ej. premiumfitness-admin
 *   SOCKET_GATEWAY_KEY_ADMIN         — x-project-key del proyecto admin
 */
final readonly class SocketGatewayClient implements RealtimeGateway
{
    private function __construct(
        private CurlHttpClient $http,
        private string $baseUrl,
        private string $clientProjectId,
        private string $clientKey,
        private string $adminProjectId,
        private string $adminKey,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            new CurlHttpClient(),
            rtrim($_ENV['SOCKET_NOTIFICATION_URL'], '/'),
            $_ENV['SOCKET_GATEWAY_PROJECT_ID_CLIENT'],
            $_ENV['SOCKET_NOTIFICATION_TOKEN'],
            $_ENV['SOCKET_GATEWAY_PROJECT_ID_ADMIN'],
            $_ENV['SOCKET_GATEWAY_KEY_ADMIN'],
        );
    }

    /**
     * Emite eventos dirigidos a un socket específico del proyecto cliente.
     *
     * @param array<int, array<string, mixed>> $events
     */
    public function emitToSocket(string $socketId, array $events): void
    {
        $this->http->request($this->baseUrl . '/webhook/emit', [
            'method'  => 'POST',
            'headers' => $this->clientHeaders(),
            'payload' => [
                'socket_id' => $socketId,
                'events'    => $events,
            ],
            'timeout' => 10,
        ]);
    }

    /**
     * Broadcast de eventos a todos los clientes del proyecto admin.
     *
     * @param array<int, array<string, mixed>> $events
     */
    public function broadcastToAdmin(array $events): void
    {
        $this->http->request($this->baseUrl . '/webhook/broadcast', [
            'method'  => 'POST',
            'headers' => $this->adminHeaders(),
            'payload' => ['events' => $events],
            'timeout' => 10,
        ]);
    }

    /** @return array<string, string> */
    private function clientHeaders(): array
    {
        return [
            'x-project-id'  => $this->clientProjectId,
            'x-project-key' => $this->clientKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    /** @return array<string, string> */
    private function adminHeaders(): array
    {
        return [
            'x-project-id'  => $this->adminProjectId,
            'x-project-key' => $this->adminKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }
}

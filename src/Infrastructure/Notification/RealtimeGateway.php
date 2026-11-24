<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

/** Envío de avisos en tiempo real (gateway de sockets de elinut). Las pruebas usan uno que los registra. */
interface RealtimeGateway
{
    /** @param array<int, array<string, mixed>> $events Avisos a una conexión (el cliente). */
    public function emitToSocket(string $socketId, array $events): void;

    /** @param array<int, array<string, mixed>> $events Avisos a todos los agentes del admin. */
    public function broadcastToAdmin(array $events): void;
}

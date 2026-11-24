<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Notification\RealtimeGateway;

/** Registra los avisos en vez de mandarlos al gateway de sockets. */
final class RecordingRealtime implements RealtimeGateway
{
    /** @var list<array{to:string, event:string, payload:mixed}> */
    public array $sent = [];

    public function emitToSocket(string $socketId, array $events): void
    {
        foreach ($events as $event) {
            $this->sent[] = ['to' => $socketId, 'event' => (string) $event['event'], 'payload' => $event['payload']];
        }
    }

    public function broadcastToAdmin(array $events): void
    {
        foreach ($events as $event) {
            $this->sent[] = ['to' => 'admin', 'event' => (string) $event['event'], 'payload' => $event['payload']];
        }
    }

    /** @return list<array{to:string, event:string, payload:mixed}> */
    public function named(string $event): array
    {
        return array_values(array_filter($this->sent, static fn (array $sent): bool => $sent['event'] === $event));
    }
}

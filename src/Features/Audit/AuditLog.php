<?php

declare(strict_types=1);

namespace App\Features\Audit;

use App\Infrastructure\Database\DB;

/**
 * Registro de las operaciones sensibles (RF-16): quién, qué, sobre qué y con qué datos.
 * Se escribe dentro de la transacción de la operación. Nunca guarda contraseñas ni tokens.
 */
final class AuditLog
{
    /** @param array<string, mixed> $data */
    public static function byAgent(int $agentId, string $action, string $entity, ?int $entityId, array $data = []): void
    {
        self::record($agentId, null, $action, $entity, $entityId, $data);
    }

    /** @param array<string, mixed> $data */
    public static function byUser(int $userId, string $action, string $entity, ?int $entityId, array $data = []): void
    {
        self::record(null, $userId, $action, $entity, $entityId, $data);
    }

    /**
     * Operación que hizo una pasarela con su webhook, sin agente ni usuario (por ejemplo, Bold anula un pago).
     *
     * @param array<string, mixed> $data
     */
    public static function byGateway(string $gateway, string $action, string $entity, ?int $entityId, array $data = []): void
    {
        self::record(null, null, $action, $entity, $entityId, ['gateway' => $gateway] + $data);
    }

    /** @param array<string, mixed> $data */
    private static function record(?int $agentId, ?int $userId, string $action, string $entity, ?int $entityId, array $data): void
    {
        DB::getInstance()->prepare(
            'INSERT INTO audit_logs (agent_id, user_id, action, entity, entity_id, data) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$agentId, $userId, $action, $entity, $entityId, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }
}

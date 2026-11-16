<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Auth\JwtService;

final class JwtFactory
{
    /** Access token real de la app web para el usuario, con el rol que tiene en la base. */
    public static function forUser(int $userId, ?string $role = null): string
    {
        return JwtService::fromEnv()->issue(['sub' => $userId, 'role' => $role ?? self::roleOf($userId), 'app' => 'web']);
    }

    /**
     * Convenience: `Authorization` header array for AppFactory::request().
     *
     * @return array<string, string>
     */
    public static function authHeader(int $userId, ?string $role = null): array
    {
        return ['Authorization' => 'Bearer ' . self::forUser($userId, $role)];
    }

    /**
     * Access token del admin para un agente de prueba (`docker/intranet-local.sql`).
     *
     * @return array<string, string>
     */
    public static function adminHeader(int $agentId = 9001, string $cargo = 'ADMINISTRADOR'): array
    {
        $token = JwtService::fromEnv()->issue([
            'sub'         => $agentId,
            'app'         => 'admin',
            'id_empleado' => $agentId,
            'usuario'     => 'Agente de prueba',
            'cargo'       => $cargo,
        ]);

        return ['Authorization' => 'Bearer ' . $token];
    }

    private static function roleOf(int $userId): string
    {
        $stmt = Seed::pdo()->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return (string) ($stmt->fetchColumn() ?: 'client');
    }
}

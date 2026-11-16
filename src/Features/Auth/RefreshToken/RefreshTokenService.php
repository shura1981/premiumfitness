<?php

declare(strict_types=1);

namespace App\Features\Auth\RefreshToken;

use App\Infrastructure\Auth\AuthConfig;
use App\Infrastructure\Database\DB;
use Throwable;

/**
 * Refresh tokens rotativos (spec seguridad/01, regla 5).
 *
 * - Cada uso entrega un token nuevo y revoca el anterior.
 * - Usar un token ya rotado revoca toda su familia (la sesión): indica un robo.
 * - Web: cada rotación renueva los 30 días. Admin: la sesión vence a las 12 horas del login.
 */
class RefreshTokenService
{
    /** Margen para dos peticiones simultáneas con la misma cookie (dos pestañas). */
    private const CONCURRENT_GRACE_SECONDS = 10;

    private readonly RefreshTokenRepository $repository;
    private readonly AuthConfig $config;

    public function __construct()
    {
        $this->repository = new RefreshTokenRepository();
        $this->config     = AuthConfig::fromEnv();
    }

    /** Abre una sesión nueva (una familia) para un usuario de la web o un agente del admin. */
    public function issue(string $app, int $ownerId, string $ip, string $userAgent): IssuedRefreshToken
    {
        $ttl = $app === 'admin' ? $this->config->adminSessionTtlSeconds : $this->config->refreshTtlSeconds;

        return $this->create($app, $ownerId, self::uuid(), time() + $ttl, $ip, $userAgent)[1];
    }

    /**
     * Cambia el token por uno nuevo.
     *
     * @throws InvalidRefreshTokenException
     */
    public function rotate(string $app, string $rawToken, string $ip, string $userAgent): RefreshRotation
    {
        $pdo = DB::getInstance();
        $pdo->beginTransaction();

        try {
            $current = $this->repository->findByHashForUpdate(self::hash($rawToken));

            if ($current === false || $current->app !== $app || $current->isExpired()) {
                $pdo->commit();

                throw new InvalidRefreshTokenException('Refresh token not found or expired.');
            }

            if ($current->revoked_at !== null) {
                $rotation = $this->resolveRevoked($current);
                $pdo->commit();

                return $rotation;
            }

            // El admin no extiende su sesión: el token nuevo vence cuando vencía el anterior.
            $expiresAt = $app === 'admin'
                ? (int) strtotime($current->expires_at)
                : time() + $this->config->refreshTtlSeconds;

            [$newId, $issued] = $this->create($app, $current->ownerId(), $current->family_id, $expiresAt, $ip, $userAgent);
            $this->repository->markReplaced($current->id, $newId);
            $pdo->commit();

            return new RefreshRotation($current->ownerId(), $issued);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /** Cierra la sesión del token (toda su familia). No falla si el token no existe. */
    public function revokeFamilyOf(string $rawToken): void
    {
        $pdo = DB::getInstance();
        $pdo->beginTransaction();
        $current = $this->repository->findByHashForUpdate(self::hash($rawToken));

        if ($current !== false) {
            $this->repository->revokeFamily($current->family_id);
        }

        $pdo->commit();
    }

    public function revokeAllForUser(int $userId): void
    {
        $this->repository->revokeAllForUser($userId);
    }

    /**
     * El token ya se había usado. Si lo rotó otra petición hace un instante (dos pestañas a la vez),
     * la sesión sigue: la cookie buena es la que puso esa petición. Si no, es reutilización: se
     * revoca la familia completa.
     *
     * @throws InvalidRefreshTokenException
     */
    private function resolveRevoked(RefreshTokenModel $current): RefreshRotation
    {
        $replacement = $current->replaced_by_id !== null ? $this->repository->findById($current->replaced_by_id) : false;
        $justRotated = time() - (int) strtotime((string) $current->revoked_at) <= self::CONCURRENT_GRACE_SECONDS;

        if ($justRotated && $replacement !== false && $replacement->revoked_at === null) {
            return new RefreshRotation($current->ownerId(), null);
        }

        $this->repository->revokeFamily($current->family_id);
        DB::getInstance()->commit();

        throw new InvalidRefreshTokenException('Refresh token reuse detected.');
    }

    /** @return array{0:int, 1:IssuedRefreshToken} Id de la fila y el token emitido. */
    private function create(string $app, int $ownerId, string $familyId, int $expiresAt, string $ip, string $userAgent): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id    = $this->repository->create($app, $ownerId, $familyId, self::hash($token), $expiresAt, $ip, $userAgent);

        return [$id, new IssuedRefreshToken($token, $expiresAt)];
    }

    /** El token tiene 256 bits aleatorios: un SHA-256 simple basta para guardarlo. */
    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function uuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

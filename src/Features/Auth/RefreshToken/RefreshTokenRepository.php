<?php

declare(strict_types=1);

namespace App\Features\Auth\RefreshToken;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;

class RefreshTokenRepository extends AbstractRepository
{
    private const COLUMNS = 'id, user_id, agent_id, app, family_id, expires_at, revoked_at, replaced_by_id';

    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    public function create(
        string $app,
        int $ownerId,
        string $familyId,
        string $tokenHash,
        int $expiresAt,
        string $ip,
        string $userAgent,
    ): int {
        $this->execute(
            'INSERT INTO refresh_tokens (user_id, agent_id, app, family_id, token_hash, expires_at, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $app === 'web' ? $ownerId : null,
                $app === 'admin' ? $ownerId : null,
                $app,
                $familyId,
                $tokenHash,
                date('Y-m-d H:i:s', $expiresAt),
                mb_substr($ip, 0, 45),
                mb_substr($userAgent, 0, 255),
            ]
        );

        return (int) $this->pdo->lastInsertId();
    }

    /** Bloquea la fila: la rotación va dentro de una transacción. */
    public function findByHashForUpdate(string $tokenHash): RefreshTokenModel|false
    {
        return $this->fetchOne(
            'SELECT ' . self::COLUMNS . ' FROM refresh_tokens WHERE token_hash = ? FOR UPDATE',
            RefreshTokenModel::class,
            [$tokenHash]
        );
    }

    public function findById(int $id): RefreshTokenModel|false
    {
        return $this->fetchOne(
            'SELECT ' . self::COLUMNS . ' FROM refresh_tokens WHERE id = ?',
            RefreshTokenModel::class,
            [$id]
        );
    }

    public function markReplaced(int $id, int $replacedById): void
    {
        $this->execute(
            'UPDATE refresh_tokens SET revoked_at = NOW(), replaced_by_id = ? WHERE id = ?',
            [$replacedById, $id]
        );
    }

    public function revokeFamily(string $familyId): void
    {
        $this->execute(
            'UPDATE refresh_tokens SET revoked_at = NOW() WHERE family_id = ? AND revoked_at IS NULL',
            [$familyId]
        );
    }

    public function revokeAllForUser(int $userId): void
    {
        $this->execute(
            'UPDATE refresh_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL',
            [$userId]
        );
    }

    /** Borra los tokens vencidos hace más de `$days` días. Devuelve cuántos. */
    public function deleteExpiredOlderThan(int $days): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM refresh_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
        $stmt->execute([$days]);

        return $stmt->rowCount();
    }
}

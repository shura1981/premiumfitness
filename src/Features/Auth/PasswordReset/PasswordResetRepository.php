<?php

declare(strict_types=1);

namespace App\Features\Auth\PasswordReset;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;

class PasswordResetRepository extends AbstractRepository
{
    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    public function create(int $userId, string $token, string $expiresAt): int
    {
        $this->execute(
            'INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)',
            [$userId, $token, $expiresAt]
        );

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Busca un token válido (no usado y no expirado).
     */
    public function findValidToken(string $token): PasswordResetModel|false
    {
        return $this->fetchOne(
            'SELECT id, user_id, token, expires_at, used_at, created_at
             FROM password_reset_tokens
             WHERE token = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1',
            PasswordResetModel::class,
            [$token]
        );
    }

    public function markAsUsed(int $id): bool
    {
        return $this->execute(
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?',
            [$id]
        );
    }

    /**
     * Invalida todos los tokens pendientes de un usuario.
     */
    public function invalidateAllForUser(int $userId): bool
    {
        return $this->execute(
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
            [$userId]
        );
    }
}

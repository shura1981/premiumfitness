<?php

declare(strict_types=1);

namespace App\Features\Auth\VerificationCode;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;

class VerificationCodeRepository extends AbstractRepository
{
    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    public function create(int $userId, string $codeHash, string $purpose, string $destination, int $expiresAt): int
    {
        $this->execute(
            "INSERT INTO verification_codes (user_id, code, purpose, channel, destination, expires_at)
             VALUES (?, ?, ?, 'sms', ?, ?)",
            [$userId, $codeHash, $purpose, $destination, date('Y-m-d H:i:s', $expiresAt)]
        );

        return (int) $this->pdo->lastInsertId();
    }

    /** El código vigente del usuario para un propósito: sin usar y sin vencer. */
    public function findActive(int $userId, string $purpose): VerificationCodeModel|false
    {
        return $this->fetchOne(
            'SELECT id, user_id, code, purpose, attempts, expires_at
             FROM verification_codes
             WHERE user_id = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC
             LIMIT 1',
            VerificationCodeModel::class,
            [$userId, $purpose]
        );
    }

    public function addAttempt(int $id): void
    {
        $this->execute('UPDATE verification_codes SET attempts = attempts + 1 WHERE id = ?', [$id]);
    }

    public function markAsUsed(int $id): void
    {
        $this->execute('UPDATE verification_codes SET used_at = NOW() WHERE id = ?', [$id]);
    }

    /** Un código nuevo invalida los anteriores del mismo propósito. */
    public function invalidate(int $userId, string $purpose): void
    {
        $this->execute(
            'UPDATE verification_codes SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL',
            [$userId, $purpose]
        );
    }

    /** Códigos enviados a un celular en las últimas 24 horas. */
    public function countSentLastDay(string $destination): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM verification_codes
             WHERE destination = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)'
        );
        $stmt->execute([$destination]);

        return (int) $stmt->fetchColumn();
    }

    /** Borra los códigos usados o vencidos hace más de un día. Devuelve cuántos. */
    public function deleteStale(): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM verification_codes
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY) AND (used_at IS NOT NULL OR expires_at < NOW())'
        );
        $stmt->execute();

        return $stmt->rowCount();
    }
}

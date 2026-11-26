<?php

declare(strict_types=1);

namespace App\Features\Auth;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;

class AuthUserRepository extends AbstractRepository
{
    private const SELECT = '
        SELECT u.id, u.full_name, u.num_cell, u.email, u.password, u.role, u.status,
               u.document_id, u.document_type_id, dt.name AS document_type_name,
               cr.trainer_id, t.full_name AS trainer_name
        FROM users u
        JOIN document_types dt ON dt.id = u.document_type_id
        LEFT JOIN client_referrals cr ON cr.client_id = u.id
        LEFT JOIN users t ON t.id = cr.trainer_id';

    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    public function findByPhone(string $phone): AuthUser|false
    {
        return $this->fetchOne(self::SELECT . ' WHERE u.num_cell = ?', AuthUser::class, [$phone]);
    }

    public function findById(int $id): AuthUser|false
    {
        return $this->fetchOne(self::SELECT . ' WHERE u.id = ?', AuthUser::class, [$id]);
    }

    /** ¿Otra cuenta usa ya este correo? */
    public function emailTaken(string $email, ?int $exceptUserId = null): bool
    {
        return $this->exists('email', $email, $exceptUserId);
    }

    /** ¿Otra cuenta usa ya este documento? */
    public function documentTaken(string $documentId, ?int $exceptUserId = null): bool
    {
        return $this->exists('document_id', $documentId, $exceptUserId);
    }

    public function documentTypeExists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM document_types WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Crea la cuenta sin verificar (`status = pending`).
     *
     * @param array{full_name:string, document_type_id:int, document_id:string, phone:string, email:string, password_hash:string, role:string} $data
     */
    public function createPending(array $data): int
    {
        $this->execute(
            "INSERT INTO users (document_id, document_type_id, full_name, num_cell, email, password, role, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')",
            [
                $data['document_id'], $data['document_type_id'], $data['full_name'], $data['phone'],
                $data['email'], $data['password_hash'], $data['role'],
            ]
        );

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Reemplaza los datos de una cuenta que nunca verificó su celular.
     *
     * @param array{full_name:string, document_type_id:int, document_id:string, phone:string, email:string, password_hash:string, role:string} $data
     */
    public function replacePending(int $id, array $data): void
    {
        $this->execute(
            "UPDATE users
             SET document_id = ?, document_type_id = ?, full_name = ?, email = ?, password = ?, role = ?
             WHERE id = ? AND status = 'pending'",
            [
                $data['document_id'], $data['document_type_id'], $data['full_name'],
                $data['email'], $data['password_hash'], $data['role'], $id,
            ]
        );
    }

    public function activate(int $id): void
    {
        $this->execute(
            "UPDATE users SET status = 'active', phone_verified_at = NOW() WHERE id = ? AND status = 'pending'",
            [$id]
        );
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->execute('UPDATE users SET password = ? WHERE id = ?', [$hash, $id]);
    }

    public function updateProfile(int $id, string $fullName, string $email): void
    {
        $this->execute('UPDATE users SET full_name = ?, email = ? WHERE id = ?', [$fullName, $email, $id]);
    }

    private function exists(string $column, string $value, ?int $exceptUserId): bool
    {
        // $column solo llega de este repositorio, nunca del usuario.
        $stmt = $this->pdo->prepare("SELECT 1 FROM users WHERE {$column} = ? AND id <> ? LIMIT 1");
        $stmt->execute([$value, $exceptUserId ?? 0]);

        return $stmt->fetchColumn() !== false;
    }
}

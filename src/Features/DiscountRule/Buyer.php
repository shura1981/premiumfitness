<?php

declare(strict_types=1);

namespace App\Features\DiscountRule;

use App\Infrastructure\Database\DB;
use PDO;

/**
 * Quien compra, con lo que decide su precio: el rol y, si es cliente, su entrenador.
 */
final readonly class Buyer
{
    public function __construct(
        public int $userId,
        public string $role,
        /** Entrenador al que se atribuye la compra de un cliente; null si no tiene. */
        public ?int $trainerId,
    ) {}

    /** Lee el rol y el entrenador de la base: no se confía en lo que diga la petición. */
    public static function fromUserId(int $userId): ?self
    {
        $stmt = DB::getInstance()->prepare(
            "SELECT u.role, cr.trainer_id
             FROM users u
             LEFT JOIN client_referrals cr ON cr.client_id = u.id
             WHERE u.id = ? AND u.status = 'active'"
        );
        $stmt->execute([$userId]);

        /** @var array{role:string, trainer_id:int|string|null}|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return new self(
            $userId,
            $row['role'],
            $row['role'] === 'client' && $row['trainer_id'] !== null ? (int) $row['trainer_id'] : null,
        );
    }

    public function isTrainer(): bool
    {
        return $this->role === 'trainer';
    }
}

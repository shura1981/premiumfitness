<?php

declare(strict_types=1);

namespace App\Features\Referral;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;

class ReferralRepository extends AbstractRepository
{
    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    public function clientHasTrainer(int $clientId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM client_referrals WHERE client_id = ?');
        $stmt->execute([$clientId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Entrenador actual del cliente, o null si no tiene. */
    public function trainerOf(int $clientId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT trainer_id FROM client_referrals WHERE client_id = ?');
        $stmt->execute([$clientId]);
        $trainerId = $stmt->fetchColumn();

        return $trainerId === false ? null : (int) $trainerId;
    }

    /** Cambia el entrenador del cliente (D-11). `linked_at` pasa a ser la fecha del cambio. */
    public function changeClientTrainer(int $clientId, int $trainerId): void
    {
        $this->pdo->prepare("UPDATE client_referrals SET trainer_id = ?, source = 'link', linked_at = NOW() WHERE client_id = ?")
            ->execute([$trainerId, $clientId]);
    }

    /**
     * Asocia el cliente al entrenador. `client_id` es único: si ya tenía entrenador no cambia nada.
     * Devuelve true si creó el vínculo.
     */
    public function linkClient(int $clientId, int $trainerId, string $source): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO client_referrals (client_id, trainer_id, source) VALUES (?, ?, ?)'
        );
        $stmt->execute([$clientId, $trainerId, $source]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Registra que A refirió a B, con los puntos que valía el referido ese día.
     * `referred_id` es único: un entrenador solo tiene un referente. Devuelve true si lo creó.
     */
    public function linkTrainer(int $referrerId, int $referredId, int $referralPoints): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO trainer_referrals (referrer_id, referred_id, referral_points) VALUES (?, ?, ?)'
        );
        $stmt->execute([$referrerId, $referredId, $referralPoints]);

        return $stmt->rowCount() === 1;
    }
}

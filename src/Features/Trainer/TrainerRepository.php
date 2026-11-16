<?php

declare(strict_types=1);

namespace App\Features\Trainer;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use PDO;

class TrainerRepository extends AbstractRepository
{
    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    public function exists(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM trainers WHERE user_id = ?');
        $stmt->execute([$userId]);

        return $stmt->fetchColumn() !== false;
    }

    public function couponExists(string $couponCode): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM trainers WHERE coupon_code = ?');
        $stmt->execute([$couponCode]);

        return $stmt->fetchColumn() !== false;
    }

    public function couponTakenByOther(string $couponCode, ?int $exceptUserId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM trainers WHERE coupon_code = ? AND user_id <> ?');
        $stmt->execute([$couponCode, $exceptUserId ?? 0]);

        return $stmt->fetchColumn() !== false;
    }

    public function updateCoupon(int $userId, string $couponCode): void
    {
        $this->execute('UPDATE trainers SET coupon_code = ? WHERE user_id = ?', [$couponCode, $userId]);
    }

    public function create(int $userId, string $couponCode): void
    {
        $this->execute('INSERT INTO trainers (user_id, coupon_code) VALUES (?, ?)', [$userId, $couponCode]);
    }

    /** Cupón del entrenador, para dejarlo en el pedido de su cliente. */
    public function findCoupon(int $userId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT coupon_code FROM trainers WHERE user_id = ?');
        $stmt->execute([$userId]);
        $coupon = $stmt->fetchColumn();

        return $coupon === false ? null : (string) $coupon;
    }

    /** Solo para una cuenta que nunca se verificó y cambia de rol al registrarse de nuevo. */
    public function delete(int $userId): void
    {
        $this->execute('DELETE FROM trainers WHERE user_id = ?', [$userId]);
    }

    /**
     * Entrenador activo por id: el único que puede recibir clientes o referidos.
     *
     * @return array{id:int, full_name:string, coupon_code:string}|null
     */
    public function findActiveById(int $userId): ?array
    {
        return $this->findActive('t.user_id = ?', $userId);
    }

    /** @return array{id:int, full_name:string, coupon_code:string}|null */
    public function findActiveByCoupon(string $couponCode): ?array
    {
        return $this->findActive('t.coupon_code = ?', $couponCode);
    }

    /** @return array{id:int, full_name:string, coupon_code:string}|null */
    private function findActive(string $condition, int|string $value): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.full_name, t.coupon_code
             FROM trainers t
             JOIN users u ON u.id = t.user_id
             WHERE {$condition} AND u.role = 'trainer' AND u.status = 'active'"
        );
        $stmt->execute([$value]);

        /** @var array{id:int|string, full_name:string, coupon_code:string}|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : ['id' => (int) $row['id'], 'full_name' => $row['full_name'], 'coupon_code' => $row['coupon_code']];
    }
}

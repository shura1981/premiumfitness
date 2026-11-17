<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use PDO;

/**
 * Intentos de pago (spec backend/03, P-24). Cada «Pagar» crea uno y su id, con el prefijo `pf-`,
 * es la referencia que recibe la pasarela: `pf-1`, `pf-2`… Un pedido puede tener varios intentos.
 *
 * El prefijo separa los pagos de PremiumFitness de los del sitio web de Nutramerican, que comparte
 * la cuenta de Bold y usa referencias numéricas: un webhook ignora la referencia que no es suya.
 */
class PaymentAttemptRepository extends AbstractRepository implements PaymentReferenceResolver
{
    public const string PREFIX = 'pf-';

    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    /** Crea el intento y devuelve su referencia. */
    public function create(int $orderId, string $gateway, float $amount): string
    {
        $this->execute(
            'INSERT INTO payment_attempts (order_id, gateway, amount) VALUES (?, ?, ?)',
            [$orderId, $gateway, $amount]
        );

        return self::PREFIX . $this->pdo->lastInsertId();
    }

    /** Pedido del intento con esa referencia; null si la referencia no es de PremiumFitness o no existe. */
    public function orderIdFor(string $reference): ?int
    {
        if (preg_match('/\A' . preg_quote(self::PREFIX, '/') . '([1-9][0-9]*)\z/', trim($reference), $match) !== 1) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT order_id FROM payment_attempts WHERE id = ?');
        $stmt->execute([(int) $match[1]]);
        $orderId = $stmt->fetchColumn();

        return $orderId === false ? null : (int) $orderId;
    }

    /**
     * Referencias de los intentos de un pedido, la más reciente primero.
     *
     * @return list<string>
     */
    public function referencesFor(int $orderId): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM payment_attempts WHERE order_id = ? ORDER BY id DESC');
        $stmt->execute([$orderId]);

        return array_map(static fn (mixed $id): string => self::PREFIX . $id, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

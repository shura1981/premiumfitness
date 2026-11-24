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
        $id = self::idOf($reference);

        if ($id === null) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT order_id FROM payment_attempts WHERE id = ?');
        $stmt->execute([$id]);
        $orderId = $stmt->fetchColumn();

        return $orderId === false ? null : (int) $orderId;
    }

    /**
     * Guarda en el intento lo que reportó la pasarela en su webhook. Solo informa: el estado del pedido
     * lo cambia el despachador del webhook.
     */
    public function recordResult(string $reference, string $status, string $transactionId, ?string $paymentMethod, float $paidAmount): void
    {
        $id = self::idOf($reference);

        if ($id === null) {
            return;
        }

        $this->execute(
            'UPDATE payment_attempts
             SET status = ?, transaction_id = ?, payment_method = ?, paid_amount = ?, notified_at = NOW()
             WHERE id = ?',
            [$status, $transactionId !== '' ? $transactionId : null, $paymentMethod, $paidAmount, $id]
        );
    }

    /**
     * El intento con esa referencia, si es de un pedido de ese usuario.
     *
     * @return array<string, mixed>|null
     */
    public function findForUser(string $reference, int $userId): ?array
    {
        $id = self::idOf($reference);

        if ($id === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT pa.id, pa.order_id, pa.gateway, pa.amount, pa.status, pa.transaction_id, pa.payment_method,
                    pa.paid_amount, pa.notified_at, pa.created_at,
                    o.amount AS order_amount, o.approved_at, o.sap_order_code, o.tracking_number, s.value AS order_status
             FROM payment_attempts pa
             JOIN orders o ON o.order_id = pa.order_id
             LEFT JOIN status_orders s ON s.status_id = o.status_id
             WHERE pa.id = ? AND o.user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        /** @var array<string, mixed>|false $row */
        return $row === false ? null : $row;
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

    /** `pf-41` → 41; null si no es una referencia de PremiumFitness. */
    private static function idOf(string $reference): ?int
    {
        return preg_match('/\A' . preg_quote(self::PREFIX, '/') . '([1-9][0-9]*)\z/', trim($reference), $match) === 1
            ? (int) $match[1]
            : null;
    }
}

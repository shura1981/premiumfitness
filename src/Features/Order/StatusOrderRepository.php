<?php

declare(strict_types=1);

namespace App\Features\Order;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use PDO;

/**
 * Repositorio para el catálogo `status_orders`.
 *
 * Reglas:
 *  - `value` es UNIQUE. El método `findOrCreateIdByValue()` garantiza obtención idempotente
 *    del id aunque el valor no exista aún (útil para el webhook de pasarela).
 */
class StatusOrderRepository extends AbstractRepository
{
    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    /** @return list<StatusOrderModel> */
    public function findAll(): array
    {
        return $this->fetchAll(
            'SELECT status_id, value FROM status_orders ORDER BY status_id',
            StatusOrderModel::class
        );
    }

    public function findById(int $statusId): StatusOrderModel|false
    {
        return $this->fetchOne(
            'SELECT status_id, value FROM status_orders WHERE status_id = ?',
            StatusOrderModel::class,
            [$statusId]
        );
    }

    public function findByValue(string $value): StatusOrderModel|false
    {
        return $this->fetchOne(
            'SELECT status_id, value FROM status_orders WHERE value = ?',
            StatusOrderModel::class,
            [$value]
        );
    }

    /**
     * Busca el id por value; si no existe, lo crea y lo devuelve.
     * Uso principal: webhook de pasarela que recibe estados arbitrarios.
     */
    public function findOrCreateIdByValue(string $value): int
    {
        $existing = $this->findByValue($value);
        if ($existing !== false) {
            return $existing->status_id;
        }

        $this->execute(
            'INSERT IGNORE INTO status_orders (value) VALUES (?)',
            [$value]
        );

        // Race-safe: re-leer por si otro proceso insertó primero.
        $again = $this->findByValue($value);
        if ($again !== false) {
            return $again->status_id;
        }

        return (int) $this->pdo->lastInsertId();
    }
}

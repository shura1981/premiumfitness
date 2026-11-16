<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;

/**
 * Base para todos los repositorios.
 *
 * Recibe PDO por constructor (inyección de dependencias):
 *   - testeable: en tests se pasa un PDO en memoria (SQLite)
 *   - sin acoplamiento estático a DB::getInstance()
 *
 * Uso:
 *   class MyRepository extends AbstractRepository
 *   {
 *       public function __construct() { parent::__construct(DB::getInstance()); }
 *   }
 */
abstract class AbstractRepository
{
    public function __construct(protected readonly PDO $pdo) {}

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param list<mixed>     $params
     * @return list<T>
     */
    protected function fetchAll(string $sql, string $class, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_CLASS, $class);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param list<mixed>     $params
     * @return T|false
     */
    protected function fetchOne(string $sql, string $class, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $stmt->setFetchMode(PDO::FETCH_CLASS, $class);

        return $stmt->fetch();
    }

    /**
     * @param list<mixed> $params
     */
    protected function execute(string $sql, array $params = []): bool
    {
        return $this->pdo->prepare($sql)->execute($params);
    }
}

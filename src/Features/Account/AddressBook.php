<?php

declare(strict_types=1);

namespace App\Features\Account;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use PDO;
use Throwable;

/**
 * Direcciones de un usuario. Toda consulta filtra por el dueño: una dirección ajena «no existe».
 */
class AddressBook extends AbstractRepository
{
    private const string SELECT = '
        SELECT a.id, a.address_name AS name, a.address, a.city_id, c.name AS city_name,
               c.department AS department_name, alm.locality_id, l.name AS locality_name, aum.is_main
        FROM address_user_map aum
        JOIN addresses a ON a.id = aum.address_id
        JOIN cities c ON c.id = a.city_id
        LEFT JOIN address_locality_map alm ON alm.address_id = a.id
        LEFT JOIN localities l ON l.id = alm.locality_id
        WHERE aum.user_id = ?';

    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    /** @return list<array<string, mixed>> Contrato `Address`, la principal primero. */
    public function all(int $userId): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' ORDER BY aum.is_main DESC, a.id DESC');
        $stmt->execute([$userId]);

        return array_map(self::present(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function find(int $userId, int $addressId): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' AND a.id = ?');
        $stmt->execute([$userId, $addressId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::present($row);
    }

    public function cityExists(int $cityId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM cities WHERE id = ?');
        $stmt->execute([$cityId]);

        return $stmt->fetchColumn() !== false;
    }

    public function localityExists(int $localityId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM localities WHERE id = ?');
        $stmt->execute([$localityId]);

        return $stmt->fetchColumn() !== false;
    }

    /** La primera dirección de un usuario queda como principal. */
    public function create(int $userId, string $name, string $address, int $cityId, ?int $localityId): int
    {
        return $this->transaction(function () use ($userId, $name, $address, $cityId, $localityId): int {
            $this->execute('INSERT INTO addresses (city_id, address, address_name) VALUES (?, ?, ?)', [$cityId, $address, $name]);
            $addressId = (int) $this->pdo->lastInsertId();

            $count = $this->pdo->prepare('SELECT COUNT(*) FROM address_user_map WHERE user_id = ?');
            $count->execute([$userId]);

            $this->execute(
                'INSERT INTO address_user_map (user_id, address_id, is_main) VALUES (?, ?, ?)',
                [$userId, $addressId, (int) $count->fetchColumn() === 0 ? 1 : 0]
            );
            $this->saveLocality($addressId, $localityId);

            return $addressId;
        });
    }

    public function update(int $addressId, string $name, string $address, int $cityId, ?int $localityId): void
    {
        $this->transaction(function () use ($addressId, $name, $address, $cityId, $localityId): void {
            $this->execute('UPDATE addresses SET city_id = ?, address = ?, address_name = ? WHERE id = ?', [$cityId, $address, $name, $addressId]);
            $this->saveLocality($addressId, $localityId);
        });
    }

    /**
     * Quita la dirección del usuario. Si algún pedido la usa se conserva la fila, para no perder
     * a dónde se envió; si no, se borra. Si era la principal, pasa a serlo la más reciente.
     */
    public function remove(int $userId, int $addressId): void
    {
        $this->transaction(function () use ($userId, $addressId): void {
            $this->execute('DELETE FROM address_user_map WHERE user_id = ? AND address_id = ?', [$userId, $addressId]);

            $used = $this->pdo->prepare('SELECT 1 FROM orders WHERE address_id = ? LIMIT 1');
            $used->execute([$addressId]);

            if ($used->fetchColumn() === false) {
                $this->execute('DELETE FROM address_locality_map WHERE address_id = ?', [$addressId]);
                $this->execute('DELETE FROM addresses WHERE id = ?', [$addressId]);
            }

            $this->execute(
                'UPDATE address_user_map SET is_main = 1
                 WHERE user_id = ? AND NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM address_user_map WHERE user_id = ? AND is_main = 1) m)
                 ORDER BY address_id DESC LIMIT 1',
                [$userId, $userId]
            );
        });
    }

    public function setMain(int $userId, int $addressId): void
    {
        $this->execute('UPDATE address_user_map SET is_main = (address_id = ?) WHERE user_id = ?', [$addressId, $userId]);
    }

    private function saveLocality(int $addressId, ?int $localityId): void
    {
        $this->execute('DELETE FROM address_locality_map WHERE address_id = ?', [$addressId]);

        if ($localityId !== null) {
            $this->execute('INSERT INTO address_locality_map (address_id, locality_id) VALUES (?, ?)', [$addressId, $localityId]);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'name'            => (string) $row['name'],
            'address'         => (string) $row['address'],
            'city_id'         => (int) $row['city_id'],
            'city_name'       => (string) $row['city_name'],
            'department_name' => (string) $row['department_name'],
            'locality_id'     => $row['locality_id'] === null ? null : (int) $row['locality_id'],
            'locality_name'   => $row['locality_name'] === null ? null : (string) $row['locality_name'],
            'is_main'         => (int) $row['is_main'] === 1,
        ];
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}

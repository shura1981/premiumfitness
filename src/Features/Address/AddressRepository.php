<?php

declare(strict_types=1);

namespace App\Features\Address;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;

class AddressRepository extends AbstractRepository
{
    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    private const string SELECT_BASE = <<<'SQL'
        SELECT a.id, a.city_id, a.address, a.address_name, a.created_at, a.updated_at,
               c.name AS city_name, c.city_dept, c.department,
               alm.locality_id, l.name AS locality_name
        FROM addresses a
        JOIN cities c ON a.city_id = c.id
        LEFT JOIN address_locality_map alm ON alm.address_id = a.id
        LEFT JOIN localities l ON l.id = alm.locality_id
        SQL;

    /** @return list<AddressModel> */
    public function findAll(): array
    {
        return $this->fetchAll(
            self::SELECT_BASE . ' ORDER BY a.id',
            AddressModel::class
        );
    }

    public function findById(int $id): AddressModel|false
    {
        return $this->fetchOne(
            self::SELECT_BASE . ' WHERE a.id = ?',
            AddressModel::class,
            [$id]
        );
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $this->execute(
            'INSERT INTO addresses (city_id, address, address_name) VALUES (?, ?, ?)',
            [$data['city_id'], $data['address'], $data['address_name']]
        );

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        return $this->execute(
            'UPDATE addresses SET city_id = ?, address = ?, address_name = ? WHERE id = ?',
            [$data['city_id'], $data['address'], $data['address_name'], $id]
        );
    }

    public function delete(int $id): bool
    {
        return $this->execute('DELETE FROM addresses WHERE id = ?', [$id]);
    }

    public function setLocality(int $addressId, int $localityId): bool
    {
        return $this->execute(
            'INSERT INTO address_locality_map (address_id, locality_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locality_id = VALUES(locality_id)',
            [$addressId, $localityId]
        );
    }

    public function removeLocality(int $addressId): bool
    {
        return $this->execute(
            'DELETE FROM address_locality_map WHERE address_id = ?',
            [$addressId]
        );
    }
}

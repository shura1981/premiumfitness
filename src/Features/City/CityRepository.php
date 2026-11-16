<?php

declare(strict_types=1);

namespace App\Features\City;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;

class CityRepository extends AbstractRepository
{
    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    /** @return list<CityModel> */
    public function findAll(): array
    {
        return $this->fetchAll(
            'SELECT id, name, city_dept, department, dan_code FROM cities ORDER BY department, name',
            CityModel::class
        );
    }

    public function findById(int $id): CityModel|false
    {
        return $this->fetchOne(
            'SELECT id, name, city_dept, department, dan_code FROM cities WHERE id = ?',
            CityModel::class,
            [$id]
        );
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $this->execute(
            'INSERT INTO cities (id, name, city_dept, department, dan_code) VALUES (?, ?, ?, ?, ?)',
            [$data['id'], $data['name'], $data['city_dept'], $data['department'], $data['dan_code']]
        );

        return (int) $data['id'];
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        return $this->execute(
            'UPDATE cities SET name = ?, city_dept = ?, department = ?, dan_code = ? WHERE id = ?',
            [$data['name'], $data['city_dept'], $data['department'], $data['dan_code'], $id]
        );
    }

    public function delete(int $id): bool
    {
        return $this->execute('DELETE FROM cities WHERE id = ?', [$id]);
    }

    /**
     * Busca ciudades cuyo nombre contenga el término dado (case-insensitive).
     *
     * @return list<CityModel>
     */
    public function findByQuery(string $query): array
    {
        $term = trim($query);

        return $this->fetchAll(
            'SELECT id, name, city_dept, department, dan_code FROM cities WHERE UPPER(name) LIKE CONCAT(\'%\', UPPER(?), \'%\') ORDER BY department, name LIMIT 10',
            CityModel::class,
            [$term]
        );
    }

    /**
     * Busca la primera ciudad cuyo nombre coincida (case-insensitive).
     * Intenta match exacto primero, luego LIKE.
     */
    public function findByName(string $name): CityModel|false
    {
        $trimmed = trim($name);

        $exact = $this->fetchOne(
            'SELECT id, name, city_dept, department, dan_code FROM cities WHERE UPPER(name) = UPPER(?)',
            CityModel::class,
            [$trimmed]
        );

        if ($exact !== false) {
            return $exact;
        }

        return $this->fetchOne(
            'SELECT id, name, city_dept, department, dan_code FROM cities WHERE UPPER(name) LIKE CONCAT(\'%\', UPPER(?), \'%\') LIMIT 1',
            CityModel::class,
            [$trimmed]
        );
    }
}

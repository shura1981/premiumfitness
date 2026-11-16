<?php

declare(strict_types=1);

namespace App\Features\Locality;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;

class LocalityRepository extends AbstractRepository
{
    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    /** @return list<LocalityModel> */
    public function findAll(): array
    {
        return $this->fetchAll(
            'SELECT id, name FROM localities ORDER BY id',
            LocalityModel::class
        );
    }

    public function findById(int $id): LocalityModel|false
    {
        return $this->fetchOne(
            'SELECT id, name FROM localities WHERE id = ?',
            LocalityModel::class,
            [$id]
        );
    }
}

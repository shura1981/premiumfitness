<?php

declare(strict_types=1);

namespace App\Features\Admin;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use App\Infrastructure\Database\IntranetTables;

class EmpleadoRepository extends AbstractRepository
{
    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    public function findByEmail(string $email): EmpleadoModel|false
    {
        return $this->fetchOne(
            'SELECT id, id_usuario, email, COALESCE(pass, \'\') AS pass, usuario, departamento, foto, celular, sede, cargo, F
             FROM ' . IntranetTables::fromEnv()->employees . '
             WHERE email = :email',
            EmpleadoModel::class,
            ['email' => $email],
        );
    }

    public function findById(int $id): EmpleadoModel|false
    {
        return $this->fetchOne(
            'SELECT id, id_usuario, email, COALESCE(pass, \'\') AS pass, usuario, departamento, foto, celular, sede, cargo, F
             FROM ' . IntranetTables::fromEnv()->employees . '
             WHERE id_usuario = :id',
            EmpleadoModel::class,
            ['id' => $id],
        );
    }

    /**
     * @return list<EmpleadoModel>
     */
    public function findAll(): array
    {
        return $this->fetchAll(
            'SELECT id, id_usuario, email, COALESCE(pass, \'\') AS pass, usuario, departamento, foto, celular, sede, cargo, F
             FROM ' . IntranetTables::fromEnv()->employees . '
             WHERE activo = 1 AND deleteSoft = 0',
            EmpleadoModel::class,
        );
    }
}

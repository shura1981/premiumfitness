<?php

declare(strict_types=1);

namespace App\Features\DocumentType;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;

class DocumentTypeRepository extends AbstractRepository
{
    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    /** @return list<DocumentTypeModel> */
    public function findAll(): array
    {
        return $this->fetchAll(
            'SELECT id, name FROM document_types ORDER BY name',
            DocumentTypeModel::class
        );
    }

    public function findById(int $id): DocumentTypeModel|false
    {
        return $this->fetchOne(
            'SELECT id, name FROM document_types WHERE id = ?',
            DocumentTypeModel::class,
            [$id]
        );
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $this->execute(
            'INSERT INTO document_types (name) VALUES (?)',
            [$data['name']]
        );

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        return $this->execute(
            'UPDATE document_types SET name = ? WHERE id = ?',
            [$data['name'], $id]
        );
    }

    public function delete(int $id): bool
    {
        return $this->execute('DELETE FROM document_types WHERE id = ?', [$id]);
    }
}

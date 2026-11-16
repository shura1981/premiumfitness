<?php

declare(strict_types=1);

namespace App\Features\DiscountRule;

use App\Infrastructure\Database\AbstractRepository;
use App\Infrastructure\Database\DB;
use PDO;

class DiscountRuleRepository extends AbstractRepository
{
    public function __construct()
    {
        parent::__construct(DB::getInstance());
    }

    /**
     * La regla vigente de cada producto: activa y dentro de sus fechas. Si hay varias, la más reciente.
     *
     * @param list<int> $productSkus
     * @return array<int, DiscountRule> Indexado por código de producto.
     */
    public function activeFor(array $productSkus): array
    {
        $productSkus = array_values(array_unique($productSkus));

        if ($productSkus === []) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($productSkus), '?'));
        $stmt  = $this->pdo->prepare(
            "SELECT product_sku, client_discount_percentage, trainer_discount_percentage, commission_percentage
             FROM product_discount_rules
             WHERE product_sku IN ({$marks})
               AND is_active = 1 AND valid_from <= NOW() AND (valid_to IS NULL OR valid_to > NOW())
             ORDER BY valid_from, id"
        );
        $stmt->execute($productSkus);

        $rules = [];
        // En orden ascendente: la más reciente pisa a las anteriores.
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rules[(int) $row['product_sku']] = new DiscountRule(
                (int) $row['product_sku'],
                self::percentage($row['client_discount_percentage']),
                self::percentage($row['trainer_discount_percentage']),
                self::percentage($row['commission_percentage']),
            );
        }

        return $rules;
    }

    private static function percentage(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}

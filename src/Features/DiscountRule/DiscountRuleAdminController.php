<?php

declare(strict_types=1);

namespace App\Features\DiscountRule;

use App\Features\Admin\AdminRequest;
use App\Features\Audit\AuditLog;
use App\Features\Product\IntranetProductCatalog;
use App\Features\Product\ProductCatalog;
use App\Infrastructure\Config\BusinessConfig;
use App\Infrastructure\Database\DB;
use App\Infrastructure\Http\JsonResponder;
use App\Shared\Exceptions\ApiException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Estrategias por producto en el admin (spec negocio/01). Una estrategia cambia, para un producto
 * y entre dos fechas, el descuento del cliente, el del entrenador o la comisión; lo que deja vacío
 * usa el valor por defecto. Si un producto tiene varias vigentes, aplica la de `valid_from` más reciente.
 *
 *   GET    /api/admin/discount-rules?active=1|0
 *   POST   /api/admin/discount-rules
 *   PUT    /api/admin/discount-rules/{id}
 *   DELETE /api/admin/discount-rules/{id}   → la desactiva; no borra el histórico
 *   GET    /api/admin/products?search=      → productos del catálogo, para elegir
 *   GET    /api/admin/settings/business     → valores por defecto vigentes
 */
class DiscountRuleAdminController
{
    use JsonResponder;

    private readonly PDO $pdo;
    private readonly ProductCatalog $catalog;

    public function __construct()
    {
        $this->pdo     = DB::getInstance();
        $this->catalog = IntranetProductCatalog::fromEnv();
    }

    public function list(Request $request, Response $response): Response
    {
        $active = $request->getQueryParams()['active'] ?? null;
        $where  = in_array($active, ['0', '1'], true) ? ' WHERE is_active = ' . (int) $active : '';
        $rows   = $this->pdo->query('SELECT * FROM product_discount_rules' . $where . ' ORDER BY is_active DESC, valid_from DESC, id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
        $names  = $this->productNames(array_map(static fn (array $row): int => (int) $row['product_sku'], $rows));

        return $this->json($response, ['data' => array_map(static fn (array $row): array => self::present($row, $names), $rows)]);
    }

    public function create(Request $request, Response $response): Response
    {
        $input = $this->input($request);

        $this->pdo->prepare(
            'INSERT INTO product_discount_rules
                (product_sku, client_discount_percentage, trainer_discount_percentage, commission_percentage, valid_from, valid_to, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        )->execute(array_values($input));
        $id = (int) $this->pdo->lastInsertId();

        AuditLog::byAgent(AdminRequest::agentId($request), 'discount_rule.create', 'product_discount_rule', $id, $input);

        return $this->json($response, $this->show($id), 201);
    }

    public function update(Request $request, Response $response, int $id): Response
    {
        $before = $this->find($id);

        if ((int) $before['is_active'] !== 1) {
            throw ApiException::conflict('rule_inactive', 'Esta estrategia está desactivada. Crea una nueva.');
        }

        $input = $this->input($request);

        if ($input['product_sku'] !== (int) $before['product_sku']) {
            throw ApiException::invalid('Revisa los datos e inténtalo de nuevo.', errors: ['product_sku' => 'No se puede cambiar el producto de una estrategia.']);
        }

        $this->pdo->prepare(
            'UPDATE product_discount_rules
             SET client_discount_percentage = ?, trainer_discount_percentage = ?, commission_percentage = ?, valid_from = ?, valid_to = ?
             WHERE id = ?'
        )->execute([
            $input['client_discount_percentage'], $input['trainer_discount_percentage'], $input['commission_percentage'],
            $input['valid_from'], $input['valid_to'], $id,
        ]);

        AuditLog::byAgent(AdminRequest::agentId($request), 'discount_rule.update', 'product_discount_rule', $id, ['after' => $input]);

        return $this->json($response, $this->show($id));
    }

    public function deactivate(Request $request, Response $response, int $id): Response
    {
        $this->find($id);
        $this->pdo->prepare('UPDATE product_discount_rules SET is_active = 0 WHERE id = ?')->execute([$id]);
        AuditLog::byAgent(AdminRequest::agentId($request), 'discount_rule.deactivate', 'product_discount_rule', $id);

        return $this->noContent($response);
    }

    public function products(Request $request, Response $response): Response
    {
        $search = mb_substr(trim((string) ($request->getQueryParams()['search'] ?? '')), 0, 60);

        return $this->json($response, ['data' => array_map(static fn ($product): array => [
            'code'         => $product->code,
            'name'         => $product->name,
            'presentation' => $product->presentation,
            'price'        => $product->price,
        ], $this->catalog->search($search))]);
    }

    public function businessSettings(Request $request, Response $response): Response
    {
        $business = BusinessConfig::fromEnv();

        return $this->json($response, [
            'client_discount_percentage'        => $business->clientDiscountPercentage,
            'trainer_discount_percentage'       => $business->trainerDiscountPercentage,
            'commission_percentage'             => $business->trainerCommissionPercentage,
            'client_purchase_points_percentage' => $business->clientPurchasePointsPercentage,
        ]);
    }

    /**
     * @return array{product_sku:int, client_discount_percentage:?float, trainer_discount_percentage:?float, commission_percentage:?float, valid_from:string, valid_to:?string}
     * @throws ApiException
     */
    private function input(Request $request): array
    {
        $body   = (array) ($request->getParsedBody() ?? []);
        $errors = [];
        $values = [];

        foreach (['client_discount_percentage', 'trainer_discount_percentage', 'commission_percentage'] as $field) {
            $raw = $body[$field] ?? null;

            if ($raw === null || $raw === '') {
                $values[$field] = null;
                continue;
            }

            if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 100) {
                $errors[$field] = 'Escribe un porcentaje entre 0 y 100.';
                continue;
            }

            $values[$field] = round((float) $raw, 2);
        }

        if ($errors === [] && array_filter($values, static fn (?float $value): bool => $value !== null) === []) {
            $errors['client_discount_percentage'] = 'Indica al menos un porcentaje.';
        }

        $sku = filter_var($body['product_sku'] ?? null, FILTER_VALIDATE_INT);
        if ($sku === false || $sku <= 0 || $this->catalog->findByCodes([$sku]) === []) {
            $errors['product_sku'] = 'Elige un producto del catálogo.';
        }

        $from = strtotime((string) ($body['valid_from'] ?? ''));
        $to   = ($body['valid_to'] ?? null) === null || $body['valid_to'] === '' ? null : strtotime((string) $body['valid_to']);

        if ($from === false) {
            $errors['valid_from'] = 'Indica desde cuándo aplica.';
        }

        if ($to === false || ($to !== null && $from !== false && $to <= $from)) {
            $errors['valid_to'] = 'La fecha final debe ser posterior a la inicial.';
        }

        if ($errors !== []) {
            throw ApiException::invalid('Revisa los datos e inténtalo de nuevo.', errors: $errors);
        }

        return [
            'product_sku'                 => (int) $sku,
            'client_discount_percentage'  => $values['client_discount_percentage'],
            'trainer_discount_percentage' => $values['trainer_discount_percentage'],
            'commission_percentage'       => $values['commission_percentage'],
            'valid_from'                  => date('Y-m-d H:i:s', (int) $from),
            'valid_to'                    => $to === null ? null : date('Y-m-d H:i:s', (int) $to),
        ];
    }

    /** @return array<string, mixed> */
    private function find(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_discount_rules WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? throw ApiException::notFound('No encontramos esa estrategia.') : $row;
    }

    /** @return array<string, mixed> */
    private function show(int $id): array
    {
        $row = $this->find($id);

        return self::present($row, $this->productNames([(int) $row['product_sku']]));
    }

    /**
     * @param list<int> $skus
     * @return array<int, string>
     */
    private function productNames(array $skus): array
    {
        $names = [];
        foreach ($this->catalog->findByCodes($skus) as $code => $product) {
            $names[$code] = $product->name;
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string>   $names
     * @return array<string, mixed> Contrato `DiscountRule`.
     */
    private static function present(array $row, array $names): array
    {
        $percentage = static fn (mixed $value): ?float => $value === null ? null : (float) $value;

        return [
            'id'                          => (int) $row['id'],
            'product_sku'                 => (int) $row['product_sku'],
            'product_name'                => $names[(int) $row['product_sku']] ?? 'Producto ' . $row['product_sku'] . ' (ya no se vende)',
            'client_discount_percentage'  => $percentage($row['client_discount_percentage']),
            'trainer_discount_percentage' => $percentage($row['trainer_discount_percentage']),
            'commission_percentage'       => $percentage($row['commission_percentage']),
            'valid_from'                  => date(DATE_ATOM, (int) strtotime((string) $row['valid_from'])),
            'valid_to'                    => $row['valid_to'] === null ? null : date(DATE_ATOM, (int) strtotime((string) $row['valid_to'])),
            'is_active'                   => (int) $row['is_active'] === 1,
            'created_at'                  => date(DATE_ATOM, (int) strtotime((string) $row['created_at'])),
        ];
    }
}

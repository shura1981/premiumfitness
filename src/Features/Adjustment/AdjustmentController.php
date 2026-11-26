<?php

declare(strict_types=1);

namespace App\Features\Adjustment;

use App\Features\Admin\AdminRequest;
use App\Infrastructure\Http\JsonResponder;
use App\Shared\Exceptions\ApiException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 *   GET  /api/admin/orders/{id}/adjustments           → la reversión del pedido, si tiene, y lo que revertiría
 *   POST /api/admin/orders/{id}/adjustments/preview   → lo que revertiría reversarlo, sin aplicarlo
 *   POST /api/admin/orders/{id}/adjustments           → reversa el pedido (`type: cancellation`, «Reversar»)
 */
class AdjustmentController
{
    use JsonResponder;

    private readonly AdjustmentService $adjustments;

    public function __construct()
    {
        $this->adjustments = new AdjustmentService();
    }

    public function list(Request $request, Response $response, int $id): Response
    {
        $existing = $this->adjustments->listFor($id);

        try {
            $preview = $this->adjustments->preview($id, 'cancellation');
        } catch (ApiException $e) {
            // Un pedido sin pagar no tiene nada que revertir.
            $preview = ['commission_amount' => 0.0, 'points' => 0, 'trainer_name' => null];
        }

        return $this->json($response, ['data' => $existing, 'preview' => $preview, 'can_adjust' => $existing === [] && !isset($e)]);
    }

    public function preview(Request $request, Response $response, int $id): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);

        return $this->json($response, $this->adjustments->preview($id, (string) ($body['type'] ?? '')));
    }

    public function create(Request $request, Response $response, int $id): Response
    {
        $body   = (array) ($request->getParsedBody() ?? []);
        $reason = trim((string) ($body['reason'] ?? ''));

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ApiException::invalid('Revisa los datos e inténtalo de nuevo.', errors: ['reason' => 'Escribe el motivo (entre 5 y 500 caracteres).']);
        }

        $adjustmentId = $this->adjustments->create($id, AdminRequest::agentId($request), (string) ($body['type'] ?? ''), $reason);

        foreach ($this->adjustments->listFor($id) as $adjustment) {
            if ($adjustment['id'] === $adjustmentId) {
                return $this->json($response, $adjustment, 201);
            }
        }

        throw ApiException::notFound();
    }
}

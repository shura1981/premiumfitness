<?php

declare(strict_types=1);

namespace App\Features\Order;

use App\Features\PaymentGateway\Normalizer\PaymentStatus;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class OrderController
{
    private const array PAYMENT_GATEWAYS = ['bold', 'addi', 'sistecredito'];

    private OrderRepository $repository;
    private StatusOrderRepository $statusRepository;

    public function __construct()
    {
        $this->repository       = new OrderRepository();
        $this->statusRepository = new StatusOrderRepository();
    }

    public function list(Request $request, Response $response): Response
    {
        [$page, $perPage] = $this->pagination($request);
        $filters = $this->extractFilters($request);
        $result = $this->repository->findAll($page, $perPage, $filters);

        return $this->json($response, $result);
    }

    public function getById(Request $request, Response $response, int $id): Response
    {
        $order = $this->repository->findById($id);

        if ($order === false) {
            return $this->jsonError($response, 'Order not found', 404);
        }

        return $this->json($response, $order);
    }

    public function listByUser(Request $request, Response $response, int $userId): Response
    {
        [$page, $perPage] = $this->pagination($request);
        $filters = $this->extractFilters($request);
        $result = $this->repository->findAllByUser($userId, $page, $perPage, $filters);

        return $this->json($response, $result);
    }

    /**
     * GET /api/users/{userId}/orders/{orderId}
     * Detalle completo de una orden (con items) para un usuario específico.
     */
    public function getDetailByUser(Request $request, Response $response, int $userId, int $orderId): Response
    {
        $order = $this->repository->findByIdAndUser($orderId, $userId);

        if ($order === false) {
            return $this->jsonError($response, 'Order not found', 404);
        }

        return $this->json($response, $order);
    }

    /**
     * POST /api/orders
     * Body: { user_id, amount, payment_gateway, agent_id?, items: [{ product_sku, product_full_price?, sap_code?, quantity, unit_price, discount_percentage?, line_total }] }
     */
    public function create(Request $request, Response $response): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $paymentGateway = $this->resolvePaymentGateway($body);
        if ($paymentGateway === null) {
            return $this->jsonError($response, 'payment_gateway must be one of: bold, addi, sistecredito.', 422);
        }

        $items = $this->normalizeItems($body['items'] ?? null);
        if ($items === null) {
            return $this->jsonError($response, 'items must be a non-empty array with product_sku, quantity, unit_price and line_total.', 422);
        }

        $statusId = null;
        if (isset($body['status_value']) && is_string($body['status_value']) && $body['status_value'] !== '') {
            $statusId = $this->statusRepository->findOrCreateIdByValue($body['status_value']);
        }

        $orderId = $this->repository->create([
            'user_id'     => (int) $body['user_id'],
            'amount'      => $body['amount'],
            'payment_gateway' => $paymentGateway,
            'agent_id'    => isset($body['agent_id']) ? (int) $body['agent_id'] : null,
            'status_id'   => $statusId,
            'address_id'  => isset($body['address_id']) ? (int) $body['address_id'] : null,
            'order_notes' => isset($body['order_notes']) && is_string($body['order_notes']) ? $body['order_notes'] : null,
            'items'       => $items,
        ]);

        $created = $this->repository->findById($orderId);

        return $this->json($response, $created, 201);
    }

    /**
     * Webhook de pasarela.
     * Body: { order_id, status_value, payment_state?: object }
     */
    public function handleWebhook(Request $request, Response $response): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $orderId     = (int) $body['order_id'];
        $statusValue = (string) $body['status_value'];
        /** @var array<string, mixed>|string $paymentState */
        $paymentState = $body['payment_state'] ?? $body;

        $order = $this->repository->findById($orderId);
        if ($order === false) {
            return $this->jsonError($response, 'Order not found', 404);
        }

        $statusId = $this->statusRepository->findOrCreateIdByValue($statusValue);
        $this->repository->markPaymentState($orderId, $paymentState, $statusId);

        return $this->json($response, ['ok' => true, 'order_id' => $orderId, 'status_id' => $statusId]);
    }

    /** PATCH /api/orders/{id}/sap-order-code */
    public function updateSapOrderCode(Request $request, Response $response, int $id): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $order = $this->repository->findById($id);
        if ($order === false) {
            return $this->jsonError($response, 'Order not found', 404);
        }

        $this->repository->updateSapOrderCode($id, (int) $body['sap_order_code']);
        (new OrderRealtime())->processed($id);

        return $this->json($response, $this->repository->findById($id));
    }

    /**
     * PATCH /api/admin/orders/{id}/sap-order-code/by-agent
     * Actualiza el código SAP y conserva la asignación del agente que procesa.
     */
    public function updateSapOrderCodeByAgent(Request $request, Response $response, int $id): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $order = $this->repository->findById($id);
        if ($order === false) {
            return $this->jsonError($response, 'Order not found', 404);
        }

        $this->repository->updateSapOrderCodeByAgent(
            $id,
            (int) $body['sap_order_code'],
            self::sessionAgentId($request)
        );

        $current = $this->repository->findById($id);
        if ($current === false) {
            return $this->jsonError($response, 'Order not found', 404);
        }

        (new OrderRealtime())->processed($id);

        return $this->json($response, $current);
    }

    /** PATCH /api/admin/orders/{id}/agent-id */
    public function updateAgentId(Request $request, Response $response, int $id): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $order = $this->repository->findById($id);
        if ($order === false) {
            return $this->jsonError($response, 'Order not found', 404);
        }

        $this->repository->updateAgentId($id, (int) $body['agent_id']);

        return $this->json($response, $this->repository->findById($id));
    }

    /**
     * PATCH /api/admin/orders/{id}/take
     * Toma el pedido para el agente autenticado si aún no tiene agente asignado.
     * Siempre devuelve el detalle actualizado del pedido para evitar una segunda petición.
     */
    public function takeByAgent(Request $request, Response $response, int $id): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $order = $this->repository->findById($id);
        if ($order === false) {
            return $this->jsonError($response, 'Order not found', 404);
        }

        $agentId = self::sessionAgentId($request);

        if ($order->agent_id === null) {
            $this->repository->updateAgentIdIfUnassigned($id, $agentId);
        }

        $current = $this->repository->findById($id);
        if ($current === false) {
            return $this->jsonError($response, 'Order not found', 404);
        }

        return $this->json($response, $current);
    }

    /**
     * PATCH /api/admin/orders/{id}/release
     * Libera la orden para otros agentes solo si aún no tiene código SAP
     * y la orden pertenece al agente solicitante.
     */
    public function releaseByAgent(Request $request, Response $response, int $id): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $order = $this->repository->findById($id);
        if ($order === false) {
            return $this->jsonError($response, 'Order not found', 404);
        }

        $this->repository->releaseAgentIdIfOwnerAndPending($id, self::sessionAgentId($request));

        $current = $this->repository->findById($id);
        if ($current === false) {
            return $this->jsonError($response, 'Order not found', 404);
        }

        return $this->json($response, $current);
    }

    /** PATCH /api/orders/{id}/tracking-number */
    public function updateTrackingNumber(Request $request, Response $response, int $id): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $order = $this->repository->findById($id);
        if ($order === false) {
            return $this->jsonError($response, 'Order not found', 404);
        }

        $this->repository->updateTrackingNumber(
            $id,
            (string) $body['tracking_number'],
            (string) $body['carrier_name']
        );
        (new OrderRealtime())->trackingUpdated($id);

        return $this->json($response, $this->repository->findById($id));
    }

    public function delete(Request $request, Response $response, int $id): Response
    {
        $order = $this->repository->findById($id);
        if ($order === false) {
            return $this->jsonError($response, 'Order not found', 404);
        }

        $this->repository->delete($id);

        return $response->withStatus(204);
    }

    public function listStatuses(Request $request, Response $response): Response
    {
        return $this->json($response, $this->statusRepository->findAll());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{0: int, 1: int}
     */
    private function pagination(Request $request): array
    {
        $query   = $request->getQueryParams();
        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));

        return [$page, $perPage];
    }

    /**
     * Extrae filtros opcionales de los query params.
     *
            * @return array{status?: string, status_id?: int, sap_pending?: int, q?: string, date_from?: string, date_to?: string, sort_order?: string, sort_by?: string}
     */
    private function extractFilters(Request $request): array
    {
        $query   = $request->getQueryParams();
        $filters = [];

        if (!empty($query['status']) && is_string($query['status'])) {
            $filters['status'] = $query['status'];
        }

        if (isset($query['status_id']) && is_numeric($query['status_id'])) {
            $filters['status_id'] = (int) $query['status_id'];
        }

        if (isset($query['sap_pending']) && is_numeric($query['sap_pending'])) {
            $filters['sap_pending'] = (int) $query['sap_pending'];
        }

        if (!empty($query['q']) && is_string($query['q'])) {
            $filters['q'] = $query['q'];
        }

        if (!empty($query['date_from']) && is_string($query['date_from'])) {
            $filters['date_from'] = $query['date_from'];
        }

        if (!empty($query['date_to']) && is_string($query['date_to'])) {
            $filters['date_to'] = $query['date_to'];
        }

        if (!empty($query['sort_order']) && is_string($query['sort_order'])) {
            $filters['sort_order'] = $query['sort_order'];
        }

        if (!empty($query['sort_by']) && is_string($query['sort_by'])) {
            $filters['sort_by'] = $query['sort_by'];
        }

        return $filters;
    }

    /** @param array<string, mixed> $body */
    private function resolvePaymentGateway(array $body): ?string
    {
        $paymentGateway = $body['payment_gateway'] ?? $body['payment_method'] ?? null;

        if (!is_string($paymentGateway) || $paymentGateway === '') {
            return null;
        }

        return in_array($paymentGateway, self::PAYMENT_GATEWAYS, true) ? $paymentGateway : null;
    }

    /**
     * @param mixed $raw
     * @return list<array{product_sku: int, product_full_price: float, sap_code: int|null, quantity: int, unit_price: float, discount_percentage: float, line_total: float}>|null
     */
    private function normalizeItems(mixed $raw): ?array
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        $items = [];
        foreach ($raw as $row) {
            if (!is_array($row)
                || !isset($row['product_sku'], $row['quantity'], $row['unit_price'], $row['line_total'])
            ) {
                return null;
            }

            $items[] = [
                'product_sku'         => (int) $row['product_sku'],
                'product_full_price'  => (float) ($row['product_full_price'] ?? $row['unit_price']),
                'sap_code'            => is_numeric($row['sap_code'] ?? null) ? (int) $row['sap_code'] : null,
                'quantity'            => (int) $row['quantity'],
                'unit_price'          => (float) $row['unit_price'],
                'discount_percentage' => (float) ($row['discount_percentage'] ?? 0),
                'line_total'          => (float) $row['line_total'],
            ];
        }

        return $items;
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function jsonError(Response $response, string $error, int $status): Response
    {
        return $this->json($response, ['error' => $error], $status);
    }

    /**
     * El agente de la sesión del admin (`AdminMiddleware`). Tomar, liberar o procesar un pedido se
     * hace siempre a nombre de quien tiene la sesión: el `agent_id` del cuerpo se ignora.
     */
    private static function sessionAgentId(Request $request): int
    {
        $claims = $request->getAttribute('admin_claims');

        return is_object($claims) ? (int) ($claims->sub ?? 0) : 0;
    }
}

<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway;

use App\Features\CronJob\PaymentEventJobRepository;
use App\Features\Order\OrderRepository;
use App\Features\Order\StatusOrderRepository;
use App\Features\PaymentGateway\Events\Handlers\BroadcastAdminOrderStatusHandler;
use App\Features\PaymentGateway\Events\Handlers\EmitClientPurchaseStatusHandler;
use App\Features\PaymentGateway\Events\Handlers\EnqueuePaymentEventJobHandler;
use App\Features\PaymentGateway\Events\Handlers\PaymentEventHandlerInterface;
use App\Features\PaymentGateway\Events\PaymentEventDispatcher;
use App\Features\PaymentGateway\Gateways\Addi\AddiWebhookProcessor;
use App\Features\PaymentGateway\Gateways\Bold\BoldPendingCronService;
use App\Features\PaymentGateway\Gateways\Bold\BoldReconciliationService;
use App\Features\PaymentGateway\Gateways\Bold\BoldSignatureVerifier;
use App\Features\PaymentGateway\Gateways\Bold\BoldWebhookProcessor;
use App\Features\PaymentGateway\Gateways\Sistecredito\SistecreditoWebhookProcessor;
use App\Features\Reward\PurchaseApproval;
use App\Features\PaymentGateway\Validators\BoldStateQueryValidator;
use App\Features\User\UserRepository;
use App\Infrastructure\Notification\SocketGatewayClient;
use App\Shared\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Orquestador único de los endpoints `/api/payment-gateway/*`.
 *
 * Responsabilidades:
 *   - El pago se abre desde el checkout (`GatewayPayment`), con los datos del pedido guardado.
 *   - Webhook: parsear payload, verificar firma (Bold `x-bold-signature`),
 *     pasar al `*WebhookProcessor`, despachar vía `PaymentEventDispatcher` y
 *     encolar efectos diferidos. Siempre responde 200 OK aunque haya errores.
 *   - Response: renderizar la página HTML que el usuario ve al volver de la
 *     pasarela y que rebota al SPA.
 *   - State (Bold): consulta del respaldo de notificaciones, conciliación
 *     puntual y cron de órdenes pendientes.
 */
class PaymentGatewayController
{
    private const string TEMPLATES_PATH = __DIR__ . '/../../../views/payment-response';
    private const string PAYMENT_METHOD_BOLD = 'bold';

    public function __construct(
        private ?PaymentEventDispatcher $dispatcher = null,
    ) {}

    // =========================================================================
    // WEBHOOKS — server-to-server. Siempre responden 200.
    // =========================================================================

    public function webhookAddi(Request $request, Response $response): Response
    {
        try {
            $payload = $this->readJsonBody($request);
            $result  = (new AddiWebhookProcessor())->process($payload);
            $outcome = $this->getDispatcher()->dispatch($result);

            return $this->json($response, ['ok' => true] + $outcome);
        } catch (Throwable $e) {
            return $this->json($response, ['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function webhookSistecredito(Request $request, Response $response): Response
    {
        try {
            $payload = $this->readJsonBody($request);
            $result  = (new SistecreditoWebhookProcessor())->process($payload);
            $outcome = $this->getDispatcher()->dispatch($result);

            return $this->json($response, ['ok' => true] + $outcome);
        } catch (Throwable $e) {
            return $this->json($response, ['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function webhookBold(Request $request, Response $response): Response
    {
        try {
            $rawBody   = (string) $request->getBody();
            $signature = $request->getHeaderLine('x-bold-signature');

            if (!BoldSignatureVerifier::fromEnv()->verify($rawBody, $signature)) {
                return $this->json($response, ['ok' => false, 'error' => 'invalid_signature']);
            }

            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            /** @var array<string, mixed> $payload */
            $payload = is_array($decoded) ? $decoded : [];

            $processor = new BoldWebhookProcessor();

            if ($processor->isVoidRejected($payload)) {
                return $this->json($response, [
                    'ok'        => true,
                    'processed' => false,
                    'reason'    => 'void_rejected',
                    'status'    => 'VOID_REJECTED',
                ]);
            }

            $result  = $processor->process($payload);
            $outcome = $this->getDispatcher()->dispatch($result);

            return $this->json($response, ['ok' => true] + $outcome);
        } catch (Throwable $e) {
            return $this->json($response, ['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // RESPONSE PAGES — HTML que ve el usuario al volver de la pasarela
    // =========================================================================

    public function responseAddi(Request $request, Response $response): Response
    {
        return $this->renderResponsePage($response, 'addi', $request, 'id_order');
    }

    public function responseSistecredito(Request $request, Response $response): Response
    {
        return $this->renderResponsePage($response, 'sistecredito', $request, 'orderId');
    }

    public function responseBold(Request $request, Response $response): Response
    {
        return $this->renderResponsePage($response, 'bold', $request, 'orderId');
    }

    // =========================================================================
    // STATE (Bold) — consulta de notificaciones + conciliación + cron
    // =========================================================================

    public function boldState(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();

        try {
            (new BoldStateQueryValidator())->validateQuery($query);
        } catch (ValidationException $e) {
            return $this->jsonError($response, 'Invalid query: ' . json_encode($e->errors()), 422);
        }

        try {
            $paymentId = isset($query['payment_id']) && is_string($query['payment_id'])
                ? trim($query['payment_id'])
                : '';
            $isExternalReference = ($paymentId === '');
            $queryValue = $isExternalReference
                ? (string) ($query['order_id'] ?? '')
                : $paymentId;

            $notification = BoldReconciliationService::fromEnv()->fetchNotification(
                $queryValue,
                $isExternalReference,
            );

            if ($notification === null) {
                return $this->jsonError($response, 'Bold notification not found', 404);
            }

            return $this->json($response, [
                'gateway'      => self::PAYMENT_METHOD_BOLD,
                'notification' => $notification,
            ]);
        } catch (Throwable $e) {
            return $this->jsonError($response, 'Bold state query failed: ' . $e->getMessage(), 502);
        }
    }

    public function boldReconcile(Request $request, Response $response): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            (new BoldStateQueryValidator())->validateQuery($body);
        } catch (ValidationException $e) {
            return $this->jsonError($response, 'Invalid payload: ' . json_encode($e->errors()), 422);
        }

        try {
            $orderId = isset($body['order_id']) && is_numeric($body['order_id'])
                ? (int) $body['order_id']
                : null;
            $paymentId = isset($body['payment_id']) && is_string($body['payment_id'])
                ? trim($body['payment_id'])
                : null;

            $outcome = BoldReconciliationService::fromEnv()->reconcile(
                dispatcher: $this->getDispatcher(),
                orderId:    $orderId,
                paymentId:  $paymentId,
            );

            if (($outcome['found'] ?? false) === false) {
                return $this->json($response, ['ok' => false] + $outcome, 404);
            }

            return $this->json($response, ['ok' => true] + $outcome);
        } catch (Throwable $e) {
            return $this->jsonError($response, 'Bold reconciliation failed: ' . $e->getMessage(), 502);
        }
    }

    public function boldPendingCron(Request $request, Response $response): Response
    {
        $date = (string) ($request->getQueryParams()['date'] ?? date('Y-m-d'));

        try {
            $report = BoldPendingCronService::fromEnv($this->getDispatcher())->reconcile($date);

            return $this->json($response, ['date' => $date] + $report);
        } catch (Throwable $e) {
            return $this->jsonError($response, 'Bold cron failed: ' . $e->getMessage(), 502);
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Construye el dispatcher con los handlers por defecto. Si un test inyecta
     * uno propio en el constructor, ese se reutiliza.
     */
    private function getDispatcher(): PaymentEventDispatcher
    {
        if ($this->dispatcher !== null) {
            return $this->dispatcher;
        }

        $orders   = new OrderRepository();
        $statuses = new StatusOrderRepository();
        $users    = new UserRepository();
        $socket   = SocketGatewayClient::fromEnv();

        /** @var list<PaymentEventHandlerInterface> $handlers Avisos: corren después de aprobar. */
        $handlers = [
            new EmitClientPurchaseStatusHandler($socket, $users),
            new BroadcastAdminOrderStatusHandler($socket),
            new EnqueuePaymentEventJobHandler(new PaymentEventJobRepository()),
        ];

        return $this->dispatcher = new PaymentEventDispatcher($orders, $statuses, $handlers, new PurchaseApproval());
    }

    /**
     * Lee el body como JSON. Si Slim ya lo parseó (ValidationMiddleware lo
     * hace), usa eso; si no, intenta decodificar el stream crudo.
     *
     * @return array<string, mixed>
     */
    private function readJsonBody(Request $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            /** @var array<string, mixed> $parsed */
            return $parsed;
        }

        $raw = (string) $request->getBody();
        if ($raw === '') {
            return [];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (\JsonException) {
            return [];
        }
    }

    private function renderResponsePage(
        Response $response,
        string $gateway,
        Request $request,
        string $queryParamName,
    ): Response {
        $file = self::TEMPLATES_PATH . "/{$gateway}.html";
        if (!is_file($file)) {
            return $this->jsonError($response, "Response template not found: {$gateway}", 500);
        }

        $html        = (string) file_get_contents($file);
        $queryParams = $request->getQueryParams();
        $rawParam    = (string) ($queryParams[$queryParamName] ?? $queryParams['bold-order-id'] ?? '');
        $txStatus    = (string) ($queryParams['bold-tx-status'] ?? '');
        $spaUrl      = PaymentGatewayResponseConfig::fromEnv()->spaReturnUrl;

        $html = str_replace(
            [
                '{{gateway}}',
                '{{query_param}}',
                '{{query_value}}',
                '{{tx_status}}',
                '{{spa_return_url}}',
            ],
            [
                htmlspecialchars($gateway, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($queryParamName, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($rawParam, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($txStatus, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($spaUrl, ENT_QUOTES, 'UTF-8'),
            ],
            $html,
        );

        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
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
}

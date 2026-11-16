<?php

declare(strict_types=1);

namespace App\Features\CronJob;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class CronJobController
{
    public function __construct(
        private readonly PaymentEventJobRepository $jobs = new PaymentEventJobRepository(),
        private ?PaymentEventJobProcessor $processor = null,
    ) {}

    public function listPaymentEventJobs(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();

        return $this->json($response, $this->jobs->list(
            page:    (int) ($query['page'] ?? 1),
            perPage: (int) ($query['per_page'] ?? 20),
            status:  isset($query['status']) ? (string) $query['status'] : null,
        ));
    }

    public function getPaymentEventJob(Request $request, Response $response, int $id): Response
    {
        $job = $this->jobs->findById($id);

        return $job === null
            ? $this->jsonError($response, 'Payment event job not found.', 404)
            : $this->json($response, $job);
    }

    public function createPaymentEventJob(Request $request, Response $response): Response
    {
        /** @var array<string, mixed> $body */
        $body    = (array) ($request->getParsedBody() ?? []);
        $orderId = (int) ($body['order_id'] ?? 0);

        if ($orderId <= 0) {
            return $this->jsonError($response, 'order_id is required.', 422);
        }

        $jobId = $this->jobs->enqueue($orderId);
        if ($jobId <= 0) {
            return $this->jsonError($response, 'order_id must reference an APROBADA order.', 422);
        }

        return $this->json($response, $this->jobs->findById($jobId), 201);
    }

    public function resetPaymentEventJob(Request $request, Response $response, int $id): Response
    {
        if ($this->jobs->findById($id) === null) {
            return $this->jsonError($response, 'Payment event job not found.', 404);
        }

        $this->jobs->reset($id);

        return $this->json($response, $this->jobs->findById($id));
    }

    public function deletePaymentEventJob(Request $request, Response $response, int $id): Response
    {
        if (!$this->jobs->delete($id)) {
            return $this->jsonError($response, 'Payment event job not found.', 404);
        }

        return $response->withStatus(204);
    }

    public function processPaymentEventJobs(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $limit = (int) ($query['limit'] ?? 50);

        try {
            return $this->json($response, $this->getProcessor()->processPending($limit));
        } catch (Throwable $e) {
            return $this->jsonError($response, 'Payment event job processing failed: ' . $e->getMessage(), 500);
        }
    }

    private function getProcessor(): PaymentEventJobProcessor
    {
        return $this->processor ??= PaymentEventJobProcessor::fromEnv();
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function jsonError(Response $response, string $message, int $status): Response
    {
        return $this->json($response, ['error' => $message], $status);
    }
}

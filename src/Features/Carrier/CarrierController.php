<?php

declare(strict_types=1);

namespace App\Features\Carrier;

use App\Features\Carrier\Exceptions\CarrierTrackingException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CarrierController
{
    public function __construct(
        private readonly ServientregaTrackingService $service
    ) {}

    public function getServientregaTracking(Request $request, Response $response): Response
    {
        $trackingNumber = $this->trackingNumberFromQuery($request);

        if ($trackingNumber === null) {
            return $this->json($response, ['error' => 'La guía es obligatoria.'], 422);
        }

        try {
            return $this->json($response, $this->service->getTrackingDetail($trackingNumber));
        } catch (CarrierTrackingException $e) {
            return $this->json($response, ['error' => $e->getMessage()], $this->statusCode($e));
        }
    }

    private function trackingNumberFromQuery(Request $request): ?string
    {
        $value = $request->getQueryParams()['tracking_number'] ?? null;

        if (!is_scalar($value)) {
            return null;
        }

        $trackingNumber = trim((string) $value);

        return $trackingNumber !== '' ? $trackingNumber : null;
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function statusCode(CarrierTrackingException $exception): int
    {
        $code = $exception->getCode();

        return $code >= 400 && $code <= 599 ? $code : 502;
    }
}

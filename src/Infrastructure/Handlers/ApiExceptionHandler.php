<?php

declare(strict_types=1);

namespace App\Infrastructure\Handlers;

use App\Shared\Exceptions\ApiException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Convierte una `ApiException` en su respuesta JSON: `{ message, code, errors? }`.
 */
class ApiExceptionHandler
{
    public function __construct(private readonly ResponseFactoryInterface $responseFactory) {}

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        /** @var ApiException $exception */
        $response = $this->responseFactory->createResponse($exception->status());
        $response->getBody()->write(
            json_encode($exception->body(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );

        foreach ($exception->headers() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response->withHeader('Content-Type', 'application/json');
    }
}

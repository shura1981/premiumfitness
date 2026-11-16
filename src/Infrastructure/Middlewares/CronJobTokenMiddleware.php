<?php

declare(strict_types=1);

namespace App\Infrastructure\Middlewares;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;

final readonly class CronJobTokenMiddleware
{
    public function __construct(
        private string $token,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public static function fromEnv(ResponseFactoryInterface $responseFactory): self
    {
        return new self($_ENV['CRON_JOB_TOKEN'], $responseFactory);
    }

    public function __invoke(Request $request, Handler $handler): Response
    {
        $headerToken = $request->getHeaderLine('X-Cron-Job-Token');

        if ($headerToken === '' || !hash_equals($this->token, $headerToken)) {
            $response = $this->responseFactory->createResponse(401);
            $response->getBody()->write(
                json_encode(['error' => 'Unauthorized cron job request.'], JSON_THROW_ON_ERROR)
            );

            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}

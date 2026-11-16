<?php

declare(strict_types=1);

namespace App\Infrastructure\Handlers;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Handler 404 que distingue entre rutas API y rutas web:
 *   - API  (path /api/* o Accept: application/json) → responde JSON
 *   - Web  → sirve views/errors/404.html o HTML inline como fallback
 */
class NotFoundHandler
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly bool $displayErrorDetails = false,
    ) {}

    public function __invoke(ServerRequestInterface $request, Throwable $exception): ResponseInterface
    {
        $method = $request->getMethod();
        $path   = $request->getUri()->getPath();

        error_log("404 Not Found: [{$method}] {$path}");

        return $this->isApiRequest($request)
            ? $this->jsonResponse($path)
            : $this->htmlResponse($path);
    }

    private function isApiRequest(ServerRequestInterface $request): bool
    {
        return str_starts_with($request->getUri()->getPath(), '/api')
            || str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    private function jsonResponse(string $path): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(404);
        $response->getBody()->write(
            json_encode(
                ['error' => 'Not Found', 'path' => $path],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )
        );

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function htmlResponse(string $path): ResponseInterface
    {
        $viewFile = __DIR__ . '/../../../views/errors/404.html';
        $html     = is_file($viewFile)
            ? (string) file_get_contents($viewFile)
            : $this->fallbackHtml($path);

        $response = $this->responseFactory->createResponse(404);
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function fallbackHtml(string $path): string
    {
        $safe = htmlspecialchars($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>404 — Not Found</title>
            <style>
                body { font-family: system-ui, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #f8f9fa; }
                .box { text-align: center; padding: 2rem; }
                h1 { font-size: 7rem; margin: 0; color: #dee2e6; }
                p  { color: #6c757d; }
                a  { color: #212529; font-weight: 600; }
            </style>
        </head>
        <body>
            <div class="box">
                <h1>404</h1>
                <p>The page <code>{$safe}</code> was not found.</p>
                <a href="/">← Go back home</a>
            </div>
        </body>
        </html>
        HTML;
    }
}

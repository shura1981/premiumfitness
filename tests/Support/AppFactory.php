<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\App;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds the real Slim application (identical to index.php) and exposes
 * convenience helpers to dispatch real HTTP requests end-to-end.
 */
final class AppFactory
{
    private static ?SlimApp $app = null;

    public static function make(): SlimApp
    {
        if (self::$app === null) {
            self::$app = App::create();
        }

        return self::$app;
    }

    /**
     * Cada petición sale de una IP distinta, salvo que se indique `$ip`: los límites por IP guardan
     * sus contadores en disco y, si no, las pruebas se bloquearían entre sí.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     * @param array<string, string> $cookies
     */
    public static function request(
        string $method,
        string $path,
        ?array $body = null,
        array $headers = [],
        array $cookies = [],
        ?string $ip = null,
    ): ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => $ip ?? self::randomIp()])
            ->withCookieParams($cookies);

        $query = parse_url($path, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $queryParams);
            $request = $request->withQueryParams($queryParams);
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $json   = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $stream = (new StreamFactory())->createStream($json);

            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($stream)
                ->withParsedBody($body);
        }

        return $request;
    }

    public static function randomIp(): string
    {
        return sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
    }

    /** Valor de una cookie puesta por la respuesta (`Set-Cookie`), o null. */
    public static function cookie(ResponseInterface $response, string $name): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (str_starts_with($header, $name . '=')) {
                return rawurldecode(substr(explode(';', $header, 2)[0], strlen($name) + 1));
            }
        }

        return null;
    }

    /** La cabecera `Set-Cookie` completa de una cookie, con sus atributos. */
    public static function cookieHeader(ResponseInterface $response, string $name): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (str_starts_with($header, $name . '=')) {
                return $header;
            }
        }

        return null;
    }

    public static function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        return self::make()->handle($request);
    }

    /** @return array<string, mixed>|list<mixed>|null */
    public static function json(ResponseInterface $response): array|null
    {
        $body = (string) $response->getBody();
        if ($body === '') {
            return null;
        }

        /** @var array<string, mixed>|list<mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

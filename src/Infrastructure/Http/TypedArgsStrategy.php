<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\InvocationStrategyInterface;

use function array_values;
use function ctype_digit;
use function is_string;

/**
 * Like RequestResponseArgs but casts numeric route params to int
 * so controllers can type-hint `int $id` directly.
 */
final class TypedArgsStrategy implements InvocationStrategyInterface
{
    /**
     * @param array<string, string> $routeArguments
     */
    public function __invoke(
        callable $callable,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $routeArguments,
    ): ResponseInterface {
        $typed = [];
        foreach ($routeArguments as $key => $value) {
            $typed[$key] = is_string($value) && ctype_digit($value)
                ? (int) $value
                : $value;
        }

        /** @var ResponseInterface */
        return $callable($request, $response, ...array_values($typed));
    }
}

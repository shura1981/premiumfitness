<?php

declare(strict_types=1);

use Tests\Support\AppFactory;
use Tests\Support\JwtFactory;
use Tests\Support\Seed;

$newUserId = static fn (): int => Seed::user();

it('rejects protected endpoint without JWT', function () use ($newUserId): void {
    $userId = $newUserId();

    $response = AppFactory::dispatch(AppFactory::request(
        'GET',
        '/api/ENDPOINT',
    ));

    expect($response->getStatusCode())->toBe(401);
});

it('accepts request with JWT and valid payload', function () use ($newUserId): void {
    $userId = $newUserId();

    $response = AppFactory::dispatch(AppFactory::request(
        'POST',
        '/api/ENDPOINT',
        body: [
            // TODO payload valido
        ],
        headers: JwtFactory::authHeader($userId),
    ));

    expect($response->getStatusCode())->toBe(200);
});

it('returns validation error with invalid payload', function () use ($newUserId): void {
    $userId = $newUserId();

    $response = AppFactory::dispatch(AppFactory::request(
        'POST',
        '/api/ENDPOINT',
        body: [
            // TODO payload invalido
        ],
        headers: JwtFactory::authHeader($userId),
    ));

    expect($response->getStatusCode())->toBe(422);
});

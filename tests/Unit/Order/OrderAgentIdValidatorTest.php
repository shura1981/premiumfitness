<?php

declare(strict_types=1);

use App\Features\Order\OrderAgentIdValidator;
use App\Shared\Exceptions\ValidationException;

it('passes with integer agent_id', function (): void {
    (new OrderAgentIdValidator())->validate(['agent_id' => 12]);

    expect(true)->toBeTrue();
});

it('fails without agent_id', function (): void {
    expect(fn () => (new OrderAgentIdValidator())->validate([]))
        ->toThrow(ValidationException::class);
});

it('fails when agent_id is not integer', function (): void {
    expect(fn () => (new OrderAgentIdValidator())->validate(['agent_id' => 'NOPE']))
        ->toThrow(ValidationException::class);
});


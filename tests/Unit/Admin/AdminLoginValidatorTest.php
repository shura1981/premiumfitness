<?php

declare(strict_types=1);

use App\Features\Admin\AdminLoginValidator;
use App\Shared\Exceptions\ValidationException;

it('passes with a valid admin login payload', function (): void {
    (new AdminLoginValidator())->validate([
        'email' => 'admin@example.com',
        'password' => 'admin-test-1234',
    ]);

    expect(true)->toBeTrue();
});

it('fails without required admin login fields', function (): void {
    expect(fn () => (new AdminLoginValidator())->validate([]))
        ->toThrow(ValidationException::class);
});

it('fails when admin login email is invalid', function (): void {
    expect(fn () => (new AdminLoginValidator())->validate([
        'email' => 'not-an-email',
        'password' => 'admin-test-1234',
    ]))
        ->toThrow(ValidationException::class);
});

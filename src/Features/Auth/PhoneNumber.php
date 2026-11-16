<?php

declare(strict_types=1);

namespace App\Features\Auth;

/**
 * Celular colombiano: 10 dígitos que empiezan por 3. Es el usuario del login.
 */
final class PhoneNumber
{
    /** Quita espacios, guiones y el indicativo 57. Devuelve null si no es un celular válido. */
    public static function normalize(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '57')) {
            $digits = substr($digits, 2);
        }

        return preg_match('/^3\d{9}$/', $digits) === 1 ? $digits : null;
    }
}

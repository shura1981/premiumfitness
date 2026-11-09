<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

/**
 * Servicio de hashing de contraseñas.
 *
 * Usa PASSWORD_ARGON2ID — más resistente a ataques GPU/ASIC que bcrypt.
 * Requiere PHP >= 7.3 con libargon2 (disponible por defecto en PHP 8.x).
 *
 * Uso:
 *   $hasher = new PasswordHasher();
 *   $hash   = $hasher->hash($plaintextPassword);          // guardar en BD
 *   $ok     = $hasher->verify($plaintextPassword, $hash); // al hacer login
 *
 *   // Rehash si el algoritmo/costo cambió (ejecutar en login exitoso):
 *   if ($hasher->needsRehash($hash)) {
 *       $newHash = $hasher->hash($plaintextPassword);
 *       // actualizar $newHash en BD
 *   }
 */
class PasswordHasher
{
    private const ALGORITHM = PASSWORD_ARGON2ID;
    private const OPTIONS   = [
        'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
        'time_cost'   => PASSWORD_ARGON2_DEFAULT_TIME_COST,
        'threads'     => PASSWORD_ARGON2_DEFAULT_THREADS,
    ];

    public function hash(string $plainText): string
    {
        return password_hash($plainText, self::ALGORITHM, self::OPTIONS);
    }

    public function verify(string $plainText, string $hash): bool
    {
        return password_verify($plainText, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::ALGORITHM, self::OPTIONS);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Notification\InMemorySmsChannel;
use Psr\Http\Message\ResponseInterface;

/**
 * Atajos de las pruebas de autenticación: peticiones reales contra la app.
 */
final class Auth
{
    /** @param array<string, mixed> $body */
    public static function post(string $path, array $body = [], ?string $refreshToken = null, ?string $ip = null, string $cookie = 'pf_rt_web'): ResponseInterface
    {
        return AppFactory::dispatch(AppFactory::request(
            'POST',
            $path,
            $body,
            cookies: $refreshToken === null ? [] : [$cookie => $refreshToken],
            ip: $ip,
        ));
    }

    /** Login del área del rol de la cuenta (`/entrar` o `/coach/entrar`); sin cuenta, el de clientes. */
    public static function login(string $phone, string $password = Seed::PASSWORD, ?string $role = null): ResponseInterface
    {
        return self::post('/api/auth/login', ['phone' => $phone, 'password' => $password, 'role' => $role ?? self::roleOf($phone)]);
    }

    private static function roleOf(string $phone): string
    {
        $stmt = Seed::pdo()->prepare('SELECT role FROM users WHERE num_cell = ?');
        $stmt->execute([$phone]);

        return (string) ($stmt->fetchColumn() ?: 'client');
    }

    /** Código de 6 dígitos del último SMS enviado al celular. */
    public static function lastCode(string $phone): ?string
    {
        $message = InMemorySmsChannel::lastTo($phone);

        return $message !== null && preg_match('/\b(\d{6})\b/', $message, $match) === 1 ? $match[1] : null;
    }

    /**
     * Datos válidos para `POST /api/auth/register`.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function registration(array $overrides = []): array
    {
        return $overrides + [
            'full_name'        => 'Laura Prueba Gómez',
            'document_type_id' => 1,
            'document_id'      => (string) random_int(1_000_000_000, 1_999_999_999),
            'phone'            => Seed::phone(),
            'email'            => uniqid('registro_', true) . '@example.com',
            'password'         => 'clave-segura-1',
            'role'             => 'client',
        ];
    }

    /** Registra y verifica una cuenta. Devuelve la respuesta de `verify-phone`. */
    public static function registerAndVerify(array $overrides = [], ?array $referral = null): ResponseInterface
    {
        $data = self::registration($overrides);
        self::post('/api/auth/register', $data);

        return self::post('/api/auth/verify-phone', [
            'phone'    => $data['phone'],
            'code'     => self::lastCode($data['phone']),
            'referral' => $referral,
        ]);
    }
}

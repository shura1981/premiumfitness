<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use RuntimeException;

/**
 * Error de negocio con respuesta HTTP: `{ message, code, errors? }`.
 * `message` es un texto listo para mostrar; `code`, una clave estable para la app.
 * La convierte en respuesta `ApiExceptionHandler`.
 */
class ApiException extends RuntimeException
{
    /**
     * @param array<string, string> $errors  Errores por campo (422).
     * @param array<string, mixed>  $extra   Datos adicionales del cuerpo.
     * @param array<string, string> $headers Headers de la respuesta.
     */
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message,
        private readonly array $errors = [],
        private readonly array $extra = [],
        private readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function unauthorized(string $message = 'Tu sesión terminó. Vuelve a entrar.', string $code = 'unauthorized'): self
    {
        return new self(401, $code, $message);
    }

    public static function forbidden(string $message = 'No tienes permiso para hacer esto.', string $code = 'forbidden'): self
    {
        return new self(403, $code, $message);
    }

    public static function notFound(string $message = 'No encontramos lo que buscas.', string $code = 'not_found'): self
    {
        return new self(404, $code, $message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }

    /** @param array<string, string> $errors */
    public static function invalid(string $message, string $code = 'validation', array $errors = []): self
    {
        return new self(422, $code, $message, $errors);
    }

    public static function tooManyRequests(int $retryAfterSeconds, string $message = 'Demasiados intentos. Espera un momento y vuelve a intentarlo.'): self
    {
        return new self(429, 'too_many_requests', $message, headers: ['Retry-After' => (string) max(1, $retryAfterSeconds)]);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        $body = ['message' => $this->getMessage(), 'code' => $this->errorCode];

        if ($this->errors !== []) {
            $body['errors'] = $this->errors;
        }

        return $body + $this->extra;
    }
}

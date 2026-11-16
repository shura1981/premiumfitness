<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Excepción para errores al consumir endpoints HTTP externos.
 *
 * Expone el código HTTP y el payload de error devuelto por el endpoint.
 */
class HttpRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        private readonly ?string $errorPayload = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function errorPayload(): ?string
    {
        return $this->errorPayload;
    }

    /**
     * @return array{error: string, statusCode: int|null, payload: string|null}
     */
    public function toError(): array
    {
        return [
            'error'      => $this->getMessage(),
            'statusCode' => $this->statusCode,
            'payload'    => $this->errorPayload,
        ];
    }
}

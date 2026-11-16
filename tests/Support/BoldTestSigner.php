<?php

declare(strict_types=1);

namespace Tests\Support;

final readonly class BoldTestSigner
{
    public function __construct(
        private string $secretKey,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            secretKey: trim((string) $_ENV['BOLD_SECRET_KEY']),
        );
    }

    public function sign(string $rawBody): string
    {
        return hash_hmac('sha256', base64_encode($rawBody), $this->secretKey);
    }
}


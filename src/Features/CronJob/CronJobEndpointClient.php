<?php

declare(strict_types=1);

namespace App\Features\CronJob;

use App\Infrastructure\Http\CurlHttpClient;

final readonly class CronJobEndpointClient
{
    public function __construct(
        private CurlHttpClient $http,
        private string $paymentEventsUrl,
        private string $token,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            new CurlHttpClient(),
            $_ENV['CRON_JOB_PAYMENT_EVENTS_URL'],
            $_ENV['CRON_JOB_TOKEN'],
        );
    }

    public function processPaymentEventJobs(): string
    {
        return $this->http->request($this->paymentEventsUrl, [
            'method'  => 'POST',
            'headers' => [
                'Accept'           => 'application/json',
                'X-Cron-Job-Token' => $this->token,
            ],
            'timeout' => 60,
        ]);
    }
}

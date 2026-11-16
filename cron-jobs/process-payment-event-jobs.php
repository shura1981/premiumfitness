<?php

declare(strict_types=1);

require_once __DIR__ . '/../load/bootstrap.php';

use App\Features\CronJob\CronJobEndpointClient;

echo CronJobEndpointClient::fromEnv()->processPaymentEventJobs() . PHP_EOL;

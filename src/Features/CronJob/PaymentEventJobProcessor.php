<?php

declare(strict_types=1);

namespace App\Features\CronJob;

use App\Features\Order\OrderModel;
use App\Features\Order\OrderRepository;
use App\Features\PaymentGateway\Events\Handlers\SendEmailHandler;
use App\Features\PaymentGateway\Events\Handlers\SendSmsHandler;
use App\Features\PaymentGateway\Events\PaymentEvent;
use App\Features\PaymentGateway\Events\PaymentEventHandlerRunner;
use App\Features\PaymentGateway\Normalizer\PaymentStatus;
use App\Features\PaymentGateway\Result\PaymentResult;
use App\Features\User\UserRepository;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Notification\SmsChannelFactory;
use RuntimeException;
use Throwable;

final class PaymentEventJobProcessor
{
    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly PaymentEventJobRepository $jobs,
        private readonly OrderRepository $orders,
        private readonly PaymentEventHandlerRunner $runner,
    ) {}

    public static function fromEnv(): self
    {
        $users = new UserRepository();

        return new self(
            new PaymentEventJobRepository(),
            new OrderRepository(),
            new PaymentEventHandlerRunner([
                new SendSmsHandler(SmsChannelFactory::fromEnv(), $users),
                new SendEmailHandler(Mailer::fromEnv(), $users),
            ])
        );
    }

    /**
     * @return array{processed: int, failed: int, skipped: int, jobs: list<array<string, mixed>>}
     */
    public function processPending(int $limit = 50): array
    {
        $processed = 0;
        $failed    = 0;
        $skipped   = 0;
        $results   = [];

        foreach ($this->jobs->findPendingApproved($limit, self::MAX_ATTEMPTS) as $job) {
            if (!$this->jobs->lock($job->job_id)) {
                $skipped++;
                continue;
            }

            try {
                $result = $this->processOne($job);
                $processed++;
                $results[] = $result;
            } catch (Throwable $e) {
                $failed++;
                $this->jobs->markFailed($job->job_id, $e->getMessage());
                $results[] = [
                    'job_id'   => $job->job_id,
                    'order_id' => $job->order_id,
                    'ok'       => false,
                    'error'    => $e->getMessage(),
                ];
            }
        }

        return [
            'processed' => $processed,
            'failed'    => $failed,
            'skipped'   => $skipped,
            'jobs'      => $results,
        ];
    }

    /** @return array<string, mixed> */
    private function processOne(PaymentEventJobModel $job): array
    {
        $order = $this->orders->findById($job->order_id);
        if ($order === false) {
            throw new RuntimeException('Order not found.');
        }

        if ($order->status_value !== PaymentStatus::TEXT_APROBADA) {
            throw new RuntimeException('Order is not APROBADA.');
        }

        $event  = new PaymentEvent($order, $this->paymentResultFromOrder($order));
        $errors = $this->runner->run($event);

        if ($errors !== []) {
            throw new RuntimeException(implode(' | ', $errors));
        }

        $this->jobs->markProcessed($job->job_id);

        return [
            'job_id'   => $job->job_id,
            'order_id' => $job->order_id,
            'ok'       => true,
        ];
    }

    private function paymentResultFromOrder(OrderModel $order): PaymentResult
    {
        $payload = $this->paymentPayload($order);

        return new PaymentResult(
            gateway:       (string) $order->payment_gateway,
            orderId:       $order->order_id,
            transactionId: $this->transactionId((string) $order->payment_gateway, $payload),
            codStatus:     PaymentStatus::APROBADA,
            statusText:    PaymentStatus::TEXT_APROBADA,
            amount:        (float) $order->amount,
            rawPayload:    $payload,
        );
    }

    /** @return array<string, mixed> */
    private function paymentPayload(OrderModel $order): array
    {
        if ($order->payment_state_json === null || trim($order->payment_state_json) === '') {
            return [];
        }

        $decoded = json_decode($order->payment_state_json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    private function transactionId(string $gateway, array $payload): string
    {
        return match ($gateway) {
            'addi' => (string) ($payload['applicationId'] ?? ''),
            'sistecredito' => is_array($payload['PaymentMethodResponse'] ?? null)
                ? (string) ($payload['PaymentMethodResponse']['TransactionId'] ?? '')
                : '',
            'bold' => is_array($payload['data'] ?? null)
                ? (string) ($payload['data']['payment_id'] ?? '')
                : '',
            'epayco' => (string) ($payload['x_ref_payco'] ?? ''),
            default => '',
        };
    }
}

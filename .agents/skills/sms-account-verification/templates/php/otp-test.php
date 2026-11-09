<?php

declare(strict_types=1);

require __DIR__ . '/Otp.php';

use PortableSms\{Context, MemoryStore, OtpService, Sender};

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class CaptureSender implements Sender
{
    public string $code = '';
    public int $sends = 0;
    public string $delivery = 'accepted';

    public function send(string $destination, string $message, string $idempotencyKey): string
    {
        $this->code = $message;
        $this->sends++;
        return $this->delivery;
    }
}

$now = 1000;
$sender = new CaptureSender();
$service = new OtpService(new MemoryStore(), $sender, str_repeat('s', 32),
    static fn (string $code, int $ttl): string => $code,
    static function () use (&$now): int { return $now; });
$context = new Context('demo', 'user-1', '+573001234567', 'login');
$first = $service->request($context, 'a');
check($first->ok && $first->id !== null, 'Request should succeed');
check(preg_match('/\A[0-9]{6}\z/', $sender->code) === 1, 'Six-digit string');
check(!str_contains(json_encode($first, JSON_THROW_ON_ERROR), $sender->code), 'No OTP in response');
$blocked = $service->request($context, 'b');
check(!$blocked->ok && $blocked->retryAfter === 60 && $sender->sends === 1, 'Destination cooldown');
check(!$service->verify(new Context('demo', 'user-1', $context->destination, 'reset'), 'a', $first->id, $sender->code)->ok, 'Purpose binding');
check($service->verify($context, 'a', $first->id, $sender->code)->ok, 'Correct OTP');
check(!$service->verify($context, 'b', $first->id, $sender->code)->ok, 'Replay rejected');
$now += 60;
$second = $service->request($context, 'a');
check($second->ok && $second->id !== null, 'Resend');
for ($i = 0; $i < 3; $i++) {
    check(!$service->verify($context, (string) $i, $second->id, 'wrong!')->ok, 'Wrong guess');
}
check(!$service->verify($context, 'fresh', $second->id, $sender->code)->ok, 'Failures persist across IPs');
$now += 60;
$third = $service->request($context, 'a');
check($third->ok && $third->id !== null, 'Third request');
check(!$service->verify($context, 'a', $second->id, $sender->code)->ok, 'Old challenge invalidated');
$now += 300;
check(!$service->verify($context, 'a', $third->id, $sender->code)->ok, 'Exact expiry');
$sender->delivery = 'rejected';
$fourth = $service->request($context, 'a');
check($fourth->ok && $fourth->id !== null, 'Rejected send tracked');
check(!$service->verify($context, 'a', $fourth->id, $sender->code)->ok, 'Rejected delivery unusable');
echo "PHP OTP scenarios passed (single process; no shared-store concurrency claim).\n";

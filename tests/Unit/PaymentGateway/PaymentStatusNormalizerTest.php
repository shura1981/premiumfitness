<?php

declare(strict_types=1);

use App\Features\PaymentGateway\Normalizer\PaymentStatus;
use App\Features\PaymentGateway\Normalizer\PaymentStatusNormalizer;

dataset('sistecredito_statuses', [
    ['Approved',                PaymentStatus::APROBADA,         PaymentStatus::TEXT_APROBADA],
    ['Rejected',                PaymentStatus::RECHAZADA,        PaymentStatus::TEXT_RECHAZADA],
    ['Pending',                 PaymentStatus::PENDIENTE,        PaymentStatus::TEXT_PENDIENTE],
    ['Failed',                  PaymentStatus::ERROR,            PaymentStatus::TEXT_ERROR],
    ['PendingForPaymentMethod', PaymentStatus::PENDIENTE_METODO, PaymentStatus::TEXT_PENDIENTE_METODO],
    ['Cancelled',               PaymentStatus::CANCELADA,        PaymentStatus::TEXT_CANCELADA],
]);

dataset('addi_statuses', [
    ['APPROVED',       PaymentStatus::APROBADA,         PaymentStatus::TEXT_APROBADA],
    ['REJECTED',       PaymentStatus::RECHAZADA,        PaymentStatus::TEXT_RECHAZADA],
    ['PENDING',        PaymentStatus::PENDIENTE,        PaymentStatus::TEXT_PENDIENTE],
    ['INTERNAL_ERROR', PaymentStatus::ERROR,            PaymentStatus::TEXT_ERROR],
    ['ABANDONED',      PaymentStatus::PENDIENTE_METODO, PaymentStatus::TEXT_ABANDONADA],
    ['DECLINED',       PaymentStatus::CANCELADA,        PaymentStatus::TEXT_CANCELADA],
]);

dataset('bold_statuses', [
    ['SALE_APPROVED', PaymentStatus::APROBADA,  PaymentStatus::TEXT_APROBADA],
    ['SALE_REJECTED', PaymentStatus::RECHAZADA, PaymentStatus::TEXT_RECHAZADA],
    ['VOID_APPROVED', PaymentStatus::RECHAZADA, PaymentStatus::TEXT_RECHAZADA],
]);

it('normalizes Sistecrédito statuses', function (string $raw, int $cod, string $text): void {
    [$gotCod, $gotText] = (new PaymentStatusNormalizer())->normalize('sistecredito', $raw);
    expect($gotCod)->toBe($cod)
        ->and($gotText)->toBe($text);
})->with('sistecredito_statuses');

it('normalizes Addi statuses', function (string $raw, int $cod, string $text): void {
    [$gotCod, $gotText] = (new PaymentStatusNormalizer())->normalize('addi', $raw);
    expect($gotCod)->toBe($cod)
        ->and($gotText)->toBe($text);
})->with('addi_statuses');

it('normalizes Bold statuses', function (string $raw, int $cod, string $text): void {
    [$gotCod, $gotText] = (new PaymentStatusNormalizer())->normalize('bold', $raw);
    expect($gotCod)->toBe($cod)
        ->and($gotText)->toBe($text);
})->with('bold_statuses');

it('returns ERROR/DESCONOCIDO for unknown status', function (): void {
    [$cod, $text] = (new PaymentStatusNormalizer())->normalize('addi', 'WHATEVER');
    expect($cod)->toBe(PaymentStatus::ERROR)
        ->and($text)->toBe(PaymentStatus::TEXT_DESCONOCIDO);
});

it('returns ERROR/DESCONOCIDO for unknown gateway', function (): void {
    [$cod, $text] = (new PaymentStatusNormalizer())->normalize('paypal', 'APPROVED');
    expect($cod)->toBe(PaymentStatus::ERROR)
        ->and($text)->toBe(PaymentStatus::TEXT_DESCONOCIDO);
});

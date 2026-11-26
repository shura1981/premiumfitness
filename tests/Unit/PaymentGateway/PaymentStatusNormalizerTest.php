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

it('un estado que no está en la tabla se guarda con el nombre que mandó la pasarela, sin aprobar', function (string $gateway, string $raw): void {
    [$cod, $text] = (new PaymentStatusNormalizer())->normalize($gateway, $raw);
    expect($cod)->toBe(PaymentStatus::ERROR)
        ->and($text)->toBe($raw);
})->with([
    'Addi'         => ['addi', 'EXPIRED'],
    'Sistecrédito' => ['sistecredito', 'Expired'],
    'Bold'         => ['bold', 'SALE_PENDING'],
]);

it('corta el nombre al largo de la columna y deja DESCONOCIDO si viene vacío', function (): void {
    $normalizer = new PaymentStatusNormalizer();

    expect($normalizer->normalize('addi', str_repeat('X', 80))[1])->toBe(str_repeat('X', 50))
        ->and($normalizer->normalize('bold', '   '))->toBe([PaymentStatus::ERROR, PaymentStatus::TEXT_DESCONOCIDO]);
});

it('un nombre desconocido nunca toma un estado que pone la app (aprobado o reversado)', function (string $raw): void {
    expect((new PaymentStatusNormalizer())->normalize('addi', $raw))->toBe([PaymentStatus::ERROR, PaymentStatus::TEXT_DESCONOCIDO]);
})->with(['aprobada', 'APROBADA', 'Reversada', 'REVERSADA']);

it('returns ERROR/DESCONOCIDO for unknown gateway', function (): void {
    [$cod, $text] = (new PaymentStatusNormalizer())->normalize('paypal', 'APPROVED');
    expect($cod)->toBe(PaymentStatus::ERROR)
        ->and($text)->toBe(PaymentStatus::TEXT_DESCONOCIDO);
});

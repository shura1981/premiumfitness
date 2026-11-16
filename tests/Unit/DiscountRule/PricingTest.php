<?php

declare(strict_types=1);

use App\Features\DiscountRule\Buyer;
use App\Features\DiscountRule\DiscountPolicy;
use App\Features\DiscountRule\DiscountRule;
use App\Features\Order\VatPolicy;
use App\Infrastructure\Config\BusinessConfig;

/*
 | Descuento por rol (reglas 2, 6 y 14) e IVA por línea (spec catalogo/01), con los ejemplos de los specs.
 | Los porcentajes se pasan aquí a mano: las reglas no dependen del .env del equipo.
 */

function policy(): DiscountPolicy
{
    return new DiscountPolicy(new BusinessConfig(
        clientDiscountPercentage: 10,
        trainerDiscountPercentage: 25,
        clientPurchasePointsPercentage: 5,
        trainerCommissionPercentage: 15,
        trainerReferralPoints: 50000,
        pointsExpirationMonths: 6,
        commissionConversionPercentage: 50,
        vatPercentage: 19,
        vatExemptProductCodes: [103040],
        webAppUrl: 'https://premiumfitness.nutramerican.com',
    ));
}

it('aplica el descuento según quién compra', function (Buyer $buyer, float $expected): void {
    expect(policy()->discountPercentage($buyer, null))->toBe($expected);
})->with([
    'entrenador: 25 %'              => [new Buyer(1, 'trainer', null), 25.0],
    'cliente con entrenador: 10 %'  => [new Buyer(2, 'client', 1), 10.0],
    'cliente sin entrenador: nada'  => [new Buyer(3, 'client', null), 0.0],
]);

it('la estrategia del producto tiene prioridad, y lo que deja vacío usa el valor por defecto', function (): void {
    $rule = new DiscountRule(100507, clientDiscountPercentage: 15.0, trainerDiscountPercentage: null, commissionPercentage: 20.0);

    expect(policy()->discountPercentage(new Buyer(2, 'client', 1), $rule))->toBe(15.0)
        ->and(policy()->discountPercentage(new Buyer(1, 'trainer', null), $rule))->toBe(25.0)
        // Una estrategia no le da descuento a quien no tiene entrenador.
        ->and(policy()->discountPercentage(new Buyer(3, 'client', null), $rule))->toBe(0.0)
        ->and(policy()->commissionPercentage($rule))->toBe(20.0)
        ->and(policy()->commissionPercentage(null))->toBe(15.0);
});

it('una estrategia puede dejar un producto sin descuento (0 no es «vacío»)', function (): void {
    $rule = new DiscountRule(100507, clientDiscountPercentage: 0.0, trainerDiscountPercentage: 0.0, commissionPercentage: 0.0);

    expect(policy()->discountPercentage(new Buyer(2, 'client', 1), $rule))->toBe(0.0)
        ->and(policy()->discountPercentage(new Buyer(1, 'trainer', null), $rule))->toBe(0.0)
        ->and(policy()->commissionPercentage($rule))->toBe(0.0);
});

it('calcula el precio con descuento redondeado al peso', function (int $price, float $discount, int $expected): void {
    expect(DiscountPolicy::finalPrice($price, $discount))->toBe($expected);
})->with([
    'Bi-Pro Classic con 10 %'      => [249900, 10, 224910],
    'entrenador con 25 %'          => [249900, 25, 187425],
    'medio peso sube'              => [64990, 25, 48743],
    'sin descuento'                => [249900, 0, 249900],
]);

it('separa el IVA de cada línea con los ejemplos del spec', function (int $code, float $paid, float $rate, float $base, float $tax): void {
    $vat = new VatPolicy(19, [103040]);

    expect($vat->split($code, $paid))->toBe(['rate' => $rate, 'base' => $base, 'tax' => $tax]);
})->with([
    '119.000 con 19 %'              => [100507, 119000.0, 19.0, 100000.0, 19000.0],
    'Bi-Pro Classic con 10 %'       => [100507, 224910.0, 19.0, 189000.0, 35910.0],
    'Nutra C no tiene IVA'          => [103040, 119000.0, 0.0, 119000.0, 0.0],
    'base con decimales'            => [100507, 48743.0, 19.0, 40960.5, 7782.5],
]);

it('la base más el IVA da siempre lo pagado', function (): void {
    $vat = new VatPolicy(19, []);

    foreach ([1, 99, 100, 48743, 187425, 224910, 999999] as $paid) {
        $split = $vat->split(1, (float) $paid);
        expect(round($split['base'] + $split['tax'], 2))->toBe((float) $paid);
    }
});

it('la comisión del 15 % sale de la base sin IVA', function (float $paid, int $code, float $commission): void {
    $base = new VatPolicy(19, [103040])->split($code, $paid)['base'];

    expect(round($base * policy()->commissionPercentage(null) / 100, 2))->toBe($commission);
})->with([
    [119000.0, 100507, 15000.0],
    [224910.0, 100507, 28350.0],
    [119000.0, 103040, 17850.0],
]);

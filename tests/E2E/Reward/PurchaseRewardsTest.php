<?php

declare(strict_types=1);

use App\Features\Referral\ReferralLink;
use Tests\Support\AppFactory;
use Tests\Support\Auth;
use Tests\Support\Seed;
use Tests\Support\Shop;

/*
 | Aprobación del pago y recompensas del entrenador (reglas 1, 6, 15 y 17; specs negocio/01 y negocio/02).
 */

/** @return array{points:int, commission:float} Saldos del entrenador sumando lo guardado. */
function balancesOf(int $trainerId): array
{
    return [
        'points'     => (int) Shop::rows("SELECT COALESCE(SUM(remaining_points), 0) p FROM point_lots WHERE trainer_id = ? AND status = 'available' AND expires_at > NOW()", [$trainerId])[0]['p'],
        'commission' => (float) Shop::rows('SELECT COALESCE(SUM(amount), 0) c FROM commission_movements WHERE trainer_id = ?', [$trainerId])[0]['c'],
    ];
}

it('al aprobarse el pago, el entrenador recibe la comisión y los puntos guardados en el pedido', function (): void {
    [$client, $trainer] = Seed::clientWithTrainer();
    $code               = Seed::catalogProduct(['valor' => 249900]);
    $orderId            = Shop::order($client, [['code' => $code, 'quantity' => 1]]);

    expect(balancesOf($trainer))->toBe(['points' => 0, 'commission' => 0.0]);

    $webhook = Shop::pay($orderId);
    $order   = Shop::orderRow($orderId);
    $lot     = Shop::rows('SELECT source, initial_points, remaining_points, status, order_id, TIMESTAMPDIFF(MONTH, accrued_at, expires_at) AS months FROM point_lots WHERE trainer_id = ?', [$trainer])[0];
    $movement = Shop::rows('SELECT type, amount, tax_base, order_id FROM commission_movements WHERE trainer_id = ?', [$trainer])[0];

    expect($webhook)->toMatchArray(['ok' => true, 'processed' => true])
        ->and($order['status_value'])->toBe('APROBADA')
        ->and($order['approved_at'])->not->toBeNull()
        ->and($order['payment_reference'])->toStartWith('bold:')
        // 224.910 pagados: 11.246 puntos (5 %) y 28.350 de comisión (15 % de la base de 189.000).
        ->and(balancesOf($trainer))->toBe(['points' => 11246, 'commission' => 28350.0])
        ->and($lot)->toBe(['source' => 'client_sale', 'initial_points' => 11246, 'remaining_points' => 11246, 'status' => 'available', 'order_id' => $orderId, 'months' => (int) $_ENV['POINTS_EXPIRATION_MONTHS']])
        ->and($movement)->toBe(['type' => 'sale_credit', 'amount' => '28350.00', 'tax_base' => '189000.00', 'order_id' => $orderId])
        ->and(Shop::rows("SELECT type, points FROM point_movements WHERE order_id = ?", [$orderId]))->toBe([['type' => 'credit', 'points' => 11246]]);
});

it('repetir el aviso de pago no acredita dos veces', function (): void {
    [$client, $trainer] = Seed::clientWithTrainer();
    $orderId            = Shop::order($client, [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 1]]);

    Shop::pay($orderId);
    $after  = balancesOf($trainer);
    $second = Shop::pay($orderId);

    expect($second)->toMatchArray(['processed' => false, 'reason' => 'already_approved'])
        ->and(balancesOf($trainer))->toBe($after)
        ->and(Shop::rows('SELECT COUNT(*) n FROM point_lots WHERE trainer_id = ?', [$trainer])[0]['n'])->toBe(1)
        ->and(Shop::rows('SELECT COUNT(*) n FROM commission_movements WHERE trainer_id = ?', [$trainer])[0]['n'])->toBe(1);
});

it('un pago por menos de lo que vale el pedido no lo aprueba ni da recompensas', function (): void {
    [$client, $trainer] = Seed::clientWithTrainer();
    $orderId            = Shop::order($client, [['code' => Seed::catalogProduct(['valor' => 249900]), 'quantity' => 1]]);

    $webhook = Shop::boldWebhook($orderId, 1000);

    expect($webhook)->toMatchArray(['processed' => false, 'reason' => 'amount_mismatch'])
        ->and(Shop::orderRow($orderId)['approved_at'])->toBeNull()
        ->and(Shop::orderRow($orderId)['status_value'])->not->toBe('APROBADA')
        ->and(balancesOf($trainer))->toBe(['points' => 0, 'commission' => 0.0]);

    // El pago correcto sí lo aprueba después.
    expect(Shop::pay($orderId)['processed'])->toBeTrue();
});

it('un aviso con la firma equivocada no aprueba nada', function (): void {
    [$client, $trainer] = Seed::clientWithTrainer();
    $orderId            = Shop::order($client, [['code' => Seed::catalogProduct(), 'quantity' => 1]]);

    $webhook = Shop::boldWebhook($orderId, 999999999, validSignature: false);

    expect($webhook)->toMatchArray(['ok' => false, 'error' => 'invalid_signature'])
        ->and(Shop::orderRow($orderId)['approved_at'])->toBeNull()
        ->and(balancesOf($trainer))->toBe(['points' => 0, 'commission' => 0.0]);
});

it('un mismo pago de la pasarela no aprueba dos pedidos', function (): void {
    [$client, $trainer] = Seed::clientWithTrainer();
    $code               = Seed::catalogProduct(['valor' => 100000]);
    $first              = Shop::order($client, [['code' => $code, 'quantity' => 1]]);
    $second             = Shop::order($client, [['code' => $code, 'quantity' => 1]]);
    $amount             = (float) Shop::orderRow($first)['amount'];

    Shop::boldWebhook($first, $amount, 'PAY-REPETIDO-' . $first);
    $afterFirst = balancesOf($trainer);
    Shop::boldWebhook($second, $amount, 'PAY-REPETIDO-' . $first);

    expect(Shop::orderRow($second)['approved_at'])->toBeNull()
        ->and(balancesOf($trainer))->toBe($afterFirst);
});

it('la compra del entrenador y la del cliente sin entrenador no generan recompensas', function (): void {
    $trainer = Seed::trainer();
    $code    = Seed::catalogProduct(['valor' => 100000]);

    Shop::pay(Shop::order($trainer, [['code' => $code, 'quantity' => 1]]));
    Shop::pay(Shop::order(Seed::user(), [['code' => $code, 'quantity' => 1]]));

    expect(balancesOf($trainer))->toBe(['points' => 0, 'commission' => 0.0])
        ->and(Shop::rows('SELECT COUNT(*) n FROM push_notifications WHERE user_id = ?', [$trainer])[0]['n'])->toBe(0);
});

it('la venta queda con el entrenador que tenía el cliente al comprar', function (): void {
    [$client, $trainer] = Seed::clientWithTrainer();
    $other              = Seed::trainer();
    $orderId            = Shop::order($client, [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 1]]);

    // El call center cambia al cliente de entrenador antes de que llegue el pago.
    Seed::pdo()->prepare('UPDATE client_referrals SET trainer_id = ? WHERE client_id = ?')->execute([$other, $client]);
    Shop::pay($orderId);

    expect(balancesOf($trainer)['points'])->toBe(4500)
        ->and(balancesOf($other))->toBe(['points' => 0, 'commission' => 0.0]);
});

it('cambiar las variables después no altera lo que el pedido guardó', function (): void {
    [$client, $trainer] = Seed::clientWithTrainer();
    $orderId            = Shop::order($client, [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 1]]);

    $original = [$_ENV['CLIENT_PURCHASE_POINTS_PERCENTAGE'], $_ENV['TRAINER_COMMISSION_PERCENTAGE']];
    [$_ENV['CLIENT_PURCHASE_POINTS_PERCENTAGE'], $_ENV['TRAINER_COMMISSION_PERCENTAGE']] = ['50', '90'];

    try {
        Shop::pay($orderId);
    } finally {
        [$_ENV['CLIENT_PURCHASE_POINTS_PERCENTAGE'], $_ENV['TRAINER_COMMISSION_PERCENTAGE']] = $original;
    }

    // 90.000 pagados: 4.500 puntos (5 %) y 15 % de la base de 75.630,25.
    expect(balancesOf($trainer))->toBe(['points' => 4500, 'commission' => 11344.54]);
});

it('deja los avisos al entrenador en la cola de notificaciones, una sola vez', function (): void {
    [$client, $trainer] = Seed::clientWithTrainer();
    $orderId            = Shop::order($client, [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 1]]);

    Shop::pay($orderId);
    Shop::pay($orderId);

    $queued = Shop::rows('SELECT type, status, app FROM push_notifications WHERE user_id = ? ORDER BY type', [$trainer]);

    expect($queued)->toBe([
        ['type' => 'commission_earned', 'status' => 'pending', 'app' => 'web'],
        ['type' => 'points_earned', 'status' => 'pending', 'app' => 'web'],
    ]);
});

// ── Puntos por referido A → B (regla 15) ──────────────────────────────────

it('los puntos por referido se activan con la primera compra aprobada de un cliente de B, una sola vez', function (): void {
    $a = Seed::trainer();
    $b = AppFactory::json(Auth::registerAndVerify(['role' => 'trainer'], ['kind' => 't', 'id' => ReferralLink::encodeId($a)]))['user']['id'];
    [$clientOfB] = Seed::clientWithTrainer($b);
    $code        = Seed::catalogProduct(['valor' => 100000]);

    // Pendiente: registrarse no da puntos.
    expect(balancesOf($a)['points'])->toBe(0);

    // Un pedido sin pagar tampoco. Dos unidades: 180.000 pagados, por encima del mínimo de 149.000.
    $first = Shop::order($clientOfB, [['code' => $code, 'quantity' => 2]]);
    expect(balancesOf($a)['points'])->toBe(0);

    Shop::pay($first);
    $referral = Shop::rows('SELECT activated_at, activation_order_id FROM trainer_referrals WHERE referred_id = ?', [$b])[0];
    $lot      = Shop::rows("SELECT source, initial_points, TIMESTAMPDIFF(MONTH, accrued_at, expires_at) AS months FROM point_lots WHERE trainer_id = ?", [$a])[0];

    expect($referral['activated_at'])->not->toBeNull()
        ->and($referral['activation_order_id'])->toBe($first)
        ->and($lot)->toBe(['source' => 'trainer_referral', 'initial_points' => (int) $_ENV['TRAINER_REFERRAL_POINTS'], 'months' => (int) $_ENV['POINTS_EXPIRATION_MONTHS']])
        ->and(balancesOf($a))->toBe(['points' => (int) $_ENV['TRAINER_REFERRAL_POINTS'], 'commission' => 0.0])
        // B recibe lo suyo por la venta, no los puntos del referido.
        ->and(balancesOf($b)['points'])->toBe(9000);

    // La segunda compra de un cliente de B no vuelve a dar puntos a A.
    Shop::pay(Shop::order($clientOfB, [['code' => $code, 'quantity' => 1]]));

    expect(balancesOf($a)['points'])->toBe((int) $_ENV['TRAINER_REFERRAL_POINTS'])
        ->and(Shop::rows('SELECT COUNT(*) n FROM point_lots WHERE trainer_id = ?', [$a])[0]['n'])->toBe(1);
});

it('A recibe los puntos que valía el referido cuando B se registró, aunque la variable cambie', function (): void {
    $a = Seed::trainer();
    $b = AppFactory::json(Auth::registerAndVerify(['role' => 'trainer'], ['kind' => 't', 'id' => ReferralLink::encodeId($a)]))['user']['id'];
    [$clientOfB] = Seed::clientWithTrainer($b);
    $atRegistration = (int) $_ENV['TRAINER_REFERRAL_POINTS'];
    $orderId        = Shop::order($clientOfB, [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 2]]);

    $_ENV['TRAINER_REFERRAL_POINTS'] = '1';

    try {
        Shop::pay($orderId);
    } finally {
        $_ENV['TRAINER_REFERRAL_POINTS'] = (string) $atRegistration;
    }

    expect(balancesOf($a)['points'])->toBe($atRegistration);
});

it('una compra de un cliente de B por debajo del mínimo no activa los puntos de A; la siguiente que llega sí (D-05)', function (): void {
    $a = Seed::trainer();
    $b = AppFactory::json(Auth::registerAndVerify(['role' => 'trainer'], ['kind' => 't', 'id' => ReferralLink::encodeId($a)]))['user']['id'];
    [$clientOfB] = Seed::clientWithTrainer($b);
    $code        = Seed::catalogProduct(['valor' => 100000]);
    $minimum     = (int) $_ENV['TRAINER_REFERRAL_MIN_PURCHASE'];

    // 90.000 en productos: B gana lo suyo, pero A sigue pendiente.
    $small = Shop::order($clientOfB, [['code' => $code, 'quantity' => 1]]);
    Shop::pay($small);
    expect($minimum)->toBe(149000)
        ->and(balancesOf($a)['points'])->toBe(0)
        ->and(balancesOf($b)['points'])->toBe(4500)
        ->and(Shop::rows('SELECT activated_at FROM trainer_referrals WHERE referred_id = ?', [$b])[0]['activated_at'])->toBeNull();

    // El flete no cuenta: 144.000 en productos más el flete pasan de 149.000, pero los productos no llegan.
    $withFreight = Shop::order($clientOfB, [['code' => Seed::catalogProduct(['valor' => 160000]), 'quantity' => 1]]);
    $row         = Shop::orderRow($withFreight);
    Shop::pay($withFreight);
    expect((float) $row['amount'])->toBeGreaterThan(149000.0)
        ->and((float) $row['amount'] - (float) $row['shipping_amount'])->toBe(144000.0)
        ->and(Shop::orderRow($withFreight)['status_value'])->toBe('APROBADA')
        ->and(balancesOf($a)['points'])->toBe(0);

    // 180.000 en productos: ahora sí.
    $enough = Shop::order($clientOfB, [['code' => $code, 'quantity' => 2]]);
    Shop::pay($enough);
    expect(balancesOf($a)['points'])->toBe((int) $_ENV['TRAINER_REFERRAL_POINTS'])
        ->and(Shop::rows('SELECT activation_order_id FROM trainer_referrals WHERE referred_id = ?', [$b])[0]['activation_order_id'])->toBe($enough);
});

it('la compra propia de B no activa los puntos de A', function (): void {
    $a = Seed::trainer();
    $b = AppFactory::json(Auth::registerAndVerify(['role' => 'trainer'], ['kind' => 't', 'id' => ReferralLink::encodeId($a)]))['user']['id'];

    Shop::pay(Shop::order($b, [['code' => Seed::catalogProduct(), 'quantity' => 1]]));

    expect(balancesOf($a)['points'])->toBe(0)
        ->and(Shop::rows('SELECT activated_at FROM trainer_referrals WHERE referred_id = ?', [$b])[0]['activated_at'])->toBeNull();
});

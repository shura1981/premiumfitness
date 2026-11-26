<?php

declare(strict_types=1);

use App\Features\Commission\MonthlyCloseService;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\Support\AppFactory;
use Tests\Support\JwtFactory;
use Tests\Support\Seed;
use Tests\Support\Shop;

/*
 | Admin: entrenadores, estrategias por producto, pago de la comisión con comprobante y ajustes
 | (specs negocio/01, negocio/04, negocio/05 y RF-17).
 */

function admin(string $method, string $path, ?array $body = null): Psr\Http\Message\ResponseInterface
{
    return AppFactory::dispatch(AppFactory::request($method, '/api/admin/' . $path, $body, JwtFactory::adminHeader()));
}

/** Liquidación cerrada de un entrenador, en un mes pasado libre. Devuelve [entrenador, liquidación, período]. */
function closedSettlement(float $commission = 80000): array
{
    Seed::pdo()->exec('DELETE FROM commission_movements WHERE settlement_id IS NULL AND created_at < DATE_FORMAT(NOW(), "%Y-%m-01")');
    $used = array_flip(array_column(Shop::rows('SELECT period FROM commission_periods'), 'period'));
    for ($date = new DateTimeImmutable('1971-01-01'); isset($used[$date->format('Y-m')]); $date = $date->modify('+1 month'));
    $period  = $date->format('Y-m');
    $trainer = Seed::trainer();
    Seed::commission($trainer, $commission, $period . '-10 10:00:00');
    $result = new MonthlyCloseService()->close($period);
    $id     = (int) Shop::rows('SELECT id FROM commission_settlements WHERE trainer_id = ?', [$trainer])[0]['id'];

    return [$trainer, $id, $result['period_id']];
}

/** Petición multipart del pago, con un archivo de verdad. */
function payRequest(int $settlementId, string $contents, string $clientName, string $path = 'pay', array $fields = []): Psr\Http\Message\ResponseInterface
{
    $request = AppFactory::request('POST', "/api/admin/commission/settlements/{$settlementId}/{$path}", null, JwtFactory::adminHeader())
        ->withParsedBody($fields + ['payment_reference' => 'TRF-123456', 'paid_at' => date('Y-m-d')]);

    if ($contents !== '') {
        $request = $request->withUploadedFiles([
            'receipt' => new UploadedFile((new StreamFactory())->createStream($contents), $clientName, 'application/pdf', strlen($contents), UPLOAD_ERR_OK),
        ]);
    }

    return AppFactory::dispatch($request);
}

const PDF_BYTES = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
// PNG de 1 × 1.
const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

// ── Entrenadores ──────────────────────────────────────────────────────────

it('lista entrenadores con búsqueda, estado y paginación, y muestra el detalle', function (): void {
    $trainer  = Seed::trainer();
    [$client] = Seed::clientWithTrainer($trainer);
    Seed::pointLot($trainer, 12345);
    Seed::commission($trainer, 6789);
    $phone = Seed::phoneOf($trainer);

    $list   = AppFactory::json(admin('GET', 'trainers?search=' . $phone . '&status=active&page=1&per_page=5'));
    $none   = AppFactory::json(admin('GET', 'trainers?search=' . $phone . '&status=suspended'));
    $detail = AppFactory::json(admin('GET', 'trainers/' . $trainer));

    expect($list)->toMatchArray(['total' => 1, 'page' => 1, 'per_page' => 5])
        ->and($list['data'][0])->toMatchArray(['id' => $trainer, 'phone' => $phone, 'status' => 'active', 'clients_count' => 1, 'points_available' => 12345, 'commission_balance' => 6789])
        ->and($none['total'])->toBe(0)
        ->and($detail['points'])->toMatchArray(['available' => 12345])
        ->and($detail['clients'][0])->toMatchArray(['id' => $client, 'orders_count' => 0])
        ->and($detail)->toHaveKeys(['referrer', 'referred_trainers', 'movements', 'settlements', 'status_reason'])
        ->and(json_encode($detail))->not->toContain('argon2');
});

it('suspende a un entrenador con motivo: cierra sus sesiones, no puede entrar y queda auditado', function (): void {
    $trainer = Seed::trainer();
    $phone   = Seed::phoneOf($trainer);
    $session = AppFactory::cookie(\Tests\Support\Auth::login($phone), 'pf_rt_web');

    $noReason = admin('PATCH', "trainers/{$trainer}/status", ['status' => 'suspended', 'reason' => '']);
    $suspend  = admin('PATCH', "trainers/{$trainer}/status", ['status' => 'suspended', 'reason' => 'Fraude reportado por el call center']);
    $audit    = Shop::rows("SELECT agent_id, data FROM audit_logs WHERE entity = 'trainer' AND entity_id = ?", [$trainer]);

    expect($noReason->getStatusCode())->toBe(422)
        ->and($suspend->getStatusCode())->toBe(200)
        ->and(AppFactory::json($suspend)['status'])->toBe('suspended')
        ->and(\Tests\Support\Auth::post('/api/auth/refresh', refreshToken: $session)->getStatusCode())->toBe(401)
        ->and(\Tests\Support\Auth::login($phone)->getStatusCode())->toBe(403)
        ->and($audit)->toHaveCount(1)
        ->and($audit[0]['agent_id'])->toBe(9001)
        ->and(json_decode($audit[0]['data'], true))->toMatchArray(['from' => 'active', 'to' => 'suspended', 'reason' => 'Fraude reportado por el call center'])
        ->and(AppFactory::json(admin('GET', 'trainers/' . $trainer))['status_reason'])->toBe('Fraude reportado por el call center');

    admin('PATCH', "trainers/{$trainer}/status", ['status' => 'active', 'reason' => 'Caso revisado y cerrado']);
    expect(\Tests\Support\Auth::login($phone)->getStatusCode())->toBe(200);
});

it('el admin no activa una cuenta que no ha verificado su celular', function (): void {
    $trainer = Seed::trainer();
    Seed::pdo()->prepare("UPDATE users SET status = 'pending' WHERE id = ?")->execute([$trainer]);

    $response = admin('PATCH', "trainers/{$trainer}/status", ['status' => 'active', 'reason' => 'Activar a mano']);

    expect($response->getStatusCode())->toBe(409)
        ->and(Shop::rows('SELECT status FROM users WHERE id = ?', [$trainer])[0]['status'])->toBe('pending');
});

// ── Estrategias por producto ──────────────────────────────────────────────

it('crea, edita y desactiva una estrategia, y la tienda la aplica de inmediato', function (): void {
    $code     = Seed::catalogProduct(['valor' => 100000, 'producto' => 'PRODUCTO CON ESTRATEGIA']);
    [$client] = Seed::clientWithTrainer();
    $price    = fn (): int => array_column(AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/products?category=PRUEBAS', headers: JwtFactory::authHeader($client))))['data'], 'final_price', 'code')[$code];

    $create = admin('POST', 'discount-rules', ['product_sku' => $code, 'client_discount_percentage' => 30, 'trainer_discount_percentage' => null, 'commission_percentage' => '', 'valid_from' => date('Y-m-d', strtotime('-1 day')), 'valid_to' => null]);
    $rule   = AppFactory::json($create);

    expect($create->getStatusCode())->toBe(201)
        ->and($rule)->toMatchArray(['product_sku' => $code, 'product_name' => 'PRODUCTO CON ESTRATEGIA', 'client_discount_percentage' => 30, 'trainer_discount_percentage' => null, 'commission_percentage' => null, 'is_active' => true])
        ->and($price())->toBe(70000);

    admin('PUT', 'discount-rules/' . $rule['id'], ['product_sku' => $code, 'client_discount_percentage' => 40, 'valid_from' => date('Y-m-d', strtotime('-1 day'))]);
    expect($price())->toBe(60000);

    $delete = admin('DELETE', 'discount-rules/' . $rule['id']);
    $listed = array_column(AppFactory::json(admin('GET', 'discount-rules?active=0'))['data'], 'is_active', 'id');

    expect($delete->getStatusCode())->toBe(204)
        ->and($price())->toBe(90000)
        // Desactivar no borra el histórico.
        ->and($listed[$rule['id']])->toBeFalse()
        ->and(admin('PUT', 'discount-rules/' . $rule['id'], ['product_sku' => $code, 'client_discount_percentage' => 5, 'valid_from' => date('Y-m-d')])->getStatusCode())->toBe(409)
        ->and(Shop::rows("SELECT action FROM audit_logs WHERE entity = 'product_discount_rule' AND entity_id = ? ORDER BY id", [$rule['id']]))
        ->toBe([['action' => 'discount_rule.create'], ['action' => 'discount_rule.update'], ['action' => 'discount_rule.deactivate']]);
});

it('rechaza una estrategia inválida', function (array $changes, string $field): void {
    $code     = Seed::catalogProduct();
    $response = admin('POST', 'discount-rules', $changes + ['product_sku' => $code, 'client_discount_percentage' => 10, 'valid_from' => date('Y-m-d')]);

    expect($response->getStatusCode())->toBe(422)
        ->and(AppFactory::json($response)['errors'])->toHaveKey($field);
})->with([
    'porcentaje mayor que 100'   => [['client_discount_percentage' => 101], 'client_discount_percentage'],
    'porcentaje negativo'        => [['commission_percentage' => -1], 'commission_percentage'],
    'ningún porcentaje'          => [['client_discount_percentage' => null], 'client_discount_percentage'],
    'producto que no se vende'   => [['product_sku' => 999999999], 'product_sku'],
    'fin antes del inicio'       => [['valid_to' => '2000-01-01'], 'valid_to'],
]);

it('busca productos del catálogo y da los valores por defecto', function (): void {
    $code     = Seed::catalogProduct(['producto' => 'BUSCABLE ' . random_int(10000, 99999)]);
    $products = AppFactory::json(admin('GET', 'products?search=' . $code))['data'];
    $settings = AppFactory::json(admin('GET', 'settings/business'));

    expect(array_column($products, 'code'))->toBe([$code])
        ->and(array_keys($products[0]))->toBe(['code', 'name', 'presentation', 'price'])
        ->and($settings)->toEqual([
            'client_discount_percentage' => (float) $_ENV['CLIENT_DISCOUNT_PERCENTAGE'], 'trainer_discount_percentage' => (float) $_ENV['TRAINER_DISCOUNT_PERCENTAGE'],
            'commission_percentage' => (float) $_ENV['TRAINER_COMMISSION_PERCENTAGE'], 'client_purchase_points_percentage' => (float) $_ENV['CLIENT_PURCHASE_POINTS_PERCENTAGE'],
        ]);
});

// ── Cierre mensual y pago ─────────────────────────────────────────────────

it('muestra el cierre con sus liquidaciones y descarga el Excel solo con sesión del admin', function (): void {
    [$trainer, $settlementId, $periodId] = closedSettlement(80000);

    $detail = AppFactory::json(admin('GET', 'commission/periods/' . $periodId));
    $report = admin('GET', "commission/periods/{$periodId}/report");

    expect(array_column(AppFactory::json(admin('GET', 'commission/periods'))['data'], 'id'))->toContain($periodId)
        ->and($detail)->toMatchArray(['trainers_count' => 1, 'total_amount' => 80000, 'paid_count' => 0, 'paid_amount' => 0])
        ->and($detail['settlements'][0])->toMatchArray(['id' => $settlementId, 'trainer_id' => $trainer, 'total_amount' => 80000, 'status' => 'closed', 'is_overdue' => true, 'has_receipt' => false, 'paid_at' => null])
        ->and($report->getStatusCode())->toBe(200)
        ->and($report->getHeaderLine('Content-Type'))->toContain('spreadsheetml')
        ->and(substr((string) $report->getBody(), 0, 2))->toBe('PK')
        ->and(AppFactory::dispatch(AppFactory::request('GET', "/api/admin/commission/periods/{$periodId}/report", headers: JwtFactory::authHeader($trainer)))->getStatusCode())->toBe(403);
});

it('registra el pago con su comprobante: queda pagada, auditada y el entrenador ve el comprobante', function (): void {
    [$trainer, $settlementId] = closedSettlement(80000);

    $pay   = payRequest($settlementId, PDF_BYTES, 'comprobante.pdf');
    $body  = AppFactory::json($pay);
    $again = payRequest($settlementId, PDF_BYTES, 'otro.pdf');

    $adminReceipt   = admin('GET', "commission/settlements/{$settlementId}/receipt");
    $trainerReceipt = AppFactory::dispatch(AppFactory::request('GET', "/api/trainer/commission/settlements/{$settlementId}/receipt", headers: JwtFactory::authHeader($trainer)));
    $otherTrainer   = AppFactory::dispatch(AppFactory::request('GET', "/api/trainer/commission/settlements/{$settlementId}/receipt", headers: JwtFactory::authHeader(Seed::trainer())));
    $view           = AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/trainer/commission', headers: JwtFactory::authHeader($trainer))))['settlements'][0];

    expect($pay->getStatusCode())->toBe(200)
        ->and($body)->toMatchArray(['status' => 'paid', 'paid_at' => date('Y-m-d'), 'payment_reference' => 'TRF-123456', 'has_receipt' => true, 'is_overdue' => false])
        ->and($body['paid_by'])->not->toBeNull()
        ->and($again->getStatusCode())->toBe(409)
        ->and(AppFactory::json($again)['code'])->toBe('already_paid')
        ->and((string) $adminReceipt->getBody())->toBe(PDF_BYTES)
        ->and($adminReceipt->getHeaderLine('Content-Type'))->toBe('application/pdf')
        ->and($adminReceipt->getHeaderLine('X-Content-Type-Options'))->toBe('nosniff')
        ->and((string) $trainerReceipt->getBody())->toBe(PDF_BYTES)
        // El comprobante de otro entrenador «no existe».
        ->and($otherTrainer->getStatusCode())->toBe(404)
        ->and($view)->toMatchArray(['status' => 'pagada', 'receipt_url' => "/trainer/commission/settlements/{$settlementId}/receipt"])
        ->and(Shop::rows("SELECT action FROM audit_logs WHERE entity = 'commission_settlement' AND entity_id = ?", [$settlementId]))->toBe([['action' => 'commission.paid']])
        ->and(Shop::rows("SELECT COUNT(*) n FROM push_notifications WHERE user_id = ? AND type = 'commission_paid'", [$trainer])[0]['n'])->toBe(1);

    // El archivo queda fuera de la carpeta pública, con un nombre que pone el servidor.
    $stored = (string) Shop::rows('SELECT receipt_path FROM commission_settlements WHERE id = ?', [$settlementId])[0]['receipt_path'];
    expect($stored)->toMatch('/^liquidacion-\d+-[a-f0-9]{16}\.pdf$/')
        ->and(is_file(__DIR__ . '/../../../storage/private/receipts/' . $stored))->toBeTrue();
});

it('decide el tipo del comprobante por su contenido, no por el nombre', function (string $contents, string $name, int $status): void {
    [, $settlementId] = closedSettlement();

    $response = payRequest($settlementId, $contents, $name);

    expect($response->getStatusCode())->toBe($status)
        ->and(Shop::rows('SELECT status FROM commission_settlements WHERE id = ?', [$settlementId])[0]['status'])->toBe($status === 200 ? 'paid' : 'closed');
})->with([
    'PNG con nombre .pdf: se acepta como imagen'  => [fn (): string => base64_decode(PNG_BASE64), 'captura.pdf', 200],
    'PHP con nombre .pdf'                        => ["<?php system(\$_GET['c']); ?>", 'comprobante.pdf', 422],
    'HTML con nombre .png'                       => ['<html><script>alert(1)</script></html>', 'foto.png', 422],
    'ejecutable con nombre .jpg'                 => ["MZ\x90\x00\x03\x00\x00\x00\x04\x00", 'foto.jpg', 422],
    'más de 5 MB'                                => [fn (): string => PDF_BYTES . str_repeat('0', 5 * 1024 * 1024), 'grande.pdf', 422],
    'sin archivo'                                => ['', 'nada.pdf', 422],
]);

it('exige referencia y una fecha que no sea futura', function (array $fields, string $field): void {
    [, $settlementId] = closedSettlement();

    $response = payRequest($settlementId, PDF_BYTES, 'c.pdf', fields: $fields);

    expect($response->getStatusCode())->toBe(422)
        ->and(AppFactory::json($response)['errors'])->toHaveKey($field);
})->with([
    [['payment_reference' => ''], 'payment_reference'],
    [['paid_at' => '2999-01-01'], 'paid_at'],
    [['paid_at' => 'ayer no sé'], 'paid_at'],
]);

it('corrige un pago mal registrado sin borrar el anterior, y lo deja auditado', function (): void {
    [, $settlementId] = closedSettlement();
    payRequest($settlementId, PDF_BYTES, 'a.pdf');
    $firstFile = (string) Shop::rows('SELECT receipt_path FROM commission_settlements WHERE id = ?', [$settlementId])[0]['receipt_path'];

    $notPaid    = payRequest(closedSettlement()[1], PDF_BYTES, 'a.pdf', 'payment');
    $onlyFields = payRequest($settlementId, '', '', 'payment', ['payment_reference' => 'TRF-CORREGIDA', 'paid_at' => date('Y-m-d', strtotime('-2 days'))]);
    $newFile    = payRequest($settlementId, base64_decode(PNG_BASE64), 'b.png', 'payment', ['payment_reference' => 'TRF-CORREGIDA']);
    $audit      = Shop::rows("SELECT action, data FROM audit_logs WHERE entity = 'commission_settlement' AND entity_id = ? ORDER BY id", [$settlementId]);

    expect($notPaid->getStatusCode())->toBe(409)
        ->and(AppFactory::json($onlyFields))->toMatchArray(['payment_reference' => 'TRF-CORREGIDA', 'paid_at' => date('Y-m-d', strtotime('-2 days')), 'has_receipt' => true])
        ->and($newFile->getStatusCode())->toBe(200)
        ->and(admin('GET', "commission/settlements/{$settlementId}/receipt")->getHeaderLine('Content-Type'))->toBe('image/png')
        ->and(array_column($audit, 'action'))->toBe(['commission.paid', 'commission.payment_corrected', 'commission.payment_corrected'])
        ->and(json_decode($audit[1]['data'], true)['previous']['payment_reference'])->toBe('TRF-123456')
        ->and(is_file(__DIR__ . '/../../../storage/private/receipts/' . $firstFile))->toBeTrue();
});

// ── Ajustes ───────────────────────────────────────────────────────────────

/** Compra aprobada de un cliente con entrenador: 2 unidades de 100.000. Devuelve [pedido, entrenador]. */
function approvedPurchase(): array
{
    [$client, $trainer] = Seed::clientWithTrainer();
    $orderId            = Shop::order($client, [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 2]]);
    Shop::pay($orderId);

    return [$orderId, $trainer];
}

function trainerBalances(int $trainerId): array
{
    return [
        (int) Shop::rows("SELECT COALESCE(SUM(remaining_points), 0) p FROM point_lots WHERE trainer_id = ? AND status = 'available'", [$trainerId])[0]['p'],
        (float) Shop::rows('SELECT COALESCE(SUM(amount), 0) c FROM commission_movements WHERE trainer_id = ?', [$trainerId])[0]['c'],
    ];
}

it('reversar un pedido pagado quita la comisión y los puntos del pedido, una sola vez', function (): void {
    [$orderId, $trainer] = approvedPurchase();
    // 180.000 pagados: 9.000 puntos y 15 % de la base de 151.260,50.
    expect(trainerBalances($trainer))->toBe([9000, 22689.08]);

    $before = AppFactory::json(admin('GET', "orders/{$orderId}/adjustments"));
    $create = admin('POST', "orders/{$orderId}/adjustments", ['type' => 'cancellation', 'reason' => 'El cliente pidió cancelar el pedido pagado']);
    $again  = admin('POST', "orders/{$orderId}/adjustments", ['type' => 'cancellation', 'reason' => 'El cliente pidió cancelar el pedido pagado']);

    expect($before)->toMatchArray(['data' => [], 'can_adjust' => true])
        ->and($before['preview'])->toMatchArray(['commission_amount' => -22689.08, 'points' => -9000])
        ->and($create->getStatusCode())->toBe(201)
        ->and(AppFactory::json($create))->toMatchArray(['type' => 'cancellation', 'effect' => ['commission_amount' => -22689.08, 'points' => -9000, 'trainer_name' => AppFactory::json($create)['effect']['trainer_name']]])
        ->and(trainerBalances($trainer))->toBe([0, 0.0])
        ->and($again->getStatusCode())->toBe(409)
        ->and(AppFactory::json($again)['code'])->toBe('already_adjusted')
        ->and(Shop::orderRow($orderId)['status_value'])->toBe('REVERSADA')
        // El cliente lo ve cancelado.
        ->and(AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/orders/' . $orderId, headers: JwtFactory::authHeader((int) Shop::orderRow($orderId)['user_id']))))['status'])->toBe('cancelado')
        // Los movimientos originales siguen ahí: el ajuste no borra nada.
        ->and(Shop::rows('SELECT type, amount FROM commission_movements WHERE trainer_id = ? ORDER BY id', [$trainer]))->toBe([
            ['type' => 'sale_credit', 'amount' => '22689.08'], ['type' => 'adjustment', 'amount' => '-22689.08'],
        ])
        ->and(Shop::rows("SELECT action, agent_id FROM audit_logs WHERE entity = 'order' AND entity_id = ?", [$orderId]))->toBe([['action' => 'order.adjustment', 'agent_id' => 9001]]);
});

it('no hay devoluciones: el único ajuste es reversar el pedido completo', function (string $type): void {
    [$orderId, $trainer] = approvedPurchase();
    $itemId              = (int) Shop::rows('SELECT item_id FROM orders_items WHERE order_id = ?', [$orderId])[0]['item_id'];

    $response = admin('POST', "orders/{$orderId}/adjustments", ['type' => $type, 'reason' => 'Devolvió una unidad', 'items' => [['item_id' => $itemId, 'quantity' => 1]]]);

    expect($response->getStatusCode())->toBe(422)
        ->and(trainerBalances($trainer))->toBe([9000, 22689.08])
        ->and(Shop::orderRow($orderId)['status_value'])->toBe('APROBADA');
})->with(['full_return', 'partial_return']);

it('retira los puntos completos: lo que queda en el lote del pedido y lo ya usado de sus otros lotes, por FIFO (P-17)', function (): void {
    [$orderId, $trainer] = approvedPurchase();
    // De los 9.000 puntos del pedido ya usó 7.000: quedan 2.000 en su lote.
    Seed::pdo()->prepare('UPDATE point_lots SET remaining_points = 2000 WHERE order_id = ?')->execute([$orderId]);
    $older = Seed::pointLot($trainer, 5000, accruedAt: '-2 months');
    $newer = Seed::pointLot($trainer, 50000, accruedAt: '+1 minute');

    $preview = AppFactory::json(admin('GET', "orders/{$orderId}/adjustments"))['preview'];
    $create  = AppFactory::json(admin('POST', "orders/{$orderId}/adjustments", ['type' => 'cancellation', 'reason' => 'Pedido cancelado por el cliente']));
    $lots    = Shop::rows('SELECT id, remaining_points FROM point_lots WHERE trainer_id = ? ORDER BY id', [$trainer]);

    expect($preview['points'])->toBe(-9000)
        ->and($create['effect']['points'])->toBe(-9000)
        // 2.000 del lote del pedido; los 7.000 que faltan, primero del lote más antiguo (5.000) y luego del siguiente.
        ->and(array_column($lots, 'remaining_points', 'id'))->toBe([
            (int) Shop::rows('SELECT id FROM point_lots WHERE order_id = ?', [$orderId])[0]['id'] => 0, $older => 0, $newer => 48000,
        ])
        ->and(trainerBalances($trainer)[0])->toBe(48000)
        ->and(Shop::orderRow($orderId)['status_value'])->toBe('REVERSADA')
        // El cliente lo ve cancelado.
        ->and(AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/orders/' . $orderId, headers: JwtFactory::authHeader((int) Shop::orderRow($orderId)['user_id']))))['status'])->toBe('cancelado');
});

it('si no le alcanzan los puntos, retira los que tenga y no queda deuda (P-17)', function (): void {
    [$orderId, $trainer] = approvedPurchase();
    // Ya usó 7.000 de los 9.000 y no tiene más puntos: solo quedan 2.000.
    Seed::pdo()->prepare('UPDATE point_lots SET remaining_points = 2000 WHERE order_id = ?')->execute([$orderId]);
    // Un lote vencido que el cron aún no marcó no cuenta.
    Seed::pointLot($trainer, 30000, expiresAt: '-1 day', accruedAt: '-7 months');

    $create = admin('POST', "orders/{$orderId}/adjustments", ['type' => 'cancellation', 'reason' => 'Pedido cancelado por el cliente']);

    expect($create->getStatusCode())->toBe(201)
        ->and(AppFactory::json($create)['effect']['points'])->toBe(-2000)
        ->and(Shop::rows('SELECT COALESCE(SUM(remaining_points), 0) p FROM point_lots WHERE trainer_id = ? AND expires_at > NOW()', [$trainer])[0]['p'])->toEqual(0)
        ->and(Shop::rows('SELECT remaining_points FROM point_lots WHERE trainer_id = ? AND expires_at < NOW()', [$trainer])[0]['remaining_points'])->toBe(30000)
        ->and(Shop::rows('SELECT MIN(remaining_points) m FROM point_lots WHERE trainer_id = ?', [$trainer])[0]['m'])->toEqual(0);
});

// ── Referido activado por la venta reversada (spec negocio/02, S-06) ───────

/** A refirió a B; un cliente de B compra y su pago aprobado activa los puntos de A. Devuelve [pedido, A, B, cliente de B]. */
function referralActivatedBySale(): array
{
    $a = Seed::trainer();
    $b = Seed::trainer();
    Seed::pdo()->prepare('INSERT INTO trainer_referrals (referrer_id, referred_id, referral_points) VALUES (?, ?, 50000)')->execute([$a, $b]);
    [$client] = Seed::clientWithTrainer($b);
    $orderId  = Shop::order($client, [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 2]]);
    Shop::pay($orderId);

    return [$orderId, $a, $b, $client];
}

/** @return array{status:string, points:int, remaining_points:int} El referido B en la pantalla de A. */
function referralSeenBy(int $a, int $b): array
{
    $trainers = AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/trainer/referrals', headers: JwtFactory::authHeader($a, 'trainer'))))['trainers'];
    $rows     = array_values(array_filter($trainers, static fn (array $t): bool => $t['id'] === $b));

    expect($rows)->toHaveCount(1);

    return ['status' => $rows[0]['status'], 'points' => $rows[0]['points'], 'remaining_points' => $rows[0]['remaining_points']];
}

it('reversar la venta que activó el referido le retira a A los 50.000 y el referido vuelve a pendiente; la próxima venta lo activa otra vez', function (): void {
    [$orderId, $a, $b, $client] = referralActivatedBySale();
    expect(trainerBalances($a)[0])->toBe(50000)
        ->and(referralSeenBy($a, $b)['status'])->toBe('activo');

    $create = AppFactory::json(admin('POST', "orders/{$orderId}/adjustments", ['type' => 'cancellation', 'reason' => 'El cliente pidió cancelar']));
    $referral = Shop::rows('SELECT activated_at, activation_order_id FROM trainer_referrals WHERE referred_id = ?', [$b])[0];

    expect(trainerBalances($a)[0])->toBe(0)
        ->and($referral)->toBe(['activated_at' => null, 'activation_order_id' => null])
        ->and(referralSeenBy($a, $b))->toBe(['status' => 'pendiente', 'points' => 50000, 'remaining_points' => 0])
        // El efecto del ajuste es el de B (9.000 puntos de su venta); los de A van aparte.
        ->and($create['effect']['points'])->toBe(-9000)
        ->and(AppFactory::json(admin('GET', "orders/{$orderId}/adjustments"))['data'][0]['effect']['points'])->toBe(-9000)
        ->and(Shop::rows("SELECT type FROM push_notifications WHERE user_id = ? AND type = 'referral_reverted'", [$a]))->toHaveCount(1)
        ->and(json_decode(Shop::rows("SELECT data FROM audit_logs WHERE entity = 'order' AND entity_id = ?", [$orderId])[0]['data'], true)['effect']['referral_points'])->toBe(-50000);

    // Otra venta aprobada de un cliente de B activa el referido otra vez, con un lote nuevo.
    $next = Shop::order($client, [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 2]]);
    Shop::pay($next);

    expect(trainerBalances($a)[0])->toBe(50000)
        ->and(Shop::rows('SELECT activation_order_id FROM trainer_referrals WHERE referred_id = ?', [$b])[0]['activation_order_id'])->toBe($next)
        ->and(Shop::rows("SELECT initial_points, remaining_points, status FROM point_lots WHERE trainer_id = ? AND source = 'trainer_referral' ORDER BY id", [$a]))->toBe([
            ['initial_points' => 50000, 'remaining_points' => 0, 'status' => 'used'],
            ['initial_points' => 50000, 'remaining_points' => 50000, 'status' => 'available'],
        ])
        ->and(referralSeenBy($a, $b))->toBe(['status' => 'activo', 'points' => 50000, 'remaining_points' => 50000]);
});

it('si A ya usó parte de los 50.000, se le retiran completos de sus otros puntos y el referido vuelve a pendiente (P-17)', function (): void {
    [$orderId, $a, $b, $client] = referralActivatedBySale();
    // A usó 20.000 de los 50.000 y tiene otros 40.000 puntos.
    Seed::pdo()->prepare("UPDATE point_lots SET remaining_points = 30000 WHERE trainer_id = ? AND source = 'trainer_referral'")->execute([$a]);
    $other = Seed::pointLot($a, 40000, accruedAt: '+1 minute');

    admin('POST', "orders/{$orderId}/adjustments", ['type' => 'cancellation', 'reason' => 'El cliente pidió cancelar']);

    // 30.000 del lote del referido y 20.000 de los otros: le quedan 20.000.
    expect(trainerBalances($a)[0])->toBe(20000)
        ->and(Shop::rows('SELECT remaining_points FROM point_lots WHERE id = ?', [$other])[0]['remaining_points'])->toBe(20000)
        ->and(Shop::rows('SELECT activation_order_id FROM trainer_referrals WHERE referred_id = ?', [$b])[0]['activation_order_id'])->toBeNull()
        ->and(referralSeenBy($a, $b)['status'])->toBe('pendiente')
        ->and(json_decode(Shop::rows("SELECT data FROM audit_logs WHERE entity = 'order' AND entity_id = ?", [$orderId])[0]['data'], true)['effect']['referral_points'])->toBe(-50000);

    // Otra venta de un cliente de B le da los 50.000 una vez más: nunca dos veces por el mismo referido.
    Shop::pay(Shop::order($client, [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 2]]));

    expect(trainerBalances($a)[0])->toBe(70000)
        ->and(referralSeenBy($a, $b))->toBe(['status' => 'activo', 'points' => 50000, 'remaining_points' => 50000]);
});

it('si A no tiene puntos suficientes, se le retira lo que tenga, no queda deuda y el referido vuelve a pendiente (P-17)', function (): void {
    [$orderId, $a, $b] = referralActivatedBySale();
    // A usó 45.000 de los 50.000 y no tiene más puntos.
    Seed::pdo()->prepare("UPDATE point_lots SET remaining_points = 5000 WHERE trainer_id = ? AND source = 'trainer_referral'")->execute([$a]);

    admin('POST', "orders/{$orderId}/adjustments", ['type' => 'cancellation', 'reason' => 'El cliente pidió cancelar']);

    expect(trainerBalances($a)[0])->toBe(0)
        ->and(Shop::rows('SELECT MIN(remaining_points) m FROM point_lots WHERE trainer_id = ?', [$a])[0]['m'])->toEqual(0)
        ->and(referralSeenBy($a, $b)['status'])->toBe('pendiente')
        ->and(json_decode(Shop::rows("SELECT data FROM audit_logs WHERE entity = 'order' AND entity_id = ?", [$orderId])[0]['data'], true)['effect']['referral_points'])->toBe(-5000)
        ->and(Shop::orderRow($orderId)['status_value'])->toBe('REVERSADA');
});

// ── Bold anula un pago aprobado (P-28) ─────────────────────────────────────

it('si Bold anula el pago de un pedido aprobado, queda REVERSADA y se revierten comisión y puntos, sin agente', function (): void {
    [$orderId, $trainer] = approvedPurchase();
    expect(trainerBalances($trainer))->toBe([9000, 22689.08]);

    $void = Shop::boldWebhook($orderId, (float) Shop::orderRow($orderId)['amount'], type: 'VOID_APPROVED');

    expect($void)->toMatchArray(['processed' => true, 'order_id' => $orderId, 'status' => 'REVERSADA'])
        ->and(Shop::orderRow($orderId)['status_value'])->toBe('REVERSADA')
        ->and(trainerBalances($trainer))->toBe([0, 0.0])
        // Lo registró Bold: sin agente, y así se ve en el admin.
        ->and(Shop::rows('SELECT agent_id, type FROM adjustments WHERE order_id = ?', [$orderId]))->toBe([['agent_id' => null, 'type' => 'cancellation']])
        ->and(AppFactory::json(admin('GET', "orders/{$orderId}/adjustments"))['data'][0]['agent_name'])->toBe('Automático (Bold)')
        ->and(Shop::rows("SELECT agent_id, user_id, JSON_VALUE(data, '$.gateway') gateway FROM audit_logs WHERE entity = 'order' AND entity_id = ?", [$orderId]))
        ->toBe([['agent_id' => null, 'user_id' => null, 'gateway' => 'bold']]);
});

it('una anulación repetida de Bold no revierte dos veces', function (): void {
    [$orderId, $trainer] = approvedPurchase();
    $amount              = (float) Shop::orderRow($orderId)['amount'];

    Shop::boldWebhook($orderId, $amount, type: 'VOID_APPROVED');
    $again = Shop::boldWebhook($orderId, $amount, type: 'VOID_APPROVED');

    expect($again)->toMatchArray(['processed' => false])
        ->and(trainerBalances($trainer))->toBe([0, 0.0])
        ->and(Shop::rows('SELECT COUNT(*) n FROM adjustments WHERE order_id = ?', [$orderId])[0]['n'])->toBe(1)
        ->and(Shop::orderRow($orderId)['status_value'])->toBe('REVERSADA');
});

it('un aviso de la pasarela después de reversar no le cambia el estado al pedido', function (string $type): void {
    [$orderId] = approvedPurchase();
    admin('POST', "orders/{$orderId}/adjustments", ['type' => 'cancellation', 'reason' => 'El cliente pidió cancelar el pedido pagado']);

    $late = Shop::boldWebhook($orderId, (float) Shop::orderRow($orderId)['amount'], type: $type);

    expect($late)->toMatchArray(['processed' => false])
        ->and(Shop::orderRow($orderId)['status_value'])->toBe('REVERSADA');
})->with(['SALE_REJECTED', 'SALE_APPROVED']);

it('si un gerente ya reversó el pedido, la anulación de Bold no revierte otra vez', function (): void {
    [$orderId, $trainer] = approvedPurchase();
    admin('POST', "orders/{$orderId}/adjustments", ['type' => 'cancellation', 'reason' => 'El cliente pidió cancelar el pedido pagado']);

    $void = Shop::boldWebhook($orderId, (float) Shop::orderRow($orderId)['amount'], type: 'VOID_APPROVED');

    expect($void)->toMatchArray(['processed' => false])
        ->and(trainerBalances($trainer))->toBe([0, 0.0])
        ->and(Shop::rows('SELECT COUNT(*) n FROM adjustments WHERE order_id = ?', [$orderId])[0]['n'])->toBe(1);
});

it('la anulación de un pago que nunca se aprobó lo deja rechazado, como siempre', function (): void {
    $orderId = Shop::order(Seed::user(), [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 1]]);

    Shop::boldWebhook($orderId, (float) Shop::orderRow($orderId)['amount'], type: 'VOID_APPROVED');

    expect(Shop::orderRow($orderId))->toMatchArray(['status_value' => 'PAGO RECHAZADO', 'approved_at' => null])
        ->and(Shop::rows('SELECT COUNT(*) n FROM adjustments WHERE order_id = ?', [$orderId])[0]['n'])->toBe(0);
});

it('cancelar un canje devuelve los puntos en un lote nuevo', function (): void {
    $trainer = Seed::trainer();
    Seed::pointLot($trainer, 300000);
    $orderId = AppFactory::json(AppFactory::dispatch(AppFactory::request('POST', '/api/trainer/redemptions', [
        'items' => [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 1]], 'address_id' => Shop::address($trainer), 'idempotency_key' => bin2hex(random_bytes(16)),
    ], JwtFactory::authHeader($trainer))))['order_id'];

    expect(trainerBalances($trainer)[0])->toBe(200000);

    $create = admin('POST', "orders/{$orderId}/adjustments", ['type' => 'cancellation', 'reason' => 'Producto sin existencias en bodega']);

    expect($create->getStatusCode())->toBe(201)
        ->and(AppFactory::json($create)['effect'])->toMatchArray(['commission_amount' => 0, 'points' => 100000])
        ->and(trainerBalances($trainer)[0])->toBe(300000)
        ->and(Shop::orderRow($orderId)['status_value'])->toBe('REVERSADA')
        ->and(Shop::rows("SELECT source, initial_points FROM point_lots WHERE trainer_id = ? AND source = 'restitution'", [$trainer]))->toBe([['source' => 'restitution', 'initial_points' => 100000]]);
});

it('el pedido de un canje dice que se pagó con puntos, en la lista y en el detalle', function (): void {
    $trainer = Seed::trainer();
    Seed::pointLot($trainer, 300000);
    $orderId = AppFactory::json(AppFactory::dispatch(AppFactory::request('POST', '/api/trainer/redemptions', [
        'items' => [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 2]], 'address_id' => Shop::address($trainer), 'idempotency_key' => bin2hex(random_bytes(16)),
    ], JwtFactory::authHeader($trainer))))['order_id'];
    [$purchase] = approvedPurchase();

    $list   = array_column(AppFactory::json(admin('GET', 'orders?per_page=100'))['data'], null, 'order_id');
    $detail = AppFactory::json(admin('GET', "orders/{$orderId}"));

    expect($list[$orderId])->toMatchArray(['order_type' => 'redemption', 'redemption_points' => 200000, 'payment_gateway' => null, 'payment_method' => 'Puntos'])
        ->and($list[$purchase])->toMatchArray(['order_type' => 'client_purchase', 'redemption_points' => null, 'payment_method' => 'Dinero (Bold)'])
        ->and($detail)->toMatchArray(['order_type' => 'redemption', 'redemption_points' => 200000, 'payment_method' => 'Puntos']);

    // El Excel del reporte de pedidos (los que ya tienen pedido de SAP) dice cómo se pagó cada uno.
    Seed::pdo()->prepare('UPDATE orders SET sap_order_code = 990001 WHERE order_id IN (?, ?)')->execute([$orderId, $purchase]);
    $day  = (string) Seed::pdo()->query('SELECT CURDATE()')->fetchColumn();
    $rows = array_column(AppFactory::json(admin('GET', "reports/orders/export?date_from={$day}&date_to={$day}")), null, 'pedido_id');

    expect($rows[$orderId])->toMatchArray(['forma_pago' => 'Puntos', 'puntos_linea' => 200000, 'puntos_pedido' => 200000])
        ->and($rows[$purchase])->toMatchArray(['forma_pago' => 'Dinero (Bold)', 'puntos_linea' => null, 'puntos_pedido' => null]);
});

it('no ajusta un pedido sin pagar ni sin motivo', function (): void {
    $pending = Shop::order(Seed::user(), [['code' => Seed::catalogProduct(), 'quantity' => 1]]);
    [$paid]  = approvedPurchase();

    expect(admin('POST', "orders/{$pending}/adjustments", ['type' => 'cancellation', 'reason' => 'Motivo suficiente'])->getStatusCode())->toBe(409)
        ->and(AppFactory::json(admin('GET', "orders/{$pending}/adjustments"))['can_adjust'])->toBeFalse()
        ->and(admin('POST', "orders/{$paid}/adjustments", ['type' => 'cancellation', 'reason' => 'no'])->getStatusCode())->toBe(422)
        ->and(admin('POST', "orders/{$paid}/adjustments", ['type' => 'regalo', 'reason' => 'Motivo suficiente'])->getStatusCode())->toBe(422);
});

// ── Hallados al conectar el admin al backend real (16 nov 2026) ────────────

it('la lista y el detalle de pedidos del admin funcionan cuando hay un canje (sin pasarela)', function (): void {
    $trainer = Seed::trainer();
    Seed::pointLot($trainer, 300000);
    $orderId = AppFactory::json(AppFactory::dispatch(AppFactory::request('POST', '/api/trainer/redemptions', [
        'items' => [['code' => Seed::catalogProduct(['valor' => 100000]), 'quantity' => 1]], 'address_id' => Shop::address($trainer), 'idempotency_key' => bin2hex(random_bytes(16)),
    ], JwtFactory::authHeader($trainer))))['order_id'];

    $list   = admin('GET', 'orders?page=1&per_page=5');
    $detail = admin('GET', 'orders/' . $orderId);
    $take   = admin('PATCH', "orders/{$orderId}/take", ['agent_id' => 123456]);

    expect($list->getStatusCode())->toBe(200)
        ->and($detail->getStatusCode())->toBe(200)
        ->and(AppFactory::json($detail)['payment_gateway'])->toBeNull()
        ->and(AppFactory::json($detail)['items'][0]['redemption_points'])->toBe(100000)
        // Tomar un pedido es siempre a nombre de quien tiene la sesión, no del `agent_id` enviado.
        ->and(AppFactory::json($take)['agent_id'])->toBe(9001);
});

it('un agente del call center no entra a entrenadores, estrategias, cierre ni registra ajustes', function (): void {
    $callCenter = JwtFactory::adminHeader(9001, 'CALL CENTER');
    $denied     = [];

    foreach ([
        ['GET', 'trainers'], ['GET', 'trainers/1'], ['PATCH', 'trainers/1/status'],
        ['GET', 'discount-rules'], ['POST', 'discount-rules'], ['PUT', 'discount-rules/1'], ['DELETE', 'discount-rules/1'],
        ['GET', 'products'], ['GET', 'settings/business'],
        ['GET', 'commission/periods'], ['GET', 'commission/periods/1'], ['GET', 'commission/periods/1/report'],
        ['POST', 'commission/settlements/1/pay'], ['POST', 'commission/settlements/1/payment'], ['GET', 'commission/settlements/1/receipt'],
        ['POST', 'orders/1/adjustments'], ['POST', 'orders/1/adjustments/preview'],
    ] as [$method, $path]) {
        $status = AppFactory::dispatch(AppFactory::request($method, '/api/admin/' . $path, $method === 'GET' ? null : [], $callCenter))->getStatusCode();

        if ($status !== 403) {
            $denied[] = "{$method} {$path} → {$status}";
        }
    }

    [$orderId] = approvedPurchase();

    expect($denied)->toBe([])
        // Sí ve los pedidos y los ajustes que tengan.
        ->and(AppFactory::dispatch(AppFactory::request('GET', '/api/admin/orders?page=1&per_page=1', headers: $callCenter))->getStatusCode())->toBe(200)
        ->and(AppFactory::dispatch(AppFactory::request('GET', "/api/admin/orders/{$orderId}/adjustments", headers: $callCenter))->getStatusCode())->toBe(200);
});

it('un agente no puede cambiar a mano el estado de un pago: solo la pasarela', function (string $status): void {
    [$client, $trainer] = Seed::clientWithTrainer();
    $orderId            = Shop::order($client, [['code' => Seed::catalogProduct(), 'quantity' => 1]]);
    $before             = Shop::orderRow($orderId)['status_value'];
    $statusId           = (int) Shop::rows('SELECT status_id FROM status_orders WHERE value = ?', [$status])[0]['status_id'];

    $manual = admin('PATCH', "orders/{$orderId}/status", ['status_id' => $statusId]);

    expect($manual->getStatusCode())->toBeIn([404, 405])
        ->and(Shop::orderRow($orderId)['status_value'])->toBe($before)
        ->and(Shop::orderRow($orderId)['approved_at'])->toBeNull()
        ->and(trainerBalances($trainer))->toBe([0, 0.0]);
})->with(['APROBADA', 'REVERSADA', 'CANCELADA']);

it('entrega el celular del agente como texto y expone Retry-After al navegador', function (): void {
    $login = \Tests\Support\Auth::post('/api/admin/auth/login', ['email' => 'agente.prueba@premiumfitness.local', 'password' => 'admin-local-123']);
    $cors  = AppFactory::dispatch(AppFactory::request('GET', '/api/health', headers: ['Origin' => explode(',', $_ENV['CORS_ALLOWED_ORIGINS'])[0]]));

    expect(AppFactory::json($login)['user']['celular'])->toBeString()
        ->and(AppFactory::json($login)['user']['cargo'])->toBe('CALL CENTER')
        ->and($cors->getHeaderLine('Access-Control-Expose-Headers'))->toContain('Retry-After');
});

<?php

declare(strict_types=1);

use App\Features\Product\CachedProductCatalog;
use App\Features\Product\IntranetProductCatalog;
use App\Infrastructure\Cache\CacheService;
use Tests\Support\AppFactory;
use Tests\Support\JwtFactory;
use Tests\Support\Seed;

/*
 | Catálogo desde la tabla de la intranet (spec catalogo/01, criterios 1 a 5).
 */

/** @return array<string, mixed>|null El producto del listado, o null si no aparece. */
function listedProduct(int $code, array $headers, string $query = ''): ?array
{
    $body = AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/products' . $query, headers: $headers)));

    foreach ($body['data'] as $product) {
        if ($product['code'] === $code) {
            return $product;
        }
    }

    return null;
}

function productDetail(int $code, array $headers): Psr\Http\Message\ResponseInterface
{
    return AppFactory::dispatch(AppFactory::request('GET', '/api/products/' . $code, headers: $headers));
}

it('lista el producto con el nombre del campo producto y solo los campos del contrato', function (): void {
    $code    = Seed::catalogProduct(['producto' => 'BIPRO DE PRUEBA', 'valor' => 249900, 'descuento' => 30, 'old_price' => 300000, 'nuevo' => 1]);
    $product = listedProduct($code, JwtFactory::authHeader(Seed::user()));

    expect($product)->toBe([
        'code' => $code, 'name' => 'BIPRO DE PRUEBA', 'category' => 'PRUEBAS', 'presentation' => '1 lb. Prueba', 'image' => null,
        'price' => 249900, 'final_price' => 249900, 'discount_percentage' => 0, 'is_out_of_stock' => false, 'is_new' => true,
        'redemption_points' => 249900,
    ]);
});

it('da a cada quien su precio: entrenador, cliente con entrenador y cliente sin entrenador', function (): void {
    $code                 = Seed::catalogProduct(['valor' => 249900]);
    [$client, $trainer]   = Seed::clientWithTrainer();

    $forTrainer = listedProduct($code, JwtFactory::authHeader($trainer));
    $forClient  = listedProduct($code, JwtFactory::authHeader($client));
    $forAlone   = listedProduct($code, JwtFactory::authHeader(Seed::user()));

    expect([$forTrainer['final_price'], $forTrainer['discount_percentage']])->toBe([187425, 25])
        ->and([$forClient['final_price'], $forClient['discount_percentage']])->toBe([224910, 10])
        ->and([$forAlone['final_price'], $forAlone['discount_percentage']])->toBe([249900, 0])
        ->and($forTrainer['price'])->toBe(249900);
});

it('el rol sale de la base, no del token: un token alterado no cambia el precio', function (): void {
    $code = Seed::catalogProduct(['valor' => 100000]);

    expect(listedProduct($code, JwtFactory::authHeader(Seed::user(), 'trainer'))['final_price'])->toBe(100000);
});

it('aplica la estrategia vigente del producto y descarta la vencida, la futura y la inactiva', function (): void {
    $code               = Seed::catalogProduct(['valor' => 100000]);
    [$client, $trainer] = Seed::clientWithTrainer();

    Seed::discountRule($code, client: 50, validFrom: '-10 days', validTo: '-1 day');
    Seed::discountRule($code, client: 60, validFrom: '+1 day');
    Seed::discountRule($code, client: 70, active: false);
    expect(listedProduct($code, JwtFactory::authHeader($client))['final_price'])->toBe(90000);

    Seed::discountRule($code, client: 20, trainer: null);
    expect(listedProduct($code, JwtFactory::authHeader($client))['final_price'])->toBe(80000)
        ->and(listedProduct($code, JwtFactory::authHeader($trainer))['final_price'])->toBe(75000);
});

it('no muestra productos en promoción, inactivos, sin precio o sin nombre', function (array $overrides): void {
    $code    = Seed::catalogProduct($overrides);
    $headers = JwtFactory::authHeader(Seed::user());

    expect(listedProduct($code, $headers))->toBeNull()
        ->and(productDetail($code, $headers)->getStatusCode())->toBe(404);
})->with([
    'promo = 1'      => [['promo' => 1]],
    'activo = 0'     => [['activo' => 0]],
    'activo NULL'    => [['activo' => null]],
    'sin precio'     => [['valor' => 0]],
    'sin nombre'     => [['producto' => '']],
]);

it('muestra un producto agotado, marcado', function (): void {
    $code = Seed::catalogProduct(['agotado' => 1]);

    expect(listedProduct($code, JwtFactory::authHeader(Seed::user()))['is_out_of_stock'])->toBeTrue();
});

it('filtra por categoría y devuelve todas las categorías', function (): void {
    $category = 'CATEGORIA ' . random_int(1000, 9999);
    $inside   = Seed::catalogProduct(['category' => $category]);
    $outside  = Seed::catalogProduct();
    $headers  = JwtFactory::authHeader(Seed::user());
    $query    = '?category=' . rawurlencode($category);

    $body = AppFactory::json(AppFactory::dispatch(AppFactory::request('GET', '/api/products' . $query, headers: $headers)));

    expect(array_column($body['data'], 'code'))->toBe([$inside])
        ->and($body['categories'])->toContain($category, 'PRUEBAS')
        ->and(listedProduct($outside, $headers, $query))->toBeNull();
});

it('la ficha trae galería, secciones limpias, presentaciones del grupo y el costo del canje', function (): void {
    $group = 'grupo-' . random_int(1000, 9999);
    $image = json_encode(['medium' => 'https://nutramerican.com/p-medium.webp', 'big' => 'https://nutramerican.com/p-big.webp']);
    $table = json_encode(['big' => 'https://nutramerican.com/tabla-big.webp']);
    $code  = Seed::catalogProduct([
        'valor' => 249900, 'grupo' => $group, 'imageswebp' => $image, 'tablasjpg' => $table, 'intro' => 'Resumen corto',
        'description' => '<p>Texto</p><script>alert(1)</script>', 'uso' => 'Una porción al día.',
    ]);
    $other     = Seed::catalogProduct(['valor' => 100000, 'grupo' => $group, 'presentation' => '5 lb. Prueba']);
    $promoted  = Seed::catalogProduct(['grupo' => $group, 'promo' => 1]);
    [$client]  = Seed::clientWithTrainer();

    $response = productDetail($code, JwtFactory::authHeader($client));
    $body     = AppFactory::json($response);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['image'])->toBe('https://nutramerican.com/p-medium.webp')
        ->and($body['images'])->toBe(['https://nutramerican.com/p-big.webp', 'https://nutramerican.com/tabla-big.webp'])
        ->and($body['summary'])->toBe('Resumen corto')
        ->and($body['sections'])->toBe([
            ['title' => 'Descripción', 'html' => '<p>Texto</p>'],
            ['title' => 'Modo de uso', 'html' => '<p>Una porción al día.</p>'],
        ])
        ->and($body['presentations'])->toBe([
            ['code' => $other, 'presentation' => '5 lb. Prueba', 'final_price' => 90000, 'is_out_of_stock' => false],
        ])
        ->and(array_column($body['presentations'], 'code'))->not->toContain($promoted)
        ->and($body['final_price'])->toBe(224910)
        // El canje es a precio completo.
        ->and($body['redemption_points'])->toBe(249900);
});

it('ninguna respuesta del catálogo expone el descuento de la web, el precio anterior ni los datos de SAP', function (): void {
    $code    = Seed::catalogProduct(['descuento' => 35, 'old_price' => 987654, 'sap_code' => 771177, 'sap_name' => 'ARTICULO-SECRETO-SAP']);
    $headers = JwtFactory::authHeader(Seed::user());

    $list   = (string) AppFactory::dispatch(AppFactory::request('GET', '/api/products', headers: $headers))->getBody();
    $detail = (string) productDetail($code, $headers)->getBody();

    foreach ([$list, $detail] as $json) {
        expect($json)->not->toContain('descuento', 'old_price', 'sap_code', 'sap_name', '987654', '771177', 'ARTICULO-SECRETO-SAP');
    }
});

it('el catálogo pide sesión', function (): void {
    expect(AppFactory::dispatch(AppFactory::request('GET', '/api/products'))->getStatusCode())->toBe(401)
        ->and(AppFactory::dispatch(AppFactory::request('GET', '/api/products/100507'))->getStatusCode())->toBe(401);
});

it('findByCodes lee siempre de la fuente aunque el listado esté en caché', function (): void {
    $code    = Seed::catalogProduct(['valor' => 100000]);
    $catalog = new CachedProductCatalog(IntranetProductCatalog::fromEnv(), new CacheService(sys_get_temp_dir() . '/pf-test-cache-' . uniqid()), 600);

    $cachedPrice = fn (): ?int => array_column($catalog->available('PRUEBAS'), 'price', 'code')[$code] ?? null;
    expect($cachedPrice())->toBe(100000);

    Seed::updateCatalogProduct($code, ['valor' => 120000]);

    expect($cachedPrice())->toBe(100000)
        ->and($catalog->findByCodes([$code])[$code]->price)->toBe(120000);

    Seed::updateCatalogProduct($code, ['promo' => 1]);

    expect($catalog->findByCodes([$code]))->toBe([]);
});

it('el usuario de la app no tiene permiso de escritura sobre crisenri_intranet', function (): void {
    // Se revisan los permisos sin intentar escribir: la intranet nunca se modifica, ni en local.
    $grants = Seed::pdo()->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);

    if (preg_grep('/^GRANT ALL PRIVILEGES ON \*\.\* /', $grants) !== []) {
        test()->markTestSkipped('El usuario local tiene todos los permisos; el de solo lectura se crea en el hosting (spec catalogo/01).');
    }

    expect(preg_grep('/(ALL PRIVILEGES|INSERT|UPDATE|DELETE).* ON (`crisenri_intranet`|\*)\.\*/', $grants))->toBe([]);
});

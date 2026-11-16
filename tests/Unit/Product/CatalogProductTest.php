<?php

declare(strict_types=1);

use App\Features\Product\CatalogProduct;
use App\Features\Product\ProductContent;
use App\Features\Product\ProductImages;

/** @return array<string, mixed> */
function intranetRow(array $overrides = []): array
{
    return $overrides + [
        'codigo' => '100507', 'producto' => '  BIPRO CLASSIC VAINILLA 2LB ', 'valor' => '249000', 'category' => 'MÓDULOS PROTEICOS',
        'presentation' => '2 lb. Vainilla', 'ruta' => 'bipro-classic', 'intro' => '', 'imageswebp' => null, 'grupo' => 'bipro-classic',
        'agotado' => 0, 'nuevo' => 0, 'sap_code' => 100570, 'sap_name' => 'Bipro Classic Vainilla 2 lb Bolsa',
    ];
}

it('convierte una fila de la intranet: el nombre es el campo producto y el precio un entero', function (): void {
    $product = CatalogProduct::fromIntranetRow(intranetRow());

    expect($product->code)->toBe(100507)
        ->and($product->name)->toBe('BIPRO CLASSIC VAINILLA 2LB')
        ->and($product->price)->toBe(249000)
        ->and($product->summary)->toBeNull()
        ->and($product->sapCode)->toBe(100570)
        ->and($product->isPurchasable())->toBeTrue();
});

// PDO entrega un bit(1) como "\x00" o "\x01", y (bool) "\x00" es verdadero: por eso se lee como número.
it('lee agotado y nuevo como número, no como el byte de un bit', function (mixed $agotado, bool $expected): void {
    $product = CatalogProduct::fromIntranetRow(intranetRow(['agotado' => $agotado]));

    expect($product->isOutOfStock)->toBe($expected)
        ->and($product->isPurchasable())->toBe(!$expected);
})->with([[0, false], ['0', false], [1, true], ['1', true]]);

it('un sap_code 0 significa que no tiene artículo SAP', function (): void {
    expect(CatalogProduct::fromIntranetRow(intranetRow(['sap_code' => 0]))->sapCode)->toBeNull();
});

it('lee las imágenes del JSON de la intranet y solo acepta URL http', function (): void {
    $images = ProductImages::fromJson(json_encode([
        'small' => 'https://nutramerican.com/a-small.webp',
        'semiMedium' => 'https://nutramerican.com/a-semi.webp',
        'big' => 'javascript:alert(1)',
        'bigger' => 'https://nutramerican.com/a-bigger.webp',
    ]));

    expect($images->small)->toBe('https://nutramerican.com/a-small.webp')
        ->and($images->medium)->toBe('https://nutramerican.com/a-semi.webp')
        ->and($images->big)->toBeNull()
        ->and($images->card())->toBe('https://nutramerican.com/a-semi.webp')
        ->and($images->full())->toBe('https://nutramerican.com/a-bigger.webp');
});

it('un JSON de imágenes vacío o mal formado no rompe el producto', function (mixed $json): void {
    expect(ProductImages::fromJson($json)->card())->toBeNull();
})->with([null, '', '{mal', '"texto"', '[]', 42]);

it('limpia el HTML de la ficha y omite las secciones vacías', function (): void {
    $content = ProductContent::fromIntranetRow([
        'description' => '<p onclick="robar()">Proteína</p><script>alert(1)</script>',
        'uso'         => "Mezcla una porción.\n\nToma después de entrenar.",
        'ingredientes' => '   ',
        'invima'      => null,
        'tablasjpg'   => null,
    ]);

    expect(array_column($content->sections, 'title'))->toBe(['Descripción', 'Modo de uso'])
        ->and($content->sections[0]['html'])->toBe('<p>Proteína</p>')
        ->and($content->sections[1]['html'])->toBe('<p>Mezcla una porción.</p><p>Toma después de entrenar.</p>');
});

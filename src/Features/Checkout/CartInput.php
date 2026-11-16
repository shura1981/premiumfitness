<?php

declare(strict_types=1);

namespace App\Features\Checkout;

use App\Shared\Exceptions\ApiException;

/**
 * Normaliza el carrito que envía la app: `[{ code, quantity }]`. Une los códigos repetidos.
 * Los precios nunca llegan de la app.
 */
final class CartInput
{
    /**
     * @return list<array{code:int, quantity:int}>
     * @throws ApiException 422 si el carrito no tiene la forma esperada
     */
    public static function normalize(mixed $items): array
    {
        if (!is_array($items) || $items === [] || count($items) > QuoteCalculator::MAX_LINES) {
            throw self::invalid();
        }

        $quantities = [];

        foreach ($items as $item) {
            $code     = is_array($item) ? filter_var($item['code'] ?? null, FILTER_VALIDATE_INT) : false;
            $quantity = is_array($item) ? filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT) : false;

            if ($code === false || $code <= 0 || $quantity === false || $quantity <= 0) {
                throw self::invalid();
            }

            $quantities[$code] = ($quantities[$code] ?? 0) + $quantity;

            if ($quantities[$code] > QuoteCalculator::MAX_QUANTITY) {
                throw ApiException::invalid('Puedes llevar hasta ' . QuoteCalculator::MAX_QUANTITY . ' unidades de cada producto.', errors: ['items' => 'Cantidad no válida.']);
            }
        }

        $normalized = [];
        foreach ($quantities as $code => $quantity) {
            $normalized[] = ['code' => (int) $code, 'quantity' => $quantity];
        }

        return $normalized;
    }

    private static function invalid(): ApiException
    {
        return ApiException::invalid('Revisa los productos de tu carrito.', errors: ['items' => 'El carrito no es válido.']);
    }
}

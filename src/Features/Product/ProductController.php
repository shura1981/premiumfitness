<?php

declare(strict_types=1);

namespace App\Features\Product;

use App\Features\DiscountRule\Buyer;
use App\Features\DiscountRule\DiscountPolicy;
use App\Features\DiscountRule\DiscountRuleRepository;
use App\Infrastructure\Http\JsonResponder;
use App\Shared\Exceptions\ApiException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Catálogo de la app web (spec catalogo/01). Los precios salen ya calculados para quien consulta.
 *
 *   GET /api/products?category=   → listado y categorías
 *   GET /api/products/{code}      → ficha
 */
class ProductController
{
    use JsonResponder;

    private readonly ProductCatalog $catalog;
    private readonly DiscountRuleRepository $rules;
    private readonly ProductPresenter $presenter;

    public function __construct(?ProductCatalog $catalog = null)
    {
        $this->catalog   = $catalog ?? CachedProductCatalog::fromEnv();
        $this->rules     = new DiscountRuleRepository();
        $this->presenter = new ProductPresenter(DiscountPolicy::fromEnv());
    }

    public function list(Request $request, Response $response): Response
    {
        $buyer    = self::buyer($request);
        $category = trim((string) ($request->getQueryParams()['category'] ?? ''));
        $all      = $this->catalog->available();
        $products = $category === ''
            ? $all
            : array_values(array_filter($all, static fn (CatalogProduct $product): bool => $product->category === $category));
        $rules    = $this->rules->activeFor(array_map(static fn (CatalogProduct $product): int => $product->code, $products));

        $categories = array_values(array_unique(array_filter(array_map(
            static fn (CatalogProduct $product): string => $product->category,
            $all,
        ))));

        return $this->json($response, [
            'data'       => array_map(
                fn (CatalogProduct $product): array => $this->presenter->summary($product, $buyer, $rules[$product->code] ?? null),
                $products,
            ),
            'categories' => $categories,
        ]);
    }

    public function show(Request $request, Response $response, int $code): Response
    {
        $buyer   = self::buyer($request);
        $product = $this->catalog->findByCode($code);

        if ($product === null) {
            throw ApiException::notFound('Este producto ya no está disponible.');
        }

        $presentations = $this->catalog->presentationsOf($code);
        $rules         = $this->rules->activeFor([
            $code,
            ...array_map(static fn (CatalogProduct $other): int => $other->code, $presentations),
        ]);

        return $this->json($response, $this->presenter->detail($product, $presentations, $buyer, $rules));
    }

    private static function buyer(Request $request): Buyer
    {
        $claims = $request->getAttribute('jwt_claims');
        $buyer  = is_object($claims) ? Buyer::fromUserId((int) ($claims->sub ?? 0)) : null;

        return $buyer ?? throw ApiException::unauthorized();
    }
}

<?php

declare(strict_types=1);

namespace App\Features\Product;

use App\Infrastructure\View\HtmlSanitizer;

/**
 * Textos de la ficha del producto. Los edita la web de la empresa: el HTML se limpia al leerlo.
 * Los ingredientes no se muestran en la app (pedido de Steven, 26 nov 2026): la columna no se lee.
 */
final readonly class ProductContent
{
    /**
     * @param list<array{title:string, html:string}> $sections
     */
    public function __construct(
        public array $sections,
        public ProductImages $nutritionTable,
    ) {}

    /** @param array<string, mixed> $row Fila de tb_productos_megaplexstars. */
    public static function fromIntranetRow(array $row): self
    {
        $sections = [];

        foreach (['description' => 'Descripción', 'uso' => 'Modo de uso', 'invima' => 'Registro sanitario'] as $column => $title) {
            $html = HtmlSanitizer::richText(isset($row[$column]) ? (string) $row[$column] : null);

            if ($html !== '') {
                $sections[] = ['title' => $title, 'html' => $html];
            }
        }

        return new self($sections, ProductImages::fromJson($row['tablasjpg'] ?? null));
    }
}

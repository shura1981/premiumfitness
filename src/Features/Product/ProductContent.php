<?php

declare(strict_types=1);

namespace App\Features\Product;

use App\Infrastructure\View\HtmlSanitizer;

/**
 * Textos de la ficha del producto. Los edita la web de la empresa: el HTML se limpia al leerlo.
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

        foreach (['description' => 'Descripción', 'uso' => 'Modo de uso', 'ingredientes' => 'Ingredientes', 'invima' => 'Registro sanitario'] as $column => $title) {
            $html = HtmlSanitizer::richText(isset($row[$column]) ? (string) $row[$column] : null);

            if ($html !== '') {
                $sections[] = ['title' => $title, 'html' => $html];
            }
        }

        return new self($sections, ProductImages::fromJson($row['tablasjpg'] ?? null));
    }
}

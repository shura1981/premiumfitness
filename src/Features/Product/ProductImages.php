<?php

declare(strict_types=1);

namespace App\Features\Product;

/**
 * Imágenes de un producto, del JSON `imageswebp` o `tablasjpg` de la intranet:
 * `{ "small": url, "medium": url, "semiMedium": url, "big": url, "bigger": url, "seo": url }`.
 * Un JSON vacío o mal formado da un objeto sin imágenes, no un error.
 */
final readonly class ProductImages
{
    public function __construct(
        public ?string $small = null,
        public ?string $medium = null,
        public ?string $big = null,
        public ?string $bigger = null,
    ) {}

    public static function fromJson(mixed $json): self
    {
        if (!is_string($json) || trim($json) === '') {
            return new self();
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            return new self();
        }

        return new self(
            small: self::url($data['small'] ?? null),
            medium: self::url($data['medium'] ?? $data['semiMedium'] ?? null),
            big: self::url($data['big'] ?? null),
            bigger: self::url($data['bigger'] ?? null),
        );
    }

    /** Para las tarjetas del listado. */
    public function card(): ?string
    {
        return $this->medium ?? $this->big ?? $this->bigger ?? $this->small;
    }

    /** Para la galería de la ficha. */
    public function full(): ?string
    {
        return $this->big ?? $this->bigger ?? $this->medium ?? $this->small;
    }

    /** Solo URL http(s): el dato lo edita la web y llega al navegador. */
    private static function url(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('#^https?://#i', $value) === 1 && filter_var($value, FILTER_VALIDATE_URL) !== false
            ? $value
            : null;
    }
}

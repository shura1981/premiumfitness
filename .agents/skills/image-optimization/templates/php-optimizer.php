<?php

declare(strict_types=1);

/**
 * PHP Image Optimizer usando la extensión Imagick (ImageMagick 7+).
 *
 * IMPORTANTE: Usar siempre la clase Imagick, NUNCA exec() ni shell_exec().
 * La extensión PHP usa su propia biblioteca de ImageMagick compilada con WebP,
 * independiente del binario del sistema que puede no tener WebP.
 *
 * Requiere: extension=imagick en php.ini
 */
class ImageOptimizer
{
    private const SQUARE_SIZES = [
        'small'      => 100,
        'semiMedium' => 200,
        'medium'     => 400,
        'big'        => 800,
        'bigger'     => 1200,
    ];

    private const SEO_WIDTH  = 1200;
    private const SEO_HEIGHT = 628;
    private const PADDING    = 50;
    private const QUALITY    = 85;

    public function __construct(
        private readonly string $sku,
        private readonly string $directoryName,
        private readonly string $imagePath,
        private readonly string $baseUrl,
        private readonly string $format = 'webp',
    ) {}

    public function optimize(): array
    {
        if (!is_dir($this->directoryName)) {
            mkdir($this->directoryName, 0755, true);
        }

        $dirBase = basename($this->directoryName);
        $baseName = pathinfo($this->imagePath, PATHINFO_FILENAME);
        $version  = time();
        $urls     = [];

        // Generar bigger (1200px) primero desde la fuente original
        $biggerPath = "{$this->directoryName}/{$baseName}bigger.{$this->format}";
        $this->renderSquare($this->imagePath, self::SQUARE_SIZES['bigger'], $biggerPath, transparent: true);
        $urls['bigger'] = "{$this->baseUrl}/{$dirBase}/{$baseName}bigger.{$this->format}?v={$version}";

        // Generar SEO (1200×628) con fondo blanco
        $seoPath = "{$this->directoryName}/{$baseName}seo.{$this->format}";
        $this->renderSeo($this->imagePath, $seoPath);
        $urls['seo'] = "{$this->baseUrl}/{$dirBase}/{$baseName}seo.{$this->format}?v={$version}";

        // Downscale desde bigger para el resto
        foreach (self::SQUARE_SIZES as $key => $size) {
            if ($key === 'bigger') {
                continue;
            }

            $destPath  = "{$this->directoryName}/{$baseName}{$key}.{$this->format}";
            $this->resize($biggerPath, $size, $destPath);
            $urls[$key] = "{$this->baseUrl}/{$dirBase}/{$baseName}{$key}.{$this->format}?v={$version}";
        }

        return $urls;
    }

    private function renderSquare(string $source, int $size, string $dest, bool $transparent = true): void
    {
        $innerSize = $size - self::PADDING;

        $image = new \Imagick($source);
        $image->resizeImage($innerSize, $innerSize, \Imagick::FILTER_LANCZOS, 1, true);
        $image->stripImage();

        $w = $image->getImageWidth();
        $h = $image->getImageHeight();

        $bg     = $transparent ? 'none' : 'white';
        $canvas = new \Imagick();
        $canvas->newImage($size, $size, new \ImagickPixel($bg), $this->format);
        $canvas->setImageCompressionQuality(self::QUALITY);
        $canvas->compositeImage($image, \Imagick::COMPOSITE_OVER, (int) floor(($size - $w) / 2), (int) floor(($size - $h) / 2));
        $canvas->writeImage($dest);

        $image->destroy();
        $canvas->destroy();
    }

    private function renderSeo(string $source, string $dest): void
    {
        $innerW = self::SEO_WIDTH  - self::PADDING;
        $innerH = self::SEO_HEIGHT - self::PADDING;

        $image = new \Imagick($source);
        $image->resizeImage($innerW, $innerH, \Imagick::FILTER_LANCZOS, 1, true);
        $image->stripImage();

        $w = $image->getImageWidth();
        $h = $image->getImageHeight();

        $canvas = new \Imagick();
        $canvas->newImage(self::SEO_WIDTH, self::SEO_HEIGHT, new \ImagickPixel('white'), $this->format);
        $canvas->setImageCompressionQuality(self::QUALITY);
        $canvas->compositeImage($image, \Imagick::COMPOSITE_OVER, (int) floor((self::SEO_WIDTH - $w) / 2), (int) floor((self::SEO_HEIGHT - $h) / 2));
        $canvas->writeImage($dest);

        $image->destroy();
        $canvas->destroy();
    }

    private function resize(string $source, int $size, string $dest): void
    {
        $image = new \Imagick($source);
        $image->resizeImage($size, $size, \Imagick::FILTER_LANCZOS, 1, true);
        $image->stripImage();
        $image->writeImage($dest);
        $image->destroy();
    }
}

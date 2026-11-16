<?php

declare(strict_types=1);

namespace App\Features\Commission;

use App\Shared\Exceptions\ApiException;
use finfo;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Comprobantes de pago de la comisión. Viven en `storage/private/receipts`, fuera de la carpeta
 * pública: solo se sirven al admin o al entrenador dueño de la liquidación. El tipo se decide por
 * el contenido real del archivo, no por su extensión ni por lo que diga el navegador.
 */
class ReceiptStorage
{
    public const int MAX_BYTES = 5 * 1024 * 1024;

    private const array ALLOWED = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
    ];

    private readonly string $directory;

    public function __construct()
    {
        $path            = (string) ($_ENV['RECEIPTS_STORAGE_PATH'] ?? 'storage/private/receipts');
        $this->directory = rtrim(str_starts_with($path, '/') ? $path : __DIR__ . '/../../../' . $path, '/');
    }

    /**
     * @return array{path:string, mime:string} Nombre del archivo guardado y su tipo real.
     * @throws ApiException 422 si el archivo no sirve
     */
    public function store(int $settlementId, ?UploadedFileInterface $file): array
    {
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            throw self::invalid('Adjunta el comprobante del pago.');
        }

        if ($file->getError() !== UPLOAD_ERR_OK || (int) $file->getSize() <= 0) {
            throw self::invalid('No pudimos leer el archivo. Inténtalo de nuevo.');
        }

        if ((int) $file->getSize() > self::MAX_BYTES) {
            throw self::invalid('El comprobante pesa más de 5 MB.');
        }

        $contents = (string) $file->getStream();
        $mime     = (string) new finfo(FILEINFO_MIME_TYPE)->buffer($contents);

        if (!isset(self::ALLOWED[$mime])) {
            throw self::invalid('El comprobante debe ser un PDF o una imagen JPG o PNG.');
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new RuntimeException('No se pudo crear la carpeta de comprobantes.');
        }

        // El nombre lo pone el servidor: nada de lo que escriba quien sube el archivo llega al disco.
        $name = sprintf('liquidacion-%d-%s.%s', $settlementId, bin2hex(random_bytes(8)), self::ALLOWED[$mime]);

        if (file_put_contents($this->directory . '/' . $name, $contents, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo guardar el comprobante.');
        }

        return ['path' => $name, 'mime' => $mime];
    }

    /** Ruta absoluta de un comprobante guardado, o null si el archivo ya no está. */
    public function absolutePath(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        $file = $this->directory . '/' . basename($name);

        return is_file($file) ? $file : null;
    }

    public function delete(?string $name): void
    {
        $file = $this->absolutePath($name);

        if ($file !== null) {
            @unlink($file);
        }
    }

    private static function invalid(string $message): ApiException
    {
        return ApiException::invalid($message, errors: ['receipt' => $message]);
    }
}

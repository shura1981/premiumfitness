<?php

declare(strict_types=1);

namespace App\Features\Auth;

/**
 * Value object con los datos de un cliente obtenidos de la API SAP.
 *
 * @phpstan-type SapType int
 */
readonly class SapClientResponse
{
    public function __construct(
        public string $idCliente,
        public string $nombres,
        public string $direccion,
        public string $ciudad,
        public string $email,
        public string $celular,
        public int    $tipo,
        public int    $descuento,
        public int    $idVendedor,
        public string $nombresVendedor,
        public string $telefono,
    ) {}

    /**
     * @param array<string, mixed> $data Respuesta JSON decodificada de la API SAP
     */
    public static function fromApiResponse(array $data): self
    {
        return new self(
            idCliente:       (string) ($data['id_cliente'] ?? ''),
            nombres:         (string) ($data['nombres'] ?? ''),
            direccion:       (string) ($data['dirección'] ?? $data['direccion'] ?? ''),
            ciudad:          (string) ($data['ciudad'] ?? ''),
            email:           (string) ($data['e_mail'] ?? ''),
            celular:         (string) ($data['celular'] ?? ''),
            tipo:            (int)    ($data['tipo'] ?? 0),
            descuento:       (int)    ($data['descuento'] ?? 0),
            idVendedor:      (int)    ($data['id_vendedor'] ?? 0),
            nombresVendedor: (string) ($data['nombres_vendedor'] ?? ''),
            telefono:        (string) ($data['teléfono'] ?? $data['telefono'] ?? ''),
        );
    }

    /**
     * Extrae el número de documento sin el prefijo de tipo (ej. "C6394880" → "6394880").
     */
    public function documentNumber(): string
    {
        $id = $this->idCliente;

        if ($id !== '' && !is_numeric($id[0])) {
            return substr($id, 1);
        }

        return $id;
    }

    /**
     * Mapea el código de tipo SAP al ID de document_types en la BD.
     *
     * SAP tipo 13 → CC (Cédula de Ciudadanía) → document_types.id depende de la BD
     * SAP tipo 31 → NIT → document_types.id depende de la BD
     *
     * @param array<int, int> $sapTypeMap Mapeo [sapTipo => documentTypeId]
     */
    public function resolveDocumentTypeId(array $sapTypeMap): int
    {
        return $sapTypeMap[$this->tipo] ?? $sapTypeMap[13] ?? 1;
    }
}

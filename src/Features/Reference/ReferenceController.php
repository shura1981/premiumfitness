<?php

declare(strict_types=1);

namespace App\Features\Reference;

use App\Features\City\CityRepository;
use App\Features\DocumentType\DocumentTypeRepository;
use App\Features\Locality\LocalityRepository;
use App\Infrastructure\Database\DB;
use App\Infrastructure\Http\JsonResponder;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Listas de apoyo de los formularios. Solo lectura.
 *
 *   GET /api/document-types          → tipos de documento (sin sesión: los pide el registro)
 *   GET /api/cities/search?q=        → ciudades por nombre (sin sesión)
 *   GET /api/localities?city_id=     → localidades de la ciudad; vacío si no las tiene
 */
class ReferenceController
{
    use JsonResponder;

    /** Código DANE de Bogotá: la única ciudad con localidades. */
    private const BOGOTA_DAN_CODE = '11001';

    public function documentTypes(Request $request, Response $response): Response
    {
        $types = array_map(
            static fn ($type): array => ['id' => (int) $type->id, 'name' => (string) $type->name],
            new DocumentTypeRepository()->findAll(),
        );

        return $this->json($response, ['data' => $types]);
    }

    public function searchCities(Request $request, Response $response): Response
    {
        $term = trim((string) ($request->getQueryParams()['q'] ?? ''));

        if (mb_strlen($term) < 2 || mb_strlen($term) > 60) {
            return $this->json($response, ['data' => []]);
        }

        // Primero la coincidencia exacta, después las que empiezan por el texto y al final las que lo contienen.
        $like = addcslashes($term, '%_\\');
        $stmt = DB::getInstance()->prepare(
            "SELECT id, name, department FROM cities
             WHERE name LIKE CONCAT('%', ?, '%')
             ORDER BY (UPPER(name) = UPPER(?)) DESC, (name LIKE CONCAT(?, '%')) DESC, CHAR_LENGTH(name), name
             LIMIT 20"
        );
        $stmt->execute([$like, $term, $like]);

        $cities = array_map(
            static fn (array $city): array => ['id' => (int) $city['id'], 'name' => (string) $city['name'], 'department' => (string) $city['department']],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );

        return $this->json($response, ['data' => $cities]);
    }

    public function localities(Request $request, Response $response): Response
    {
        $city = new CityRepository()->findById((int) ($request->getQueryParams()['city_id'] ?? 0));

        if ($city === false || $city->dan_code !== self::BOGOTA_DAN_CODE) {
            return $this->json($response, ['data' => []]);
        }

        $localities = array_map(
            static fn ($locality): array => ['id' => (int) $locality->id, 'name' => (string) $locality->name],
            new LocalityRepository()->findAll(),
        );

        return $this->json($response, ['data' => $localities]);
    }
}

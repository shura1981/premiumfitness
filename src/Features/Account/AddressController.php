<?php

declare(strict_types=1);

namespace App\Features\Account;

use App\Infrastructure\Http\JsonResponder;
use App\Shared\Exceptions\ApiException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Direcciones de quien tiene la sesión. El usuario sale del token; una dirección ajena responde 404.
 *
 *   GET    /api/account/addresses
 *   POST   /api/account/addresses
 *   PUT    /api/account/addresses/{id}
 *   DELETE /api/account/addresses/{id}
 *   PATCH  /api/account/addresses/{id}/main
 */
class AddressController
{
    use JsonResponder;

    private const int MAX_ADDRESSES = 20;

    private readonly AddressBook $addresses;

    public function __construct()
    {
        $this->addresses = new AddressBook();
    }

    public function list(Request $request, Response $response): Response
    {
        return $this->json($response, ['data' => $this->addresses->all(self::userId($request))]);
    }

    public function create(Request $request, Response $response): Response
    {
        $userId = self::userId($request);

        if (count($this->addresses->all($userId)) >= self::MAX_ADDRESSES) {
            throw ApiException::conflict('too_many_addresses', 'Ya tienes muchas direcciones guardadas. Borra una para agregar otra.');
        }

        [$name, $address, $cityId, $localityId] = $this->input($request);
        $id = $this->addresses->create($userId, $name, $address, $cityId, $localityId);

        return $this->json($response, (array) $this->addresses->find($userId, $id), 201);
    }

    public function update(Request $request, Response $response, int $id): Response
    {
        $userId = self::userId($request);
        $this->owned($userId, $id);

        [$name, $address, $cityId, $localityId] = $this->input($request);
        $this->addresses->update($id, $name, $address, $cityId, $localityId);

        return $this->json($response, (array) $this->addresses->find($userId, $id));
    }

    public function delete(Request $request, Response $response, int $id): Response
    {
        $userId = self::userId($request);
        $this->owned($userId, $id);
        $this->addresses->remove($userId, $id);

        return $this->noContent($response);
    }

    public function setMain(Request $request, Response $response, int $id): Response
    {
        $userId = self::userId($request);
        $this->owned($userId, $id);
        $this->addresses->setMain($userId, $id);

        return $this->noContent($response);
    }

    private function owned(int $userId, int $addressId): void
    {
        if ($this->addresses->find($userId, $addressId) === null) {
            throw ApiException::notFound('No encontramos esa dirección.');
        }
    }

    /** @return array{0:string, 1:string, 2:int, 3:?int} nombre, dirección, ciudad y localidad */
    private function input(Request $request): array
    {
        $body       = (array) ($request->getParsedBody() ?? []);
        $cityId     = (int) $body['city_id'];
        $localityId = isset($body['locality_id']) && $body['locality_id'] !== '' ? (int) $body['locality_id'] : null;

        if (!$this->addresses->cityExists($cityId)) {
            throw ApiException::invalid('Revisa los datos e inténtalo de nuevo.', errors: ['city_id' => 'Elige una ciudad de la lista.']);
        }

        if ($localityId !== null && !$this->addresses->localityExists($localityId)) {
            throw ApiException::invalid('Revisa los datos e inténtalo de nuevo.', errors: ['locality_id' => 'Elige una localidad de la lista.']);
        }

        return [trim((string) $body['name']), trim((string) $body['address']), $cityId, $localityId];
    }

    private static function userId(Request $request): int
    {
        $claims = $request->getAttribute('jwt_claims');
        $userId = is_object($claims) ? (int) ($claims->sub ?? 0) : 0;

        return $userId > 0 ? $userId : throw ApiException::unauthorized();
    }
}

<?php

declare(strict_types=1);

namespace App\Features\Referral;

use App\Features\Auth\AuthUserRepository;
use App\Infrastructure\Http\JsonResponder;
use App\Shared\Exceptions\ApiException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 *   GET  /api/referrals/trainers/{id}   → nombre del entrenador de un enlace (sin sesión)
 *   POST /api/client/coupon             → el cliente asocia el cupón de su entrenador
 */
class ReferralController
{
    use JsonResponder;

    private readonly ReferralService $referrals;

    public function __construct()
    {
        $this->referrals = new ReferralService();
    }

    public function trainerOfLink(Request $request, Response $response, string $id): Response
    {
        $trainer = $this->referrals->trainerOfLink($id);

        if ($trainer === null) {
            throw ApiException::notFound('Este enlace no es válido o el entrenador ya no está activo.');
        }

        // Solo el nombre y el id del enlace: el enlace es público.
        return $this->json($response, ['full_name' => $trainer['full_name'], 'link_id' => ReferralLink::encodeId($trainer['id'])]);
    }

    /** El cliente con sesión abre el enlace de otro entrenador y confirma el cambio (D-11). */
    public function changeTrainer(Request $request, Response $response): Response
    {
        $claims = $request->getAttribute('jwt_claims');
        $userId = is_object($claims) ? (int) ($claims->sub ?? 0) : 0;
        $body   = (array) ($request->getParsedBody() ?? []);

        $this->referrals->changeTrainer($userId, is_object($claims) ? (string) ($claims->role ?? '') : '', (string) $body['link_id']);

        $user = new AuthUserRepository()->findById($userId);

        if ($user === false) {
            throw ApiException::unauthorized();
        }

        return $this->json($response, $user->toSessionUser());
    }

    public function linkByCoupon(Request $request, Response $response): Response
    {
        $claims = $request->getAttribute('jwt_claims');
        $userId = is_object($claims) ? (int) ($claims->sub ?? 0) : 0;
        $body   = (array) ($request->getParsedBody() ?? []);

        $this->referrals->linkByCoupon($userId, is_object($claims) ? (string) ($claims->role ?? '') : '', (string) $body['coupon_code']);

        $user = new AuthUserRepository()->findById($userId);

        if ($user === false) {
            throw ApiException::unauthorized();
        }

        return $this->json($response, $user->toSessionUser());
    }
}

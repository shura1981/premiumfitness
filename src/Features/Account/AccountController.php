<?php

declare(strict_types=1);

namespace App\Features\Account;

use App\Features\Auth\AuthUser;
use App\Features\Auth\AuthUserRepository;
use App\Infrastructure\Http\JsonResponder;
use App\Shared\Exceptions\ApiException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * La cuenta de quien tiene la sesión. El id sale siempre del token, nunca de la URL.
 *
 *   GET /api/account   → perfil
 *   PUT /api/account   → nombre y correo
 */
class AccountController
{
    use JsonResponder;

    private readonly AuthUserRepository $users;

    public function __construct()
    {
        $this->users = new AuthUserRepository();
    }

    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, $this->currentUser($request)->toProfile());
    }

    public function update(Request $request, Response $response): Response
    {
        $user     = $this->currentUser($request);
        $body     = (array) ($request->getParsedBody() ?? []);
        $fullName = trim(preg_replace('/\s+/', ' ', (string) $body['full_name']) ?? '');
        $email    = mb_strtolower(trim((string) $body['email']));

        if ($this->users->emailTaken($email, $user->id)) {
            throw ApiException::conflict('email_taken', 'Este correo ya está en uso en otra cuenta.');
        }

        $this->users->updateProfile($user->id, $fullName, $email);

        return $this->json($response, $this->currentUser($request)->toProfile());
    }

    private function currentUser(Request $request): AuthUser
    {
        $claims = $request->getAttribute('jwt_claims');
        $user   = is_object($claims) ? $this->users->findById((int) ($claims->sub ?? 0)) : false;

        if ($user === false || !$user->isActive()) {
            throw ApiException::unauthorized();
        }

        return $user;
    }
}

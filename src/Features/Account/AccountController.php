<?php

declare(strict_types=1);

namespace App\Features\Account;

use App\Features\Auth\AuthUser;
use App\Features\Auth\AuthUserRepository;
use App\Features\User\UserRepository;
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

    /**
     * PUT /api/account/socket — la conexión de esta sesión en el gateway de sockets (spec integraciones/03).
     * El backend le avisa ahí los cambios de sus pedidos. Cada conexión nueva reemplaza la anterior.
     */
    public function registerSocket(Request $request, Response $response): Response
    {
        $user  = $this->currentUser($request);
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = is_string($body['socket_token'] ?? null) ? trim($body['socket_token']) : '';

        // Los ids de Socket.IO son cortos y con caracteres de URL.
        if (preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $token) !== 1) {
            throw ApiException::invalid('La conexión no es válida.', errors: ['socket_token' => 'Conexión no válida.']);
        }

        new UserRepository()->updateSocketToken($user->id, $token);

        return $this->noContent($response);
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

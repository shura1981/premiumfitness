<?php

declare(strict_types=1);

namespace App\Features\Auth;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    private const ACCESS_TOKEN_TTL = 900; // 15 minutes
    private const REFRESH_TOKEN_TTL = 604800; // 7 days

    public function login(Request $request, Response $response): Response
    {
        $parsedBody = $request->getParsedBody();
        $username = $parsedBody['username'] ?? '';
        $password = $parsedBody['password'] ?? '';

        // TODO: Validate credentials against database
        $isValidUser = ($username === 'admin' && $password === 'secret');

        if (!$isValidUser) {
            $response->getBody()->write(json_encode(['error' => 'Invalid credentials']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $userId = 1; // Example ID

        // Generate Tokens (TODO: Implementation depends on your JWT library, e.g., firebase/php-jwt)
        $accessToken = $this->generateJwt($userId, self::ACCESS_TOKEN_TTL);
        $refreshToken = $this->generateJwt($userId, self::REFRESH_TOKEN_TTL);

        // TODO: Optionally save the refresh token in the database to allow revoking

        // Set HttpOnly Cookie for Refresh Token
        $cookieHeader = sprintf(
            'refresh_token=%s; HttpOnly; Secure; SameSite=Strict; Path=/api/auth; Max-Age=%d',
            $refreshToken,
            self::REFRESH_TOKEN_TTL
        );

        $payload = [
            'access_token' => $accessToken,
            'expires_in' => self::ACCESS_TOKEN_TTL,
            'user' => ['id' => $userId, 'username' => $username]
        ];

        $response->getBody()->write(json_encode($payload));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withAddedHeader('Set-Cookie', $cookieHeader)
            ->withStatus(200);
    }

    public function refresh(Request $request, Response $response): Response
    {
        $cookies = $request->getCookieParams();
        $refreshToken = $cookies['refresh_token'] ?? null;

        if (!$refreshToken) {
            return $response->withStatus(401);
        }

        // TODO: Validate the refresh token (signature, expiration, and optionally check in DB)
        $isValid = true; // Assume valid for template
        $userId = 1; // Extract from validated token

        if (!$isValid) {
            return $response->withStatus(401);
        }

        // Generate new Access Token
        $newAccessToken = $this->generateJwt($userId, self::ACCESS_TOKEN_TTL);

        $payload = [
            'access_token' => $newAccessToken,
            'expires_in' => self::ACCESS_TOKEN_TTL
        ];

        $response->getBody()->write(json_encode($payload));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }

    public function logout(Request $request, Response $response): Response
    {
        // Clear the HttpOnly cookie by setting an expiration date in the past
        $cookieHeader = 'refresh_token=; HttpOnly; Secure; SameSite=Strict; Path=/api/auth; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT';

        return $response
            ->withAddedHeader('Set-Cookie', $cookieHeader)
            ->withStatus(204); // No content
    }

    private function generateJwt(int $userId, int $ttl): string
    {
        // Dummy implementation. Use firebase/php-jwt in production.
        return base64_encode(json_encode(['user_id' => $userId, 'exp' => time() + $ttl]));
    }
}

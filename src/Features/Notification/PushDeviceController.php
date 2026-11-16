<?php

declare(strict_types=1);

namespace App\Features\Notification;

use App\Infrastructure\Database\DB;
use App\Infrastructure\Http\JsonResponder;
use App\Shared\Exceptions\ApiException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Dispositivos que reciben push (spec integraciones/01, regla 4).
 *
 *   POST   /api/account/push-devices   → registra el token de este dispositivo
 *   DELETE /api/account/push-devices   → lo quita (al cerrar sesión o desactivar las notificaciones)
 */
class PushDeviceController
{
    use JsonResponder;

    public function register(Request $request, Response $response): Response
    {
        [$userId, $token] = self::input($request);
        $body     = (array) ($request->getParsedBody() ?? []);
        $platform = in_array($body['platform'] ?? 'web', ['web', 'android', 'ios'], true) ? (string) ($body['platform'] ?? 'web') : 'web';

        // El token identifica al dispositivo: si otra cuenta lo tenía (mismo celular), pasa a esta.
        DB::getInstance()->prepare(
            "INSERT INTO push_devices (user_id, app, platform, token) VALUES (?, 'web', ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), platform = VALUES(platform), last_seen_at = NOW()"
        )->execute([$userId, $platform, $token]);

        return $this->noContent($response);
    }

    public function unregister(Request $request, Response $response): Response
    {
        [$userId, $token] = self::input($request);

        DB::getInstance()->prepare('DELETE FROM push_devices WHERE user_id = ? AND token = ?')->execute([$userId, $token]);

        return $this->noContent($response);
    }

    /** @return array{0:int, 1:string} usuario y token */
    private static function input(Request $request): array
    {
        $claims = $request->getAttribute('jwt_claims');
        $userId = is_object($claims) ? (int) ($claims->sub ?? 0) : 0;
        $body   = (array) ($request->getParsedBody() ?? []);
        $token  = is_string($body['token'] ?? null) ? trim($body['token']) : '';

        if ($userId <= 0) {
            throw ApiException::unauthorized();
        }

        if ($token === '' || strlen($token) > 255 || preg_match('/^[A-Za-z0-9_:.\-]+$/', $token) !== 1) {
            throw ApiException::invalid('No pudimos activar las notificaciones en este dispositivo.', errors: ['token' => 'Token no válido.']);
        }

        return [$userId, $token];
    }
}

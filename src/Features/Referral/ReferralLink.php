<?php

declare(strict_types=1);

namespace App\Features\Referral;

/**
 * Enlace del entrenador (regla 12): `/r/c/<id>` trae a un cliente y `/r/t/<id>` a un entrenador.
 * `<id>` es el id del entrenador en base64 (también en su variante para URL).
 */
final readonly class ReferralLink
{
    public const KIND_CLIENT  = 'c';
    public const KIND_TRAINER = 't';

    private function __construct(
        public string $kind,
        public int $trainerId,
    ) {}

    /** Lo que envía la app: `{ kind, id }`. Devuelve null si falta o no es válido. */
    public static function fromRequest(mixed $referral): ?self
    {
        if (!is_array($referral)) {
            return null;
        }

        $kind      = $referral['kind'] ?? null;
        $trainerId = self::decodeId($referral['id'] ?? null);

        if (!in_array($kind, [self::KIND_CLIENT, self::KIND_TRAINER], true) || $trainerId === null) {
            return null;
        }

        return new self($kind, $trainerId);
    }

    public static function decodeId(mixed $encoded): ?int
    {
        if (!is_string($encoded) || $encoded === '' || strlen($encoded) > 32) {
            return null;
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($decoded === false || !ctype_digit($decoded) || strlen($decoded) > 18) {
            return null;
        }

        $id = (int) $decoded;

        return $id > 0 ? $id : null;
    }

    public static function encodeId(int $trainerId): string
    {
        return rtrim(strtr(base64_encode((string) $trainerId), '+/', '-_'), '=');
    }
}

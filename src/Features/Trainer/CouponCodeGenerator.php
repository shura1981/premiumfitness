<?php

declare(strict_types=1);

namespace App\Features\Trainer;

/**
 * Cupón del entrenador: las letras de su primer nombre y un número, único (por ejemplo, JORGE47).
 */
class CouponCodeGenerator
{
    private const MAX_NAME_LENGTH = 10;

    public function __construct(private readonly TrainerRepository $trainers) {}

    public function generate(string $fullName): string
    {
        $base   = $this->base($fullName);
        $digits = 2;

        for ($attempt = 1; ; $attempt++) {
            $code = $base . str_pad((string) random_int(0, (10 ** $digits) - 1), $digits, '0', STR_PAD_LEFT);

            if (!$this->trainers->couponExists($code)) {
                return $code;
            }

            // Si el nombre es muy común, se alarga el número.
            if ($attempt % 5 === 0) {
                $digits++;
            }
        }
    }

    private function base(string $fullName): string
    {
        $firstName = strtok(trim($fullName), ' ') ?: '';
        $ascii     = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $firstName) ?: '';
        $letters   = strtoupper(preg_replace('/[^A-Za-z]/', '', $ascii) ?? '');

        return $letters === '' ? 'PF' : substr($letters, 0, self::MAX_NAME_LENGTH);
    }
}

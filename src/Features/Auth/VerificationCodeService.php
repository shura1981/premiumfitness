<?php

declare(strict_types=1);

namespace App\Features\Auth;

use App\Features\Auth\VerificationCode\VerificationCodeRepository;
use App\Infrastructure\Auth\AuthConfig;
use App\Infrastructure\Notification\NotificationChannelInterface;
use App\Infrastructure\Notification\SmsChannelFactory;
use App\Shared\Exceptions\ApiException;

/**
 * Códigos SMS para verificar el celular y recuperar la contraseña (spec seguridad/01, regla 8).
 *
 *   1. send() invalida los códigos anteriores del mismo propósito, genera uno nuevo,
 *      guarda su hash y lo envía por SMS.
 *   2. verify() cuenta el intento, compara en tiempo constante y marca el código como usado.
 *
 * El código nunca se devuelve por la API ni se escribe en los logs.
 */
class VerificationCodeService
{
    public const PURPOSE_PHONE = 'phone_verification';
    public const PURPOSE_RESET = 'password_reset';

    private readonly VerificationCodeRepository $repository;
    private readonly AuthConfig $config;
    private readonly NotificationChannelInterface $sms;

    public function __construct(?NotificationChannelInterface $sms = null)
    {
        $this->repository = new VerificationCodeRepository();
        $this->config     = AuthConfig::fromEnv();
        $this->sms        = $sms ?? SmsChannelFactory::fromEnv();
    }

    /**
     * Envía un código nuevo. Devuelve los segundos de validez.
     *
     * @throws ApiException 429 si el celular superó los envíos del día
     */
    public function send(int $userId, string $purpose, string $phone): int
    {
        if ($this->repository->countSentLastDay($phone) >= $this->config->otpMaxSendsPerDay) {
            throw ApiException::tooManyRequests(3600, 'Ya enviamos varios códigos a este celular. Inténtalo más tarde.');
        }

        $code = $this->generateCode();

        $this->repository->invalidate($userId, $purpose);
        $this->repository->create($userId, $this->hash($userId, $code), $purpose, $phone, time() + $this->config->otpTtlSeconds);

        $this->sms->send($phone, 'PremiumFitness', sprintf(
            'PremiumFitness: tu código es %s. Vence en %d minutos. No lo compartas con nadie.',
            $code,
            intdiv($this->config->otpTtlSeconds, 60),
        ));

        return $this->config->otpTtlSeconds;
    }

    /**
     * Comprueba el código. Cada llamada gasta un intento; superado el máximo, el código deja
     * de servir aunque sea el correcto.
     */
    public function verify(int $userId, string $purpose, string $code): bool
    {
        $record = $this->repository->findActive($userId, $purpose);

        if ($record === false) {
            return false;
        }

        if ($record->attempts >= $this->config->otpMaxAttempts) {
            $this->repository->markAsUsed($record->id);

            return false;
        }

        $this->repository->addAttempt($record->id);

        if (!hash_equals($record->code, $this->hash($userId, $code))) {
            return false;
        }

        $this->repository->markAsUsed($record->id);

        return true;
    }

    public function ttlSeconds(): int
    {
        return $this->config->otpTtlSeconds;
    }

    private function generateCode(): string
    {
        $max = (10 ** $this->config->otpLength) - 1;

        return str_pad((string) random_int(0, $max), $this->config->otpLength, '0', STR_PAD_LEFT);
    }

    /** Hash con clave y atado al usuario: un volcado de la base no permite probar los códigos. */
    private function hash(int $userId, string $code): string
    {
        return $this->config->hash($userId . ':' . trim($code));
    }
}

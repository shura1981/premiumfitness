<?php

declare(strict_types=1);

namespace App\Features\Referral;

use App\Features\Notification\PushOutbox;
use App\Features\Trainer\TrainerRepository;
use App\Infrastructure\Config\BusinessConfig;
use App\Shared\Exceptions\ApiException;

/**
 * Vínculos de referido (reglas 12 y 13; specs negocio/01, negocio/02 y arquitectura/02):
 *   - cliente → entrenador, por enlace o por cupón: uno solo y no lo cambia por sí mismo;
 *   - entrenador A → entrenador B, solo cuando B se registra por el enlace de A.
 * Un enlace inválido o de un entrenador inactivo no asocia a nadie.
 */
class ReferralService
{
    private readonly ReferralRepository $referrals;
    private readonly TrainerRepository $trainers;
    private readonly BusinessConfig $business;

    public function __construct()
    {
        $this->referrals = new ReferralRepository();
        $this->trainers  = new TrainerRepository();
        $this->business  = BusinessConfig::fromEnv();
    }

    /**
     * Entrenador de un enlace, para mostrar su nombre antes de registrarse.
     *
     * @return array{id:int, full_name:string, coupon_code:string}|null
     */
    public function trainerOfLink(string $encodedId): ?array
    {
        $trainerId = ReferralLink::decodeId($encodedId);

        return $trainerId === null ? null : $this->trainers->findActiveById($trainerId);
    }

    /**
     * Aplica el enlace guardado cuando la cuenta entra: al verificar el celular o al iniciar sesión.
     *
     * @param bool $isNewAccount true al verificar el celular. Una cuenta de entrenador que ya
     *                           existía no gana referente por abrir un enlace después.
     */
    public function applyLink(int $userId, string $role, ?ReferralLink $link, bool $isNewAccount): void
    {
        if ($link === null || $link->trainerId === $userId) {
            return;
        }

        $trainer = $this->trainers->findActiveById($link->trainerId);

        if ($trainer === null) {
            return;
        }

        if ($link->kind === ReferralLink::KIND_CLIENT && $role === 'client' && $this->referrals->linkClient($userId, $trainer['id'], 'link')) {
            $this->notifyNewClient($trainer['id'], $userId);
        }

        if (
            $link->kind === ReferralLink::KIND_TRAINER && $role === 'trainer' && $isNewAccount
            && $this->referrals->linkTrainer($trainer['id'], $userId, $this->business->trainerReferralPoints)
        ) {
            PushOutbox::enqueue(
                $trainer['id'],
                'trainer_referred',
                'Un entrenador se registró con tu enlace',
                sprintf(
                    'Ganarás %s puntos cuando uno de sus clientes haga su primera compra.',
                    number_format($this->business->trainerReferralPoints, 0, ',', '.'),
                ),
                ['route' => '/entrenador/referidos'],
                "trainer-referral:{$userId}",
            );
        }
    }

    /** El aviso no lleva datos del cliente: solo que hay uno nuevo. */
    private function notifyNewClient(int $trainerId, int $clientId): void
    {
        PushOutbox::enqueue(
            $trainerId,
            'client_linked',
            'Tienes un cliente nuevo',
            'Un cliente se asoció a ti. Sus compras te darán puntos y comisión.',
            ['route' => '/entrenador/referidos'],
            "client-link:{$clientId}",
        );
    }

    /**
     * El cliente escribe el cupón de su entrenador.
     *
     * @throws ApiException
     */
    public function linkByCoupon(int $userId, string $role, string $couponCode): void
    {
        if ($role !== 'client') {
            throw ApiException::forbidden('El cupón es para cuentas de cliente.');
        }

        if ($this->referrals->clientHasTrainer($userId)) {
            throw ApiException::conflict('already_linked', 'Tu cuenta ya tiene un entrenador asociado.');
        }

        $trainer = $this->trainers->findActiveByCoupon(strtoupper(trim($couponCode)));

        if ($trainer === null) {
            throw new ApiException(404, 'coupon_not_found', 'No encontramos ese cupón. Revísalo con tu entrenador.');
        }

        if (!$this->referrals->linkClient($userId, $trainer['id'], 'coupon')) {
            throw ApiException::conflict('already_linked', 'Tu cuenta ya tiene un entrenador asociado.');
        }

        $this->notifyNewClient($trainer['id'], $userId);
    }
}

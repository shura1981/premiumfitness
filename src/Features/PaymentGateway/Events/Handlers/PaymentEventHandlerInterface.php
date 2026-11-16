<?php

declare(strict_types=1);

namespace App\Features\PaymentGateway\Events\Handlers;

use App\Features\PaymentGateway\Events\PaymentEvent;

/**
 * Contrato para handlers post-actualización de pago.
 *
 * Implementaciones suelen retornar `supports() === $event->result->isApproved()`
 * para sólo reaccionar a APROBADA. Para sumar lógica nueva (ej. facturación,
 * sincronización SAP, push notifications) basta con crear otra clase que
 * implemente esta interfaz y registrarla en el dispatcher — Open/Closed.
 */
interface PaymentEventHandlerInterface
{
    public function supports(PaymentEvent $event): bool;

    public function handle(PaymentEvent $event): void;
}

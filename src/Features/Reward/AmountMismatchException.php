<?php

declare(strict_types=1);

namespace App\Features\Reward;

use RuntimeException;

/**
 * La pasarela reporta un pago por menos de lo que vale el pedido: no se aprueba.
 */
class AmountMismatchException extends RuntimeException {}

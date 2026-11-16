<?php

declare(strict_types=1);

namespace App\Features\Point;

use RuntimeException;

/**
 * El entrenador no tiene puntos vigentes suficientes para la operación.
 */
class NotEnoughPointsException extends RuntimeException {}

<?php

declare(strict_types=1);

use App\Infrastructure\App;

require_once __DIR__ . '/load/bootstrap.php';

App::create()->run();

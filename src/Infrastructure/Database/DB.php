<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use LogicException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Connection manager — responsabilidad única: proveer la instancia PDO.
 * Los métodos de query viven en AbstractRepository.
 *
 * Uso:
 *   DB::boot(DatabaseConfig::fromEnv());   // una sola vez, en App::create()
 *   DB::getInstance();                     // en cualquier repositorio
 */
class DB
{
    private static ?PDO $instance = null;
    private static ?DatabaseConfig $config = null;

    private function __construct() {}

    public static function boot(DatabaseConfig $config): void
    {
        self::$config = $config;
    }

    private static function connect(): PDO
    {
        $config = self::$config
            ?? throw new LogicException('DB not configured. Call DB::boot() first in App::create().');

        try {
            return new PDO(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $config->host,
                    $config->port,
                    $config->name,
                    $config->charset,
                ),
                $config->user,
                $config->pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    // La app trabaja en hora de Colombia (load/bootstrap.php). La sesión de la base usa la
                    // misma hora, sin depender de cómo esté configurado el servidor: NOW() y las fechas
                    // que escribe PHP quedan en el mismo huso. Colombia no tiene horario de verano.
                    \Pdo\Mysql::ATTR_INIT_COMMAND => "SET time_zone = '-05:00'",
                ]
            );
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed.', previous: $e);
        }
    }

    public static function getInstance(): PDO
    {
        return self::$instance ??= self::connect();
    }
}

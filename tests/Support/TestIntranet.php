<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use RuntimeException;

/**
 * Base de la intranet para las pruebas. Las pruebas crean productos y agentes, y eso nunca se hace
 * en `crisenri_intranet`: de ella solo se copia la estructura (CREATE TABLE ... LIKE), sin datos.
 */
final class TestIntranet
{
    public const string DATABASE = 'crisenri_intranet_pruebas';
    private const string SOURCE  = 'crisenri_intranet';
    private const array TABLES   = ['tb_empleados', 'tb_productos_megaplexstars'];
    private const string FIXTURE = __DIR__ . '/../fixtures/intranet-pruebas.sql';

    /** Crea la base de pruebas si falta y carga sus datos (idempotente). */
    public static function prepare(): void
    {
        $pdo = self::connect(null);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . self::DATABASE . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        foreach (self::TABLES as $table) {
            if (self::exists($pdo, self::SOURCE, $table) && !self::exists($pdo, self::DATABASE, $table)) {
                $pdo->exec(sprintf('CREATE TABLE `%s`.`%s` LIKE `%s`.`%s`', self::DATABASE, $table, self::SOURCE, $table));
            }
        }

        self::connect(self::DATABASE)->exec((string) file_get_contents(self::FIXTURE));
    }

    /** Conexión con el usuario del .env, solo en local y nunca a `crisenri_intranet`. */
    public static function connect(?string $database): PDO
    {
        $host = (string) $_ENV['DB_HOST'];

        if (!in_array($host, ['127.0.0.1', 'localhost'], true)) {
            throw new RuntimeException('Las pruebas solo corren contra la base local.');
        }
        if ($database !== null && $database !== self::DATABASE) {
            throw new RuntimeException('Las pruebas solo escriben en ' . self::DATABASE . '.');
        }

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;%scharset=utf8mb4', $host, (int) ($_ENV['DB_PORT'] ?? 3306), $database !== null ? "dbname={$database};" : ''),
            (string) $_ENV['DB_USER'],
            (string) $_ENV['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        // Los productos y agentes de prueba solo llenan las columnas que usa la app; la tabla copiada
        // de la intranet puede tener otras obligatorias, que toman su valor vacío.
        $pdo->exec("SET SESSION sql_mode = ''");

        return $pdo;
    }

    private static function exists(PDO $pdo, string $database, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
        $stmt->execute([$database, $table]);

        return (int) $stmt->fetchColumn() > 0;
    }
}

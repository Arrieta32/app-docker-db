<?php
declare(strict_types=1);

namespace App\Infrastructure;

use PDO;
use PDOException;
use Throwable;

final class DatabaseConnection
{
    private static ?PDO $instance = null;
    private static bool $isFallbackMode = false;
    private static bool $attempted = false;
    private static ?string $lastError = null;

    private function __construct() {}

    private function __clone() {}

    public function __wakeup(): void
    {
        throw new \Exception("No se permite des-serializar una instancia Singleton.");
    }

    public static function getConnection(): ?PDO
    {
        if (self::$attempted) {
            return self::$instance;
        }
        self::$attempted = true;

//continúa desde la diapositiva 24
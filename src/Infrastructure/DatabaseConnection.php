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

   
    private function __construct()
    {
    }


    private function __clone()
    {
    }

    /**
     * Evita la deserialización de la instancia Singleton.
     * 
     * @throws \Exception
     */
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

        $host = getenv('DB_HOST') ?: 'db';
        $port = getenv('DB_PORT') ?: '5432';
        $dbname = getenv('DB_NAME') ?: 'app_db';
        $user = getenv('DB_USER') ?: 'app_user';
        $pass = getenv('DB_PASS') ?: 'app_secret_password';

        // DSN PostgreSQL con tiempo de espera de conexión de 2 segundos
        $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s;connect_timeout=2", $host, $port, $dbname);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 2,
        ];

        try {
            self::$instance = new PDO($dsn, $user, $pass, $options);
            self::$isFallbackMode = false;
            self::$lastError = null;
        } catch (PDOException | Throwable $e) {
            self::$instance = null;
            self::$isFallbackMode = true;
            self::$lastError = $e->getMessage();

            error_log(sprintf(
                "[DatabaseConnection WARNING] Fallo al conectar a PostgreSQL (%s:%s/%s): %s. Activando Modo Contingencia JSON.",
                $host,
                $port,
                $dbname,
                $e->getMessage()
            ));
        }

        return self::$instance;
    }

    /**
     * Indica si el sistema está operando en Modo Contingencia por caída de PostgreSQL.
     */
    public static function isFallbackMode(): bool
    {
        if (!self::$attempted) {
            self::getConnection();
        }

        return self::$isFallbackMode;
    }

    /**
     * Retorna la descripción legible del estado actual del motor de persistencia.
     */
    public static function getEngineStatus(): string
    {
        if (!self::$attempted) {
            self::getConnection();
        }

        return self::$isFallbackMode ? "Modo Contingencia JSON" : "PostgreSQL Activo";
    }

    /**
     * Retorna el último mensaje de error registrado durante la conexión, si aplica.
     */
    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * Reinicia el estado estático para permitir reintentos de conexión.
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$isFallbackMode = false;
        self::$attempted = false;
        self::$lastError = null;
    }
}

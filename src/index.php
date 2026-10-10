<?php

declare(strict_types=1);

/**
 * Punto de entrada principal - Agendamiento Médico (Sesión 1: Fundación, Conexión y Resiliencia)
 * PHP 8.4 • Docker Compose • PostgreSQL 16
 */

require_once __DIR__ . '/bootstrap.php';

use App\Infrastructure\DatabaseConnection;
use App\Infrastructure\JsonFallbackStorage;

$engineStatus = DatabaseConnection::getEngineStatus();
$isFallback = DatabaseConnection::isFallbackMode();

// Si la petición proviene de un navegador web, redirigir al Dashboard de Resiliencia
if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header('Location: /public/test_resiliencia.php');
    exit;
}

// Salida legible para invocaciones CLI o APIs
echo "===========================================================\n";
echo " AGENDAMIENTO MÉDICO - SESIÓN 1: FUNDACIÓN Y RESILIENCIA  \n";
echo "===========================================================\n";
echo "Motor Activo: " . $engineStatus . "\n";
echo "Modo Contingencia: " . ($isFallback ? "ACTIVO" : "INACTIVO") . "\n";
echo "Ruta Fallback: " . (new JsonFallbackStorage())->getFilePath() . "\n";
echo "===========================================================\n";
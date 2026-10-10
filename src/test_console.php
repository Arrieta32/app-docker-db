<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Infrastructure\DatabaseConnection;
use App\Infrastructure\JsonFallbackStorage;

/**
 * Script CLI de Validación de Consola y Diagnóstico de Resiliencia
 * Modulo: Alta de Paciente (Superclase Persona + Subclase Paciente)
 * Ejecución: php src/test_console.php
 */

echo "===================================================================\n";
echo "  SISTEMA DE AGENDAMIENTO MÉDICO - DIAGNÓSTICO PACIENTES (CLI)   \n";
echo "===================================================================\n\n";

echo "📌 Versión de PHP: " . PHP_VERSION . "\n";
echo "📌 Entorno: CLI\n";

// 1. Diagnóstico de la conexión a la Base de Datos
$engineStatus = DatabaseConnection::getEngineStatus();
$isFallbackMode = DatabaseConnection::isFallbackMode();
$pdo = DatabaseConnection::getConnection();

echo "📌 Estado del Motor: [" . $engineStatus . "]\n";

if (!$isFallbackMode && $pdo !== null) {
    echo "🟢 CONEXIÓN PRINCIPAL: PostgreSQL 16 Conectado exitosamente.\n";
    try {
        $stmtPersona = $pdo->query('SELECT COUNT(*) AS total FROM "Persona"');
        $personaCount = $stmtPersona ? $stmtPersona->fetchColumn() : 0;

        $stmtPaciente = $pdo->query('SELECT COUNT(*) AS total FROM "Paciente"');
        $pacienteCount = $stmtPaciente ? $stmtPaciente->fetchColumn() : 0;

        echo "   ↳ Personas en BD PostgreSQL (Superclase): " . $personaCount . "\n";
        echo "   ↳ Pacientes en BD PostgreSQL (Subclase):  " . $pacienteCount . "\n";

        // Prueba rápida de inserción transaccional de un Paciente vía CLI
        $docTest = (string)rand(1000000, 9999999);
        $pdo->beginTransaction();

        $stmtInsPersona = $pdo->prepare('
            INSERT INTO "Persona" (
                "IdEmpresa", "NumeroDocumento", "NombreCompleto", "FechaNacimiento", "Telefono",
                "CatTipoDoc", "ItemTipoDoc", "CatSexo", "ItemSexo", "CatCiudad", "ItemCiudad", "Activo"
            ) VALUES (
                1, :doc, \'Paciente Prueba CLI\', \'1990-01-01\', \'0981-000000\',
                \'TIPO_DOC\', \'CI\', \'SEXO\', \'M\', \'CIUDAD\', \'ASU\', true
            ) RETURNING "IdPersona"
        ');
        $stmtInsPersona->execute([':doc' => $docTest]);
        $idPersonaNuevo = (int)$stmtInsPersona->fetchColumn();

        $stmtInsPaciente = $pdo->prepare('INSERT INTO "Paciente" ("IdPersona") VALUES (:id)');
        $stmtInsPaciente->execute([':id' => $idPersonaNuevo]);

        $pdo->commit();
        echo "   ↳ Prueba Transaccional CLI (Persona #{$idPersonaNuevo} + Paciente #{$idPersonaNuevo}): [OK]\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "   ⚠️ Error en prueba transaccional: " . $e->getMessage() . "\n";
    }
} else {
    echo "⚠️ CONTINGENCIA ACTIVA: PostgreSQL fuera de línea.\n";
    if ($lastError = DatabaseConnection::getLastError()) {
        echo "   ↳ Razón registrada: " . $lastError . "\n";
    }
}

// 2. Diagnóstico del Almacenamiento Fallback JSON
echo "\n📁 Verificación de Almacenamiento JSON (data/pacientes_fallback.json):\n";
$fallbackStorage = new JsonFallbackStorage();
$pacientesFallback = $fallbackStorage->obtenerPacientes();
$filePath = $fallbackStorage->getFilePath();

echo "   ↳ Ruta del archivo: " . $filePath . "\n";
echo "   ↳ Permiso de Escritura en carpeta 'data/': " . (is_writable(dirname($filePath)) ? "SÍ (775/777 OK)" : "NO (Revisar permisos)") . "\n";
echo "   ↳ Pacientes registrados en JSON Contingencia: " . count($pacientesFallback) . "\n";

// 3. Simulación de escritura en modo de prueba CLI Fallback Storage
$testPac = [
    'numero_documento' => (string)rand(1000000, 9999999),
    'nombre_completo'  => 'Paciente Contingencia CLI',
    'fecha_nacimiento' => '1995-10-10',
    'telefono'        => '0971-000111',
    'item_tipo_doc'     => 'CI',
    'item_sexo'        => 'F',
    'item_ciudad'      => 'ASU',
    'origen'           => 'Validación CLI'
];

$guardadoExitoso = $fallbackStorage->guardarPaciente($testPac);
if ($guardadoExitoso) {
    echo "   ↳ Prueba de persistencia en Fallback Storage: [OK]\n";
} else {
    echo "   ↳ Prueba de persistencia en Fallback Storage: [FALLÓ]\n";
}

echo "\n-------------------------------------------------------------------\n";
echo "  RESULTADO: El motor respondió como [" . $engineStatus . "]\n";
echo "===================================================================\n";

exit(0);

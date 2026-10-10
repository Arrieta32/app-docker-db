<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Clase JsonFallbackStorage
 * 
 * Gestiona el almacenamiento de contingencia en disco en formato JSON (data/pacientes_fallback.json).
 * Permite mantener la operatividad del sistema cuando la base de datos PostgreSQL se encuentra fuera de línea.
 */
class JsonFallbackStorage
{
    private string $filePath;

    /**
     * Inicializa el almacenamiento especificando o autodetectando la ruta del archivo JSON.
     */
    public function __construct(?string $filePath = null)
    {
        if ($filePath === null) {
            $this->filePath = dirname(__DIR__, 2) . '/data/pacientes_fallback.json';
        } else {
            $this->filePath = $filePath;
        }

        $this->inicializarArchivo();
    }

    /**
     * Crea el directorio y el archivo JSON con un arreglo vacío [] si aún no existen,
     * otorgando permisos de lectura y escritura globales (0666 / 0777).
     */
    private function inicializarArchivo(): void
    {
        $dir = dirname($this->filePath);

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
            @chmod($dir, 0777);
        }

        if (!file_exists($this->filePath)) {
            @file_put_contents(
                $this->filePath,
                json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );
            @chmod($this->filePath, 0666);
        } else {
            // Asegurar que el usuario de la web (www-data) tenga permisos de escritura sobre archivos creados por CLI (root)
            if (!is_writable($this->filePath)) {
                @chmod($this->filePath, 0666);
            }
        }
    }

    /**
     * Obtiene el listado completo de pacientes desde el archivo JSON de contingencia.
     * 
     * @return array<int, array<string, mixed>>
     */
    public function obtenerPacientes(): array
    {
        if (!file_exists($this->filePath) || !is_readable($this->filePath)) {
            return [];
        }

        $jsonContent = file_get_contents($this->filePath);
        if ($jsonContent === false || trim($jsonContent) === '') {
            return [];
        }

        $data = json_decode($jsonContent, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Agrega un nuevo paciente al archivo JSON utilizando bloqueo exclusivo (LOCK_EX)
     * para garantizar concurrencia segura.
     * 
     * @param array<string, mixed> $paciente
     */
    public function guardarPaciente(array $paciente): bool
    {
        $this->inicializarArchivo();

        $pacientes = $this->obtenerPacientes();

        // Enriquecer el paciente con metadata de contingencia
        if (!isset($paciente['id_persona'])) {
            $paciente['id_persona'] = 'FALLBACK-' . date('YmdHis') . '-' . sprintf('%04d', rand(1, 9999));
        }
        if (!isset($paciente['creado_en'])) {
            $paciente['creado_en'] = date('Y-m-d H:i:s');
        }
        if (!isset($paciente['origen'])) {
            $paciente['origen'] = 'Contingencia JSON Local (data/pacientes_fallback.json)';
        }

        $pacientes[] = $paciente;

        $jsonFinal = json_encode($pacientes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($jsonFinal === false) {
            return false;
        }

        if (!is_writable($this->filePath)) {
            @chmod($this->filePath, 0666);
        }

        $result = @file_put_contents($this->filePath, $jsonFinal, LOCK_EX) !== false;
        if ($result) {
            @chmod($this->filePath, 0666);
        }

        return $result;
    }

    /**
     * Compatibilidad con citas en contingencia.
     * @return array<int, array<string, mixed>>
     */
    public function obtenerCitas(): array
    {
        return $this->obtenerPacientes();
    }

    /**
     * Compatibilidad con citas en contingencia.
     * @param array<string, mixed> $cita
     */
    public function guardarCita(array $cita): bool
    {
        return $this->guardarPaciente($cita);
    }

    /**
     * Retorna la ruta absoluta del archivo de almacenamiento JSON.
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }
}

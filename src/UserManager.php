<?php

class UserManager
{
    private string $filePath;

    public function __construct(string $filename = 'users.json')
    {
        // Por esto (se creará src/data/):
        $dir = __DIR__ . '/data';
        
        // Crear el directorio si no existe 
        // con permisos de escritura
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->filePath = $dir . '/' . $filename;

   
        if (!file_exists($this->filePath)) {
            file_put_contents($this->filePath, json_encode([], JSON_PRETTY_PRINT));
        }
    }

    public function listarUsuarios(): array
    {
        if (!file_exists($this->filePath) || !is_readable($this->filePath)) {
            return [];
        }

        $jsonContent = file_get_contents($this->filePath);
        $data = json_decode($jsonContent, true);

        return is_array($data) ? $data : [];
    }

    /** Agrega un nuevo usuario sin sobrescribir los anteriores   */
    public function guardarUsuario(array $nuevoUsuario): bool
    {
        // 1. Obtener la lista actual
        $usuarios = $this->listarUsuarios();

        // 2. Agregar fecha de registro e ID incremental simple
        $nuevoUsuario['id'] = count($usuarios) + 1;
        $nuevoUsuario['creado_en'] = date('Y-m-d H:i:s');

        $usuarios[] = $nuevoUsuario;

        // 3. Serializar y guardar con bloqueo de archivo (LOCK_EX)
        $jsonFinal = json_encode($usuarios, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        return file_put_contents($this->filePath, $jsonFinal, LOCK_EX) !== false;
    }
}
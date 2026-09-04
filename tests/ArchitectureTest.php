<?php
// tests/ArchitectureTest.php

echo "Iniciando pruebas automatizadas...\n";

// 1. Validar que el archivo index.php existe
$file = __DIR__ . '/../src/index.php';
if (!file_exists($file)) {
    fwrite(STDERR, "❌ ERROR: src/index.php no encontrado.\n");
    exit(1);
}

// 2. Comprobar sintaxis de PHP (PHP Linting)
$output = [];
$return_var = 0;
exec("php -l " . escapeshellarg($file), $output, $return_var);

if ($return_var !== 0) {
    fwrite(STDERR, "❌ ERROR: Error de sintaxis detectado en index.php.\n");
    exit(1);
}

echo "✔ Sintaxis de PHP correcta en src/index.php.\n";

// 3. Validar que no se hayan dejado credenciales quemadas en el código
$content = file_get_contents($file);
if (str_contains($content, 'password123_hardcoded')) {
    fwrite(STDERR, "❌ ERROR: Credenciales en texto duro detectadas.\n");
    exit(1);
}

echo "✔ Verificación de seguridad básica superada.\n";
echo "🎉 Todas las pruebas pasaron con éxito.\n";
exit(0);
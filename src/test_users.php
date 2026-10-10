<?php
require_once __DIR__ . '/UserManager.php';

$manager = new UserManager();
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $rol    = trim($_POST['rol'] ?? 'Estudiante');

    if ($nombre !== '' && $email !== '') {
        $guardado = $manager->guardarUsuario([
            'nombre' => $nombre,
            'email'  => $email,
            'rol'    => $rol
        ]);

        $mensaje = $guardado 
            ? "<p style='color: green;'>✔ Usuario registrado correctamente en users.json.</p>" 
            : "<p style='color: red;'>❌ Error al escribir en el archivo.</p>";
    } else {
        $mensaje = "<p style='color: orange;'>⚠️ Por favor llena todos los campos.</p>";
    }
}

$usuarios = $manager->listarUsuarios();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Laboratorio POO - Gestión de Usuarios JSON</title>
    <style>
        body { font-family: sans-serif; max-width: 700px; margin: 30px auto; padding: 0 15px; }
        input, select, button { padding: 8px; margin: 5px 0; width: 100%; box-sizing: border-box; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #cbd5e1; padding: 8px; text-align: left; }
        th { background: #f1f5f9; }
    </style>
</head>
<body>

    <h2>Registro de Usuarios (POO + JSON)</h2>
    <?= $mensaje ?>

    <form method="POST">
        <label>Nombre Completo:</label>
        <input type="text" name="nombre" required placeholder="Ej. Ana Pérez">

        <label>Correo Institucional:</label>
        <input type="email" name="email" required placeholder="Ej. aperez@ug.edu.ec">

        <label>Rol:</label>
        <select name="rol">
            <option value="Estudiante">Estudiante</option>
            <option value="Docente">Docente</option>
            <option value="Admin">Admin</option>
        </select>

        <button type="submit" style="background: #2563eb; color: white; border: none; font-weight: bold; cursor: pointer;">
            Guardar Usuario
        </button>
    </form>

    <h3>Usuarios Persistidos en Archivo:</h3>
    <?php if (empty($usuarios)): ?>
        <p>No hay usuarios registrados aún.</p>
    <?php else: ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Email</th>
                <th>Rol</th>
                <th>Fecha Registro</th>
            </tr>
            <?php foreach ($usuarios as $u): ?>
            <tr>
                <td><?= htmlspecialchars((string)$u['id']) ?></td>
                <td><?= htmlspecialchars($u['nombre']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars($u['rol']) ?></td>
                <td><?= htmlspecialchars($u['creado_en']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

</body>
</html>
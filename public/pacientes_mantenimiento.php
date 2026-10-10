<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Infrastructure\DatabaseConnection;
use App\Infrastructure\JsonFallbackStorage;

$actionMessage = null;
$actionMessageType = 'info';

// Instanciar el almacenamiento de contingencia para Pacientes
$fallbackStorage = new JsonFallbackStorage();

// Procesar el alta de Paciente (Superclase Persona + Subclase Paciente)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $numeroDocumento = trim($_POST['numero_documento'] ?? '');
    $nombreCompleto  = trim($_POST['nombre_completo'] ?? '');
    $fechaNacimiento = trim($_POST['fecha_nacimiento'] ?? '1995-05-15');
    $telefono        = trim($_POST['telefono'] ?? '');
    $itemTipoDoc     = trim($_POST['item_tipo_doc'] ?? 'CI');
    $itemSexo        = trim($_POST['item_sexo'] ?? 'M');
    $itemCiudad      = trim($_POST['item_ciudad'] ?? 'ASU');

    if (empty($numeroDocumento)) {
        $numeroDocumento = (string)rand(1000000, 9999999);
    }
    if (empty($nombreCompleto)) {
        $nombreCompleto = 'Paciente Demo ' . rand(100, 999);
    }

    $isFallback = DatabaseConnection::isFallbackMode();

    if (!$isFallback) {
        $pdo = DatabaseConnection::getConnection();
        if ($pdo !== null) {
            try {
                // Iniciar transacción explícita para garantizar inserción atómica en ambas tablas
                $pdo->beginTransaction();

                // 1. Obtener IdEmpresa existente
                $idEmpresa = (int)($pdo->query('SELECT "IdEmpresa" FROM "Empresa" LIMIT 1')->fetchColumn() ?: 1);

                // 2. Insertar en la tabla SUPERCLASE "Persona"
                $stmtPersona = $pdo->prepare('
                    INSERT INTO "Persona" (
                        "IdEmpresa", 
                        "NumeroDocumento", 
                        "NombreCompleto", 
                        "FechaNacimiento", 
                        "Telefono",
                        "CatTipoDoc", "ItemTipoDoc",
                        "CatSexo", "ItemSexo",
                        "CatCiudad", "ItemCiudad",
                        "Activo"
                    ) VALUES (
                        :idEmpresa,
                        :numeroDocumento,
                        :nombreCompleto,
                        :fechaNacimiento,
                        :telefono,
                        \'TIPO_DOC\', :itemTipoDoc,
                        \'SEXO\', :itemSexo,
                        \'CIUDAD\', :itemCiudad,
                        true
                    ) RETURNING "IdPersona"
                ');

                $stmtPersona->execute([
                    ':idEmpresa'       => $idEmpresa,
                    ':numeroDocumento' => $numeroDocumento,
                    ':nombreCompleto'  => $nombreCompleto,
                    ':fechaNacimiento' => $fechaNacimiento,
                    ':telefono'        => $telefono ?: '0981-123456',
                    ':itemTipoDoc'     => $itemTipoDoc,
                    ':itemSexo'        => $itemSexo,
                    ':itemCiudad'      => $itemCiudad,
                ]);

                $idPersona = (int)$stmtPersona->fetchColumn();

                // 3. Insertar en la tabla SUBCLASE "Paciente" (IS-A Persona)
                $stmtPaciente = $pdo->prepare('
                    INSERT INTO "Paciente" ("IdPersona", "IdSeguro", "Activo")
                    VALUES (:idPersona, NULL, true)
                ');

                $stmtPaciente->execute([
                    ':idPersona' => $idPersona
                ]);

                // Confirmar transacción en ambas tablas
                $pdo->commit();

                $actionMessage = "🟢 <strong>Paciente Creado Exitosamente:</strong> Transacción atómica ejecutada en la Superclase <code>Persona</code> (ID #{$idPersona}) y Subclase <code>Paciente</code> (ID #{$idPersona}) en PostgreSQL 16.";
                $actionMessageType = "success";
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                // En caso de error de BD, conmutar y guardar en fallback JSON
                $guardado = $fallbackStorage->guardarPaciente([
                    'numero_documento' => $numeroDocumento,
                    'nombre_completo'  => $nombreCompleto,
                    'fecha_nacimiento' => $fechaNacimiento,
                    'telefono'        => $telefono,
                    'item_tipo_doc'     => $itemTipoDoc,
                    'item_sexo'        => $itemSexo,
                    'item_ciudad'      => $itemCiudad,
                    'origen'           => 'Fallback por Excepción BD',
                    'error_db'         => $e->getMessage()
                ]);

                $actionMessage = "⚠️ <strong>Excepción BD:</strong> Error al escribir en PostgreSQL (<em>" . htmlspecialchars($e->getMessage()) . "</em>). Paciente registrado en <strong>data/pacientes_fallback.json</strong>.";
                $actionMessageType = "warning";
            }
        } else {
            $isFallback = true;
        }
    }

    if ($isFallback && $actionMessage === null) {
        $guardado = $fallbackStorage->guardarPaciente([
            'numero_documento' => $numeroDocumento,
            'nombre_completo'  => $nombreCompleto,
            'fecha_nacimiento' => $fechaNacimiento,
            'telefono'        => $telefono,
            'item_tipo_doc'     => $itemTipoDoc,
            'item_sexo'        => $itemSexo,
            'item_ciudad'      => $itemCiudad,
            'origen'           => 'Contingencia JSON Automática'
        ]);

        if ($guardado) {
            $actionMessage = "⚠️ <strong>Modo Contingencia Activo:</strong> PostgreSQL está fuera de línea. Paciente registrado exitosamente en <code>data/pacientes_fallback.json</code>.";
            $actionMessageType = "warning";
        } else {
            $actionMessage = "❌ <strong>Error:</strong> No se pudo escribir en el archivo de contingencia JSON.";
            $actionMessageType = "danger";
        }
    }
}

// Consultar pacientes registrados
$isFallbackMode = DatabaseConnection::isFallbackMode();
$engineStatus   = DatabaseConnection::getEngineStatus();
$pdoConn        = DatabaseConnection::getConnection();

$pacientesBD = [];
if (!$isFallbackMode && $pdoConn !== null) {
    try {
        $stmt = $pdoConn->query('
            SELECT 
                p."IdPersona",
                p."NumeroDocumento",
                p."NombreCompleto",
                p."FechaNacimiento",
                p."Telefono",
                p."ItemTipoDoc",
                p."ItemSexo",
                p."ItemCiudad",
                pa."Activo" AS "PacienteActivo"
            FROM "Paciente" pa
            JOIN "Persona" p ON pa."IdPersona" = p."IdPersona"
            ORDER BY p."IdPersona" DESC
        ');
        $pacientesBD = $stmt ? $stmt->fetchAll() : [];
    } catch (Throwable $e) {
        $pacientesBD = [];
    }
}

$pacientesFallback = $fallbackStorage->obtenerPacientes();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mantenimiento Paciente | Agendamiento Médico</title>
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome CDN -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #0f172a;
            --card-bg: #1e293b;
            --card-border: #334155;
            --accent-green: #10b981;
            --accent-yellow: #f59e0b;
            --accent-blue: #3b82f6;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #0b0f19;
            color: var(--text-main);
            min-height: 100vh;
            padding-bottom: 3rem;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        .navbar-brand {
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .hero-banner {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border-bottom: 1px solid var(--card-border);
            padding: 1.75rem 0;
            margin-bottom: 2rem;
            transition: background 0.3s ease, border-color 0.3s ease;
        }

        .card-custom {
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 1rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
            transition: transform 0.2s ease, box-shadow 0.2s ease, background-color 0.3s ease, border-color 0.3s ease;
        }

        .card-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px -5px rgba(0, 0, 0, 0.4);
        }

        .form-control, .form-select {
            background-color: #0f172a;
            border: 1px solid #334155;
            color: #f8fafc;
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            transition: background-color 0.3s ease, border-color 0.3s ease, color 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            background-color: #0f172a;
            border-color: #3b82f6;
            color: #f8fafc;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25);
        }

        .table-dark-custom {
            --bs-table-bg: transparent;
            --bs-table-color: #f8fafc;
            --bs-table-border-color: #334155;
        }

        /* Despliegue dinámico del menú al hacer Hover en escritorio */
        @media (min-width: 992px) {
            .nav-item.dropdown:hover .dropdown-menu {
                display: block;
                margin-top: 0;
                animation: fadeIn 0.2s ease-in-out;
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Estilos para Modo Claro (Light Theme) */
        body.light-theme {
            background-color: #f8fafc;
            color: #0f172a;
        }

        body.light-theme .navbar {
            background-color: #0f172a !important;
        }

        body.light-theme .hero-banner {
            background: linear-gradient(135deg, #ffffff 0%, #e2e8f0 100%);
            border-bottom: 1px solid #cbd5e1;
        }

        body.light-theme .hero-banner h1 {
            color: #0f172a !important;
        }

        body.light-theme .hero-banner p {
            color: #475569 !important;
        }

        body.light-theme .card-custom {
            background-color: #ffffff;
            border-color: #e2e8f0;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
        }

        body.light-theme .card-header {
            border-bottom-color: #e2e8f0 !important;
            color: #0f172a !important;
        }

        body.light-theme .card-header h2, 
        body.light-theme .card-header h3,
        body.light-theme .card-header h5,
        body.light-theme .card-header h6 {
            color: #0f172a !important;
        }

        body.light-theme .form-control, 
        body.light-theme .form-select {
            background-color: #ffffff;
            border-color: #cbd5e1;
            color: #0f172a;
        }

        body.light-theme .form-control:focus, 
        body.light-theme .form-select:focus {
            background-color: #ffffff;
            border-color: #2563eb;
            color: #0f172a;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        body.light-theme .table-dark-custom {
            --bs-table-color: #0f172a;
            --bs-table-border-color: #e2e8f0;
        }

        body.light-theme .table-dark-custom thead.table-dark {
            background-color: #e2e8f0 !important;
            color: #0f172a !important;
        }

        body.light-theme .text-white {
            color: #0f172a !important;
        }

        body.light-theme .text-muted {
            color: #64748b !important;
        }
    </style>
</head>
<body>

    <!-- Header / Navbar Principal con Menú Desplegable -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-slate-900 border-bottom border-slate-800">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="pacientes_mantenimiento.php">
                <i class="fa-solid fa-hospital-user text-primary fs-4"></i>
                <span>Agendamiento Médico <span class="badge bg-primary fs-6 fw-normal ms-2">Sesión 1</span></span>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarMain">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-4">
                    <!-- Enlace a Diagnóstico / Test de Resiliencia (Para Demostración Docente) -->
                    <li class="nav-item">
                        <a class="nav-link" href="test_resiliencia.php">
                            <i class="fa-solid fa-vial me-1 text-info"></i> Test Resiliencia
                        </a>
                    </li>

                    <!-- Grupo Paciente con enlace directo y submenú Mantenimiento Paciente -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle active fw-bold" href="pacientes_mantenimiento.php" id="pacienteDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fa-solid fa-user-injured me-1 text-primary"></i> Paciente
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark shadow-lg border-slate-700" aria-labelledby="pacienteDropdown">
                            <li>
                                <a class="dropdown-item py-2 fw-semibold active" href="pacientes_mantenimiento.php">
                                    <i class="fa-solid fa-users-gear me-2 text-primary"></i> Mantenimiento Paciente
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>

                <div class="d-flex align-items-center gap-3">
                    <span class="badge bg-secondary py-2 px-3">
                        <i class="fa-solid fa-server me-1"></i> <?= htmlspecialchars($engineStatus) ?>
                    </span>

                    <!-- Botón Modo Claro / Modo Oscuro -->
                    <button id="themeToggleBtn" type="button" class="btn btn-sm btn-outline-light rounded-pill px-3 fw-semibold d-flex align-items-center gap-2 shadow-sm">
                        <i class="fa-solid fa-sun text-warning" id="themeIcon"></i>
                        <span id="themeText">Modo Claro</span>
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <!-- Subheader Banner -->
    <div class="hero-banner">
        <div class="container">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <h1 class="h3 fw-bold mb-1">Mantenimiento Paciente</h1>
                    <p class="text-muted mb-0">Gestión de alta y mantenimiento de pacientes (Persistencia Relacional Transaccional <code>Persona</code> + <code>Paciente</code>).</p>
                </div>
                <div>
                    <span class="badge bg-primary fs-6 px-3 py-2">
                        <i class="fa-solid fa-users me-2"></i> Total Registrados: <?= !$isFallbackMode ? count($pacientesBD) : count($pacientesFallback) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="container">

        <!-- Notificaciones de acciones POST -->
        <?php if ($actionMessage): ?>
            <div class="alert alert-<?= $actionMessageType ?> alert-dismissible fade show border-0 shadow-lg mb-4" role="alert">
                <?= $actionMessage ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Formulario de Crear Nuevo Paciente -->
        <div class="card card-custom mb-4">
            <div class="card-header bg-transparent border-bottom border-slate-700 py-3">
                <h2 class="h5 mb-0 fw-bold"><i class="fa-solid fa-user-plus me-2 text-primary"></i>Alta de Paciente (Creación Persona + Paciente)</h2>
            </div>
            <div class="card-body">
                <form method="POST" action="pacientes_mantenimiento.php">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted fw-semibold">Número de Documento (CI)</label>
                            <input type="text" name="numero_documento" class="form-control" placeholder="Ej: 5432109" value="<?= (string)rand(4000000, 5999999) ?>" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small text-muted fw-semibold">Nombre Completo del Paciente</label>
                            <input type="text" name="nombre_completo" class="form-control" placeholder="Ej: Ana María Elizabeth Benítez" value="Ana María Elizabeth Benítez" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted fw-semibold">Fecha de Nacimiento</label>
                            <input type="date" name="fecha_nacimiento" class="form-control" value="1996-08-24" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted fw-semibold">Teléfono de Contacto</label>
                            <input type="text" name="telefono" class="form-control" value="0981-445566" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted fw-semibold">Tipo Documento</label>
                            <select name="item_tipo_doc" class="form-select">
                                <option value="CI" selected>Cédula de Identidad (CI)</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small text-muted fw-semibold">Sexo</label>
                            <select name="item_sexo" class="form-select">
                                <option value="F" selected>Femenino (F)</option>
                                <option value="M">Masculino (M)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted fw-semibold">Ciudad</label>
                            <select name="item_ciudad" class="form-select">
                                <option value="ASU" selected>Asunción (ASU)</option>
                            </select>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <button type="submit" class="btn btn-primary fw-bold px-4 shadow">
                            <i class="fa-solid fa-floppy-disk me-2"></i>Guardar Nuevo Paciente
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tabla de Mantenimiento de Pacientes -->
        <div class="card card-custom">
            <div class="card-header bg-transparent border-bottom border-slate-700 py-3 d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0 fw-bold"><i class="fa-solid fa-list-ul me-2 text-info"></i>Listado de Pacientes Registrados</h2>
                <span class="badge bg-secondary"><?= !$isFallbackMode ? 'Motor: PostgreSQL 16' : 'Motor: Contingencia JSON' ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (!$isFallbackMode && !empty($pacientesBD)): ?>
                    <div class="table-responsive">
                        <table class="table table-dark-custom table-hover align-middle mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>ID Persona</th>
                                    <th>Documento</th>
                                    <th>Nombre Completo</th>
                                    <th>Fecha Nac.</th>
                                    <th>Teléfono</th>
                                    <th>Sexo</th>
                                    <th>Ciudad</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pacientesBD as $pac): ?>
                                    <tr>
                                        <td class="fw-bold text-info">#<?= htmlspecialchars((string)$pac['IdPersona']) ?></td>
                                        <td class="fw-semibold"><?= htmlspecialchars($pac['NumeroDocumento']) ?></td>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($pac['NombreCompleto']) ?></div>
                                            <div class="text-muted" style="font-size: 0.75rem;">Persistencia: Persona + Paciente</div>
                                        </td>
                                        <td><?= htmlspecialchars($pac['FechaNacimiento']) ?></td>
                                        <td><?= htmlspecialchars($pac['Telefono'] ?? 'S/N') ?></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($pac['ItemSexo']) ?></span></td>
                                        <td><span class="badge bg-dark border border-slate-700"><?= htmlspecialchars($pac['ItemCiudad']) ?></span></td>
                                        <td><span class="badge bg-success">Activo</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif (!empty($pacientesFallback)): ?>
                    <div class="table-responsive">
                        <table class="table table-dark-custom table-hover align-middle mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>Documento</th>
                                    <th>Nombre Completo</th>
                                    <th>Teléfono</th>
                                    <th>Sexo</th>
                                    <th>Ciudad</th>
                                    <th>Origen</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_reverse($pacientesFallback) as $pac): ?>
                                    <tr>
                                        <td class="fw-bold text-warning"><?= htmlspecialchars($pac['numero_documento'] ?? 'N/A') ?></td>
                                        <td class="fw-bold"><?= htmlspecialchars($pac['nombre_completo'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($pac['telefono'] ?? 'S/N') ?></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($pac['item_sexo'] ?? 'M') ?></span></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($pac['item_ciudad'] ?? 'ASU') ?></span></td>
                                        <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($pac['origen'] ?? 'Fallback JSON') ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center p-5 text-muted">
                        <i class="fa-solid fa-users-slash fs-1 mb-3 opacity-50"></i>
                        <p class="mb-0">No se encontraron pacientes registrados aún.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- Bootstrap 5 Bundle JS CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/bootstrap.bundle.min.js"></script>

    <!-- Script de Alternancia de Tema -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const themeToggleBtn = document.getElementById('themeToggleBtn');
            const themeIcon = document.getElementById('themeIcon');
            const themeText = document.getElementById('themeText');

            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'light') {
                enableLightTheme();
            }

            themeToggleBtn.addEventListener('click', () => {
                if (document.body.classList.contains('light-theme')) {
                    enableDarkTheme();
                } else {
                    enableLightTheme();
                }
            });

            function enableLightTheme() {
                document.body.classList.add('light-theme');
                themeIcon.className = 'fa-solid fa-moon text-primary';
                themeText.textContent = 'Modo Oscuro';
                localStorage.setItem('theme', 'light');
            }

            function enableDarkTheme() {
                document.body.classList.remove('light-theme');
                themeIcon.className = 'fa-solid fa-sun text-warning';
                themeText.textContent = 'Modo Claro';
                localStorage.setItem('theme', 'dark');
            }
        });
    </script>
</body>
</html>

<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Infrastructure\DatabaseConnection;
use App\Infrastructure\JsonFallbackStorage;

$actionMessage = null;
$actionMessageType = 'info';

// Instanciar el almacenamiento de contingencia para Pacientes
$fallbackStorage = new JsonFallbackStorage();

// Procesar la creación de un nuevo Paciente en el entorno de prueba de resiliencia
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $numeroDocumento = trim($_POST['numero_documento'] ?? '');
    $nombreCompleto  = trim($_POST['nombre_completo'] ?? '');
    $fechaNacimiento = trim($_POST['fecha_nacimiento'] ?? '1995-05-15');
    $telefono        = trim($_POST['telefono'] ?? '');
    $itemTipoDoc     = trim($_POST['item_tipo_doc'] ?? 'CI');
    $itemSexo        = trim($_POST['item_sexo'] ?? 'M');
    $itemCiudad      = trim($_POST['item_ciudad'] ?? 'ASU');
    $forceFallback   = isset($_POST['force_fallback']) && $_POST['force_fallback'] === '1';

    if (empty($numeroDocumento)) {
        $numeroDocumento = (string)rand(1000000, 9999999);
    }
    if (empty($nombreCompleto)) {
        $nombreCompleto = 'Paciente Prueba ' . rand(100, 999);
    }

    $isFallback = DatabaseConnection::isFallbackMode() || $forceFallback;

    if (!$isFallback) {
        $pdo = DatabaseConnection::getConnection();
        if ($pdo !== null) {
            try {
                $pdo->beginTransaction();

                $idEmpresa = (int)($pdo->query('SELECT "IdEmpresa" FROM "Empresa" LIMIT 1')->fetchColumn() ?: 1);

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

                $stmtPaciente = $pdo->prepare('
                    INSERT INTO "Paciente" ("IdPersona", "IdSeguro", "Activo")
                    VALUES (:idPersona, NULL, true)
                ');

                $stmtPaciente->execute([
                    ':idPersona' => $idPersona
                ]);

                $pdo->commit();

                $actionMessage = "🟢 <strong>ÉXITO TRANSACCIONAL:</strong> Paciente registrado exitosamente en <strong>2 tablas de PostgreSQL</strong>: Tabla <code>Persona</code> (ID #{$idPersona}) y Tabla <code>Paciente</code> (FK IdPersona #{$idPersona}).";
                $actionMessageType = "success";
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

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

                $actionMessage = "⚠️ <strong>CONMUTACIÓN A FALLBACK:</strong> No se pudo escribir en PostgreSQL (<em>" . htmlspecialchars($e->getMessage()) . "</em>). Transacción revertida. Paciente guardado en <strong>data/pacientes_fallback.json</strong>.";
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
            'origen'           => $forceFallback ? 'Prueba Manual Fallback' : 'Contingencia JSON Automática'
        ]);

        if ($guardado) {
            $actionMessage = "⚠️ <strong>CONTINGENCIA ACTIVA:</strong> PostgreSQL fuera de línea / Modo Contingencia. Registro de Paciente almacenado exitosamente en <code>data/pacientes_fallback.json</code> sin interrumpir el servicio.";
            $actionMessageType = "warning";
        } else {
            $actionMessage = "❌ <strong>ERROR DE ESCRITURA:</strong> No se pudo escribir en el archivo de contingencia JSON.";
            $actionMessageType = "danger";
        }
    }
}

// Información de estado del motor
$isFallbackMode = DatabaseConnection::isFallbackMode();
$engineStatus   = DatabaseConnection::getEngineStatus();
$pdoConn        = DatabaseConnection::getConnection();

// Datos desde PostgreSQL (si está activo)
$totalPersonasBD  = 0;
$totalPacientesBD = 0;
$pacientesListBD  = [];

if (!$isFallbackMode && $pdoConn !== null) {
    try {
        $totalPersonasBD  = (int)$pdoConn->query('SELECT COUNT(*) FROM "Persona"')->fetchColumn();
        $totalPacientesBD = (int)$pdoConn->query('SELECT COUNT(*) FROM "Paciente"')->fetchColumn();

        $query = '
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
            LIMIT 10
        ';
        $stmtPacientes = $pdoConn->query($query);
        $pacientesListBD = $stmtPacientes ? $stmtPacientes->fetchAll() : [];
    } catch (Throwable $e) {
        $totalPersonasBD  = 0;
        $totalPacientesBD = 0;
    }
}

// Datos desde JSON Fallback
$pacientesFallback = $fallbackStorage->obtenerPacientes();
$dataDir           = dirname(__DIR__) . '/data';
$dataWritable      = is_writable($dataDir);
$jsonFileExists    = file_exists($fallbackStorage->getFilePath());
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sesión 1: Fundación, Conexión y Resiliencia | Agendamiento Médico</title>
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
            padding: 2rem 0;
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
            transform: translateY(-3px);
            box-shadow: 0 15px 30px -5px rgba(0, 0, 0, 0.4);
        }

        .badge-status {
            font-size: 1rem;
            padding: 0.75rem 1.25rem;
            border-radius: 50rem;
            font-weight: 700;
            letter-spacing: 0.3px;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .badge-active {
            background-color: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(52, 211, 153, 0.3);
        }

        .badge-fallback {
            background-color: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(251, 191, 36, 0.3);
        }

        .tech-item {
            background: rgba(15, 23, 42, 0.6);
            border-radius: 0.75rem;
            padding: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.05);
            transition: background-color 0.3s ease, border-color 0.3s ease;
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

    <!-- Header / Nav con Menú Paciente > Mantenimiento Paciente -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-slate-900 border-bottom border-slate-800">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="test_resiliencia.php">
                <i class="fa-solid fa-hospital-user text-primary fs-4"></i>
                <span>Agendamiento Médico <span class="badge bg-primary fs-6 fw-normal ms-2">Sesión 1</span></span>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarMain">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-4">
                    <!-- Enlace a Diagnóstico / Test de Resiliencia -->
                    <li class="nav-item">
                        <a class="nav-link active fw-bold" href="test_resiliencia.php">
                            <i class="fa-solid fa-vial me-1 text-info"></i> Test Resiliencia
                        </a>
                    </li>

                    <!-- Grupo Paciente con enlace directo y submenú Mantenimiento Paciente -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="pacientes_mantenimiento.php" id="pacienteDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fa-solid fa-user-injured me-1 text-primary"></i> Paciente
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark shadow-lg border-slate-700" aria-labelledby="pacienteDropdown">
                            <li>
                                <a class="dropdown-item py-2 fw-semibold" href="pacientes_mantenimiento.php">
                                    <i class="fa-solid fa-users-gear me-2 text-primary"></i> Mantenimiento Paciente
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>

                <div class="d-flex align-items-center gap-3">
                    <span class="navbar-text text-muted small d-none d-md-inline">
                        PHP 8.4 • Docker Compose • PostgreSQL 16
                    </span>

                    <!-- Botón de Modo Claro / Modo Oscuro -->
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
                    <h1 class="h3 fw-bold mb-1">Fundación, Conexión y Resiliencia (Demostración Docente)</h1>
                    <p class="text-muted mb-0">Demostración interactiva de alta disponibilidad y conmutación transparente a JSON Contingencia.</p>
                </div>
                <div>
                    <?php if (!$isFallbackMode): ?>
                        <span class="badge-status badge-active">
                            <span class="pulse-dot pulse-green"></span>
                            🟢 CONEXIÓN PRINCIPAL: PostgreSQL 16 Conectado
                        </span>
                    <?php else: ?>
                        <span class="badge-status badge-fallback">
                            <span class="pulse-dot pulse-yellow"></span>
                            ⚠️ CONTINGENCIA ACTIVA: PostgreSQL Fuera de Línea - Operando con JSON en Disco
                        </span>
                    <?php endif; ?>
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

        <!-- Layout Grid -->
        <div class="row g-4">

            <!-- Columna Izquierda: Estado Principal y Formulario de Prueba de Resiliencia -->
            <div class="col-lg-7">

                <!-- Card de Estado del Motor -->
                <div class="card card-custom mb-4">
                    <div class="card-header bg-transparent border-bottom border-slate-700 py-3 d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0 fw-bold"><i class="fa-solid fa-server me-2 text-info"></i>Estado del Motor de Persistencia</h2>
                        <span class="badge bg-secondary"><?= htmlspecialchars($engineStatus) ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (!$isFallbackMode): ?>
                            <div class="p-3 mb-3 rounded bg-emerald-950/40 border border-emerald-800/40 text-emerald-200">
                                <div class="d-flex align-items-center gap-3">
                                    <i class="fa-solid fa-database fs-2 text-success"></i>
                                    <div>
                                        <h3 class="h6 mb-1 text-white fw-bold">Base de Datos PostgreSQL 16 Activa (Esquema Multi-tenant)</h3>
                                        <p class="mb-0 text-muted small">Los registros se insertan transaccionalmente en la Superclase <code>Persona</code> y la Subclase <code>Paciente</code>.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-4">
                                    <div class="tech-item text-center">
                                        <div class="text-muted small">Empresas</div>
                                        <div class="fs-3 fw-bold text-success">1</div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="tech-item text-center">
                                        <div class="text-muted small">Personas en BD</div>
                                        <div class="fs-3 fw-bold text-primary"><?= $totalPersonasBD ?></div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="tech-item text-center">
                                        <div class="text-muted small">Pacientes (IS-A)</div>
                                        <div class="fs-3 fw-bold text-info"><?= $totalPacientesBD ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="p-3 mb-3 rounded bg-amber-950/40 border border-amber-800/40 text-amber-200">
                                <div class="d-flex align-items-center gap-3">
                                    <i class="fa-solid fa-triangle-exclamation fs-2 text-warning"></i>
                                    <div>
                                        <h3 class="h6 mb-1 text-white fw-bold">PostgreSQL Fuera de Línea — Modo Contingencia JSON Activo</h3>
                                        <p class="mb-0 text-muted small">El alta de paciente se resguarda en <code>data/pacientes_fallback.json</code> sin interrumpir el servicio web.</p>
                                    </div>
                                </div>
                            </div>
                            <?php if ($lastErr = DatabaseConnection::getLastError()): ?>
                                <div class="alert alert-dark border border-warning text-warning small mb-3">
                                    <i class="fa-solid fa-bug me-1"></i> <strong>Detalle de Excepción PDO:</strong> <?= htmlspecialchars($lastErr) ?>
                                </div>
                            <?php endif; ?>
                            <div class="tech-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted">Pacientes en Fallback (JSON):</span>
                                    <span class="fs-4 fw-bold text-warning"><?= count($pacientesFallback) ?> registros</span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Formulario de Prueba de Resiliencia (Crear Paciente Demo / Fallback) -->
                <div class="card card-custom">
                    <div class="card-header bg-transparent border-bottom border-slate-700 py-3">
                        <h2 class="h5 mb-0 fw-bold"><i class="fa-solid fa-flask me-2 text-primary"></i>Prueba Docente de Resiliencia (Inserción + Conmutación)</h2>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="test_resiliencia.php">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small text-muted">Número de Documento (CI)</label>
                                    <input type="text" name="numero_documento" class="form-control" value="<?= (string)rand(4000000, 5999999) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small text-muted">Nombre Completo</label>
                                    <input type="text" name="nombre_completo" class="form-control" value="Paciente Prueba Resiliencia" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small text-muted">Fecha de Nacimiento</label>
                                    <input type="date" name="fecha_nacimiento" class="form-control" value="1996-08-24" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small text-muted">Teléfono de Contacto</label>
                                    <input type="text" name="telefono" class="form-control" value="0981-445566" required>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2 mt-4">
                                <button type="submit" class="btn btn-primary fw-bold px-4">
                                    <i class="fa-solid fa-vial me-2"></i>Probar Inserción en BD
                                </button>
                                <button type="submit" name="force_fallback" value="1" class="btn btn-outline-warning fw-bold">
                                    <i class="fa-solid fa-bolt me-2"></i>Simular Fallback Forzado en JSON
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>

            <!-- Columna Derecha: Panel Técnico y Registros Fallback -->
            <div class="col-lg-5">

                <!-- Panel de Información Técnica -->
                <div class="card card-custom mb-4">
                    <div class="card-header bg-transparent border-bottom border-slate-700 py-3">
                        <h2 class="h5 mb-0 fw-bold"><i class="fa-solid fa-microchip me-2 text-warning"></i>Panel de Información Técnica</h2>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-column gap-3">
                            <div class="tech-item d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold">Versión de PHP</div>
                                    <div class="text-muted small">PHP_VERSION</div>
                                </div>
                                <span class="badge bg-primary fs-6"><?= PHP_VERSION ?></span>
                            </div>

                            <div class="tech-item d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold">Driver PDO PostgreSQL</div>
                                    <div class="text-muted small">pdo_pgsql</div>
                                </div>
                                <?php if (extension_loaded('pdo_pgsql')): ?>
                                    <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i> Instalado</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1"></i> Ausente</span>
                                <?php endif; ?>
                            </div>

                            <div class="tech-item d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold">Permiso Escritura <code>data/</code></div>
                                    <div class="text-muted small">Directorio Fallback Local</div>
                                </div>
                                <?php if ($dataWritable): ?>
                                    <span class="badge bg-success"><i class="fa-solid fa-lock-open me-1"></i> Escritura OK</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="fa-solid fa-lock me-1"></i> Sin Permiso</span>
                                <?php endif; ?>
                            </div>

                            <div class="tech-item d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold">Archivo <code>pacientes_fallback.json</code></div>
                                    <div class="text-muted small">Persistencia Contingencia</div>
                                </div>
                                <?php if ($jsonFileExists): ?>
                                    <span class="badge bg-info"><i class="fa-solid fa-file-code me-1"></i> Creado (<?= count($pacientesFallback) ?> pacientes)</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">No Creado</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Registros en Fallback JSON -->
                <div class="card card-custom">
                    <div class="card-header bg-transparent border-bottom border-slate-700 py-3 d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0 fw-bold"><i class="fa-solid fa-folder-open me-2 text-warning"></i>Registros en Fallback JSON</h2>
                        <span class="badge bg-warning text-dark fw-bold"><?= count($pacientesFallback) ?></span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($pacientesFallback)): ?>
                            <div class="text-center p-4 text-muted">
                                <i class="fa-solid fa-inbox fs-1 mb-2 opacity-50"></i>
                                <p class="mb-0 small">No hay registros en el archivo JSON fallback todavía.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive" style="max-height: 350px; overflow-y: auto;">
                                <table class="table table-dark-custom table-hover align-middle mb-0 small">
                                    <thead class="table-dark sticky-top">
                                        <tr>
                                            <th>Documento</th>
                                            <th>Paciente</th>
                                            <th>Origen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_reverse($pacientesFallback) as $pac): ?>
                                            <tr>
                                                <td class="fw-bold text-warning"><?= htmlspecialchars($pac['numero_documento'] ?? 'N/A') ?></td>
                                                <td>
                                                    <div class="fw-bold"><?= htmlspecialchars($pac['nombre_completo'] ?? 'N/A') ?></div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-warning text-dark" style="font-size: 0.65rem;"><?= htmlspecialchars($pac['origen'] ?? 'Fallback') ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

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

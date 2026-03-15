<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/onesignal_config.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Administrador') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$conexion = getConexion();

// Inicializar mensajes de sesión
if (!isset($_SESSION['mensajes'])) {
    $_SESSION['mensajes'] = [];
}

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // CREAR período
    if (isset($_POST['accion']) && $_POST['accion'] === 'crear') {
        $nombre = $_POST['nombre'] ?? '';
        $lapso = $_POST['lapso'] ?? '';
        $año_escolar = $_POST['año_escolar'] ?? '';
        $fecha_inicio = $_POST['fecha_inicio'] ?? '';
        $fecha_fin = $_POST['fecha_fin'] ?? '';
        
        // Validar que no se superpongan fechas
        $stmt_validar = $conexion->prepare("
            SELECT COUNT(*) FROM periodos_escolares 
            WHERE (fecha_inicio <= ? AND fecha_fin >= ?)
            OR (fecha_inicio <= ? AND fecha_fin >= ?)
        ");
        $stmt_validar->execute([$fecha_fin, $fecha_inicio, $fecha_inicio, $fecha_fin]);
        $superposicion = $stmt_validar->fetchColumn();
        
        if ($superposicion > 0) {
            $_SESSION['mensajes'][] = ['tipo' => 'error', 'texto' => '❌ Las fechas se superponen con otro período existente'];
        } else {
            try {
                $stmt = $conexion->prepare("
                    INSERT INTO periodos_escolares 
                    (nombre, lapso, año_escolar, fecha_inicio, fecha_fin, creado_por) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$nombre, $lapso, $año_escolar, $fecha_inicio, $fecha_fin, $_SESSION['usuario_id']]);
                $_SESSION['mensajes'][] = ['tipo' => 'exito', 'texto' => '✅ Período creado exitosamente'];
            } catch (Exception $e) {
                $_SESSION['mensajes'][] = ['tipo' => 'error', 'texto' => '❌ Error: ' . $e->getMessage()];
            }
        }
        
        // Redirigir para evitar reenvío
        header('Location: periodos.php');
        exit();
    }
    
    // EDITAR período
    if (isset($_POST['accion']) && $_POST['accion'] === 'editar') {
        $id = $_POST['id'] ?? 0;
        $nombre = $_POST['nombre'] ?? '';
        $lapso = $_POST['lapso'] ?? '';
        $año_escolar = $_POST['año_escolar'] ?? '';
        $fecha_inicio = $_POST['fecha_inicio'] ?? '';
        $fecha_fin = $_POST['fecha_fin'] ?? '';
        
        try {
            $stmt = $conexion->prepare("
                UPDATE periodos_escolares 
                SET nombre = ?, lapso = ?, año_escolar = ?, fecha_inicio = ?, fecha_fin = ?
                WHERE id = ?
            ");
            $stmt->execute([$nombre, $lapso, $año_escolar, $fecha_inicio, $fecha_fin, $id]);
            $_SESSION['mensajes'][] = ['tipo' => 'exito', 'texto' => '✅ Período actualizado exitosamente'];
        } catch (Exception $e) {
            $_SESSION['mensajes'][] = ['tipo' => 'error', 'texto' => '❌ Error al actualizar: ' . $e->getMessage()];
        }
        
        header('Location: periodos.php');
        exit();
    }
    
    // ELIMINAR período
    if (isset($_POST['accion']) && $_POST['accion'] === 'eliminar') {
        $id = $_POST['id'] ?? 0;
        
        // Verificar si tiene historiales asociados
        $stmt_check = $conexion->prepare("SELECT COUNT(*) FROM historial_academico WHERE periodo_id = ?");
        $stmt_check->execute([$id]);
        $tiene_historiales = $stmt_check->fetchColumn();
        
        if ($tiene_historiales > 0) {
            $_SESSION['mensajes'][] = ['tipo' => 'error', 'texto' => '❌ No se puede eliminar: el período tiene historiales académicos asociados'];
        } else {
            try {
                $stmt = $conexion->prepare("DELETE FROM periodos_escolares WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['mensajes'][] = ['tipo' => 'exito', 'texto' => '✅ Período eliminado exitosamente'];
            } catch (Exception $e) {
                $_SESSION['mensajes'][] = ['tipo' => 'error', 'texto' => '❌ Error al eliminar: ' . $e->getMessage()];
            }
        }
        
        header('Location: periodos.php');
        exit();
    }
    
    // Activar/Desactivar período
    if (isset($_POST['accion']) && $_POST['accion'] === 'toggle') {
        $periodo_id = $_POST['periodo_id'] ?? 0;
        $activo = $_POST['activo'] ?? 0;
        
        $stmt = $conexion->prepare("UPDATE periodos_escolares SET activo = ? WHERE id = ?");
        $stmt->execute([$activo, $periodo_id]);
        $_SESSION['mensajes'][] = ['tipo' => 'exito', 'texto' => '✅ Período actualizado'];
        
        header('Location: periodos.php');
        exit();
    }
}

// Cargar período para editar (si viene por GET)
$periodo_editar = null;
if (isset($_GET['editar'])) {
    $stmt = $conexion->prepare("SELECT * FROM periodos_escolares WHERE id = ?");
    $stmt->execute([$_GET['editar']]);
    $periodo_editar = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Obtener todos los períodos
$periodos = $conexion->query("
    SELECT p.*, u.nombre as creador,
           (SELECT COUNT(*) FROM historial_academico WHERE periodo_id = p.id) as total_historiales
    FROM periodos_escolares p
    LEFT JOIN usuarios u ON p.creado_por = u.id
    ORDER BY p.año_escolar DESC, p.lapso ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Recuperar mensajes de sesión y limpiarlos
$mensajes = $_SESSION['mensajes'] ?? [];
$_SESSION['mensajes'] = [];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Gestión de Períodos - SIEDUCRES</title>
    <?php require_once '../includes/favicon.php'; ?>
    <?php require_once '../includes/header_onesignal.php'; ?> 
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --text-dark: #333333;
            --text-muted: #666666;
            --border: #E0E0E0;
            --surface: #FFFFFF;
            --background: #F5F5F5;
            --primary-cyan: #4BC4E7;
            --primary-pink: #EF5E8E;
            --primary-lime: #C2D54E;
            --primary-purple: #9b8afb;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--background);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header responsive */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 16px;
            height: 60px;
            background-color: var(--surface);
            border-bottom: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            position: relative;
            z-index: 100;
        }

        @media (min-width: 768px) {
            .header {
                padding: 0 24px;
            }
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .logo {
            height: 32px;
        }

        @media (min-width: 768px) {
            .logo {
                height: 40px;
            }
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .icon-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: #F0F0F0;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.2s;
            flex-shrink: 0;
        }

        .icon-btn:hover {
            background-color: #E0E0E0;
        }

        .icon-btn img {
            width: 20px;
            height: 20px;
            object-fit: contain;
        }

        .menu-dropdown {
            position: absolute;
            top: 60px;
            right: 16px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            display: none;
            min-width: 180px;
            z-index: 1000;
        }

        @media (min-width: 768px) {
            .menu-dropdown {
                right: 24px;
            }
        }

        .menu-item {
            padding: 12px 16px;
            font-size: 14px;
            color: var(--text-dark);
            text-decoration: none;
            display: block;
            transition: background 0.2s;
        }

        .menu-item:hover {
            background-color: #F8F8F8;
        }

        /* Banner responsive */
        .banner {
            position: relative;
            height: 80px;
            overflow: hidden;
        }

        @media (min-width: 768px) {
            .banner {
                height: 100px;
            }
        }

        .banner-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: top;
        }

        .banner-content {
            text-align: center;
            position: relative;
            z-index: 2;
            max-width: 800px;
            padding: 16px;
            margin: 0 auto;
        }

        .banner-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 8px;
        }

        @media (min-width: 768px) {
            .banner-title {
                font-size: 36px;
            }
        }

        /* Contenido principal */
        .main-content {
            flex: 1;
            padding: 20px 16px;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        @media (min-width: 768px) {
            .main-content {
                padding: 40px 20px;
            }
        }

        /* Tarjetas */
        .card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
            border: 1px solid var(--border);
        }

        @media (min-width: 768px) {
            .card {
                padding: 24px;
                margin-bottom: 30px;
            }
        }

        .card-header {
            background-color: var(--primary-cyan);
            margin: -20px -20px 16px -20px;
            padding: 16px 20px;
            border-radius: 16px 16px 0 0;
            color: white;
        }

        @media (min-width: 768px) {
            .card-header {
                margin: -24px -24px 20px -24px;
                padding: 20px 24px;
            }
        }

        .card-header h2 {
            color: white;
            font-weight: 600;
            font-size: 1.2rem;
        }

        /* Grid para formulario */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }

        @media (min-width: 768px) {
            .form-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
            }
        }

        .form-group {
            margin-bottom: 12px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
            font-size: 14px;
        }

        input, select {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            transition: border-color 0.2s;
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--primary-cyan);
        }

        /* Botones */
        .btn-primary {
            background-color: var(--primary-cyan);
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.3s, opacity 0.3s;
            width: 100%;
            font-size: 15px;
        }

        @media (min-width: 768px) {
            .btn-primary {
                width: auto;
                padding: 12px 30px;
                font-size: 14px;
            }
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            opacity: 0.9;
        }

        /* Contenedor de acciones responsive */
        .acciones-container {
            display: flex;
            flex-direction: column;
            gap: 6px;
            align-items: stretch;
        }

        @media (min-width: 768px) {
            .acciones-container {
                flex-direction: row;
                gap: 8px;
                align-items: center;
            }
        }

        .btn-edit, .btn-delete, .btn-toggle {
            padding: 8px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            width: 100%;
        }

        @media (min-width: 768px) {
            .btn-edit, .btn-delete, .btn-toggle {
                width: auto;
                white-space: nowrap;
            }
        }

        .btn-edit {
            background-color: var(--primary-lime);
            color: #333;
        }

        .btn-delete {
            background-color: var(--primary-pink);
            color: white;
        }

        .btn-toggle {
            background-color: #f8d7da;
            color: #721c24;
        }

        /* Mensajes */
        .mensaje-exito, .mensaje-error {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .mensaje-exito {
            background-color: var(--primary-lime);
            color: #333;
            border: 1px solid #b1c94a;
        }

        .mensaje-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Tabla responsive */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -20px;
            padding: 0 20px;
        }

        table {
            width: 100%;
            min-width: 900px;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th {
            background-color: var(--primary-cyan);
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
        }

        tr:hover {
            background-color: #f9f9f9;
        }

        /* Badges */
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge-activo {
            background-color: var(--primary-lime);
            color: #333;
        }

        .badge-inactivo {
            background-color: #f8d7da;
            color: #721c24;
        }

        /* Info box */
        .info-box {
            background-color: #e3f2fd;
            border-left: 4px solid var(--primary-cyan);
            padding: 20px;
            border-radius: 8px;
        }

        .info-box h3 {
            color: var(--primary-purple);
            margin-bottom: 10px;
            font-size: 18px;
        }

        .info-box ul {
            margin-left: 20px;
            line-height: 1.8;
            font-size: 14px;
        }

        /* Enlace volver */
        .back-link {
            display: block;
            text-align: center;
            color: var(--primary-pink);
            text-decoration: none;
            font-weight: 500;
            margin-top: 15px;
            font-size: 14px;
        }

        @media (min-width: 768px) {
            .back-link {
                float: right;
                margin-top: 0;
            }
        }

        .back-link:hover {
            text-decoration: underline;
        }

        /* Modal responsive */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            padding: 16px;
        }

        .modal-content {
            background: white;
            padding: 24px;
            border-radius: 16px;
            max-width: 400px;
            width: 100%;
            text-align: center;
        }

        .modal-buttons {
            display: flex;
            flex-direction: column;
            gap: 8px;
            justify-content: center;
            margin-top: 20px;
        }

        @media (min-width: 768px) {
            .modal-buttons {
                flex-direction: row;
            }
        }

        .modal-btn-confirm {
            background-color: var(--primary-pink);
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            width: 100%;
        }

        .modal-btn-cancel {
            background-color: #ccc;
            color: #333;
            padding: 12px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            width: 100%;
        }

        @media (min-width: 768px) {
            .modal-btn-confirm, .modal-btn-cancel {
                width: auto;
            }
        }

        /* Footer */
        .footer {
            height: 50px;
            background-color: var(--surface);
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 16px;
            font-size: 12px;
            color: var(--text-muted);
            position: sticky;
            bottom: 0;
        }

        @media (min-width: 768px) {
            .footer {
                padding: 0 24px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>

    <?php require_once '../includes/header_comun.php'; ?>

    <!-- Banner -->
    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image" onerror="this.style.display='none'">
    </div>

    <!-- Título -->
    <div class="banner-content">
        <h1 class="banner-title">📅 Gestión de Períodos Escolares</h1>
    </div>
    

    <!-- Contenido principal -->
    <main class="main-content">
        <!-- Mensajes desde sesión -->
        <?php foreach ($mensajes as $msg): ?>
            <div class="mensaje-<?php echo $msg['tipo']; ?>">
                <?php echo $msg['texto']; ?>
            </div>
        <?php endforeach; ?>

        <!-- Formulario para crear/editar período -->
        <div class="card">
            <div class="card-header">
                <h2><?php echo $periodo_editar ? '✏️ Editar Período' : '➕ Crear Nuevo Período'; ?></h2>
            </div>
            
            <form method="POST">
                <input type="hidden" name="accion" value="<?php echo $periodo_editar ? 'editar' : 'crear'; ?>">
                <?php if ($periodo_editar): ?>
                    <input type="hidden" name="id" value="<?php echo $periodo_editar['id']; ?>">
                <?php endif; ?>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Nombre del Período:</label>
                        <input type="text" name="nombre" placeholder="Ej: Primer Lapso 2024-2025" 
                               value="<?php echo $periodo_editar ? htmlspecialchars($periodo_editar['nombre']) : ''; ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Lapso:</label>
                        <select name="lapso" required>
                            <option value="">Seleccionar...</option>
                            <option value="1" <?php echo ($periodo_editar && $periodo_editar['lapso'] == 1) ? 'selected' : ''; ?>>1° Lapso</option>
                            <option value="2" <?php echo ($periodo_editar && $periodo_editar['lapso'] == 2) ? 'selected' : ''; ?>>2° Lapso</option>
                            <option value="3" <?php echo ($periodo_editar && $periodo_editar['lapso'] == 3) ? 'selected' : ''; ?>>3° Lapso</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Año Escolar:</label>
                        <input type="text" name="año_escolar" placeholder="2024-2025" 
                               value="<?php echo $periodo_editar ? htmlspecialchars($periodo_editar['año_escolar']) : ''; ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Fecha Inicio:</label>
                        <input type="date" name="fecha_inicio" 
                               value="<?php echo $periodo_editar ? $periodo_editar['fecha_inicio'] : ''; ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Fecha Fin:</label>
                        <input type="date" name="fecha_fin" 
                               value="<?php echo $periodo_editar ? $periodo_editar['fecha_fin'] : ''; ?>" required>
                    </div>
                </div>
                
                <button type="submit" class="btn-primary">
                    <?php echo $periodo_editar ? '✏️ Actualizar Período' : '➕ Crear Período'; ?>
                </button>
                
                <?php if ($periodo_editar): ?>
                    <a href="periodos.php" class="back-link">← Cancelar edición</a>
                <?php else: ?>
                    <a href="index.php" class="back-link">← Volver al Panel</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Lista de períodos -->
        <div class="card">
            <div class="card-header">
                <h2>📋 Períodos Existentes</h2>
            </div>
            
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Lapso</th>
                            <th>Año Escolar</th>
                            <th>Fecha Inicio</th>
                            <th>Fecha Fin</th>
                            <th>Estado</th>
                            <th>Historiales</th>
                            <th>Creado por</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($periodos as $p): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($p['nombre']); ?></strong></td>
                            <td><?php echo $p['lapso']; ?>°</td>
                            <td><?php echo $p['año_escolar']; ?></td>
                            <td><?php echo date('d/m/Y', strtotime($p['fecha_inicio'])); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($p['fecha_fin'])); ?></td>
                            <td>
                                <span class="badge <?php echo $p['activo'] ? 'badge-activo' : 'badge-inactivo'; ?>">
                                    <?php echo $p['activo'] ? 'Activo' : 'Inactivo'; ?>
                                </span>
                            </td>
                            <td><?php echo $p['total_historiales']; ?></td>
                            <td><?php echo $p['creador'] ?? 'Sistema'; ?></td>
                            <td>
                                <div class="acciones-container">
                                    <a href="?editar=<?php echo $p['id']; ?>" class="btn-edit">✏️ Editar</a>
                                    <button class="btn-delete" onclick="confirmarEliminar(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars($p['nombre']); ?>')">🗑️ Eliminar</button>
                                    <form method="POST" style="width: 100%;">
                                        <input type="hidden" name="accion" value="toggle">
                                        <input type="hidden" name="periodo_id" value="<?php echo $p['id']; ?>">
                                        <input type="hidden" name="activo" value="<?php echo $p['activo'] ? 0 : 1; ?>">
                                        <button type="submit" class="btn-toggle" style="background: <?php echo $p['activo'] ? '#f8d7da' : '#d4edda'; ?>">
                                            <?php echo $p['activo'] ? 'Desactivar' : 'Activar'; ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Información importante -->
        <div class="card info-box">
            <h3>ℹ️ Información importante</h3>
            <ul>
                <li>Los períodos se usan para filtrar el <strong>Historial Académico</strong> por lapsos</li>
                <li>Cada estudiante puede tener un historial por período</li>
                <li>Las fechas de los períodos determinan qué actividades y contenidos se incluyen</li>
                <li>Puedes tener múltiples períodos (ej: 2023-2024, 2024-2025) con sus 3 lapsos cada uno</li>
                <li>Los períodos <span class="badge badge-activo">Activos</span> aparecerán en los filtros del historial</li>
                <li><strong>No se pueden eliminar</strong> períodos que ya tienen historiales académicos asociados</li>
            </ul>
        </div>
    </main>

    <!-- Modal de confirmación para eliminar -->
    <div class="modal" id="modalEliminar">
        <div class="modal-content">
            <h3 style="color: var(--primary-pink); margin-bottom: 15px;">🗑️ Confirmar Eliminación</h3>
            <p id="modal-mensaje">¿Estás seguro de que deseas eliminar este período?</p>
            <p style="font-size: 12px; color: #999; margin-top: 10px;">Esta acción no se puede deshacer.</p>
            
            <form method="POST" id="formEliminar">
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="id" id="periodo_id_eliminar">
                
                <div class="modal-buttons">
                    <button type="button" class="modal-btn-cancel" onclick="cerrarModal()">Cancelar</button>
                    <button type="submit" class="modal-btn-confirm">Sí, eliminar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <span>v2.0.0</span>
        <span>Soporte Técnico</span>
    </footer>

    <script>
        // Toggle menú hamburguesa
        document.getElementById('menu-toggle').addEventListener('click', function(e) {
            e.stopPropagation();
            const dropdown = document.getElementById('dropdown');
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        });

        document.addEventListener('click', function(e) {
            const dropdown = document.getElementById('dropdown');
            const toggle = document.getElementById('menu-toggle');
            if (!toggle.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });

        // Modal de confirmación para eliminar
        function confirmarEliminar(id, nombre) {
            document.getElementById('periodo_id_eliminar').value = id;
            document.getElementById('modal-mensaje').innerHTML = `¿Estás seguro de que deseas eliminar el período <strong>"${nombre}"</strong>?`;
            document.getElementById('modalEliminar').style.display = 'flex';
        }

        function cerrarModal() {
            document.getElementById('modalEliminar').style.display = 'none';
        }

        window.onclick = function(event) {
            const modal = document.getElementById('modalEliminar');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>
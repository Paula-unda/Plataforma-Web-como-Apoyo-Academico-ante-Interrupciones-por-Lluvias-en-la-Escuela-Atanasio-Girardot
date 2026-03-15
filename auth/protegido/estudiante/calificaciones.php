<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/onesignal_config.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Estudiante') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$estudiante_id = $_SESSION['usuario_id'];

// ✅ INICIALIZAR VARIABLES
$calificaciones = [];
$pendientes = [];
$total_calificadas = 0;
$total_pendientes = 0;
$promedio = 0;

try {
    $conexion = getConexion();
    
    // 🔴 OBTENER GRADO Y SECCIÓN DEL ESTUDIANTE
    $query_est = "SELECT grado, seccion FROM estudiantes WHERE usuario_id = ?";
    $stmt_est = $conexion->prepare($query_est);
    $stmt_est->execute([$estudiante_id]);
    $estudiante = $stmt_est->fetch(PDO::FETCH_ASSOC);
    
    if (!$estudiante) {
        error_log("❌ Estudiante no encontrado: " . $estudiante_id);
        throw new Exception("Estudiante no encontrado");
    }
    
    $grado = $estudiante['grado'];
    $seccion = $estudiante['seccion'];
    
    error_log("✅ Estudiante ID: $estudiante_id - Grado: $grado - Sección: $seccion");
    
    // ✅ QUERY 1: Calificadas (SOLO actividades del grado/sección del estudiante)
    $query_calificadas = "
        SELECT 
            e.id,
            e.estudiante_id,
            e.calificacion,
            e.observaciones,
            e.fecha_entrega,
            a.titulo as actividad_titulo,
            a.grado,
            a.seccion
        FROM entregas_estudiantes e
        INNER JOIN actividades a ON e.actividad_id = a.id
        WHERE e.estudiante_id = ? 
            AND e.calificacion IS NOT NULL
            AND a.grado = ? 
            AND a.seccion = ?
        ORDER BY e.fecha_entrega DESC
    ";
    
    // ✅ QUERY 2: Pendientes (SOLO actividades del grado/sección del estudiante)
    $query_pendientes = "
        SELECT 
            a.id,
            a.titulo as actividad_titulo,
            a.fecha_entrega,
            a.grado,
            a.seccion,
            NULL as calificacion,
            NULL as observaciones
        FROM actividades a
        WHERE a.activo = true
            AND a.grado = ? 
            AND a.seccion = ?
            AND a.id NOT IN (
                SELECT actividad_id FROM entregas_estudiantes 
                WHERE estudiante_id = ?
            )
        ORDER BY a.fecha_entrega DESC
    ";
    
    // Ejecutar queries con los parámetros correctos
    $stmt = $conexion->prepare($query_calificadas);
    $stmt->execute([$estudiante_id, $grado, $seccion]);
    $calificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $conexion->prepare($query_pendientes);
    $stmt->execute([$grado, $seccion, $estudiante_id]);
    $pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("=== CALIFICACIONES FILTRADAS ===");
    error_log("Grado/Sección del estudiante: $grado - $seccion");
    error_log("Calificadas: " . count($calificaciones));
    error_log("Pendientes: " . count($pendientes));
    
    // 🔴 VERIFICAR QUE TODAS LAS ACTIVIDADES TENGAN EL GRADO/SECCIÓN CORRECTO
    foreach ($calificaciones as $c) {
        error_log("Calificada: {$c['actividad_titulo']} - Grado: {$c['grado']} - Sección: {$c['seccion']}");
    }
    foreach ($pendientes as $p) {
        error_log("Pendiente: {$p['actividad_titulo']} - Grado: {$p['grado']} - Sección: {$p['seccion']}");
    }
    
} catch (Exception $e) {
    error_log("Error en calificaciones: " . $e->getMessage());
}

// Estadísticas
$total_calificadas = count($calificaciones);
$total_pendientes = count($pendientes);

if ($total_calificadas > 0) {
    $suma = array_sum(array_column($calificaciones, 'calificacion'));
    $promedio = round($suma / $total_calificadas, 2);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Calificaciones - SIEDUCRES</title>
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
            --success: #28a745;
            --warning: #ffc107;
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
            position: relative;
            z-index: 100;
        }

        @media (min-width: 768px) {
            .header {
                padding: 0 24px;
            }
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
            padding: 16px 16px 8px;
        }

        @media (min-width: 768px) {
            .banner-content {
                padding: 20px;
            }
        }

        .banner-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        @media (min-width: 768px) {
            .banner-title {
                font-size: 36px;
            }
        }

        .banner-subtitle {
            font-size: 14px;
            color: var(--text-muted);
        }

        @media (min-width: 768px) {
            .banner-subtitle {
                font-size: 18px;
            }
        }

        /* Contenido principal */
        .main-content {
            flex: 1;
            padding: 20px 16px;
        }

        @media (min-width: 768px) {
            .main-content {
                padding: 40px 20px;
            }
        }

        .content-container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Botón regresar */
        .btn-regresar {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-dark);
            margin-bottom: 20px;
            font-size: 14px;
            transition: transform 0.2s;
        }

        .btn-regresar:hover {
            transform: translateX(-2px);
        }

        @media (min-width: 768px) {
            .btn-regresar {
                padding: 10px 20px;
                font-size: 16px;
            }
        }

        /* Tarjeta de resumen */
        .resumen-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        @media (min-width: 768px) {
            .resumen-card {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 32px;
            }
        }

        .resumen-header h2 {
            font-size: 20px;
            margin-bottom: 4px;
        }

        .resumen-header p {
            font-size: 14px;
        }

        .estadisticas-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        @media (min-width: 768px) {
            .estadisticas-container {
                display: flex;
                gap: 32px;
            }
        }

        .estadistica {
            text-align: center;
        }

        .estadistica-numero {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary-cyan);
        }

        @media (min-width: 768px) {
            .estadistica-numero {
                font-size: 36px;
            }
        }

        .estadistica-label {
            font-size: 11px;
            color: var(--text-muted);
        }

        @media (min-width: 768px) {
            .estadistica-label {
                font-size: 13px;
            }
        }

        .btn-export {
            background: var(--primary-cyan);
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            width: 100%;
            transition: transform 0.2s;
        }

        @media (min-width: 768px) {
            .btn-export {
                width: auto;
                padding: 12px 24px;
            }
        }

        .btn-export:hover {
            transform: translateY(-2px);
        }

        /* Tabs */
        .tabs {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 20px;
        }

        @media (min-width: 768px) {
            .tabs {
                flex-direction: row;
            }
        }

        .tab {
            padding: 12px 20px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            text-align: center;
            transition: all 0.2s;
            width: 100%;
        }

        @media (min-width: 768px) {
            .tab {
                width: auto;
            }
        }

        .tab.active {
            background: var(--primary-cyan);
            color: white;
            border-color: var(--primary-cyan);
        }

        /* Tarjeta de tabla */
        .tabla-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.05);
        }

        @media (min-width: 768px) {
            .tabla-card {
                padding: 32px;
            }
        }

        .tabla-card h3 {
            font-size: 18px;
            margin-bottom: 16px;
        }

        @media (min-width: 768px) {
            .tabla-card h3 {
                font-size: 20px;
                margin-bottom: 24px;
            }
        }

        /* Tabla responsive */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -20px;
            padding: 0 20px;
        }

        .calificaciones-table {
            width: 100%;
            min-width: 600px;
            border-collapse: collapse;
        }

        .calificaciones-table thead {
            background: var(--primary-cyan);
            color: white;
        }

        .calificaciones-table th,
        .calificaciones-table td {
            padding: 12px 10px;
            text-align: left;
            font-size: 14px;
        }

        @media (min-width: 768px) {
            .calificaciones-table th,
            .calificaciones-table td {
                padding: 16px;
            }
        }

        .calificaciones-table tbody tr {
            cursor: pointer;
            transition: background 0.2s;
        }

        .calificaciones-table tbody tr:hover {
            background: #f8f9fa;
        }

        /* Badges */
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        @media (min-width: 768px) {
            .badge {
                font-size: 12px;
            }
        }

        .badge-excelente {
            background: rgba(40,167,69,0.1);
            color: var(--success);
        }

        .badge-bueno {
            background: rgba(75,196,231,0.1);
            color: var(--primary-cyan);
        }

        .badge-pendiente {
            background: rgba(255,193,7,0.1);
            color: var(--warning);
        }

        /* Botón de acción en tabla */
        .btn-ver {
            background: var(--primary-purple);
            color: white;
            border: none;
            border-radius: 4px;
            padding: 4px 12px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
        }

        /* Vista de cards para móvil */
        .mobile-cards {
            display: none;
        }

        .actividad-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            border: 1px solid var(--border);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .card-titulo {
            font-weight: 700;
            color: var(--text-dark);
            font-size: 16px;
        }

        .card-badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .card-detalle {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 12px;
        }

        .detalle-item {
            font-size: 13px;
        }

        .detalle-label {
            color: var(--text-muted);
            display: block;
            font-size: 11px;
        }

        .detalle-valor {
            font-weight: 600;
            color: var(--text-dark);
        }

        .card-footer {
            display: flex;
            justify-content: flex-end;
            margin-top: 8px;
        }

        .card-btn {
            background: var(--primary-purple);
            color: white;
            border: none;
            border-radius: 6px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        @media (max-width: 600px) {
            .table-responsive {
                display: none;
            }
            .mobile-cards {
                display: block;
            }
        }

        /* No datos */
        .no-datos {
            text-align: center;
            padding: 40px 20px;
        }

        .no-datos-icon {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.3;
        }

        @media (min-width: 768px) {
            .no-datos-icon {
                font-size: 64px;
            }
        }

        .no-datos h3 {
            font-size: 18px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .no-datos p {
            font-size: 14px;
            color: var(--text-muted);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 16px;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 24px;
            max-width: 600px;
            width: 100%;
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
        }

        @media (min-width: 768px) {
            .modal-content {
                padding: 32px;
            }
        }

        .modal-close {
            position: absolute;
            top: 16px;
            right: 16px;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-muted);
        }

        .modal-info {
            margin-bottom: 16px;
        }

        .modal-info-label {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        @media (min-width: 768px) {
            .modal-info-label {
                font-size: 14px;
            }
        }

        .modal-info-value {
            font-size: 15px;
            font-weight: 600;
        }

        @media (min-width: 768px) {
            .modal-info-value {
                font-size: 16px;
            }
        }

        .modal-actions {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 24px;
        }

        @media (min-width: 768px) {
            .modal-actions {
                flex-direction: row;
                gap: 12px;
            }
        }

        .btn-modal {
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            width: 100%;
        }

        @media (min-width: 768px) {
            .btn-modal {
                width: auto;
            }
        }

        .btn-modal-primary {
            background: var(--primary-cyan);
            color: white;
        }

        .btn-modal-secondary {
            background: #f0f0f0;
            color: var(--text-dark);
        }

        /* Footer */
        .footer {
            height: 50px;
            background: var(--surface);
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 16px;
            font-size: 12px;
            color: var(--text-muted);
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

    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner" class="banner-image" onerror="this.style.display='none'">
    </div>
    
    <div class="banner-content">
        <h1 class="banner-title">Calificaciones</h1>
        <p class="banner-subtitle">Revisa tus notas y retroalimentación</p>
    </div>

    <main class="main-content">
        <div class="content-container">
            <a href="index.php" class="btn-regresar">← Volver al Panel</a>

            <!-- Resumen -->
            <div class="resumen-card">
                <div class="resumen-header">
                    <h2>Tu Desempeño</h2>
                    <p style="color: var(--text-muted);">Resumen de calificaciones</p>
                </div>
                
                <div class="estadisticas-container">
                    <div class="estadistica">
                        <div class="estadistica-numero"><?php echo $total_calificadas; ?></div>
                        <div class="estadistica-label">Calificadas</div>
                    </div>
                    <div class="estadistica">
                        <div class="estadistica-numero"><?php echo $total_pendientes; ?></div>
                        <div class="estadistica-label">Pendientes</div>
                    </div>
                    <div class="estadistica">
                        <div class="estadistica-numero"><?php echo number_format($promedio, 1); ?>/20</div>
                        <div class="estadistica-label">Promedio</div>
                    </div>
                </div>
                
                <button class="btn-export" onclick="exportarPDF()">📄 Descargar PDF</button>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <div class="tab active" onclick="mostrarTab('calificadas', this)">📊 Calificadas (<?php echo $total_calificadas; ?>)</div>
                <div class="tab" onclick="mostrarTab('pendientes', this)">⏳ Pendientes (<?php echo $total_pendientes; ?>)</div>
            </div>

            <!-- Tab Calificadas -->
            <div id="tab-calificadas" class="tabla-card">
                <h3>Actividades Calificadas</h3>
                
                <?php if (count($calificaciones) > 0): ?>
                    <!-- Vista Desktop (tabla) -->
                    <div class="table-responsive">
                        <table class="calificaciones-table">
                            <thead>
                                <tr>
                                    <th>Actividad</th>
                                    <th>Fecha</th>
                                    <th>Nota</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($calificaciones as $cal): ?>
                                    <tr onclick="verDetalle(<?php echo htmlspecialchars(json_encode($cal)); ?>)">
                                        <td><strong><?php echo htmlspecialchars($cal['actividad_titulo']); ?></strong></td>
                                        <td><?php echo $cal['fecha_entrega'] ? date('d/m/Y', strtotime($cal['fecha_entrega'])) : 'Sin fecha'; ?></td>
                                        <td><span class="badge badge-bueno"><?php echo number_format($cal['calificacion'], 2); ?>/20</span></td>
                                        <td>
                                            <button class="btn-ver" onclick="event.stopPropagation(); verDetalle(<?php echo htmlspecialchars(json_encode($cal)); ?>)">
                                                Ver
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Vista Móvil (cards) -->
                    <div class="mobile-cards">
                        <?php foreach ($calificaciones as $cal): ?>
                            <div class="actividad-card" onclick="verDetalle(<?php echo htmlspecialchars(json_encode($cal)); ?>)">
                                <div class="card-header">
                                    <span class="card-titulo"><?php echo htmlspecialchars($cal['actividad_titulo']); ?></span>
                                    <span class="card-badge badge-bueno"><?php echo number_format($cal['calificacion'], 2); ?>/20</span>
                                </div>
                                
                                <div class="card-detalle">
                                    <div class="detalle-item">
                                        <span class="detalle-label">Fecha</span>
                                        <span class="detalle-valor"><?php echo $cal['fecha_entrega'] ? date('d/m/Y', strtotime($cal['fecha_entrega'])) : 'Sin fecha'; ?></span>
                                    </div>
                                </div>
                                
                                <div class="card-footer">
                                    <button class="card-btn" onclick="event.stopPropagation(); verDetalle(<?php echo htmlspecialchars(json_encode($cal)); ?>)">
                                        Ver detalles
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-datos">
                        <div class="no-datos-icon">📭</div>
                        <h3>No hay calificaciones disponibles</h3>
                        <p>Vuelve a consultar más tarde</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Tab Pendientes -->
            <div id="tab-pendientes" class="tabla-card" style="display: none;">
                <h3>Actividades Pendientes</h3>
                
                <?php if (count($pendientes) > 0): ?>
                    <!-- Vista Desktop (tabla) -->
                    <div class="table-responsive">
                        <table class="calificaciones-table">
                            <thead>
                                <tr>
                                    <th>Actividad</th>
                                    <th>Fecha Límite</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendientes as $pend): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($pend['actividad_titulo']); ?></strong></td>
                                        <td><?php echo $pend['fecha_entrega'] ? date('d/m/Y', strtotime($pend['fecha_entrega'])) : 'Sin fecha'; ?></td>
                                        <td><span class="badge badge-pendiente"> Pendiente</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Vista Móvil (cards) -->
                    <div class="mobile-cards">
                        <?php foreach ($pendientes as $pend): ?>
                            <div class="actividad-card">
                                <div class="card-header">
                                    <span class="card-titulo"><?php echo htmlspecialchars($pend['actividad_titulo']); ?></span>
                                    <span class="card-badge badge-pendiente">Pendiente</span>
                                </div>
                                
                                <div class="card-detalle">
                                    <div class="detalle-item">
                                        <span class="detalle-label">Fecha límite</span>
                                        <span class="detalle-valor"><?php echo $pend['fecha_entrega'] ? date('d/m/Y', strtotime($pend['fecha_entrega'])) : 'Sin fecha'; ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-datos">
                        <div class="no-datos-icon">🎉</div>
                        <h3>¡Todo al día!</h3>
                        <p>No tienes actividades pendientes</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Modal de detalle -->
    <div id="modalDetalle" class="modal">
        <div class="modal-content">
            <button class="modal-close" onclick="cerrarModal()">&times;</button>
            <h2 id="modalTitulo" style="margin-bottom: 24px; font-size: 20px;">Detalle</h2>
            
            <div class="modal-info">
                <div class="modal-info-label">Nota</div>
                <div class="modal-info-value" id="modalNota">-</div>
            </div>
            
            <div class="modal-info">
                <div class="modal-info-label">Fecha</div>
                <div class="modal-info-value" id="modalFecha">-</div>
            </div>
            
            <div class="modal-info">
                <div class="modal-info-label">Retroalimentación</div>
                <div class="modal-info-value" id="modalRetro" style="font-weight: 400; white-space: pre-line;">-</div>
            </div>
            
            <div class="modal-actions">
                <button class="btn-modal btn-modal-primary" onclick="exportarPDF()">📄 Exportar PDF</button>
                <button class="btn-modal btn-modal-secondary" onclick="cerrarModal()">Cerrar</button>
            </div>
        </div>
    </div>

    <footer class="footer">
        <span>SIEDUCRES v2.0</span>
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

        // Función para cambiar entre tabs
        function mostrarTab(tab, element) {
            // Remover active de todos los tabs
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            // Agregar active al tab clickeado
            element.classList.add('active');
            
            // Ocultar todos los contenidos de tabs
            document.getElementById('tab-calificadas').style.display = 'none';
            document.getElementById('tab-pendientes').style.display = 'none';
            
            // Mostrar el tab seleccionado
            document.getElementById('tab-' + tab).style.display = 'block';
        }

        // Función para ver detalle de calificación
        function verDetalle(cal) {
            document.getElementById('modalTitulo').textContent = cal.actividad_titulo || 'Detalle';
            document.getElementById('modalNota').textContent = (cal.calificacion ? cal.calificacion + '/20' : 'Sin calificación');
            document.getElementById('modalFecha').textContent = cal.fecha_entrega ? new Date(cal.fecha_entrega).toLocaleDateString('es-ES') : 'Sin fecha';
            document.getElementById('modalRetro').textContent = cal.observaciones || 'Sin retroalimentación';
            document.getElementById('modalDetalle').classList.add('active');
        }

        // Función para cerrar modal
        function cerrarModal() {
            document.getElementById('modalDetalle').classList.remove('active');
        }

        // Función para exportar PDF
        function exportarPDF() {
            window.open('generar_pdf_calificaciones.php', '_blank');
        }

        // Cerrar modal con tecla Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                cerrarModal();
            }
        });

        // Cerrar modal al hacer clic fuera
        document.getElementById('modalDetalle').addEventListener('click', function(e) {
            if (e.target === this) {
                cerrarModal();
            }
        });
    </script>
</body>
</html>
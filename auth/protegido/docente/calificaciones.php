<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/onesignal_config.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Docente') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$mensaje = $_SESSION['mensaje_temporal'] ?? '';
$error = $_SESSION['error_temporal'] ?? '';
unset($_SESSION['mensaje_temporal'], $_SESSION['error_temporal']);

// Obtener estudiante seleccionado
$estudiante_seleccionado = isset($_GET['estudiante']) ? (int)$_GET['estudiante'] : 0;

try {
    $conexion = getConexion();
    
    // Obtener datos del docente
    $stmt_docente = $conexion->prepare("SELECT grado, seccion FROM docentes WHERE usuario_id = ?");
    $stmt_docente->execute([$usuario_id]);
    $docente = $stmt_docente->fetch(PDO::FETCH_ASSOC);
    
    if (!$docente) {
        header('Location: index.php?error=Docente+sin+grado+asignado');
        exit();
    }
    
    // ============================================
    // 1. OBTENER LISTA DE ESTUDIANTES CON ESTADÍSTICAS
    // ============================================
    $estudiantes = $conexion->prepare("
        SELECT 
            u.id,
            u.nombre,
            u.correo,
            e.grado,
            e.seccion,
            COUNT(DISTINCT a.id) as total_actividades,
            COUNT(DISTINCT ee.id) as entregas_realizadas,
            COUNT(DISTINCT CASE WHEN ee.estado = 'calificado' THEN ee.id END) as entregas_calificadas,
            COUNT(DISTINCT CASE WHEN ee.fecha_entrega > a.fecha_entrega THEN ee.id END) as entregas_atrasadas,
            COUNT(DISTINCT CASE WHEN ee.estado = 'enviado' THEN ee.id END) as pendientes_calificar,
            ROUND(AVG(ee.calificacion)::numeric, 2) as promedio,
            MAX(ee.fecha_entrega) as ultima_entrega
        FROM usuarios u
        INNER JOIN estudiantes e ON u.id = e.usuario_id
        CROSS JOIN actividades a
        LEFT JOIN entregas_estudiantes ee ON a.id = ee.actividad_id AND u.id = ee.estudiante_id
        WHERE u.rol = 'Estudiante' 
            AND e.grado = ? 
            AND e.seccion = ?
            AND a.docente_id = ?
            AND a.activo = true
        GROUP BY u.id, u.nombre, u.correo, e.grado, e.seccion
        ORDER BY u.nombre
    ");
    $estudiantes->execute([$docente['grado'], $docente['seccion'], $usuario_id]);
    $lista_estudiantes = $estudiantes->fetchAll(PDO::FETCH_ASSOC);
    
    // ============================================
    // 2. OBTENER DETALLE DE ACTIVIDADES DEL ESTUDIANTE SELECCIONADO
    // ============================================
    $actividades_estudiante = [];
    if ($estudiante_seleccionado > 0) {
        $actividades = $conexion->prepare("
            SELECT 
                a.id,
                a.titulo,
                a.descripcion,
                a.tipo,
                a.fecha_entrega as fecha_limite,
                ee.id as entrega_id,
                ee.fecha_entrega as fecha_entrega,
                ee.archivo_entregado,
                ee.comentario,
                ee.calificacion,
                ee.observaciones,
                ee.estado,
                CASE 
                    WHEN ee.fecha_entrega IS NULL THEN 'sin_entregar'
                    WHEN ee.fecha_entrega > a.fecha_entrega THEN 'atrasado'
                    ELSE 'a_tiempo'
                END as condicion_entrega,
                CASE 
                    WHEN ee.estado IS NULL THEN 'Pendiente'
                    WHEN ee.estado = 'enviado' THEN 'Enviado'
                    WHEN ee.estado = 'calificado' THEN 'Calificado'
                    ELSE 'Pendiente'
                END as estado_texto,
                EXTRACT(DAY FROM (ee.fecha_entrega - a.fecha_entrega)) as dias_atraso
            FROM actividades a
            LEFT JOIN entregas_estudiantes ee ON a.id = ee.actividad_id AND ee.estudiante_id = ?
            WHERE a.docente_id = ? 
                AND a.activo = true
            ORDER BY a.fecha_entrega DESC
        ");
        $actividades->execute([$estudiante_seleccionado, $usuario_id]);
        $actividades_estudiante = $actividades->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener nombre del estudiante seleccionado
        $stmt_nombre = $conexion->prepare("SELECT nombre FROM usuarios WHERE id = ?");
        $stmt_nombre->execute([$estudiante_seleccionado]);
        $nombre_estudiante = $stmt_nombre->fetchColumn();
    }
    
    // ============================================
    // 3. ESTADÍSTICAS GENERALES
    // ============================================
    $stats = $conexion->prepare("
        SELECT 
            COUNT(DISTINCT a.id) as total_actividades,
            COUNT(DISTINCT u.id) as total_estudiantes,
            COUNT(DISTINCT ee.id) as total_entregas,
            COUNT(DISTINCT CASE WHEN ee.estado = 'enviado' THEN ee.id END) as pendientes_calificar,
            COUNT(DISTINCT CASE WHEN ee.fecha_entrega > a.fecha_entrega THEN ee.id END) as entregas_atrasadas,
            ROUND(AVG(ee.calificacion)::numeric, 2) as promedio_general,
            (COUNT(DISTINCT a.id) * COUNT(DISTINCT u.id) - COUNT(DISTINCT ee.id)) as no_entregadas
        FROM actividades a
        CROSS JOIN usuarios u
        INNER JOIN estudiantes e ON u.id = e.usuario_id
        LEFT JOIN entregas_estudiantes ee ON a.id = ee.actividad_id AND u.id = ee.estudiante_id
        WHERE a.docente_id = ? 
            AND a.activo = true
            AND u.rol = 'Estudiante'
            AND e.grado = ? 
            AND e.seccion = ?
    ");
    $stats->execute([$usuario_id, $docente['grado'], $docente['seccion']]);
    $estadisticas = $stats->fetch(PDO::FETCH_ASSOC);

    // Calcular porcentaje de cumplimiento
    $total_esperado = ($estadisticas['total_actividades'] ?? 0) * ($estadisticas['total_estudiantes'] ?? 0);
    $total_entregas = $estadisticas['total_entregas'] ?? 0;
    $porcentaje_cumplimiento = $total_esperado > 0 ? round(($total_entregas / $total_esperado) * 100, 1) : 0;
    
} catch (Exception $e) {
    error_log("Error en calificaciones: " . $e->getMessage());
    $error = 'Error al cargar los datos.';
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
            --primary-cyan: #4BC4E7;
            --primary-pink: #EF5E8E;
            --primary-lime: #C2D54E;
            --primary-purple: #9B8AFB;
            --text-dark: #333333;
            --text-muted: #666666;
            --border: #E0E0E0;
            --surface: #FFFFFF;
            --background: #F5F5F5;
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

        /* Banner responsive */
        .banner {
            height: 80px;
            overflow: hidden;
            position: relative;
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
            color: var(--text-dark);
            margin-bottom: 4px;
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

        /* Alertas */
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        /* Estadísticas responsive */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }

        @media (min-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin-bottom: 30px;
            }
        }

        .stat-card {
            background: var(--surface);
            border-radius: 12px;
            padding: 15px;
            border-left: 4px solid;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .stat-card.cyan { border-left-color: var(--primary-cyan); }
        .stat-card.pink { border-left-color: var(--primary-pink); }
        .stat-card.lime { border-left-color: var(--primary-lime); }
        .stat-card.purple { border-left-color: var(--primary-purple); }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        @media (min-width: 768px) {
            .stat-value {
                font-size: 32px;
            }
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-muted);
        }

        .stat-detail {
            font-size: 11px;
            margin-top: 4px;
        }

        /* Acciones container responsive */
        .acciones-container {
            background: white;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 24px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(75, 196, 231, 0.1);
        }

        .acciones-wrapper {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        @media (min-width: 1024px) {
            .acciones-wrapper {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        .selector-wrapper {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        @media (min-width: 1024px) {
            .selector-wrapper {
                flex-direction: row;
                align-items: center;
                gap: 15px;
                flex: 1;
            }
        }

        .estudiante-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(75, 196, 231, 0.1);
            padding: 8px 15px;
            border-radius: 50px;
            font-size: 14px;
        }

        .filter-select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #eef2f6;
            border-radius: 12px;
            font-size: 15px;
            color: var(--text-dark);
            background-color: white;
            cursor: pointer;
            transition: all 0.3s;
            outline: none;
        }

        .filter-select:hover,
        .filter-select:focus {
            border-color: var(--primary-cyan);
            box-shadow: 0 0 0 3px rgba(75, 196, 231, 0.1);
        }

        .botones-wrapper {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        @media (min-width: 768px) {
            .botones-wrapper {
                flex-direction: row;
                gap: 12px;
            }
        }

        .btn-reporte {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            width: 100%;
        }

        @media (min-width: 768px) {
            .btn-reporte {
                width: auto;
            }
        }

        .btn-reporte.cyan {
            background: var(--primary-cyan);
            color: white;
            box-shadow: 0 4px 15px rgba(75, 196, 231, 0.3);
        }

        .btn-reporte.purple {
            background: var(--primary-purple);
            color: white;
            box-shadow: 0 4px 15px rgba(155, 138, 251, 0.3);
        }

        .btn-reporte.disabled {
            background: #e0e0e0;
            color: #999;
            cursor: not-allowed;
            opacity: 0.7;
            pointer-events: none;
        }

        /* Panel contenedor responsive */
        .panel-container {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        @media (min-width: 1024px) {
            .panel-container {
                display: grid;
                grid-template-columns: 300px 1fr;
                gap: 20px;
            }
        }

        /* Panel de estudiantes */
        .estudiantes-panel {
            background: var(--surface);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            max-height: 500px;
            overflow-y: auto;
        }

        @media (min-width: 1024px) {
            .estudiantes-panel {
                height: fit-content;
                max-height: 600px;
            }
        }

        .estudiantes-panel h2 {
            font-size: 18px;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--primary-cyan);
        }

        .estudiante-item {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
        }

        .estudiante-item:hover {
            background: #f0f0f0;
            border-color: var(--border);
        }

        .estudiante-item.active {
            background: rgba(75, 196, 231, 0.1);
            border-color: var(--primary-cyan);
        }

        .estudiante-nombre {
            font-weight: 600;
            margin-bottom: 4px;
            font-size: 14px;
        }

        .estudiante-progreso {
            display: flex;
            gap: 6px;
            font-size: 11px;
            color: var(--text-muted);
            flex-wrap: wrap;
        }

        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
        }

        .badge-pendiente {
            background: #fff3cd;
            color: #856404;
        }

        .badge-atrasado {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-calificado {
            background: #d4edda;
            color: #155724;
        }

        /* Panel de actividades */
        .actividades-panel {
            background: var(--surface);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow-x: auto;
        }

        .actividades-panel h2 {
            font-size: 18px;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--primary-purple);
        }

        /* Tabla responsive */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            min-width: 700px;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 12px 8px;
            background: #f8f9fa;
            font-weight: 600;
            color: var(--text-dark);
            border-bottom: 2px solid var(--border);
            font-size: 13px;
        }

        td {
            padding: 12px 8px;
            border-bottom: 1px solid var(--border);
            color: var(--text-muted);
            font-size: 13px;
        }

        tr:hover td {
            background: #f8f9fa;
        }

        .estado-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .estado-sin-entregar { background: #e0e0e0; color: #666; }
        .estado-enviado { background: #cce5ff; color: #004085; }
        .estado-calificado { background: #d4edda; color: #155724; }
        .estado-atrasado { background: #f8d7da; color: #721c24; }

        .btn-ver, .btn-calificar {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-ver {
            background: var(--primary-cyan);
            color: var(--text-dark);
        }

        .btn-calificar {
            background: var(--primary-lime);
            color: var(--text-dark);
        }

        .no-seleccion {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }

        /* Modal responsive */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            padding: 16px;
        }

        .modal-content {
            background: white;
            width: 100%;
            max-width: 500px;
            margin: 20px auto;
            padding: 24px;
            border-radius: 16px;
            max-height: 90vh;
            overflow-y: auto;
        }
        body {
            padding-top: 60px;  /* ← ALTURA DEL HEADER */
        }

        @media (min-width: 768px) {
            .modal-content {
                margin: 50px auto;
                padding: 30px;
            }
        }

        .modal h3 {
            margin-bottom: 16px;
        }

        .modal p {
            margin-bottom: 20px;
            color: var(--text-muted);
        }

        .modal form {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .modal label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
        }

        .modal input,
        .modal textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
        }

        .modal-buttons {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 8px;
        }

        @media (min-width: 768px) {
            .modal-buttons {
                flex-direction: row;
                justify-content: flex-end;
                gap: 12px;
            }
        }

        .modal-btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
        }

        @media (min-width: 768px) {
            .modal-btn {
                width: auto;
            }
        }

        .modal-btn-cancel {
            background: #f0f0f0;
            border: 1px solid var(--border);
        }

        .modal-btn-primary {
            background: var(--primary-lime);
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
        }
        /* Enlace volver */
        .back-link {
            display: block;
            color: var(--primary-pink);
            text-decoration: none;
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
        <h1 class="banner-title">Calificaciones por Estudiante</h1>
        <p style="color: var(--text-muted);"><?php echo htmlspecialchars($docente['grado'] . ' ' . $docente['seccion']); ?></p>
    </div>
    <!-- 🔴 FLECHA DE VOLVER A LA IZQUIERDA -->
    <?php if (basename($_SERVER['PHP_SELF']) != 'index.php'): ?>
        <div style="max-width: 1200px; margin: 10px 0 10px 40px; padding: 0; width: 100%;">
            <a href="index.php" class="back-link">← Volver al Panel</a>
        </div>
    <?php endif; ?>

    <main class="main-content">
        <?php if ($mensaje): ?>
            <div class="alert alert-success">✅ <?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Estadísticas generales -->
        <div class="stats-grid">
            <div class="stat-card cyan">
                <div class="stat-value"><?php echo $estadisticas['total_estudiantes'] ?? 0; ?></div>
                <div class="stat-label">Estudiantes</div>
            </div>
            <div class="stat-card pink">
                <div class="stat-value"><?php echo $estadisticas['total_actividades'] ?? 0; ?></div>
                <div class="stat-label">Actividades</div>
            </div>
            <div class="stat-card lime">
                <div class="stat-value"><?php echo $estadisticas['pendientes_calificar'] ?? 0; ?></div>
                <div class="stat-label">Por calificar</div>
            </div>
            <div class="stat-card purple">
                <div class="stat-value"><?php echo $estadisticas['entregas_atrasadas'] ?? 0; ?></div>
                <div class="stat-label">Atrasadas</div>
            </div>
            <div class="stat-card" style="border-left-color: #FF6B6B;">
                <div class="stat-value" style="color: #FF6B6B;"><?php echo $estadisticas['no_entregadas'] ?? 0; ?></div>
                <div class="stat-label">No entregadas</div>
                <div class="stat-detail" style="color: #FF6B6B;">
                    <?php echo $porcentaje_cumplimiento; ?>% de cumplimiento
                </div>
            </div>
        </div>

        <!-- SELECTOR Y BOTONES RESPONSIVE -->
        <div class="acciones-container">
            <div class="acciones-wrapper">
                <!-- SELECTOR DE ESTUDIANTE -->
                <div class="selector-wrapper">
                    <div class="estudiante-badge">
                        <span style="font-size: 18px;">👤</span>
                        <span style="font-weight: 600; color: var(--primary-cyan);">Estudiante:</span>
                    </div>
                    <select class="filter-select" onchange="if(this.value) window.location.href='calificaciones.php?estudiante='+this.value">
                        <option value="">-- Seleccionar estudiante --</option>
                        <?php foreach ($lista_estudiantes as $est): ?>
                            <option value="<?php echo $est['id']; ?>" <?php echo $estudiante_seleccionado == $est['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($est['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- BOTONES DE REPORTE -->
                <div class="botones-wrapper">
                    <!-- Botón Reporte General -->
                    <a href="reporte_calificaciones.php?tipo=general" target="_blank" class="btn-reporte cyan">
                        <span>📊</span>
                        <span>Reporte General</span>
                    </a>
                    
                    <!-- Botón Reporte Individual -->
                    <?php if (isset($estudiante_seleccionado) && $estudiante_seleccionado > 0): ?>
                        <a href="reporte_calificaciones.php?tipo=individual&estudiante=<?php echo $estudiante_seleccionado; ?>" 
                           target="_blank" class="btn-reporte purple">
                            <span>📄</span>
                            <span>Reporte: <?php echo htmlspecialchars($nombre_estudiante ?? ''); ?></span>
                        </a>
                    <?php else: ?>
                        <div class="btn-reporte disabled">
                            <span>🔒</span>
                            <span>Selecciona estudiante</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Panel principal -->
        <div class="panel-container">
            <!-- Lista de estudiantes -->
            <div class="estudiantes-panel">
                <h2>📋 Estudiantes</h2>
                
                <?php if (count($lista_estudiantes) > 0): ?>
                    <?php foreach ($lista_estudiantes as $est): ?>
                        <div class="estudiante-item <?php echo $estudiante_seleccionado == $est['id'] ? 'active' : ''; ?>" 
                             onclick="window.location.href='calificaciones.php?estudiante=<?php echo $est['id']; ?>'">
                            <div class="estudiante-nombre">
                                <?php echo htmlspecialchars($est['nombre']); ?>
                            </div>
                            <div class="estudiante-progreso">
                                <span>📊 <?php echo $est['entregas_calificadas'] . '/' . $est['total_actividades']; ?></span>
                                
                                <?php if ($est['pendientes_calificar'] > 0): ?>
                                    <span class="badge badge-pendiente">⏳ <?php echo $est['pendientes_calificar']; ?></span>
                                <?php endif; ?>
                                
                                <?php if ($est['entregas_atrasadas'] > 0): ?>
                                    <span class="badge badge-atrasado">⚠️ <?php echo $est['entregas_atrasadas']; ?></span>
                                <?php endif; ?>
                                
                                <?php if ($est['promedio'] > 0): ?>
                                    <span>🎯 <?php echo $est['promedio']; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color: var(--text-muted); text-align: center;">No hay estudiantes</p>
                <?php endif; ?>
            </div>

            <!-- Detalle de actividades del estudiante -->
            <div class="actividades-panel">
                <?php if ($estudiante_seleccionado > 0 && !empty($nombre_estudiante)): ?>
                    <h2>📝 <?php echo htmlspecialchars($nombre_estudiante); ?> - Actividades</h2>
                    
                    <?php if (count($actividades_estudiante) > 0): ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Actividad</th>
                                        <th>Fecha límite</th>
                                        <th>Entrega</th>
                                        <th>Estado</th>
                                        <th>Calificación</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($actividades_estudiante as $act): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($act['titulo']); ?></strong>
                                                <br><small style="font-size: 11px;"><?php echo ucfirst($act['tipo']); ?></small>
                                            </td>
                                            <td><?php echo date('d/m/Y', strtotime($act['fecha_limite'])); ?></td>
                                            <td>
                                                <?php if ($act['fecha_entrega']): ?>
                                                    <?php echo date('d/m/Y', strtotime($act['fecha_entrega'])); ?>
                                                    <?php if ($act['condicion_entrega'] === 'atrasado'): ?>
                                                        <br><small style="color: #dc3545;">
                                                            <?php echo $act['dias_atraso']; ?> día(s)
                                                        </small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted);">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="estado-badge <?php echo 'estado-' . strtolower($act['estado_texto']); ?>">
                                                    <?php echo $act['estado_texto']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($act['calificacion'] !== null): ?>
                                                    <strong style="color: var(--primary-purple);">
                                                        <?php echo number_format($act['calificacion'], 1); ?>
                                                    </strong>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted);">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($act['estado_texto'] === 'Enviado' || $act['estado_texto'] === 'Calificado'): ?>
                                                    <button class="btn-calificar" onclick="abrirModal(
                                                        <?php echo $act['id']; ?>,
                                                        <?php echo $estudiante_seleccionado; ?>,
                                                        '<?php echo htmlspecialchars($act['titulo']); ?>',
                                                        <?php echo $act['calificacion'] ?? 'null'; ?>,
                                                        '<?php echo htmlspecialchars($act['observaciones'] ?? ''); ?>'
                                                    )">
                                                        <?php echo $act['estado_texto'] === 'Calificado' ? 'Editar' : 'Calificar'; ?>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if ($act['archivo_entregado'] && isset($act['entrega_id'])): ?>
                                                    <a href="ver_entrega.php?id=<?php echo $act['entrega_id']; ?>" class="btn-ver">Ver detalles</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <input type="hidden" id="feedback-<?php echo $act['entrega_id']; ?>" 
                                               value="<?php echo htmlspecialchars($act['observaciones'] ?? ''); ?>">
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; padding: 40px; color: var(--text-muted);">
                            No hay actividades para este estudiante.
                        </p>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div class="no-seleccion">
                        <h3 style="margin-bottom: 16px;">👈 Selecciona un estudiante</h3>
                        <p>Haz clic en un estudiante para ver sus actividades y calificaciones.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Modal de calificación -->
    <div id="modalCalificar" class="modal">
        <div class="modal-content">
            <h3>Calificar actividad</h3>
            <p id="modalActividadTitulo"></p>
            
            <form id="formCalificar" method="POST" action="procesar_calificacion.php">
                <input type="hidden" name="actividad_id" id="modalActividadId">
                <input type="hidden" name="estudiante_id" id="modalEstudianteId">
                
                <div>
                    <label>Calificación (0-20)</label>
                    <input type="number" name="calificacion" id="modalCalificacion" 
                           step="0.1" min="0" max="20" required>
                </div>
                
                <div>
                    <label>Observaciones / Retroalimentación</label>
                    <textarea name="observaciones" id="modalObservaciones" rows="4"></textarea>
                </div>
                
                <div class="modal-buttons">
                    <button type="button" class="modal-btn modal-btn-cancel" onclick="cerrarModal()">Cancelar</button>
                    <button type="submit" class="modal-btn modal-btn-primary">Guardar calificación</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de feedback -->
    <div id="modalFeedback" class="modal">
        <div class="modal-content">
            <h3>📝 Retroalimentación</h3>
            <div id="feedbackContenido" style="margin-bottom: 20px; padding: 16px; background: #f8f9fa; border-radius: 8px; max-height: 300px; overflow-y: auto;"></div>
            <div style="text-align: right;">
                <button class="modal-btn modal-btn-primary" onclick="cerrarFeedback()">Cerrar</button>
            </div>
        </div>
    </div>

    <footer class="footer">
        <span>v2.0.0</span>
        <span>Soporte Técnico</span>
    </footer>

    <script>
        // Menú hamburguesa
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

        // Modal de calificación
        function abrirModal(actividadId, estudianteId, titulo, calificacion, observaciones) {
            document.getElementById('modalActividadId').value = actividadId;
            document.getElementById('modalEstudianteId').value = estudianteId;
            document.getElementById('modalActividadTitulo').textContent = titulo;
            document.getElementById('modalCalificacion').value = calificacion !== null ? calificacion : '';
            document.getElementById('modalObservaciones').value = observaciones || '';
            
            document.getElementById('modalCalificar').style.display = 'flex';
        }

        function cerrarModal() {
            document.getElementById('modalCalificar').style.display = 'none';
        }

        // Modal de feedback
        function verFeedback(entregaId) {
            const campoOculto = document.getElementById('feedback-' + entregaId);
            const feedback = campoOculto ? campoOculto.value : 'No hay observaciones disponibles';
            
            document.getElementById('feedbackContenido').innerHTML = 
                '<p style="white-space: pre-line;">' + feedback + '</p>';
            
            document.getElementById('modalFeedback').style.display = 'flex';
        }

        function cerrarFeedback() {
            document.getElementById('modalFeedback').style.display = 'none';
        }

        // Cerrar modales al hacer clic fuera
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>
<?php
session_start();
require_once '../../funciones.php';

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
    // 3. ESTADÍSTICAS GENERALES - MODIFICADO
    // ============================================
    $stats = $conexion->prepare("
        SELECT 
            COUNT(DISTINCT a.id) as total_actividades,
            COUNT(DISTINCT u.id) as total_estudiantes,
            COUNT(DISTINCT ee.id) as total_entregas,
            COUNT(DISTINCT CASE WHEN ee.estado = 'enviado' THEN ee.id END) as pendientes_calificar,
            COUNT(DISTINCT CASE WHEN ee.fecha_entrega > a.fecha_entrega THEN ee.id END) as entregas_atrasadas,
            ROUND(AVG(ee.calificacion)::numeric, 2) as promedio_general,
            -- ✅ NUEVA MÉTRICA: Actividades no entregadas
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

    // ✅ Calcular adicionalmente el porcentaje de cumplimiento
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calificaciones - SIEDUCRES</title>
    <?php require_once '../includes/favicon.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
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
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 24px;
            height: 60px;
            background-color: var(--surface);
            border-bottom: 1px solid var(--border);
        }
        
        .logo { height: 40px; }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 16px;
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
        }

        .icon-btn img {
            width: 20px;
            height: 20px;
            object-fit: contain;
        }
        
        .icon-btn:hover {
            background-color: #E0E0E0;
        }
        
        .menu-dropdown {
            position: absolute;
            top: 60px;
            right: 24px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            display: none;
            min-width: 180px;
        }
        
        .menu-item {
            padding: 10px 16px;
            font-size: 14px;
            color: var(--text-dark);
            text-decoration: none;
            display: block;
        }
        
        .menu-item:hover {
            background-color: #F8F8F8;
        }
        
        .banner {
            height: 100px;
            overflow: hidden;
            position: relative;
        }
        
        .banner-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .banner-content {
            text-align: center;
            padding: 20px;
            max-width: 800px;
            margin: 0 auto;
        }
        
        .banner-title {
            font-size: 36px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 8px;
        }
        
        .main-content {
            flex: 1;
            padding: 40px 20px;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }
        
        .footer {
            height: 50px;
            background-color: var(--surface);
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 24px;
            font-size: 13px;
            color: var(--text-muted);
        }
        
        /* Estadísticas */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--surface);
            border-radius: 12px;
            padding: 20px;
            border-left: 4px solid;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .stat-card.cyan { border-left-color: var(--primary-cyan); }
        .stat-card.pink { border-left-color: var(--primary-pink); }
        .stat-card.lime { border-left-color: var(--primary-lime); }
        .stat-card.purple { border-left-color: var(--primary-purple); }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-muted);
        }
        
        /* Panel de estudiantes */
        .panel-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 20px;
        }
        
        .estudiantes-panel {
            background: var(--surface);
            border-radius: 16px;
            padding: 20px;
            height: fit-content;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
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
        }
        
        .estudiante-progreso {
            display: flex;
            gap: 8px;
            font-size: 12px;
            color: var(--text-muted);
            flex-wrap: wrap;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
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
        
        /* Tabla de actividades */
        .actividades-panel {
            background: var(--surface);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .actividades-panel h2 {
            font-size: 18px;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--primary-purple);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            text-align: left;
            padding: 12px;
            background: #f8f9fa;
            font-weight: 600;
            color: var(--text-dark);
            border-bottom: 2px solid var(--border);
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid var(--border);
            color: var(--text-muted);
        }
        
        tr:hover td {
            background: #f8f9fa;
        }
        
        .estado-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .estado-sin-entregar { background: #e0e0e0; color: #666; }
        .estado-enviado { background: #cce5ff; color: #004085; }
        .estado-calificado { background: #d4edda; color: #155724; }
        .estado-atrasado { background: #f8d7da; color: #721c24; }
        
        .calificacion-input {
            width: 70px;
            padding: 6px;
            border: 1px solid var(--border);
            border-radius: 4px;
            text-align: center;
        }
        
        .btn-calificar {
            background: var(--primary-lime);
            color: var(--text-dark);
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-calificar:hover {
            background: #acbe36;
        }
        
        .btn-ver {
            background: var(--primary-cyan);
            color: var(--text-dark);
            padding: 6px 12px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
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
        
        .no-seleccion {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        /* Estilos mejorados para selector y botones */
        .acciones-container {
            background: white;
            border-radius: 16px;
            padding: 15px 20px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            border: 1px solid rgba(75, 196, 231, 0.1);
            transition: all 0.3s ease;
        }

        .acciones-container:hover {
            box-shadow: 0 8px 25px rgba(75, 196, 231, 0.15);
            border-color: var(--primary-cyan);
        }

        .selector-wrapper {
            flex: 1;
        }

        .filter-select {
            border: 2px solid #eef2f6;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 15px;
            color: var(--text-dark);
            background-color: white;
            cursor: pointer;
            transition: all 0.3s;
            outline: none;
        }

        .filter-select:hover {
            border-color: var(--primary-cyan);
        }

        .filter-select:focus {
            border-color: var(--primary-cyan);
            box-shadow: 0 0 0 3px rgba(75, 196, 231, 0.1);
        }

        .btn-reporte {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 24px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            border: none;
            cursor: pointer;
        }

        .btn-reporte::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn-reporte:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .btn-reporte:hover::before {
            left: 100%;
        }

        .btn-reporte:active {
            transform: translateY(0);
        }

        /* Responsive para móviles */
        @media (max-width: 768px) {
            .acciones-container {
                flex-direction: column;
                align-items: stretch;
            }
            
            .selector-wrapper {
                flex-direction: column;
                align-items: stretch;
            }
            
            .botones-wrapper {
                flex-direction: column;
            }
            
            .btn-reporte {
                justify-content: center;
            }
        }
        /* Estilo adicional para la nueva tarjeta */
        .stat-card {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .stat-card .stat-detail {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* Estilo específico para la tarjeta de no entregadas */
        .stat-card[style*="border-left-color: #FF6B6B"] {
            background: linear-gradient(135deg, #fff5f5 0%, #ffffff 100%);
        }

        .stat-card[style*="border-left-color: #FF6B6B"] .stat-value {
            color: #FF6B6B;
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .stat-card[style*="border-left-color: #FF6B6B"] .stat-label {
            color: #FF6B6B;
            font-weight: 600;
        }

        /* Barra de progreso pequeña para el porcentaje */
        .progress-bar {
            width: 100%;
            height: 4px;
            background: #f0f0f0;
            border-radius: 2px;
            margin-top: 10px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: #4BC4E7;
            border-radius: 2px;
            transition: width 0.3s ease;
        }

        .progress-fill.warning {
            background: #FF6B6B;
        }

        .progress-fill.success {
            background: #C2D54E;
        }
        @media (max-width: 1024px) {
            .panel-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-left">
            <img src="../../../assets/logo.svg" alt="SIEDUCRES" class="logo">
        </div>
        <div class="header-right">
            <div class="icon-btn">
                <img src="../../../assets/icon-bell.svg" alt="Notificaciones">
            </div>
            <div class="icon-btn">
                <img src="../../../assets/icon-user.svg" alt="Perfil">
            </div>
            <div class="icon-btn" id="menu-toggle">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="#333333">
                    <path d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/>
                </svg>
            </div>
            <div class="menu-dropdown" id="dropdown">
                <a href="index.php" class="menu-item">Panel Principal</a>
                <a href="gestion_actividades.php" class="menu-item">Gestión de Actividades</a>
                <a href="../../logout.php" class="menu-item">Cerrar sesión</a>
            </div>
        </div>
    </header>

    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner" class="banner-image">
    </div>
    
    <div class="banner-content">
        <h1 class="banner-title">Calificaciones por Estudiante</h1>
        <p><?php echo htmlspecialchars($docente['grado'] . ' ' . $docente['seccion']); ?></p>
    </div>

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
            <div class="stat-card" style="border-left-color: #FF6B6B; background: linear-gradient(135deg, #fff5f5 0%, #ffffff 100%);">
                <div class="stat-value" style="color: #FF6B6B;"><?php echo $estadisticas['no_entregadas'] ?? 0; ?></div>
                <div class="stat-label">No entregadas</div>
                <div class="stat-detail" style="color: #FF6B6B;">
                    <?php echo $porcentaje_cumplimiento; ?>% de cumplimiento
                </div>
                <div style="margin-top: 8px; font-size: 11px; color: #666;">
                    <?php 
                    $total_esperado = ($estadisticas['total_actividades'] ?? 0) * ($estadisticas['total_estudiantes'] ?? 0);
                    echo "Esperadas: $total_esperado | Entregadas: {$estadisticas['total_entregas']}";
                    ?>
                </div>
            </div>
        </div>
        <!-- ✅ SELECTOR Y BOTONES - REEMPLAZA TODO ESTO -->
        <div class="acciones-container" style="display: flex; align-items: center; justify-content: space-between; gap: 20px; margin-bottom: 25px; background: white; padding: 15px 20px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid rgba(75, 196, 231, 0.1);">
            
            <!-- SELECTOR DE ESTUDIANTE (IZQUIERDA) -->
            <div class="selector-wrapper" style="display: flex; align-items: center; gap: 15px; flex: 1;">
                <div style="display: flex; align-items: center; gap: 8px; background: rgba(75, 196, 231, 0.1); padding: 8px 15px; border-radius: 50px;">
                    <span style="font-size: 20px;">👤</span>
                    <span style="font-weight: 600; color: var(--primary-cyan);">Estudiante:</span>
                </div>
                <select class="filter-select" onchange="if(this.value) window.location.href='calificaciones.php?estudiante='+this.value" 
                        style="flex: 1; padding: 12px 16px; border: 2px solid #eef2f6; border-radius: 12px; font-size: 15px; color: var(--text-dark); background-color: white; cursor: pointer; transition: all 0.3s; outline: none;">
                    <option value="">-- Seleccionar estudiante para reporte --</option>
                    <?php foreach ($lista_estudiantes as $est): ?>
                        <option value="<?php echo $est['id']; ?>" <?php echo $estudiante_seleccionado == $est['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($est['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- BOTONES DE REPORTE (DERECHA) -->
            <div class="botones-wrapper" style="display: flex; gap: 12px;">
                
                <!-- Botón Reporte General (COLOR CYAN) -->
                <a href="reporte_calificaciones.php?tipo=general" target="_blank" 
                class="btn-reporte" 
                style="display: inline-flex; align-items: center; gap: 10px; padding: 12px 24px; background: var(--primary-cyan); color: white; text-decoration: none; border-radius: 50px; font-weight: 600; font-size: 14px; box-shadow: 0 4px 15px rgba(75, 196, 231, 0.3); transition: all 0.3s ease; border: none;">
                    <span style="font-size: 18px;">📊</span>
                    <span>Reporte General</span>
                </a>
                
                <!-- Botón Reporte Individual (COLOR PURPLE) - SOLO SI HAY ESTUDIANTE SELECCIONADO -->
                <?php if (isset($estudiante_seleccionado) && $estudiante_seleccionado > 0): ?>
                    <a href="reporte_calificaciones.php?tipo=individual&estudiante=<?php echo $estudiante_seleccionado; ?>" 
                    target="_blank" 
                    class="btn-reporte" 
                    style="display: inline-flex; align-items: center; gap: 10px; padding: 12px 24px; background: var(--primary-purple); color: white; text-decoration: none; border-radius: 50px; font-weight: 600; font-size: 14px; box-shadow: 0 4px 15px rgba(155, 138, 251, 0.3); transition: all 0.3s ease; border: none;">
                        <span style="font-size: 18px;">📄</span>
                        <span>Reporte: <?php echo htmlspecialchars($nombre_estudiante ?? ''); ?></span>
                    </a>
                <?php else: ?>
                    <!-- Botón deshabilitado si no hay estudiante seleccionado -->
                    <button disabled 
                            style="display: inline-flex; align-items: center; gap: 10px; padding: 12px 24px; background: #e0e0e0; color: #999; border-radius: 50px; font-weight: 600; font-size: 14px; border: none; cursor: not-allowed; opacity: 0.7;">
                        <span style="font-size: 18px;">🔒</span>
                        <span>Selecciona estudiante</span>
                    </button>
                <?php endif; ?>
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
                                            <br><small><?php echo ucfirst($act['tipo']); ?></small>
                                        </td>
                                        <td><?php echo date('d/m/Y', strtotime($act['fecha_limite'])); ?></td>
                                        <td>
                                            <?php if ($act['fecha_entrega']): ?>
                                                <?php echo date('d/m/Y H:i', strtotime($act['fecha_entrega'])); ?>
                                                <?php if ($act['condicion_entrega'] === 'atrasado'): ?>
                                                    <br><small style="color: #dc3545;">
                                                        Atrasado <?php echo $act['dias_atraso']; ?> día(s)
                                                    </small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted);">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $estado_class = 'estado-' . str_replace(' ', '-', $act['estado_texto']);
                                            ?>
                                            <span class="estado-badge <?php echo $estado_class; ?>">
                                                <?php echo $act['estado_texto']; ?>
                                            </span>
                                            <?php if ($act['condicion_entrega'] === 'atrasado' && $act['estado_texto'] !== 'Pendiente'): ?>
                                                <br><small style="color: #dc3545;">(Atrasada)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($act['calificacion'] !== null): ?>
                                                <strong style="font-size: 16px; color: var(--primary-purple);">
                                                    <?php echo number_format($act['calificacion'], 1); ?>/20
                                                </strong>
                                                <?php if (!empty($act['observaciones'])): ?>
                                                    <br>
                                                    <button onclick="verFeedback(<?php echo $act['entrega_id']; ?>, '<?php echo htmlspecialchars($act['observaciones'], ENT_QUOTES); ?>')" 
                                                            class="btn-ver" style="margin-top: 4px; background: var(--primary-purple);">
                                                        Ver feedback
                                                    </button>
                                                <?php endif; ?>
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
                                            
                                            <?php if ($act['archivo_entregado']): ?>
                                                <a href="../../uploads/entregas/<?php echo $act['archivo_entregado']; ?>" 
                                                   target="_blank" class="btn-ver">Ver</a>
                                            <?php endif; ?>
                                        </td>
                                        <input type="hidden" id="feedback-<?php echo $act['entrega_id']; ?>" 
                                            value="<?php echo htmlspecialchars($act['observaciones'] ?? ''); ?>">
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
    <div id="modalCalificar" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
        <div style="background: white; width: 90%; max-width: 500px; margin: 50px auto; padding: 30px; border-radius: 16px;">
            <h3 style="margin-bottom: 20px;">Calificar actividad</h3>
            <p id="modalActividadTitulo" style="margin-bottom: 20px; color: var(--text-muted);"></p>
            
            <form id="formCalificar" method="POST" action="procesar_calificacion.php">
                <input type="hidden" name="actividad_id" id="modalActividadId">
                <input type="hidden" name="estudiante_id" id="modalEstudianteId">
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Calificación (0-20)</label>
                    <input type="number" name="calificacion" id="modalCalificacion" 
                           step="0.1" min="0" max="20" style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px;" required>
                </div>
                
                <div style="margin-bottom: 24px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Observaciones / Retroalimentación</label>
                    <textarea name="observaciones" id="modalObservaciones" 
                              rows="4" style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; resize: vertical;"></textarea>
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" onclick="cerrarModal()" style="padding: 10px 20px; background: #f0f0f0; border: 1px solid var(--border); border-radius: 8px; cursor: pointer;">Cancelar</button>
                    <button type="submit" style="padding: 10px 20px; background: var(--primary-lime); border: none; border-radius: 8px; cursor: pointer; font-weight: 600;">Guardar calificación</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Modal de feedback -->
    <div id="modalFeedback" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
        <div style="background: white; width: 90%; max-width: 500px; margin: 50px auto; padding: 30px; border-radius: 16px;">
            <h3 style="margin-bottom: 20px;">📝 Retroalimentación</h3>
            <div id="feedbackContenido" style="margin-bottom: 20px; padding: 16px; background: #f8f9fa; border-radius: 8px; max-height: 300px; overflow-y: auto;"></div>
            <div style="text-align: right;">
                <button onclick="cerrarFeedback()" style="padding: 8px 20px; background: var(--primary-cyan); border: none; border-radius: 8px; cursor: pointer;">Cerrar</button>
            </div>
        </div>
    </div>
    <footer class="footer">
        <span>v2.0.0</span>
        <span>Soporte Técnico</span>
    </footer>

    <script>
        // Menú hamburguesa
        document.getElementById('menu-toggle').addEventListener('click', function() {
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

        // En calificaciones.php, cuando haces clic en un estudiante
        function seleccionarEstudiante(id, nombre) {
            window.location.href = 'calificaciones.php?estudiante=' + id;
        }

        // Modal de calificación
        function abrirModal(actividadId, estudianteId, titulo, calificacion, observaciones) {
            document.getElementById('modalActividadId').value = actividadId;
            document.getElementById('modalEstudianteId').value = estudianteId;
            document.getElementById('modalActividadTitulo').textContent = titulo;
            document.getElementById('modalCalificacion').value = calificacion !== null ? calificacion : '';
            document.getElementById('modalObservaciones').value = observaciones || '';
            
            document.getElementById('modalCalificar').style.display = 'block';
        }
        // Modal de feedback - VERSIÓN CORREGIDA
        function verFeedback(entregaId) {
            // Buscar el campo oculto con ese ID
            const campoOculto = document.getElementById('feedback-' + entregaId);
            
            // Obtener el valor o usar un mensaje por defecto
            const feedback = campoOculto ? campoOculto.value : 'No hay observaciones disponibles';
            
            // Mostrar el feedback en el modal
            document.getElementById('feedbackContenido').innerHTML = 
                '<p style="white-space: pre-line; font-size: 14px; line-height: 1.6;">' + feedback + '</p>';
            
            // Mostrar el modal
            document.getElementById('modalFeedback').style.display = 'block';
        }
        function cerrarFeedback() {
            document.getElementById('modalFeedback').style.display = 'none';
        }

        function cerrarModal() {
            document.getElementById('modalCalificar').style.display = 'none';
        }

        // Cerrar modal al hacer clic fuera
        window.onclick = function(event) {
            const modal = document.getElementById('modalCalificar');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>
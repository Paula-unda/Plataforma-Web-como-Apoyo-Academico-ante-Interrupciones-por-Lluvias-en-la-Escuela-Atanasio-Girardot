<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/onesignal_config.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Representante') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$conexion = getConexion();
$usuario_id = $_SESSION['usuario_id'];

// =====================================================
// VERIFICAR SI EXISTE LA TABLA NOTIFICACIONES
// =====================================================
$tabla_notificaciones_existe = false;
try {
    $check = $conexion->query("SELECT 1 FROM notificaciones LIMIT 1");
    $tabla_notificaciones_existe = true;
} catch (Exception $e) {
    $tabla_notificaciones_existe = false;
}

// =====================================================
// OBTENER HIJOS DEL REPRESENTANTE
// =====================================================
$sql_hijos = "
    SELECT 
        u.id,
        u.nombre,
        e.grado,
        e.seccion,
        (SELECT COUNT(*) FROM entregas_estudiantes WHERE estudiante_id = u.id AND estado = 'pendiente') as actividades_pendientes,
        (SELECT COUNT(*) FROM entregas_estudiantes WHERE estudiante_id = u.id AND estado = 'calificado') as actividades_calificadas,
        (SELECT ROUND(AVG(calificacion)::numeric, 2) FROM entregas_estudiantes WHERE estudiante_id = u.id AND calificacion IS NOT NULL) as promedio
";

// Agregar notificaciones solo si la tabla existe
if ($tabla_notificaciones_existe) {
    $sql_hijos .= ",
        (SELECT COUNT(*) FROM notificaciones WHERE usuario_id = u.id AND leido = false) as notificaciones_no_leidas";
} else {
    $sql_hijos .= ", 0 as notificaciones_no_leidas";
}

$sql_hijos .= "
    FROM representantes_estudiantes re
    JOIN usuarios u ON re.estudiante_id = u.id
    JOIN estudiantes e ON u.id = e.usuario_id
    WHERE re.representante_id = ?
    ORDER BY u.nombre
";

$stmt_hijos = $conexion->prepare($sql_hijos);
$stmt_hijos->execute([$usuario_id]);
$hijos = $stmt_hijos->fetchAll(PDO::FETCH_ASSOC);

// Hijo seleccionado (por defecto el primero)
$hijo_seleccionado_id = $_GET['hijo_id'] ?? ($hijos[0]['id'] ?? 0);

// Si no hay hijos, mostrar mensaje
$sin_hijos = empty($hijos);

// =====================================================
// OBTENER DATOS DEL HIJO SELECCIONADO
// =====================================================
$hijo_actual = null;
$actividades_recientes = [];
$notificaciones = [];
$encuestas_pendientes = [];

if (!$sin_hijos && $hijo_seleccionado_id) {
    // Datos del hijo seleccionado
    foreach ($hijos as $h) {
        if ($h['id'] == $hijo_seleccionado_id) {
            $hijo_actual = $h;
            break;
        }
    }
    
    if ($hijo_actual) {
        // Actividades recientes del hijo
        $stmt_act = $conexion->prepare("
            SELECT 
                a.titulo,
                a.tipo,
                a.fecha_entrega,
                COALESCE(ee.estado, 'pendiente') as estado,
                ee.calificacion,
                ee.fecha_entrega as fecha_entrega_real
            FROM actividades a
            LEFT JOIN entregas_estudiantes ee ON a.id = ee.actividad_id AND ee.estudiante_id = ?
            WHERE a.grado = ? AND a.seccion = ?
            ORDER BY a.fecha_entrega DESC
            LIMIT 5
        ");
        $stmt_act->execute([$hijo_seleccionado_id, $hijo_actual['grado'], $hijo_actual['seccion']]);
        $actividades_recientes = $stmt_act->fetchAll(PDO::FETCH_ASSOC);
        
        // Notificaciones del hijo (solo si la tabla existe)
        if ($tabla_notificaciones_existe) {
            $stmt_notif = $conexion->prepare("
                SELECT *
                FROM notificaciones
                WHERE usuario_id = ?
                ORDER BY fecha_envio DESC
                LIMIT 5
            ");
            $stmt_notif->execute([$hijo_seleccionado_id]);
            $notificaciones = $stmt_notif->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Verificar si existe la tabla encuestas
        $tabla_encuestas_existe = false;
        try {
            $check_enc = $conexion->query("SELECT 1 FROM encuestas LIMIT 1");
            $tabla_encuestas_existe = true;
        } catch (Exception $e) {
            $tabla_encuestas_existe = false;
        }
        
        // Encuestas pendientes (si las tablas existen)
        if ($tabla_encuestas_existe) {
            try {
                $stmt_enc = $conexion->prepare("
                    SELECT e.*
                    FROM encuestas e
                    WHERE e.activo = true
                    AND e.fecha_cierre >= CURRENT_DATE
                    AND NOT EXISTS (
                        SELECT 1 FROM respuestas_encuesta re 
                        WHERE re.encuesta_id = e.id AND re.usuario_id = ?
                    )
                    ORDER BY e.fecha_publicacion DESC
                ");
                $stmt_enc->execute([$hijo_seleccionado_id]);
                $encuestas_pendientes = $stmt_enc->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $encuestas_pendientes = [];
            }
        }
    }
}

// =====================================================
// ESTADÍSTICAS GLOBALES DEL REPRESENTANTE
// =====================================================
$total_hijos = count($hijos);
$total_notificaciones_no_leidas = 0;
$total_actividades_pendientes = 0;

foreach ($hijos as $h) {
    $total_notificaciones_no_leidas += $h['notificaciones_no_leidas'];
    $total_actividades_pendientes += $h['actividades_pendientes'];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Panel de Representante - SIEDUCRES</title>
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

        .banner-subtitle {
            font-size: 16px;
            color: var(--text-muted);
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

        /* Selector de hijos responsive */
        .selector-hijos {
            background: white;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 24px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
            border: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        @media (min-width: 768px) {
            .selector-hijos {
                flex-direction: row;
                align-items: center;
                gap: 20px;
                padding: 20px;
            }
        }

        .selector-label {
            font-weight: 600;
            color: var(--primary-purple);
            font-size: 16px;
        }

        .hijos-tabs {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            flex: 1;
        }

        .hijo-tab {
            padding: 10px 20px;
            border-radius: 30px;
            background: #f0f0f0;
            color: var(--text-dark);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            border: 2px solid transparent;
            font-size: 14px;
        }

        .hijo-tab:hover {
            background: #e0e0e0;
        }

        .hijo-tab.active {
            background: var(--primary-cyan);
            color: white;
            border-color: var(--primary-purple);
        }

        .hijo-tab .badge {
            background: var(--primary-pink);
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 12px;
            margin-left: 8px;
        }

        /* Stats grid - IGUAL QUE EN REPORTES */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }

        @media (min-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 20px;
            }
        }

        .stat-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border-left: 4px solid var(--primary-cyan);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary-cyan);
            margin-bottom: 5px;
        }

        @media (min-width: 768px) {
            .stat-number {
                font-size: 32px;
            }
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-muted);
        }

        .stat-small {
            font-size: 11px;
            color: #999;
            margin-top: 5px;
        }

        /* Grid de contenido */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        @media (min-width: 1024px) {
            .content-grid {
                grid-template-columns: 2fr 1fr;
            }
        }

        .card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
            border: 1px solid var(--border);
        }

        .card-header {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-cyan);
        }

        @media (min-width: 768px) {
            .card-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }

        .card-header h2 {
            color: var(--primary-purple);
            font-size: 18px;
            font-weight: 600;
        }

        .card-header a {
            color: var(--primary-pink);
            text-decoration: none;
            font-size: 14px;
        }

        /* Tablas responsive */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            min-width: 500px;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 12px 8px;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 13px;
        }

        td {
            padding: 12px 8px;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge-pendiente { background: #fff3cd; color: #856404; }
        .badge-enviado { background: #d4edda; color: #155724; }
        .badge-calificado { background: #cce5ff; color: #004085; }
        .badge-info { background: var(--primary-cyan); color: white; }

        /* Notificaciones */
        .notificacion-item {
            padding: 12px;
            border-bottom: 1px solid var(--border);
            transition: background 0.2s;
        }

        .notificacion-item:hover {
            background: #f9f9f9;
        }

        .notificacion-titulo {
            font-weight: 600;
            margin-bottom: 4px;
            font-size: 14px;
        }

        .notificacion-fecha {
            font-size: 11px;
            color: #999;
        }

        .no-leida {
            background: #e3f2fd;
            border-left: 3px solid var(--primary-cyan);
        }

        /* Encuestas */
        .encuestas-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
        }

        @media (min-width: 768px) {
            .encuestas-grid {
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            }
        }

        .encuesta-item {
            padding: 15px;
            border: 1px solid var(--border);
            border-radius: 8px;
            transition: transform 0.2s;
        }

        .encuesta-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .btn-responder {
            background: var(--primary-lime);
            color: #333;
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            margin-top: 10px;
            text-decoration: none;
            display: inline-block;
            font-size: 13px;
        }

        /* ===== NUEVAS TARJETAS DE NAVEGACIÓN (ESTILO INDEX) ===== */
        .cards-navegacion {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .nav-card {
            background: linear-gradient(135deg, var(--primary-cyan), var(--primary-purple));
            border-radius: 16px;
            padding: 24px 20px;
            text-align: center;
            color: white;
            text-decoration: none;
            display: block;
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            min-width: 200px;
            max-width: 220px;
            flex: 1;
        }

        .nav-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 10px 24px rgba(0,0,0,0.15);
        }

        .nav-card-1 { background: linear-gradient(135deg, var(--primary-cyan), var(--primary-purple)); }
        .nav-card-2 { background: linear-gradient(135deg, var(--primary-pink), #f89ca6); }
        .nav-card-3 { background: linear-gradient(135deg, var(--primary-lime), #d7e07a); }
        .nav-card-4 { background: linear-gradient(135deg, var(--primary-purple), #b09cff); }

        .nav-card-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .nav-card-icon svg,
        .nav-card-icon img {
            width: 50px;
            height: 50px;
            object-fit: contain;
            filter: brightness(0) invert(1);
        }

        .nav-card-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 5px;
            color: white;
        }

        .nav-card-desc {
            font-size: 13px;
            opacity: 0.9;
            color: white;
        }

        /* Mensajes */
        .no-hijos {
            text-align: center;
            padding: 40px 20px;
            background: white;
            border-radius: 16px;
        }

        .no-hijos-icon {
            font-size: 60px;
            margin-bottom: 20px;
        }

        .no-hijos h2 {
            color: var(--text-muted);
            margin-bottom: 10px;
        }

        .tabla-no-existe {
            text-align: center;
            padding: 20px;
            color: #999;
            font-style: italic;
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
    <div style="height: 60px; width: 100%; background: transparent;"></div>

    <!-- Header -->
    <?php require_once '../includes/header_comun.php'; ?>

    <!-- Banner -->
    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image" onerror="this.style.display='none'">
    </div>

    <!-- Título -->
    <div class="banner-content">
        <h1 class="banner-title">👨‍👩‍👧‍👦 Panel de Representante</h1>
        <p class="banner-subtitle">Bienvenido, <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?></p>
    </div>

    <main class="main-content">
        
        <?php if ($sin_hijos): ?>
            <!-- Mensaje cuando no tiene hijos -->
            <div class="no-hijos">
                <div class="no-hijos-icon">👤</div>
                <h2>No tienes estudiantes asociados</h2>
                <p>Contacta al administrador para asociar estudiantes a tu cuenta</p>
            </div>
        <?php else: ?>

        <!-- Selector de hijos -->
        <div class="selector-hijos">
            <span class="selector-label">👥 Mis Representados:</span>
            <div class="hijos-tabs">
                <?php foreach ($hijos as $h): ?>
                <a href="?hijo_id=<?php echo $h['id']; ?>" class="hijo-tab <?php echo $h['id'] == $hijo_seleccionado_id ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($h['nombre']); ?>
                    (<?php echo $h['grado']; ?>-<?php echo $h['seccion']; ?>)
                    <?php if ($tabla_notificaciones_existe && $h['notificaciones_no_leidas'] > 0): ?>
                        <span class="badge"><?php echo $h['notificaciones_no_leidas']; ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Estadísticas rápidas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_hijos; ?></div>
                <div class="stat-label">Total Representados</div>
            </div>
            <div class="stat-card" style="border-left-color: var(--primary-pink);">
                <div class="stat-number"><?php echo $total_actividades_pendientes; ?></div>
                <div class="stat-label">Actividades Pendientes</div>
                <div class="stat-small">En todos tus representados</div>
            </div>
            <?php if ($tabla_notificaciones_existe): ?>
            <div class="stat-card" style="border-left-color: var(--primary-lime);">
                <div class="stat-number"><?php echo $total_notificaciones_no_leidas; ?></div>
                <div class="stat-label">Notificaciones No Leídas</div>
                <div class="stat-small">En todos tus representados</div>
            </div>
            <?php endif; ?>
            <?php if ($hijo_actual): ?>
            <div class="stat-card" style="border-left-color: var(--primary-purple);">
                <div class="stat-number"><?php echo $hijo_actual['promedio'] ?: '0.00'; ?></div>
                <div class="stat-label">Promedio de <?php echo htmlspecialchars($hijo_actual['nombre']); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($hijo_actual): ?>
        <!-- Grid de contenido principal -->
        <div class="content-grid">
            <!-- Columna izquierda: Actividades recientes -->
            <div class="card">
                <div class="card-header">
                    <h2>📝 Actividades Recientes de <?php echo htmlspecialchars($hijo_actual['nombre']); ?></h2>
                </div>
                
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Actividad</th>
                                <th>Tipo</th>
                                <th>Estado</th>
                                <th>Calificación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($actividades_recientes)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; padding: 20px; color: #999;">
                                        No hay actividades recientes
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($actividades_recientes as $act): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($act['titulo']); ?></td>
                                    <td><span class="badge badge-info"><?php echo $act['tipo']; ?></span></td>
                                    <td>
                                        <span class="badge badge-<?php echo $act['estado']; ?>">
                                            <?php echo $act['estado']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $act['calificacion'] ?: '-'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Columna derecha: Notificaciones -->
            <div class="card">
                <div class="card-header">
                    <h2>🔔 Notificaciones de <?php echo htmlspecialchars($hijo_actual['nombre']); ?></h2>
                    <a href="../comun/notificaciones.php?estudiante_id=<?php echo $hijo_seleccionado_id; ?>">Ver todas →</a>
                </div>
                
                <div style="max-height: 300px; overflow-y: auto;">
                    <?php if (!$tabla_notificaciones_existe): ?>
                        <div class="tabla-no-existe">
                            ⚠️ El módulo de notificaciones aún no está configurado
                        </div>
                    <?php elseif (empty($notificaciones)): ?>
                        <div style="text-align: center; padding: 20px; color: #999;">
                            No hay notificaciones
                        </div>
                    <?php else: ?>
                        <?php foreach ($notificaciones as $notif): ?>
                        <div class="notificacion-item <?php echo !$notif['leido'] ? 'no-leida' : ''; ?>">
                            <div class="notificacion-titulo"><?php echo htmlspecialchars($notif['titulo']); ?></div>
                            <div style="font-size: 13px; margin: 5px 0;"><?php echo htmlspecialchars($notif['mensaje']); ?></div>
                            <div class="notificacion-fecha"><?php echo date('d/m/Y H:i', strtotime($notif['fecha_envio'])); ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Encuestas pendientes -->
        <div class="card">
            <div class="card-header">
                <h2>📋 Encuestas Pendientes para <?php echo htmlspecialchars($hijo_actual['nombre']); ?></h2>
            </div>
            
            <?php if (empty($encuestas_pendientes)): ?>
                <div style="text-align: center; padding: 20px; color: #999;">
                    No hay encuestas pendientes
                </div>
            <?php else: ?>
                <div class="encuestas-grid">
                    <?php foreach ($encuestas_pendientes as $enc): ?>
                    <div class="encuesta-item">
                        <h3 style="margin-bottom: 5px; font-size: 16px;"><?php echo htmlspecialchars($enc['titulo']); ?></h3>
                        <p style="color: #666; font-size: 13px; margin-bottom: 10px;"><?php echo htmlspecialchars($enc['descripcion']); ?></p>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 11px; color: #999;">
                                Vence: <?php echo date('d/m/Y', strtotime($enc['fecha_cierre'])); ?>
                            </span>
                            <a href="../comun/responder_encuesta.php?id=<?php echo $enc['id']; ?>" class="btn-responder">
                                Responder
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- TARJETAS DE NAVEGACIÓN RÁPIDA (CORREGIDAS) -->
        <div class="cards-navegacion">
            <!-- Tarjeta 1: Historial Completo (REPORTES) -->
            <a href="../comun/reportes.php?estudiante_id=<?php echo $hijo_seleccionado_id; ?>" class="nav-card nav-card-1">
                <div class="nav-card-icon">
                    <svg width="50" height="50" viewBox="0 0 24 24" fill="white">
                        <path d="M4 6h16v2H4V6zm2-4h12v2H6V2zm16 8H2v12h20V10zm-2 10H4v-8h16v8z"/>
                    </svg>
                </div>
                <h3 class="nav-card-title">Historial Completo</h3>
                <p class="nav-card-desc">Ver actividades, calificaciones y progreso</p>
            </a>
            
            
            <!-- Tarjeta 3: Encuestas -->
            <a href="../comun/encuestas_disponibles.php" class="nav-card nav-card-3">
                <div class="nav-card-icon">
                    <svg width="50" height="50" viewBox="0 0 24 24" fill="white">
                        <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1s-2.4.84-2.82 2H5v18h14V3zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm4 12H8v-2h8v2zm0-4H8V9h8v2z"/>
                    </svg>
                </div>
                <h3 class="nav-card-title">Encuestas</h3>
                <p class="nav-card-desc">Responder encuestas pendientes</p>
            </a>
            
            
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </main>

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
    </script>
</body>
</html>
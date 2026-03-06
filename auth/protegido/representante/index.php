<?php
session_start();
require_once '../../funciones.php';

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
    // La tabla no existe, ignorar
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Representante - SIEDUCRES</title>
    <?php require_once '../includes/favicon.php'; ?>
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

        /* Encabezado */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 24px;
            height: 60px;
            background-color: var(--surface);
            border-bottom: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            position: relative;
            z-index: 100;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .logo {
            height: 40px;
        }

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
            transition: background 0.2s;
        }

        .menu-item:hover {
            background-color: #F8F8F8;
        }

        /* Banner */
        .banner {
            position: relative;
            height: 100px; 
            overflow: hidden;
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
            padding: 20px;
            margin: 0 auto;
        }

        .banner-title {
            font-size: 36px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 8px;
        }

        .banner-subtitle {
            font-size: 18px;
            color: var(--text-muted);
        }

        /* Contenido principal */
        .main-content {
            flex: 1;
            padding: 40px 20px;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        /* Selector de hijos */
        .selector-hijos {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
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

        /* Stats grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
            border-left: 4px solid var(--primary-cyan);
        }

        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: var(--primary-cyan);
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            color: var(--text-muted);
        }

        .stat-small {
            font-size: 12px;
            color: #999;
            margin-top: 5px;
        }

        /* Tarjetas de contenido */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
            border: 1px solid var(--border);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-cyan);
        }

        .card-header h2 {
            color: var(--primary-purple);
            font-size: 20px;
            font-weight: 600;
        }

        .card-header a {
            color: var(--primary-pink);
            text-decoration: none;
            font-size: 14px;
        }

        /* Tablas */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 12px 8px;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 14px;
        }

        td {
            padding: 12px 8px;
            border-bottom: 1px solid var(--border);
        }

        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-pendiente { background: #fff3cd; color: #856404; }
        .badge-enviado { background: #d4edda; color: #155724; }
        .badge-calificado { background: #cce5ff; color: #004085; }
        .badge-info { background: var(--primary-cyan); color: white; }
        .badge-warning { background: var(--primary-pink); color: white; }

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
        }

        .notificacion-fecha {
            font-size: 12px;
            color: #999;
        }

        .no-leida {
            background: #e3f2fd;
            border-left: 3px solid var(--primary-cyan);
        }

        /* Encuestas */
        .encuesta-item {
            padding: 15px;
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-bottom: 10px;
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
        }

        .btn-responder:hover {
            opacity: 0.9;
        }

        /* Mensaje sin hijos */
        .no-hijos {
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 16px;
        }

        .no-hijos-icon {
            font-size: 80px;
            margin-bottom: 20px;
        }

        .no-hijos h2 {
            color: var(--text-muted);
            margin-bottom: 10px;
        }

        /* Mensaje de tabla no existente */
        .tabla-no-existe {
            text-align: center;
            padding: 30px;
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
            padding: 0 24px;
            font-size: 13px;
            color: var(--text-muted);
            position: sticky;
            bottom: 0;
        }

        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            .banner-title {
                font-size: 28px;
            }
        }
    </style>
</head>
<body>

    <!-- Header -->
    <header class="header">
        <div class="header-left">
            <img src="../../../assets/logo.svg" alt="SIEDUCRES" class="logo">
        </div>
        <div class="header-right">
            <div class="icon-btn" onclick="window.location.href='../comun/notificaciones.php'">
                <img src="../../../assets/icon-bell.svg" alt="Notificaciones">
                <?php if ($tabla_notificaciones_existe && $total_notificaciones_no_leidas > 0): ?>
                    <span style="position: relative; top: -10px; right: 5px; background: var(--primary-pink); color: white; border-radius: 50%; padding: 2px 6px; font-size: 10px;"><?php echo $total_notificaciones_no_leidas; ?></span>
                <?php endif; ?>
            </div>
            <div class="icon-btn" onclick="window.location.href='perfil.php'">
                <img src="../../../assets/icon-user.svg" alt="Perfil">
            </div>
            <div class="icon-btn" id="menu-toggle">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="#333333">
                    <path d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/>
                </svg>
            </div>
            <div class="menu-dropdown" id="dropdown">
                <a href="perfil.php" class="menu-item">Mi Perfil</a>
                <a href="../../logout.php" class="menu-item">Cerrar sesión</a>
            </div>
        </div>
    </header>

    <!-- Banner -->
    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image">
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
                    <a href="../comun/historial.php?estudiante_id=<?php echo $hijo_seleccionado_id; ?>">Ver todas →</a>
                </div>
                
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
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
                    <?php foreach ($encuestas_pendientes as $enc): ?>
                    <div class="encuesta-item">
                        <h3 style="margin-bottom: 5px;"><?php echo htmlspecialchars($enc['titulo']); ?></h3>
                        <p style="color: #666; font-size: 14px; margin-bottom: 10px;"><?php echo htmlspecialchars($enc['descripcion']); ?></p>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 12px; color: #999;">
                                Vence: <?php echo date('d/m/Y', strtotime($enc['fecha_cierre'])); ?>
                            </span>
                            <a href="../comun/ver_encuesta.php?id=<?php echo $enc['id']; ?>&estudiante_id=<?php echo $hijo_seleccionado_id; ?>" class="btn-responder">
                                Responder
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tarjetas de navegación rápida -->
        <div style="display: flex; gap: 15px; justify-content: center; margin-top: 30px; flex-wrap: wrap;">
            <!-- Historial completo -->
            <a href="../comun/historial.php?estudiante_id=<?php echo $hijo_seleccionado_id; ?>" style="text-decoration: none;">
                <div style="background: linear-gradient(135deg, var(--primary-cyan), var(--primary-purple)); padding: 20px; border-radius: 12px; width: 200px; text-align: center; color: white;">
                    <div style="font-size: 40px; margin-bottom: 10px;">📚</div>
                    <h3 style="font-size: 16px;">Historial Completo</h3>
                </div>
            </a>
            
            <!-- Ver calificaciones -->
            <a href="../estudiante/calificaciones.php?estudiante_id=<?php echo $hijo_seleccionado_id; ?>" style="text-decoration: none;">
                <div style="background: linear-gradient(135deg, var(--primary-pink), #f89ca6); padding: 20px; border-radius: 12px; width: 200px; text-align: center; color: white;">
                    <div style="font-size: 40px; margin-bottom: 10px;">📊</div>
                    <h3 style="font-size: 16px;">Calificaciones</h3>
                </div>
            </a>
            
            <!-- Foro -->
            <a href="../comun/foro.php?estudiante_id=<?php echo $hijo_seleccionado_id; ?>" style="text-decoration: none;">
                <div style="background: linear-gradient(135deg, var(--primary-lime), #d7e07a); padding: 20px; border-radius: 12px; width: 200px; text-align: center; color: #333;">
                    <div style="font-size: 40px; margin-bottom: 10px;">💬</div>
                    <h3 style="font-size: 16px;">Participación en Foros</h3>
                </div>
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
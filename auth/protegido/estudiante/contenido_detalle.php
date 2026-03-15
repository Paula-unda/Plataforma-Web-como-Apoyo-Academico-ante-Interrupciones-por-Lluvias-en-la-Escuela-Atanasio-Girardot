<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/onesignal_config.php';

error_log("=== DEBUG SESIÓN INICIO ===");
error_log("usuario_id: " . ($_SESSION['usuario_id'] ?? 'NO EXISTE'));
error_log("usuario_rol: " . ($_SESSION['usuario_rol'] ?? 'NO EXISTE'));
error_log("sesionActiva: " . (sesionActiva() ? 'SI' : 'NO'));
error_log("=== DEBUG SESIÓN FIN ===");

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Estudiante') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$content_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$contenido = obtenerContenidoPorId($content_id);

if (!$contenido) {
    header('Location: contenidos.php?error=Contenido+no+encontrado');
    exit();
}

// =====================================================
// MARCAR AUTOMÁTICAMENTE COMO VISTO AL ACCEDER
// =====================================================
try {
    $conexion = getConexion();
    
    // Verificar si ya existe un registro de visualización
    $stmt_check = $conexion->prepare("
        SELECT id FROM progreso_contenido 
        WHERE estudiante_id = ? AND contenido_id = ? AND material_id IS NULL
    ");
    $stmt_check->execute([$_SESSION['usuario_id'], $content_id]);
    
    if (!$stmt_check->fetch()) {
        // No existe, crear registro de visualización
        $stmt_insert = $conexion->prepare("
            INSERT INTO progreso_contenido 
            (estudiante_id, contenido_id, material_id, porcentaje_visto, completado, principales_completados, ultima_visualizacion)
            VALUES (?, ?, NULL, 100, true, 1, CURRENT_TIMESTAMP)
        ");
        $stmt_insert->execute([$_SESSION['usuario_id'], $content_id]);
        error_log("✅ Contenido marcado como visto automáticamente");
    }
    
    // Obtener la fecha de visualización
    $stmt_fecha = $conexion->prepare("
        SELECT ultima_visualizacion FROM progreso_contenido 
        WHERE estudiante_id = ? AND contenido_id = ? AND material_id IS NULL
    ");
    $stmt_fecha->execute([$_SESSION['usuario_id'], $content_id]);
    $visualizacion = $stmt_fecha->fetch(PDO::FETCH_ASSOC);
    $fecha_visto = $visualizacion ? $visualizacion['ultima_visualizacion'] : null;
    
} catch (Exception $e) {
    error_log("Error al marcar contenido como visto: " . $e->getMessage());
    $fecha_visto = null;
}

error_log("=== DEBUG CONTENIDO DETALLE ===");
error_log("Contenido ID: " . $content_id);
error_log("Estudiante ID (sesión): " . $_SESSION['usuario_id']);

$actividad_vinculada = null;
if (function_exists('obtenerActividadPorContenido')) {
    $actividad_vinculada = obtenerActividadPorContenido($content_id, $_SESSION['usuario_id']);
    error_log("🔍 FUNCION LLAMADA - Contenido: " . $content_id);
} else {
    error_log("❌ FUNCION NO EXISTE");
}

error_log("🔍 ACTIVIDAD: " . ($actividad_vinculada ? 'ENCONTRADA' : 'NO ENCONTRADA'));
if ($actividad_vinculada) {
    error_log("🔍 ACTIVIDAD ID: " . $actividad_vinculada['id']);
    error_log("🔍 ACTIVIDAD TITULO: " . $actividad_vinculada['titulo']);
}

$mostrar_recursos = false;
$recurso_tipo = 'ninguno';
$recurso_url = '';

$materiales = [];
try {
    $conexion = getConexion();
    $stmt_mat = $conexion->prepare("
        SELECT * FROM materiales 
        WHERE contenido_id = ? AND activo = true 
        ORDER BY orden ASC
    ");
    $stmt_mat->execute([$content_id]);
    $materiales = $stmt_mat->fetchAll(PDO::FETCH_ASSOC);
    error_log("📎 Materiales encontrados: " . count($materiales));
} catch (Exception $e) {
    error_log("Error obteniendo materiales: " . $e->getMessage());
}

// =====================================================
// DETECCIÓN DE RECURSOS
// =====================================================
$mostrar_video = false;
$video_url = '';
$mostrar_documento = false;
$documento_url = '';

// 1. DETECTAR VIDEO (YouTube o local)
if (!empty($contenido['enlace'])) {
    $url_limpia = trim($contenido['enlace']);
    if (strpos($url_limpia, 'youtube.com') !== false || strpos($url_limpia, 'youtu.be') !== false) {
        $mostrar_video = true;
        $recurso_tipo_adicional = 'video';
        if (strpos($url_limpia, 'watch?v=') !== false) {
            parse_str(parse_url($url_limpia, PHP_URL_QUERY), $params);
            $video_id = $params['v'];
            $video_url = 'https://www.youtube.com/embed/' . $video_id . '?enablejsapi=1';
        } elseif (strpos($url_limpia, 'youtu.be/') !== false) {
            $parts = explode('/', parse_url($url_limpia, PHP_URL_PATH));
            $video_id = $parts[count($parts)-1];
            $video_url = 'https://www.youtube.com/embed/' . $video_id . '?enablejsapi=1';
        } else {
            $video_url = $url_limpia;
        }
    } elseif (strpos($url_limpia, '.mp4') !== false || strpos($url_limpia, '.webm') !== false) {
        $mostrar_video = true;
        $recurso_tipo_adicional = 'video_local';
        $video_url = $url_limpia;
    } else {
        $mostrar_video = true;
        $recurso_tipo_adicional = 'enlace';
        $video_url = $url_limpia;
    }
}

// 2. DETECTAR DOCUMENTO (archivo adjunto)
if (!empty($contenido['archivo_adjunto'])) {
    $mostrar_documento = true;
    $documento_url = '../../../uploads/contenidos/' . htmlspecialchars($contenido['archivo_adjunto']);
    $documento_nombre = basename($contenido['archivo_adjunto']);
}

// 3. DETERMINAR SI HAY ALGÚN RECURSO
$mostrar_recursos = $mostrar_video || $mostrar_documento;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($contenido['titulo']); ?> - SIEDUCRES</title>
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
            --primary-purple: #9b8afb;
            --text-dark: #333333;
            --text-muted: #666666;
            --border: #E0E0E0;
            --surface: #FFFFFF;
            --background: #F5F5F5;
            --success: #28a745;
            --warning: #ffc107;
            --visto-bg: #e8f5e9;
            --visto-border: #28a745;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--background);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header móvil */
        .mobile-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .back-button {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--primary-pink);
            padding: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s;
        }

        .back-button:hover {
            background: rgba(239, 94, 142, 0.1);
        }

        .logo {
            height: 32px;
        }

        .header-right {
            display: flex;
            gap: 8px;
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

        /* Menú hamburguesa */
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

        /* Banner */
        .banner {
            position: relative;
            height: 80px;
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
            padding: 16px;
            margin: 0 auto;
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

        .banner-subtitle {
            font-size: 16px;
            color: var(--text-muted);
        }

        /* Contenedor principal */
        .container {
            flex: 1;
            padding: 16px;
            max-width: 1000px;
            width: 100%;
            margin: 0 auto;
        }

        /* Tarjeta de contenido */
        .content-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border-left: 4px solid var(--primary-cyan);
        }

        .content-title {
            color: var(--primary-cyan);
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 12px;
            line-height: 1.3;
        }

        @media (min-width: 768px) {
            .content-title {
                font-size: 28px;
            }
        }

        /* NUEVO: Indicador de visto */
        .visto-indicador {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--visto-bg);
            color: var(--visto-border);
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            border: 1px solid var(--visto-border);
            margin-left: 15px;
        }

        .visto-indicador span {
            font-size: 18px;
        }

        .content-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 15px;
            margin: 15px 0;
            padding: 15px 0;
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }

        .meta-item {
            font-size: 14px;
            color: var(--text-muted);
        }

        .meta-item strong {
            color: var(--text-dark);
        }

        .content-description {
            color: var(--text-muted);
            margin: 20px 0;
            line-height: 1.6;
            font-size: 15px;
        }

        /* ===== SECCIÓN DE RECURSOS PRINCIPALES ===== */
        .recurso-principal {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 20px;
            margin: 20px 0;
            border: 1px solid var(--border);
        }

        .recurso-titulo {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 15px;
        }

        .recurso-titulo h3 {
            font-size: 18px;
            color: var(--text-dark);
        }

        .badge-principal {
            background: var(--primary-cyan);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
        }

        /* ===== MATERIALES ADICIONALES ===== */
        .materiales-grid {
            display: grid;
            gap: 20px;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            margin-top: 20px;
        }

        .material-card {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 20px;
            border-left: 4px solid var(--primary-cyan);
            transition: transform 0.2s;
        }

        .material-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .material-titulo {
            font-weight: 600;
            color: var(--text-dark);
        }

        .material-tipo {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .tipo-video { background: #4BC4E7; color: white; }
        .tipo-documento { background: #C2D54E; color: #333; }
        .tipo-enlace { background: #9b8afb; color: white; }

        /* Player */
        .player-container {
            position: relative;
            width: 100%;
            padding-bottom: 56.25%;
            height: 0;
            margin: 15px 0;
            border-radius: 12px;
            overflow: hidden;
            background: #000;
        }

        .player {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: none;
        }

        /* Botones de acción */
        .action-btn {
            display: inline-block;
            padding: 12px 24px;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }

        .action-btn.secondary {
            background: var(--surface);
            color: var(--text-dark);
            border: 1px solid var(--border);
        }

        .action-btn.secondary:hover {
            background: #f0f0f0;
        }

        .activity-btn-container {
            text-align: center;
            margin: 30px 0;
        }

        .activity-btn {
            display: inline-block;
            padding: 16px 48px;
            background: linear-gradient(135deg, var(--primary-pink), var(--primary-purple));
            color: white;
            border: none;
            border-radius: 50px;
            text-decoration: none;
            font-size: 18px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 6px 16px rgba(0,0,0,0.15);
        }

        .activity-btn:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 24px rgba(0,0,0,0.2);
        }

        /* Footer */
        .footer {
            text-align: center;
            padding: 20px 16px;
            color: var(--text-muted);
            font-size: 13px;
            border-top: 1px solid var(--border);
            margin-top: 20px;
            background: var(--surface);
        }

        @media (max-width: 768px) {
            .materiales-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header_comun.php'; ?>

    <!-- Flecha de retroceso DEBAJO del header -->
    <div style="padding: 8px 16px; background: white; border-bottom: 1px solid var(--border);">
        <button class="back-button" onclick="window.location.href='contenidos.php'" 
                style="background: none; border: none; font-size: 16px; cursor: pointer; color: var(--primary-pink); display: flex; align-items: center; gap: 6px; padding: 8px 0;">
            <span style="font-size: 20px;">←</span>
            <span>Volver a contenidos</span>
        </button>
    </div>

    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image" onerror="this.style.display='none'">
    </div>

    <div class="banner-content">
        <h1 class="banner-title"><?php echo htmlspecialchars($contenido['titulo']); ?></h1>
        <p class="banner-subtitle"><?php echo htmlspecialchars($contenido['asignatura']); ?></p>
    </div>

    <div class="container">
        <!-- Tarjeta principal -->
        <div class="content-card">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 10px;">
                <h1 class="content-title" style="margin-bottom: 0;"><?php echo htmlspecialchars($contenido['titulo']); ?></h1>
                
                <!-- NUEVO: Indicador de visto -->
                <div class="visto-indicador">
                    <span>✅</span> Visto el <?php echo $fecha_visto ? date('d/m/Y H:i', strtotime($fecha_visto)) : date('d/m/Y H:i'); ?>
                </div>
            </div>
            
            <div class="content-meta">
                <span class="meta-item"><strong>📅 Fecha:</strong> <?php echo date('d/m/Y', strtotime($contenido['fecha_publicacion'])); ?></span>
                <span class="meta-item"><strong>👨‍🏫 Docente:</strong> <?php echo htmlspecialchars($contenido['docente_nombre'] ?? 'No especificado'); ?></span>
                <span class="meta-item"><strong>📚 Asignatura:</strong> <?php echo htmlspecialchars($contenido['asignatura']); ?></span>
                <span class="meta-item"><strong>🎯 Dirigido a:</strong> <?php echo htmlspecialchars($contenido['grado'] ?? 'Todos'); ?> <?php echo htmlspecialchars($contenido['seccion'] ?? ''); ?></span>
            </div>

            <p class="content-description">
                <?php echo nl2br(htmlspecialchars($contenido['descripcion'])); ?>
            </p>

            <!-- ===== RECURSOS PRINCIPALES ===== -->
            <?php if ($mostrar_recursos): ?>
                
                <!-- VIDEO (si existe) -->
                <?php if ($mostrar_video): ?>
                <div class="recurso-principal" id="recurso-video">
                    <div class="recurso-titulo">
                        <h3>📌 Video / Enlace</h3>
                        <span class="badge-principal"><?php echo ucfirst($recurso_tipo_adicional); ?></span>
                    </div>
                    
                    <!-- VIDEO YOUTUBE -->
                    <?php if ($recurso_tipo_adicional === 'video'): ?>
                        <div class="player-container">
                            <iframe class="player" 
                                    src="<?php echo htmlspecialchars($video_url); ?>" 
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                                    allowfullscreen>
                            </iframe>
                        </div>
                    <?php endif; ?>
                    
                    <!-- VIDEO LOCAL -->
                    <?php if ($recurso_tipo_adicional === 'video_local'): ?>
                        <div class="player-container">
                            <video class="player" controls>
                                <source src="<?php echo htmlspecialchars($video_url); ?>" type="video/mp4">
                                Tu navegador no soporta el elemento de video.
                            </video>
                        </div>
                    <?php endif; ?>
                    
                    <!-- ENLACE -->
                    <?php if ($recurso_tipo_adicional === 'enlace'): ?>
                        <div style="text-align: center; padding: 30px;">
                            <div style="font-size: 48px; margin-bottom: 15px;">🔗</div>
                            <p style="margin-bottom: 15px;">Enlace externo disponible</p>
                            <a href="<?php echo htmlspecialchars($video_url); ?>" 
                            target="_blank"
                            style="display: inline-block; background: #4BC4E7; color: white; padding: 12px 30px; border-radius: 30px; text-decoration: none; font-weight: 600;">
                                ABRIR ENLACE
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <!-- DOCUMENTO (si existe) -->
                <?php if ($mostrar_documento): ?>
                <div class="recurso-principal" id="recurso-documento">
                    <div class="recurso-titulo">
                        <h3>📌 Documento adjunto</h3>
                        <span class="badge-principal">Documento</span>
                    </div>
                    
                    <!-- SECCIÓN DE DESCARGA -->
                    <div style="display: flex; align-items: center; gap: 20px; padding: 15px; background: white; border-radius: 8px;">
                        <!-- Ícono del documento -->
                        <div style="font-size: 40px;">📄</div>
                        
                        <!-- Nombre del archivo (CLICABLE PARA DESCARGAR) -->
                        <div style="flex: 1;">
                            <a href="<?php echo htmlspecialchars($documento_url); ?>" 
                            download
                            style="font-weight: 600; color: var(--primary-cyan); text-decoration: none; word-break: break-all;">
                                <?php echo $documento_nombre; ?>
                            </a>
                            <p style="font-size: 12px; color: #666; margin-top: 5px;">
                                Haz clic en el nombre o en el botón para descargar
                            </p>
                        </div>
                        
                        <!-- Botón de descarga -->
                        <a href="<?php echo htmlspecialchars($documento_url); ?>" 
                        download
                        style="background: #C2D54E; color: #333; padding: 10px 20px; border-radius: 30px; text-decoration: none; font-weight: 600; white-space: nowrap;">
                            DESCARGAR
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                
            <?php endif; ?>

            <!-- ===== MATERIALES ADICIONALES (solo para descarga/visualización, sin botones de marcar) ===== -->
            <?php if (!empty($materiales)): ?>
                <h3 style="font-size: 20px; margin: 30px 0 15px; color: var(--text-dark);">
                    Materiales adicionales (<?php echo count($materiales); ?>)
                </h3>
                
                <div class="materiales-grid">
                    <?php foreach ($materiales as $material): 
                        $clase_tipo = '';
                        switch($material['tipo']) {
                            case 'video': $clase_tipo = 'tipo-video'; break;
                            case 'documento': $clase_tipo = 'tipo-documento'; break;
                            case 'enlace': $clase_tipo = 'tipo-enlace'; break;
                        }
                    ?>
                        <div class="material-card" id="material-<?php echo $material['id']; ?>">
                            
                            <div class="material-header">
                                <span class="material-titulo"><?php echo htmlspecialchars($material['titulo']); ?></span>
                                <span class="material-tipo <?php echo $clase_tipo; ?>">
                                    <?php echo ucfirst($material['tipo']); ?>
                                </span>
                            </div>
                            
                            <!-- VIDEO -->
                            <?php if ($material['tipo'] === 'video' && !empty($material['url'])): ?>
                                <div style="margin: 15px 0;">
                                    <?php 
                                    $url = $material['url'];
                                    if (strpos($url, 'youtube.com/watch?v=') !== false) {
                                        parse_str(parse_url($url, PHP_URL_QUERY), $params);
                                        $url = 'https://www.youtube.com/embed/' . $params['v'] . '?enablejsapi=1';
                                    } elseif (strpos($url, 'youtu.be/') !== false) {
                                        $parts = explode('/', parse_url($url, PHP_URL_PATH));
                                        $video_id = end($parts);
                                        $url = 'https://www.youtube.com/embed/' . $video_id . '?enablejsapi=1';
                                    }
                                    ?>
                                    <iframe width="100%" height="180" src="<?php echo htmlspecialchars($url); ?>" 
                                            frameborder="0" allowfullscreen></iframe>
                                </div>
                            <?php endif; ?>
                            
                            <!-- DOCUMENTO ADICIONAL -->
                            <?php if ($material['tipo'] === 'documento' && !empty($material['archivo'])): ?>
                                <div style="margin: 15px 0; text-align: center;">
                                    <?php
                                    $archivo_url = '../../../uploads/materiales/' . $material['archivo'];
                                    $nombre_archivo = basename($material['archivo']);
                                    ?>
                                    <div style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f0f0f0; border-radius: 8px;">
                                        <span style="font-size: 24px;">📄</span>
                                        <div style="flex: 1; text-align: left;">
                                            <a href="<?php echo htmlspecialchars($archivo_url); ?>" 
                                            download
                                            style="color: var(--primary-cyan); text-decoration: none; font-weight: 500; word-break: break-all;">
                                                <?php echo $nombre_archivo; ?>
                                            </a>
                                        </div>
                                        <a href="<?php echo htmlspecialchars($archivo_url); ?>" 
                                        download
                                        style="background: #C2D54E; color: #333; padding: 5px 15px; border-radius: 20px; text-decoration: none; font-size: 13px; font-weight: 600;">
                                            DESCARGAR
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <!-- ENLACE -->
                            <?php if ($material['tipo'] === 'enlace' && !empty($material['url'])): ?>
                                <div style="margin: 15px 0; text-align: center;">
                                    <a href="<?php echo htmlspecialchars($material['url']); ?>" 
                                       target="_blank" 
                                       style="display: inline-block; background: #4BC4E7; color: white; padding: 8px 20px; border-radius: 30px; text-decoration: none; font-weight: 600;">
                                        ABRIR ENLACE
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Botón volver -->
            <div style="margin-top: 30px; text-align: center;">
                <a href="contenidos.php" class="action-btn secondary">
                    ← Volver a contenidos
                </a>
            </div>

            <!-- Actividad vinculada -->
            <?php if ($actividad_vinculada): ?>
                <div class="activity-btn-container">
                    <a href="detalle_actividad.php?id=<?php echo $actividad_vinculada['id']; ?>" class="activity-btn">
                        Ir a la actividad
                    </a>
                </div>
            <?php else: ?>
                <div class="activity-btn-container">
                    <div style="display: inline-block; padding: 16px 48px; background: #e0e0e0; color: #999; border-radius: 50px; font-size: 18px; font-weight: 600;">
                        Actividad no disponible aún
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <footer class="footer">
        <p>© <?php echo date('Y'); ?> SIEDUCRES - Plataforma Educativa</p>
        <p style="font-size: 11px; margin-top: 4px;">v2.0.0</p>
    </footer>

    <script>
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
    </script>
</body>
</html>
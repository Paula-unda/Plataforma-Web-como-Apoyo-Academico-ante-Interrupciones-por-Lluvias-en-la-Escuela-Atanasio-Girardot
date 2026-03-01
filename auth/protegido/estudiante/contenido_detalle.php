<?php
session_start();
require_once '../../funciones.php';

// 🔍 ¿ESTÁ ESTE CÓDIGO AQUÍ?
error_log("=== DEBUG SESIÓN INICIO ===");
error_log("usuario_id: " . ($_SESSION['usuario_id'] ?? 'NO EXISTE'));
error_log("usuario_rol: " . ($_SESSION['usuario_rol'] ?? 'NO EXISTE'));
error_log("sesionActiva: " . (sesionActiva() ? 'SI' : 'NO'));
error_log("=== DEBUG SESIÓN FIN ===");

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Estudiante') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}
// Obtener el ID del contenido desde la URL
$content_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Obtener el contenido específico
$contenido = obtenerContenidoPorId($content_id);

if (!$contenido) {
    header('Location: contenidos.php?error=Contenido+no+encontrado');
    exit();
}

// Obtener progreso actual
// Obtener progreso actual
$progreso_actual = obtenerProgresoContenido($_SESSION['usuario_id'], $content_id);

// 🔍 DEBUG AGREGADO
error_log("=== DEBUG CONTENIDO DETALLE ===");
error_log("Contenido ID: " . $content_id);
error_log("Estudiante ID (sesión): " . $_SESSION['usuario_id']);
error_log("Progreso cargado de BD: " . $progreso_actual . "%");
// Verificar si hay actividad asignada a este contenido

// Verificar si hay actividad asignada a este contenido
$actividad_vinculada = null;
if (function_exists('obtenerActividadPorContenido')) {
    $actividad_vinculada = obtenerActividadPorContenido($content_id, $_SESSION['usuario_id']);
    error_log("🔍 FUNCION LLAMADA - Contenido: " . $content_id);
} else {
    error_log("❌ FUNCION NO EXISTE");
}

// Debug simple
error_log("🔍 ACTIVIDAD: " . ($actividad_vinculada ? 'ENCONTRADA' : 'NO ENCONTRADA'));
if ($actividad_vinculada) {
    error_log("🔍 ACTIVIDAD ID: " . $actividad_vinculada['id']);
    error_log("🔍 ACTIVIDAD TITULO: " . $actividad_vinculada['titulo']);
}
// Determinar si hay recursos
$mostrar_recursos = false;
$recurso_tipo = 'ninguno';
$recurso_url = '';

// Obtener materiales adicionales de este contenido
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

if (!empty($contenido['enlace'])) {
    $url_limpia = trim($contenido['enlace']);
    if (strpos($url_limpia, 'youtube.com') !== false || strpos($url_limpia, 'youtu.be') !== false) {
        $recurso_tipo = 'video';
        // ✅ Convertir URL de YouTube a embed (SIN ESPACIOS)
        if (strpos($url_limpia, 'watch?v=') !== false) {
            parse_str(parse_url($url_limpia, PHP_URL_QUERY), $params);
            $video_id = $params['v'];
            $recurso_url = 'https://www.youtube.com/embed/' . $video_id . '?enablejsapi=1';
        } elseif (strpos($url_limpia, 'youtu.be/') !== false) {
            $parts = explode('/', parse_url($url_limpia, PHP_URL_PATH));
            $video_id = $parts[count($parts)-1];
            $recurso_url = 'https://www.youtube.com/embed/' . $video_id . '?enablejsapi=1';
        } else {
            $recurso_url = $url_limpia;
        }
    } elseif (strpos($url_limpia, '.mp4') !== false || strpos($url_limpia, '.webm') !== false) {
        $recurso_tipo = 'video_local';
        $recurso_url = $url_limpia;
    } else {
        $recurso_tipo = 'enlace';
        $recurso_url = $url_limpia;
    }
    $mostrar_recursos = true;
} elseif (!empty($contenido['archivo_adjunto'])) {
    $recurso_tipo = 'documento';
    $recurso_url = '../../../uploads/' . htmlspecialchars($contenido['archivo_adjunto']);
    $mostrar_recursos = true;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($contenido['titulo']); ?> - SIEDUCRES</title>
    <?php require_once '../includes/favicon.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --text-dark: #333333; --text-muted: #666666; --border: #E0E0E0;
            --surface: #FFFFFF; --background: #F5F5F5; --primary: #4BC4E7;
            --success: #28a745; --warning: #ffc107; --danger: #dc3545;
            --card-1: #EF5E8E; --card-2: #24d4dc; --card-3: #cbd74f;
            --card-4: #a78bfe; --card-5: #f89ca6;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--background); color: var(--text-dark); min-height: 100vh; display: flex; flex-direction: column; }
        .header { display: flex; justify-content: space-between; align-items: center; padding: 0 24px; height: 60px; background-color: var(--surface); border-bottom: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: relative; z-index: 100; }
        .logo { height: 40px; }
        .header-right { display: flex; align-items: center; gap: 16px; }
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; background-color: #F0F0F0; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background 0.2s; }
        .icon-btn:hover { background-color: #E0E0E0; }
        .icon-btn img { width: 20px; height: 20px; object-fit: contain; }
        .menu-dropdown { position: absolute; top: 60px; right: 24px; background: white; border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); display: none; min-width: 180px; }
        .menu-item { padding: 10px 16px; font-size: 14px; color: var(--text-dark); text-decoration: none; display: block; transition: background 0.2s; }
        .menu-item:hover { background-color: #F8F8F8; }
        .banner { position: relative; height: 100px; overflow: hidden; }
        .banner-image { width: 100%; height: 100%; object-fit: cover; object-position: top; }
        .banner-content { text-align: center; position: relative; z-index: 2; max-width: 800px; padding: 20px; margin: 0 auto; }
        .banner-title { font-size: 36px; font-weight: 700; color: var(--text-dark); margin-bottom: 8px; }
        .banner-subtitle { font-size: 18px; color: var(--text-muted); }
        .footer { height: 50px; background-color: var(--surface); border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; padding: 0 24px; font-size: 13px; color: var(--text-muted); position: sticky; bottom: 0; left: 0; right: 0; }
        .main-content { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 20px; }
        .content-container { width: 100%; max-width: 900px; }
        .info-card { background: var(--surface); border-radius: 16px; padding: 32px; margin-bottom: 24px; box-shadow: 0 6px 16px rgba(0,0,0,0.05); }
        .content-title { font-size: 28px; font-weight: 700; margin-bottom: 24px; color: var(--text-dark); }
        .content-meta { display: flex; flex-wrap: wrap; gap: 20px; padding: 16px 0; margin-bottom: 24px; border-bottom: 1px solid var(--border); }
        .meta-item { display: flex; align-items: center; gap: 8px; font-size: 14px; color: var(--text-muted); }
        .meta-item strong { color: var(--text-dark); font-weight: 600; }
        .content-description { font-size: 16px; line-height: 1.6; color: var(--text-muted); margin-bottom: 24px; text-align: justify; }
        .progress-section { margin: 32px 0; padding: 24px; background: linear-gradient(135deg, var(--primary), #6fb1fc); border-radius: 12px; color: white; }
        .progress-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .progress-title { font-size: 18px; font-weight: 600; }
        .progress-percentage { font-size: 24px; font-weight: 700; }
        .progress-bar-container { height: 12px; background: rgba(255,255,255,0.3); border-radius: 6px; overflow: hidden; }
        .progress-bar-fill { height: 100%; background: white; border-radius: 6px; transition: width 0.5s ease; }
        .progress-stats { display: flex; justify-content: space-between; margin-top: 12px; font-size: 13px; opacity: 0.9; }
        .player-container { position: relative; width: 100%; padding-bottom: 56.25%; height: 0; margin: 24px 0; border-radius: 12px; overflow: hidden; background: #000; }
        .player { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: none; }
        .document-preview { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 300px; background: #f8f9fa; border-radius: 12px; border: 2px dashed var(--border); margin: 24px 0; }
        .document-icon { font-size: 64px; color: var(--primary); margin-bottom: 16px; }
        .resource-actions { display: flex; gap: 16px; margin-top: 24px; flex-wrap: wrap; }
        .action-btn { padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; }
        .action-btn.primary { background: var(--primary); color: white; }
        .action-btn.primary:hover { background: #3a7bc8; transform: translateY(-2px); }
        .action-btn.secondary { background: var(--surface); color: var(--text-dark); border: 1px solid var(--border); }
        .action-btn.secondary:hover { background: #f0f0f0; transform: translateY(-2px); }
        .action-btn.success { background: var(--success); color: white; }
        .action-btn.success:hover { background: #218838; transform: translateY(-2px); }
        .activity-btn-container { text-align: center; margin: 32px 0; }
        .activity-btn { display: inline-block; padding: 16px 48px; background: linear-gradient(135deg, var(--card-1), var(--card-5)); color: white; border: none; border-radius: 12px; text-decoration: none; font-size: 18px; font-weight: 600; transition: all 0.3s; box-shadow: 0 6px 16px rgba(0,0,0,0.15); }
        .activity-btn:hover { transform: translateY(-4px); box-shadow: 0 10px 24px rgba(0,0,0,0.2); }
        .activity-btn::after { content: " →"; font-weight: bold; }
        .materiales-grid {
            display: grid;
            gap: 20px;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            margin-top: 20px;
        }

        .material-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            border-left: 4px solid var(--primary);
            transition: transform 0.2s;
        }

        .material-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        @media (max-width: 768px) {
            .materiales-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 768px) {
            .info-card { padding: 24px; }
            .content-title { font-size: 24px; }
            .resource-actions { flex-direction: column; }
            .action-btn { width: 100%; justify-content: center; }
            .activity-btn { padding: 14px 32px; font-size: 16px; }
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
                <a href="../../logout.php" class="menu-item">Cerrar sesión</a>
            </div>
        </div>
    </header>

    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image">
    </div>
    
    <div class="banner-content">
        <h1 class="banner-title"><?php echo htmlspecialchars($contenido['titulo']); ?></h1>
        <p class="banner-subtitle"><?php echo htmlspecialchars($contenido['asignatura']); ?></p>
    </div>

    <main class="main-content">
        <div class="content-container">
            <div class="info-card">
                <h1 class="content-title"><?php echo htmlspecialchars($contenido['titulo']); ?></h1>
                
                <div class="content-meta">
                    <div class="meta-item">
                        <strong>📅 Fecha:</strong> <?php echo date('d/m/Y', strtotime($contenido['fecha_publicacion'])); ?>
                    </div>
                    <div class="meta-item">
                        <strong>👨‍🏫 Docente:</strong> <?php echo htmlspecialchars($contenido['docente_nombre'] ?? 'No especificado'); ?>
                    </div>
                    <div class="meta-item">
                        <strong>📚 Asignatura:</strong> <?php echo htmlspecialchars($contenido['asignatura']); ?>
                    </div>
                    <div class="meta-item">
                        <strong>🎯 Dirigido a:</strong> <?php echo htmlspecialchars($contenido['grado'] ?? 'Todos'); ?> <?php echo htmlspecialchars($contenido['seccion'] ?? ''); ?>
                    </div>
                </div>

                <p class="content-description">
                    <?php echo nl2br(htmlspecialchars($contenido['descripcion'])); ?>
                </p>

                <div class="progress-section">
                    <div class="progress-header">
                        <span class="progress-title">Tu progreso</span>
                        <span class="progress-percentage"><?php echo number_format($progreso_actual, 0); ?>%</span>
                    </div>
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill" style="width: <?php echo $progreso_actual; ?>%"></div>
                    </div>
                    <div class="progress-stats">
                        <span><?php echo $progreso_actual >= 100 ? '✓ Contenido completado' : 'En progreso'; ?></span>
                        <?php 
                        // ✅ SOLO mostrar fecha si existe progreso mayor a 0
                        $fecha_actualizacion = obtenerFechaUltimaVisualizacion($content_id);
                        if ($fecha_actualizacion && $progreso_actual > 0) {
                            echo '<span>Última actualización: ' . date('d/m/Y H:i', strtotime($fecha_actualizacion)) . '</span>';
                        }
                        // ✅ Si no hay progreso, no mostrar nada
                        ?>
                    </div>
                </div>
                <!-- Botón de Completado (aparece al llegar a 100%) -->
                <div id="btn-completado-container" style="display: none; margin-top: 24px; text-align: center;">
                    <button id="btn-marcar-completado" class="activity-btn" style="background: linear-gradient(135deg, #28a745, #20c997);">
                        ✅ Marcar como Completado
                    </button>
                    <p style="margin-top: 12px; color: var(--text-muted); font-size: 14px;">
                        Haz clic para guardar tu progreso en la base de datos
                    </p>
                </div>

                <!-- Mensaje de éxito (oculto por defecto) -->
                <div id="mensaje-completado" style="display: none; margin-top: 24px; padding: 16px; background: rgba(40,167,69,0.1); border: 1px solid #28a745; border-radius: 8px; text-align: center; color: #28a745;">
                    ✅ ¡Progreso guardado exitosamente!
                </div>

                <?php if ($mostrar_recursos): ?>
                    
                    <!-- VIDEO PRINCIPAL (YouTube) -->
                    <?php if ($recurso_tipo === 'video'): ?>
                        <div class="player-container">
                            <iframe class="player" 
                                    src="<?php echo htmlspecialchars($recurso_url); ?>" 
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                                    allowfullscreen>
                            </iframe>
                        </div>
                    <?php endif; ?>
                    
                    <!-- VIDEO LOCAL -->
                    <?php if ($recurso_tipo === 'video_local'): ?>
                        <div class="player-container">
                            <video class="player" controls>
                                <source src="<?php echo htmlspecialchars($recurso_url); ?>" type="video/mp4">
                                Tu navegador no soporta el elemento de video.
                            </video>
                        </div>
                    <?php endif; ?>
                    
                    <!-- ======================================== -->
                    <!-- DOCUMENTO PRINCIPAL (SIEMPRE QUE EXISTA) -->
                    <!-- ======================================== -->
                    <?php if (!empty($contenido['archivo_adjunto'])): ?>
                        <div style="margin: 24px 0; padding: 20px; background: #f8f9fa; border-radius: 12px; border: 1px solid #e0e0e0;">
                            <h3 style="font-size: 18px; margin-bottom: 16px; color: #333; border-bottom: 1px solid #ddd; padding-bottom: 8px;">
                                Documento principal
                            </h3>
                            <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
                                <span style="font-size: 48px;"></span>
                                <div style="flex: 1;">
                                    <p style="font-weight: 600; margin-bottom: 4px;">
                                        <?php echo basename($contenido['archivo_adjunto']); ?>
                                    </p>
                                    <a href="../../../uploads/contenidos/<?php echo htmlspecialchars($contenido['archivo_adjunto']); ?>" 
                                    download
                                    style="display: inline-block; background: #C2D54E; color: var(--text-dark); padding: 10px 20px; border-radius: 6px; text-decoration: none; margin-top: 8px; font-weight: 600;">
                                        DESCARGAR DOCUMENTO PRINCIPAL
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- ENLACE -->
                    <?php if ($recurso_tipo === 'enlace'): ?>
                        <div class="document-preview" style="background: #e3f2fd;">
                            <div class="document-icon" style="color: var(--primary);">🔗</div>
                            <p style="font-size: 18px; font-weight: 600; color: var(--primary);">Enlace externo disponible</p>
                        </div>
                    <?php endif; ?>

                    

                <?php endif; ?>

                <!-- 📚 MATERIALES ADICIONALES -->
                <?php if (!empty($materiales)): ?>
                    <div style="margin-top: 40px; padding-top: 20px; border-top: 2px solid var(--border);">
                        <h3 style="font-size: 20px; margin-bottom: 20px; color: var(--text-dark);">
                            Materiales adicionales (<?php echo count($materiales); ?>)
                        </h3>
                        
                        <div style="display: grid; gap: 16px; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));">
                            <?php foreach ($materiales as $material): ?>
                                <div style="background: #f8f9fa; border-radius: 12px; padding: 16px; border-left: 4px solid var(--primary);">
                                    
                                    <!-- Título -->
                                    <h4 style="font-size: 16px; font-weight: 600; margin-bottom: 12px; color: var(--text-dark);">
                                        <?php echo htmlspecialchars($material['titulo']); ?>
                                    </h4>
                                    
                                    <!-- Tipo con icono -->
                                    <div style="margin-bottom: 12px;">
                                        <?php 
                                        $icono = '';
                                        $color = '';
                                        switch($material['tipo']) {
                                            case 'video': $color = '#4BC4E7'; break;
                                            case 'documento': $color = '#acbe36'; break;
                                            case 'enlace': $color = '#9B8AFB'; break;
                                        }
                                        ?>
                                        <span style="background: <?php echo $color; ?>20; color: <?php echo $color; ?>; padding: 4px 8px; border-radius: 4px;">
                                            <?php echo $icono . ' ' . ucfirst($material['tipo']); ?>
                                        </span>
                                    </div>
                                    
                                    <!-- VIDEO -->
                                    <?php if ($material['tipo'] === 'video' && !empty($material['url'])): ?>
                                        <div class="player-container" style="margin: 12px 0;">
                                            <?php 
                                            $url = $material['url'];
                                            if (strpos($url, 'youtube.com/watch?v=') !== false) {
                                                parse_str(parse_url($url, PHP_URL_QUERY), $params);
                                                $url = 'https://www.youtube.com/embed/' . $params['v'];
                                            } elseif (strpos($url, 'youtu.be/') !== false) {
                                                $parts = explode('/', parse_url($url, PHP_URL_PATH));
                                                $video_id = end($parts);
                                                $url = 'https://www.youtube.com/embed/' . $video_id;
                                            }
                                            ?>
                                            <iframe class="player" 
                                                    src="<?php echo htmlspecialchars($url); ?>" 
                                                    allowfullscreen>
                                            </iframe>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- DOCUMENTO -->
                                    <?php if ($material['tipo'] === 'documento' && !empty($material['archivo'])): ?>
                                        <div style="margin: 12px 0; padding: 12px; background: white; border-radius: 8px; text-align: center;">
                                            <div style="font-size: 48px; margin-bottom: 8px;"></div>
                                            <p style="font-size: 14px; color: #666; margin-bottom: 12px; word-break: break-all;">
                                                <?php echo basename($material['archivo']); ?>
                                            </p>
                                            <a href="../../../uploads/materiales/<?php echo htmlspecialchars($material['archivo']); ?>" 
                                            download
                                            style="display: inline-block; background: #C2D54E; color: var(--text-dark); padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 14px;">
                                                DESCARGAR
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                    
                                    
                                    <!-- ENLACE -->
                                    <?php if ($material['tipo'] === 'enlace' && !empty($material['url'])): ?>
                                        <div style="margin: 12px 0; text-align: center;">
                                            <a href="<?php echo htmlspecialchars($material['url']); ?>" 
                                            target="_blank"
                                            style="display: inline-block; background: #24d4dc; color: var(--text-dark); padding: 8px 16px; border-radius: 6px; text-decoration: none;">
                                                ABRIR ENLACE
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                    
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <a href="contenidos.php" class="action-btn secondary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M19 12H5M12 19l-7-7 7-7"></path>
                </svg>
                Volver a contenidos
            </a>           

            <?php if ($actividad_vinculada): ?>
                <div class="activity-btn-container">
                    <a href="detalle_actividad.php?id=<?php echo $actividad_vinculada['id']; ?>" class="activity-btn">Ir a la actividad</a>
                </div>
            <?php else: ?>
                <div class="activity-btn-container">
                    <div style="display: inline-block; padding: 16px 48px; background: #e0e0e0; color: #999; border-radius: 12px; font-size: 18px; font-weight: 600; cursor: not-allowed;">
                        Actividad no disponible aún
                    </div>
                    <p style="margin-top: 12px; color: var(--text-muted); font-size: 14px;">El docente asignará una actividad pronto.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <footer class="footer">
        <span>v2.0.0</span>
        <span>Soporte Técnico</span>
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

        // ========================================
        // SISTEMA DE PROGRESO
        // ========================================
        const CONTENIDO_ID = <?php echo $contenido['id']; ?>;
        let progresoActual = <?php echo $progreso_actual; ?>;
        let tiempoInicio = Date.now();
        let intervaloYouTube = null;
        let playerYouTube = null;

        function actualizarProgreso(porcentaje) {
            porcentaje = Math.min(100, Math.max(0, parseFloat(porcentaje.toFixed(2))));
            if (Math.abs(porcentaje - progresoActual) < 1) return;
            
            console.log('Enviando progreso:', porcentaje + '%');
            
            fetch('actualizar_progreso.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    contenido_id: CONTENIDO_ID,
                    porcentaje: porcentaje
                })
            })
            .then(response => response.json())
            .then(data => {
                console.log('Response data:', data);
                if (data.success) {
                    progresoActual = porcentaje;
                    document.querySelector('.progress-bar-fill').style.width = porcentaje + '%';
                    document.querySelector('.progress-percentage').textContent = Math.round(porcentaje) + '%';
                    
                    const estado = document.querySelector('.progress-stats span:first-child');
                    if (estado) {
                        estado.textContent = porcentaje >= 100 ? '✓ Contenido completado' : 'En progreso';
                    }
                    
                    // ✅ MOSTRAR BOTÓN AL LLEGAR A 100%
                    if (porcentaje >= 100) {
                        document.getElementById('btn-completado-container').style.display = 'block';
                        console.log('Botón de completado mostrado');
                    }
                    
                    console.log('Progreso actualizado:', porcentaje + '%');
                } else {
                    console.error('Error en respuesta:', data.error);
                }
            })
            .catch(error => console.error('Error de red:', error));
        }

        window.addEventListener('load', function() {
            console.log('🚀 Sistema de progreso iniciado');
            console.log('📊 Progreso inicial:', progresoActual + '%');
            console.log('🎬 Contenido ID:', CONTENIDO_ID);
            
            const videosLocales = document.querySelectorAll('video');
            if (videosLocales.length > 0) {
                console.log('🎬 Video local detectado:', videosLocales.length);
                videosLocales.forEach(video => {
                    video.addEventListener('timeupdate', function() {
                        if (this.duration > 0) {
                            const porcentaje = (this.currentTime / this.duration) * 100;
                            if (porcentaje > progresoActual) {
                                actualizarProgreso(porcentaje);
                            }
                        }
                    });
                    
                    video.addEventListener('ended', function() {
                        console.log('🎬 Video terminado - Progreso al 100%');
                        actualizarProgreso(100);
                    });
                });
                return;
            }
            
            const youtubeIframes = document.querySelectorAll('iframe[src*="youtube"], iframe[src*="youtu.be"]');
            console.log('🎥 YouTube iframes encontrados:', youtubeIframes.length);
            
            if (youtubeIframes.length > 0) {
                console.log('🎥 YouTube detectado - Cargando API...');
                
                if (typeof YT !== 'undefined') {
                    console.log('✅ YouTube API ya cargada');
                    iniciarYouTube();
                } else {
                    console.log('⏳ Cargando YouTube API...');
                    const tag = document.createElement('script');
                    tag.src = 'https://www.youtube.com/iframe_api'; // ✅ SIN ESPACIOS
                    const firstScript = document.getElementsByTagName('script')[0];
                    firstScript.parentNode.insertBefore(tag, firstScript);
                    
                    window.onYouTubeIframeAPIReady = iniciarYouTube;
                }
            } else {
                console.log('📄 Contenido sin video - Usando tiempo de permanencia (30 seg = 100%)');
                setInterval(() => {
                    const tiempo = Date.now() - tiempoInicio;
                    const porcentaje = Math.min(100, (tiempo / 30000) * 100);
                    
                    console.log('⏱️ Tiempo:', Math.floor(tiempo/1000), 'seg | Progreso:', porcentaje.toFixed(2) + '%');
                    
                    if (porcentaje > progresoActual && porcentaje < 100) {
                        actualizarProgreso(porcentaje);
                    } else if (porcentaje >= 100 && progresoActual < 100) {
                        console.log('✅ Contenido completado - 100%');
                        actualizarProgreso(100);
                    }
                }, 3000);
            }
        });

        function iniciarYouTube() {
            console.log('✅ YouTube API cargada');
            
            const iframes = document.querySelectorAll('iframe[src*="youtube"], iframe[src*="youtu.be"]');
            iframes.forEach((iframe, index) => {
                const id = 'yt-player-' + index;
                iframe.id = id;
                
                console.log('🎬 Creando reproductor YT para:', id);
                
                try {
                    playerYouTube = new YT.Player(id, {
                        events: {
                            'onReady': function(event) {
                                console.log('✅ Reproductor YouTube listo:', id);
                            },
                            'onStateChange': function(event) {
                                console.log('📊 Estado del player:', event.data);
                                
                                if (event.data === YT.PlayerState.PLAYING) {
                                    console.log('▶️ Video reproduciéndose');
                                    
                                    if (intervaloYouTube) clearInterval(intervaloYouTube);
                                    
                                    intervaloYouTube = setInterval(() => {
                                        if (playerYouTube && playerYouTube.getPlayerState() === YT.PlayerState.PLAYING) {
                                            const currentTime = playerYouTube.getCurrentTime();
                                            const duration = playerYouTube.getDuration();
                                            
                                            if (duration > 0) {
                                                const porcentaje = (currentTime / duration) * 100;
                                                console.log('⏱️ Progreso del video:', porcentaje.toFixed(2) + '%');
                                                
                                                if (porcentaje > progresoActual + 5) {
                                                    actualizarProgreso(porcentaje);
                                                }
                                                
                                                if (currentTime >= duration - 5 || porcentaje >= 95) {
                                                    console.log('🎬 Video casi terminado - Progreso al 100%');
                                                    actualizarProgreso(100);
                                                    clearInterval(intervaloYouTube);
                                                }
                                            }
                                        }
                                    }, 2000);
                                }
                                
                                if (event.data === YT.PlayerState.ENDED) {
                                    console.log('🎬 Video TERMINADO - Progreso al 100%');
                                    actualizarProgreso(100);
                                    if (intervaloYouTube) clearInterval(intervaloYouTube);
                                }
                            }
                        }
                    });
                } catch (error) {
                    console.error('❌ Error al crear reproductor:', error);
                }
            });
        }

        document.querySelector('.action-btn.success')?.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('📄 Documento descargado - Progreso al 100%');
            actualizarProgreso(100);
            setTimeout(() => window.location.href = this.href, 300);
        });

        window.addEventListener('beforeunload', function() {
            if (playerYouTube && playerYouTube.getPlayerState() === YT.PlayerState.PLAYING) {
                const currentTime = playerYouTube.getCurrentTime();
                const duration = playerYouTube.getDuration();
                if (duration > 0) {
                    const porcentaje = (currentTime / duration) * 100;
                    actualizarProgreso(porcentaje);
                }
            }
            
            if (intervaloYouTube) clearInterval(intervaloYouTube);
        });
        
        document.getElementById('btn-marcar-completado')?.addEventListener('click', function() {
            console.log('📡 Guardado manual solicitado');
            
            fetch('actualizar_progreso.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    contenido_id: CONTENIDO_ID,
                    porcentaje: 100
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Ocultar botón y mostrar mensaje de éxito
                    document.getElementById('btn-completado-container').style.display = 'none';
                    document.getElementById('mensaje-completado').style.display = 'block';
                    
                    // Actualizar UI
                    document.querySelector('.progress-bar-fill').style.width = '100%';
                    document.querySelector('.progress-percentage').textContent = '100%';
                    document.querySelector('.progress-stats span:first-child').textContent = '✓ Contenido completado';
                    
                    console.log('✅ Guardado manual exitoso');
                    
                    // Ocultar mensaje después de 3 segundos
                    setTimeout(() => {
                        document.getElementById('mensaje-completado').style.display = 'none';
                    }, 3000);
                } else {
                    alert('Error al guardar: ' + data.error);
                }
            })
            .catch(error => {
                alert('Error de conexión: ' + error.message);
            });
        });
    </script>
</body>
</html> 

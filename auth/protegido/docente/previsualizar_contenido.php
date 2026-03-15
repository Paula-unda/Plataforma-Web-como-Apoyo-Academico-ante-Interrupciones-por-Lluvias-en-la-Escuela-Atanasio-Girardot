<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/onesignal_config.php';

// Verificar que sea docente
if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Docente') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$contenido_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($contenido_id <= 0) {
    header('Location: gestion_contenidos.php?error=ID+no+válido');
    exit();
}

try {
    $conexion = getConexion();
    
    // Verificar que el contenido pertenezca a este docente
    $check = $conexion->prepare("
        SELECT c.*, u.nombre as docente_nombre 
        FROM contenidos c
        LEFT JOIN usuarios u ON c.docente_id = u.id
        WHERE c.id = ? AND c.docente_id = ?
    ");
    $check->execute([$contenido_id, $usuario_id]);
    $contenido = $check->fetch(PDO::FETCH_ASSOC);
    
    if (!$contenido) {
        header('Location: gestion_contenidos.php?error=Contenido+no+encontrado');
        exit();
    }
    
    // Obtener materiales adicionales
    $materiales = $conexion->prepare("
        SELECT * FROM materiales 
        WHERE contenido_id = ? AND activo = true 
        ORDER BY orden ASC
    ");
    $materiales->execute([$contenido_id]);
    $lista_materiales = $materiales->fetchAll(PDO::FETCH_ASSOC);
    
    // Determinar tipo de recurso principal
    $recurso_tipo = 'ninguno';
    $recurso_url = '';
    
    if (!empty($contenido['enlace'])) {
        $url_limpia = trim($contenido['enlace']);
        if (strpos($url_limpia, 'youtube.com') !== false || strpos($url_limpia, 'youtu.be') !== false) {
            $recurso_tipo = 'video';
            if (strpos($url_limpia, 'watch?v=') !== false) {
                parse_str(parse_url($url_limpia, PHP_URL_QUERY), $params);
                $video_id = $params['v'];
                $recurso_url = 'https://www.youtube.com/embed/' . $video_id;
            } elseif (strpos($url_limpia, 'youtu.be/') !== false) {
                $parts = explode('/', parse_url($url_limpia, PHP_URL_PATH));
                $video_id = $parts[count($parts)-1];
                $recurso_url = 'https://www.youtube.com/embed/' . $video_id;
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
    }
    
} catch (Exception $e) {
    error_log("Error en previsualización: " . $e->getMessage());
    header('Location: gestion_contenidos.php?error=Error+al+cargar+previsualización');
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Previsualizar - <?php echo htmlspecialchars($contenido['titulo']); ?></title>
    <?php require_once '../includes/favicon.php'; ?>
    <?php require_once '../includes/header_onesignal.php'; ?> 
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
        
        /* Banner de previsualización */
        .preview-banner {
            background: linear-gradient(135deg, var(--primary-purple), var(--primary-cyan));
            color: white;
            padding: 12px 24px;
            text-align: center;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 1000;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .preview-banner span {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .preview-banner a {
            background: white;
            color: var(--text-dark);
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .preview-banner a:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 24px;
            height: 60px;
            background-color: var(--surface);
            border-bottom: 1px solid var(--border);
            position: relative;
            z-index: 100;
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
        
        .icon-btn:hover { background-color: #E0E0E0; }
        .icon-btn img { width: 20px; height: 20px; object-fit: contain; }
        
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
        
        .menu-item:hover { background-color: #F8F8F8; }
        
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
        
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 40px 20px;
        }
        
        .content-container {
            width: 100%;
            max-width: 900px;
        }
        
        .info-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.05);
            border: 2px dashed var(--primary-purple);
            position: relative;
        }
        
        .info-card::before {
            content: "VISTA PREVIA - CÓMO LO VERÁN LOS ESTUDIANTES";
            position: absolute;
            top: -12px;
            left: 20px;
            background: var(--primary-purple);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .content-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 24px;
            color: var(--text-dark);
        }
        
        .content-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            padding: 16px 0;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border);
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--text-muted);
        }
        
        .meta-item strong {
            color: var(--text-dark);
            font-weight: 600;
        }
        
        .content-description {
            font-size: 16px;
            line-height: 1.6;
            color: var(--text-muted);
            margin-bottom: 24px;
            text-align: justify;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        /* Simulación de progreso (para previsualización) */
        .preview-progress {
            margin: 32px 0;
            padding: 24px;
            background: linear-gradient(135deg, var(--primary-cyan), #6fb1fc);
            border-radius: 12px;
            color: white;
            opacity: 0.7;
        }
        
        .player-container {
            position: relative;
            width: 100%;
            padding-bottom: 56.25%;
            height: 0;
            margin: 24px 0;
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
        
        .document-section {
            margin: 24px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 12px;
            border: 1px solid #e0e0e0;
        }
        
        .document-download {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        
        .btn-download {
            display: inline-block;
            background: var(--primary-lime);
            color: var(--text-dark);
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
        }
        
        .btn-download:hover {
            background: #acbe36;
            transform: translateY(-2px);
        }
        
        .materiales-grid {
            display: grid;
            gap: 20px;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            margin-top: 20px;
        }
        
        .material-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 16px;
            border-left: 4px solid var(--primary-cyan);
        }
        
        .material-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        
        .material-type {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            margin-bottom: 12px;
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
            position: sticky;
            bottom: 0;
        }
        
        @media (max-width: 768px) {
            .content-title { font-size: 24px; }
            .info-card { padding: 24px; }
            .preview-banner { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
    <!-- Banner de previsualización -->
    <div class="preview-banner">
        <span>
            <span style="font-size: 20px;">👁️</span> 
            MODO PREVISUALIZACIÓN - Estás viendo cómo verán este contenido los estudiantes
        </span>
        <a href="gestion_contenidos.php">← Volver a Gestión</a>
    </div>

    <?php require_once '../includes/header_comun.php'; ?>

    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image">
    </div>
    
    <div class="banner-content">
        <h1 class="banner-title">Previsualización</h1>
        <p class="banner-subtitle"><?php echo htmlspecialchars($contenido['asignatura']); ?></p>
    </div>

    <main class="main-content">
        <div class="content-container">
            <div class="info-card">
                <h1 class="content-title"><?php echo htmlspecialchars($contenido['titulo']); ?></h1>
                
                <div class="content-meta">
                    <div class="meta-item">
                        <span style="color: var(--primary-cyan);">📅</span>
                        <strong>Fecha:</strong> <?php echo date('d/m/Y', strtotime($contenido['fecha_publicacion'])); ?>
                    </div>
                    <div class="meta-item">
                        <span style="color: var(--primary-pink);">👨‍🏫</span>
                        <strong>Docente:</strong> <?php echo htmlspecialchars($contenido['docente_nombre'] ?? 'Tú'); ?>
                    </div>
                    <div class="meta-item">
                        <span style="color: var(--primary-lime);">📚</span>
                        <strong>Asignatura:</strong> <?php echo htmlspecialchars($contenido['asignatura']); ?>
                    </div>
                    <div class="meta-item">
                        <span style="color: var(--primary-purple);">🎯</span>
                        <strong>Dirigido a:</strong> <?php echo htmlspecialchars($contenido['grado'] . ' ' . $contenido['seccion']); ?>
                    </div>
                </div>

                <div class="content-description">
                    <?php echo nl2br(htmlspecialchars($contenido['descripcion'])); ?>
                </div>

                <!-- Simulación de progreso (solo visual) -->
                <div class="preview-progress">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 12px;">
                        <span>Progreso del estudiante</span>
                        <span>0%</span>
                    </div>
                    <div style="height: 8px; background: rgba(255,255,255,0.3); border-radius: 4px;">
                        <div style="width: 0%; height: 100%; background: white; border-radius: 4px;"></div>
                    </div>
                    <div style="margin-top: 8px; font-size: 12px; opacity: 0.8;">
                        ⚠️ En vista previa no se guarda progreso
                    </div>
                </div>

                <!-- RECURSOS PRINCIPALES -->
                <?php if (!empty($contenido['enlace'])): ?>
                    <?php if ($recurso_tipo === 'video'): ?>
                        <div class="player-container">
                            <iframe class="player" 
                                    src="<?php echo htmlspecialchars($recurso_url); ?>" 
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                                    allowfullscreen>
                            </iframe>
                        </div>
                    <?php elseif ($recurso_tipo === 'video_local'): ?>
                        <div class="player-container">
                            <video class="player" controls>
                                <source src="<?php echo htmlspecialchars($recurso_url); ?>" type="video/mp4">
                            </video>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- DOCUMENTO PRINCIPAL -->
                <?php if (!empty($contenido['archivo_adjunto'])): ?>
                    <div class="document-section">
                        <h3 style="font-size: 18px; margin-bottom: 16px;">📄 Documento principal</h3>
                        <div class="document-download">
                            <span style="font-size: 48px;">📄</span>
                            <div style="flex: 1;">
                                <p style="font-weight: 600; margin-bottom: 4px;">
                                    <?php echo basename($contenido['archivo_adjunto']); ?>
                                </p>
                                <a href="../../../uploads/contenidos/<?php echo htmlspecialchars($contenido['archivo_adjunto']); ?>" 
                                   target="_blank" class="btn-download">
                                    VER DOCUMENTO
                                </a>
                                <small style="display: block; margin-top: 4px; color: var(--text-muted);">
                                    Los estudiantes podrán descargar este archivo
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ENLACE PRINCIPAL (si no es video) -->
                <?php if (!empty($contenido['enlace']) && $recurso_tipo === 'enlace'): ?>
                    <div class="document-section">
                        <h3 style="font-size: 18px; margin-bottom: 16px;">🔗 Enlace externo</h3>
                        <a href="<?php echo htmlspecialchars($contenido['enlace']); ?>" 
                           target="_blank" class="btn-download" style="background: var(--primary-cyan);">
                            ABRIR ENLACE
                        </a>
                    </div>
                <?php endif; ?>

                <!-- MATERIALES ADICIONALES -->
                <?php if (!empty($lista_materiales)): ?>
                    <div style="margin-top: 40px;">
                        <h3 style="font-size: 20px; margin-bottom: 20px;">
                            📎 Materiales adicionales (<?php echo count($lista_materiales); ?>)
                        </h3>
                        
                        <div class="materiales-grid">
                            <?php foreach ($lista_materiales as $material): ?>
                                <div class="material-card">
                                    <h4 class="material-title"><?php echo htmlspecialchars($material['titulo']); ?></h4>
                                    
                                    <div style="margin-bottom: 12px;">
                                        <?php 
                                        $color = '';
                                        switch($material['tipo']) {
                                            case 'video': $color = 'var(--primary-cyan)'; break;
                                            case 'documento': $color = 'var(--primary-lime)'; break;
                                            case 'enlace': $color = 'var(--primary-purple)'; break;
                                        }
                                        ?>
                                        <span class="material-type" style="background: <?php echo $color; ?>20; color: <?php echo $color; ?>;">
                                            <?php echo ucfirst($material['tipo']); ?>
                                        </span>
                                    </div>
                                    
                                    <?php if ($material['tipo'] === 'video' && !empty($material['url'])): ?>
                                        <div style="font-size: 14px; color: var(--text-muted);">
                                            Video incrustado (YouTube)
                                        </div>
                                    <?php elseif ($material['tipo'] === 'documento' && !empty($material['archivo'])): ?>
                                        <div style="font-size: 14px; color: var(--text-muted);">
                                            Archivo: <?php echo basename($material['archivo']); ?>
                                        </div>
                                    <?php elseif ($material['tipo'] === 'enlace' && !empty($material['url'])): ?>
                                        <div style="font-size: 14px; color: var(--text-muted);">
                                            Enlace externo
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div style="margin-top: 12px; font-size: 12px; color: var(--text-muted);">
                                        ⚠️ Los estudiantes verán este material con su reproductor/botón correspondiente
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Mensaje sobre actividades (solo informativo) -->
                <div style="margin-top: 40px; padding: 16px; background: #f0f0f0; border-radius: 8px; text-align: center;">
                    <p style="color: var(--text-muted);">
                        ℹ️ Si este contenido tiene una actividad vinculada, los estudiantes verán un botón 
                        "Ir a la actividad" debajo de esta sección.
                    </p>
                </div>
            </div>

            <!-- Botón para volver (solo para el docente) -->
            <div style="text-align: center; margin-top: 30px;">
                <a href="gestion_contenidos.php" class="btn-download" style="background: var(--surface); color: var(--text-dark); border: 1px solid var(--border);">
                    ← Volver a Gestión de Contenidos
                </a>
            </div>
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
    </script>
</body>
</html>
<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/onesignal_config.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Estudiante') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

// Obtener los contenidos con progreso para este estudiante
$contenidos = obtenerContenidosConProgreso($_SESSION['usuario_id']);


// 🔍 DEBUG
error_log("=== DEBUG CONTENIDOS CON PROGRESO ===");
error_log("Total contenidos: " . count($contenidos));
foreach ($contenidos as $cont) {
    error_log("Contenido ID: " . $cont['id'] . " | Título: " . $cont['titulo']);
    error_log("  - porcentaje_visto: " . ($cont['porcentaje_visto'] ?? 'NULL'));
    error_log("  - completado: " . ($cont['completado'] ?? 'NULL'));
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contenidos Educativos - SIEDUCRES</title>
    <?php require_once '../includes/favicon.php'; ?>
    <?php require_once '../includes/header_onesignal.php'; ?> 
    <!-- Metadatos para evitar caché -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --text-dark: #333333; 
            --text-muted: #666666; 
            --border: #E0E0E0;
            --surface: #FFFFFF; 
            --background: #F5F5F5;
            --visto-bg: #e8f5e9;
            --visto-border: #28a745;
            --recien-bg: #fff3cd;
            --recien-border: #ffc107;
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
            box-shadow: 0 1px 3px rgba(0,0,0,0.05); 
            position: relative; 
            z-index: 100; 
        }
        .logo { height: 40px; }
        .header-right { display: flex; align-items: center; gap: 16px; }
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
        .main-content { 
            flex: 1; 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            justify-content: center; 
            padding: 40px 20px; 
        }
        .card-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); 
            gap: 24px; 
            justify-content: center; 
            max-width: 1400px; 
            width: 100%; 
        }
        .card { 
            background: linear-gradient(135deg, #4a90e2, #6fb1fc); 
            border-radius: 16px; 
            padding: 32px 24px; 
            text-align: center; 
            color: var(--text-dark); 
            text-decoration: none; 
            display: block; 
            box-shadow: 0 6px 16px rgba(0,0,0,0.1); 
            transition: transform 0.3s, box-shadow 0.3s; 
            min-width: 280px; 
            position: relative; 
            overflow: hidden; 
        }
        .card:hover { 
            transform: translateY(-6px); 
            box-shadow: 0 10px 24px rgba(0,0,0,0.15); 
        }
        .card:nth-child(3n+1) { background: linear-gradient(135deg, #f36c7b, #f89ca6); }
        .card:nth-child(3n+2) { background: linear-gradient(135deg, #24d4dc, #5ce0e6); }
        .card:nth-child(3n+3) { background: linear-gradient(135deg, #cbd74f, #d7e07a); }
        .card-icon { 
            width: 120px; 
            height: 120px; 
            margin: 0 auto 20px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
        }
        .card-icon img { 
            width: 80px; 
            height: 80px; 
            object-fit: contain; 
        }
        .card-title { 
            font-size: 20px; 
            font-weight: 600; 
            margin-bottom: 12px; 
            min-height: 50px; 
        }
        .card-desc { 
            font-size: 14px; 
            opacity: 0.9; 
            line-height: 1.5; 
            margin-bottom: 12px; 
            min-height: 80px; 
            display: -webkit-box; 
            -webkit-line-clamp: 4; 
            -webkit-box-orient: vertical; 
            overflow: hidden; 
        }
        .card-meta { 
            font-size: 13px; 
            margin: 6px 0; 
            opacity: 0.85; 
        }
        .card-meta strong { 
            opacity: 1; 
            font-weight: 600; 
        }
        /* ===== NUEVOS INDICADORES DE ESTADO ===== */
        .estado-container {
            margin: 16px 0;
            display: flex;
            justify-content: center;
        }
        .estado-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            color: #000000; /* Texto negro */
            border: 1px solid;
        }
        .estado-badge.visto {
            background-color: var(--visto-bg);
            border-color: var(--visto-border);
        }
        .estado-badge.recien-visto {
            background-color: var(--recien-bg);
            border-color: var(--recien-border);
        }
        .estado-badge span {
            font-size: 18px;
        }
        .view-btn { 
            display: inline-block; 
            margin-top: 16px; 
            padding: 10px 20px; 
            background-color: var(--surface); 
            color: var(--text-dark); 
            border: 1px solid var(--border); 
            border-radius: 8px; 
            text-decoration: none; 
            font-size: 14px; 
            font-weight: 600; 
            transition: all 0.2s; 
            cursor: pointer; 
            width: 100%; 
        }
        .view-btn:hover { 
            background-color: #f0f0f0; 
            transform: scale(1.03); 
        }
        .no-contenidos { 
            text-align: center; 
            padding: 60px 20px; 
            max-width: 600px; 
        }
        .no-contenidos-icon { 
            font-size: 64px; 
            margin-bottom: 20px; 
            opacity: 0.3; 
        }
        .no-contenidos-title { 
            font-size: 24px; 
            font-weight: 600; 
            margin-bottom: 12px; 
            color: var(--text-muted); 
        }
        .no-contenidos-desc { 
            font-size: 16px; 
            color: var(--text-muted); 
            line-height: 1.6; 
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
            left: 0; 
            right: 0; 
        }
        /* Enlace volver */
        .back-link {
            display: block;
            color: #EF5E8E;
            text-decoration: none;
        }
        @media (max-width: 768px) {
            .banner-title { font-size: 28px; }
            .banner { height: 160px; }
            .card-grid { grid-template-columns: 1fr; }
            .card { width: 100%; max-width: 400px; }
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header_comun.php'; ?>

    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image">
    </div>
    
    <div class="banner-content">
        <h1 class="banner-title">Contenidos Educativos</h1>
    </div>
    <!-- 🔴 FLECHA DE VOLVER A LA IZQUIERDA -->
    <?php if (basename($_SERVER['PHP_SELF']) != 'index.php'): ?>
        <div style="max-width: 1200px; margin: 10px 0 10px 40px; padding: 0; width: 100%;">
            <a href="index.php" class="back-link">← Volver al Panel</a>
        </div>
    <?php endif; ?>

    <main class="main-content">
        <?php if (count($contenidos) > 0): ?>
            <div class="card-grid">
                <?php foreach ($contenidos as $contenido): 
                    // Determinar estado de visualización
                    $esta_completado = ($contenido['porcentaje_visto'] ?? 0) >= 100;
                    $es_recien_visto = false;
                    
                    // Verificar si se vio en los últimos 5 minutos
                    if ($esta_completado && !empty($contenido['ultima_visualizacion'])) {
                        $hace_5_min = strtotime('-5 minutes');
                        $fecha_visto = strtotime($contenido['ultima_visualizacion']);
                        $es_recien_visto = ($fecha_visto > $hace_5_min);
                    }
                ?>
                    <a href="contenido_detalle.php?id=<?php echo $contenido['id']; ?>" class="card">
                        
                        <h2 class="card-title"><?php echo htmlspecialchars($contenido['titulo']); ?></h2>
                        
                        <p class="card-desc"><?php echo htmlspecialchars(mb_substr($contenido['descripcion'], 0, 150)); ?>...</p>
                        
                        <p class="card-meta"><strong>📚 Asignatura:</strong> <?php echo htmlspecialchars($contenido['asignatura'] ?? 'General'); ?></p>
                        <p class="card-meta"><strong>👨‍🏫 Docente:</strong> <?php echo htmlspecialchars($contenido['docente_nombre'] ?? 'No especificado'); ?></p>
                        <p class="card-meta"><strong>📅 Fecha:</strong> <?php echo date('d/m/Y', strtotime($contenido['fecha_publicacion'])); ?></p>
                        
                        <!-- ✅ NUEVO: Indicador de estado en texto negro -->
                        <div class="estado-container">
                            <?php if ($esta_completado): ?>
                                <?php if ($es_recien_visto): ?>
                                    <div class="estado-badge recien-visto">
                                        <span>🆕</span> Recién visto
                                    </div>
                                <?php else: ?>
                                    <div class="estado-badge visto">
                                        <span>✅</span> Visto
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="estado-badge visto" style="background: #f0f0f0; border-color: #ccc;">
                                    <span>📖</span> Sin ver
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <span class="view-btn">Ver contenido →</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-contenidos">
                <div class="no-contenidos-icon">📚</div>
                <h2 class="no-contenidos-title">No hay contenidos disponibles</h2>
                <p class="no-contenidos-desc">
                    Aún no se han publicado contenidos educativos para tu grado y sección. 
                    Por favor, espera a que los docentes suban el material.
                </p>
            </div>
        <?php endif; ?>
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
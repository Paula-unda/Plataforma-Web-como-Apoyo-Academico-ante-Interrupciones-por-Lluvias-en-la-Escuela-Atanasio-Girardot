<?php
session_start();
require_once '../../funciones.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Estudiante') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

// Obtener actividades para este estudiante
$actividades = obtenerActividadesEstudiante($_SESSION['usuario_id']);

// DEBUG
error_log("=== DEBUG ACTIVIDADES ===");
error_log("Total actividades: " . count($actividades));
foreach ($actividades as $act) {
    error_log("Actividad ID: " . $act['id'] . " | Título: " . $act['titulo']);
    error_log("  - calificacion: '" . ($act['calificacion'] ?? 'NULL') . "'");
    error_log("  - estado_final: " . ($act['estado_final'] ?? 'NO EXISTE'));
}

// Filtrar solo tareas e indicaciones
$actividades = array_filter($actividades, function($act) {
    return in_array($act['tipo'], ['tarea', 'indicacion']);
});
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Actividades - SIEDUCRES</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --text-dark: #333333; --text-muted: #666666; --border: #E0E0E0;
            --surface: #FFFFFF; --background: #F5F5F5;
            --primary: #4a90e2; --success: #28a745; --warning: #ffc107; --danger: #dc3545;
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
        .card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px; justify-content: center; max-width: 1400px; width: 100%; }
        .card { background: linear-gradient(135deg, var(--primary), #6fb1fc); border-radius: 16px; padding: 32px 24px; text-align: center; color: var(--text-dark); text-decoration: none; display: block; box-shadow: 0 6px 16px rgba(0,0,0,0.1); transition: transform 0.3s, box-shadow 0.3s; min-width: 280px; position: relative; overflow: hidden; }
        .card:hover { transform: translateY(-6px); box-shadow: 0 10px 24px rgba(0,0,0,0.15); }
        .card:nth-child(3n+1) { background: linear-gradient(135deg, #f36c7b, #f89ca6); }
        .card:nth-child(3n+2) { background: linear-gradient(135deg, #24d4dc, #5ce0e6); }
        .card:nth-child(3n+3) { background: linear-gradient(135deg, #cbd74f, #d7e07a); }

        .card-icon { width: 100px; height: 100px; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.2); border-radius: 16px; }
        .card-icon svg { width: 60px; height: 60px; fill: white; }
        .card-title { font-size: 20px; font-weight: 600; margin-bottom: 12px; min-height: 50px; }
        .card-desc { font-size: 14px; opacity: 0.9; line-height: 1.5; margin-bottom: 16px; min-height: 60px; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
        .card-meta { font-size: 13px; margin: 6px 0; opacity: 0.85; }
        .card-meta strong { opacity: 1; font-weight: 600; }

        .estado-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; margin: 10px 0; }
        .estado-pendiente { background: rgba(255,255,255,0.3); color: white; }
        .estado-enviado { background: rgba(40, 167, 69, 0.3); color: white; }
        .estado-atrasado { background: rgba(255, 193, 7, 0.3); color: white; }
        .estado-calificado { background: rgba(255,255,255,0.4); color: white; font-weight: 700; }

        .btn-detalles { display: inline-block; margin-top: 16px; padding: 10px 24px; background-color: rgba(255,255,255,0.25); color: white; border: 1px solid rgba(255,255,255,0.4); border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; transition: all 0.2s; width: 100%; max-width: 200px; }
        .btn-detalles:hover { background-color: rgba(255,255,255,0.35); transform: translateY(-2px); }

        .no-actividades { text-align: center; padding: 60px 20px; max-width: 600px; }
        .no-actividades-icon { font-size: 64px; margin-bottom: 20px; opacity: 0.3; }
        .no-actividades-title { font-size: 24px; font-weight: 600; margin-bottom: 12px; color: var(--text-muted); }
        .no-actividades-desc { font-size: 16px; color: var(--text-muted); line-height: 1.6; }

        @media (max-width: 768px) {
            .banner-title { font-size: 28px; }
            .banner { height: 160px; }
            .card-grid { grid-template-columns: 1fr; }
            .card { width: 100%; max-width: 400px; }
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
        <h1 class="banner-title">Actividades</h1>
    </div>

    <main class="main-content">
        <?php if (count($actividades) > 0): ?>
            <div class="card-grid">
                <?php foreach ($actividades as $actividad): 
                    $estado_clase = 'estado-pendiente';
                    $estado_texto = 'Pendiente';
                    
                    if (isset($actividad['calificacion']) && $actividad['calificacion'] !== null && $actividad['calificacion'] !== '') {
                        $estado_clase = 'estado-calificado';
                        $estado_texto = 'Calificada';
                    } elseif (isset($actividad['estado_final']) && $actividad['estado_final'] === 'enviado') {
                        $estado_clase = 'estado-enviado';
                        $estado_texto = 'Por calificar';
                    } elseif (isset($actividad['estado_final']) && $actividad['estado_final'] === 'atrasado') {
                        $estado_clase = 'estado-atrasado';
                        $estado_texto = 'Atrasada';
                    }
                    
                    $fecha_entrega = !empty($actividad['fecha_entrega']) && $actividad['fecha_entrega'] !== '0000-00-00'
                        ? date('d/m/Y', strtotime($actividad['fecha_entrega']))
                        : 'Fecha no especificada';
                ?>
                    <a href="detalle_actividad.php?id=<?php echo (int)$actividad['id']; ?>" class="card">
                        <div class="card-icon">
                            <svg viewBox="0 0 24 24">
                                <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                            </svg>
                        </div>
                        <h2 class="card-title"><?php echo htmlspecialchars($actividad['titulo']); ?></h2>
                        <p class="card-desc"><?php echo htmlspecialchars(mb_substr($actividad['descripcion'], 0, 100)); ?>...</p>
                        
                        <p class="card-meta"><strong>👨‍🏫 Docente:</strong> <?php echo htmlspecialchars($actividad['docente_nombre'] ?? 'No especificado'); ?></p>
                        <p class="card-meta"><strong>🔖 Tipo:</strong> <?php echo ucfirst(htmlspecialchars($actividad['tipo'])); ?></p>
                        <p class="card-meta"><strong>📅 Entrega:</strong> <?php echo $fecha_entrega; ?></p>
                        
                        <?php if (!empty($actividad['grado']) || !empty($actividad['seccion'])): ?>
                            <p class="card-meta">
                                <strong>🎯 Para:</strong> 
                                <?php echo htmlspecialchars($actividad['grado'] ?? ''); ?>
                                <?php echo htmlspecialchars($actividad['seccion'] ?? ''); ?>
                            </p>
                        <?php endif; ?>
                        
                        <!-- ✅ UN SOLO SPAN - SIN ANIDAR -->
                        <span class="estado-badge <?php echo $estado_clase; ?>">
                            <?php echo $estado_texto; ?>
                            <?php if (isset($actividad['calificacion']) && $actividad['calificacion'] !== null && $actividad['calificacion'] !== ''): ?>
                                <br>
                                <small style="font-size: 11px; margin-top: 4px;">
                                    
                                </small>
                            <?php endif; ?>
                        </span>
                        
                        <span class="btn-detalles">
                            <svg width="16" height="16" viewBox="0 0 24 24" style="vertical-align:middle; margin-right:4px;">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" fill="none" stroke="white" stroke-width="2"/>
                                <circle cx="12" cy="12" r="3" fill="none" stroke="white" stroke-width="2"/>
                            </svg>
                            Ver detalles
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-actividades">
                <div class="no-actividades-icon">📚</div>
                <h2 class="no-actividades-title">No hay actividades disponibles</h2>
                <p class="no-actividades-desc">
                    Aún no se han asignado actividades para tu grado y sección. 
                    Por favor, espera a que tu docente publique nuevas tareas o exámenes.
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
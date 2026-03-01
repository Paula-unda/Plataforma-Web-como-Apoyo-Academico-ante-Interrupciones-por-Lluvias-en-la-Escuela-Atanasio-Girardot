<?php
session_start();
require_once '../../funciones.php';

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
    
    // Estadísticas generales (CORREGIDO)
    $stats = $conexion->prepare("
        WITH estudiantes_seccion AS (
            SELECT u.id 
            FROM usuarios u
            INNER JOIN estudiantes e ON u.id = e.usuario_id
            WHERE u.rol = 'Estudiante' 
                AND e.grado = ? 
                AND e.seccion = ?
        )
        SELECT 
            (SELECT COUNT(*) FROM estudiantes_seccion) as total_estudiantes,
            COALESCE(AVG(p.porcentaje_visto), 0) as progreso_promedio,
            COUNT(CASE WHEN p.porcentaje_visto >= 100 THEN 1 END) as estudiantes_completados,
            COUNT(CASE WHEN p.porcentaje_visto > 0 AND p.porcentaje_visto < 100 THEN 1 END) as estudiantes_en_progreso,
            COUNT(CASE WHEN p.porcentaje_visto = 0 OR p.porcentaje_visto IS NULL THEN 1 END) as estudiantes_sin_iniciar,
            MAX(p.porcentaje_visto) as max_progreso,
            MIN(p.porcentaje_visto) as min_progreso,
            MAX(p.ultima_visualizacion) as ultima_actividad
        FROM estudiantes_seccion es
        LEFT JOIN progreso_contenido p ON es.id = p.estudiante_id AND p.contenido_id = ?
    ");
    $stats->execute([$contenido['grado'], $contenido['seccion'], $contenido_id]);
    $estadisticas = $stats->fetch(PDO::FETCH_ASSOC);
    
    // Lista de estudiantes con su progreso
    $estudiantes = $conexion->prepare("
        SELECT 
            u.id,
            u.nombre,
            u.correo,
            COALESCE(p.porcentaje_visto, 0) as progreso,
            CASE 
                WHEN p.porcentaje_visto >= 100 THEN 'Completado'
                WHEN p.porcentaje_visto > 0 THEN 'En progreso'
                ELSE 'Sin iniciar'
            END as estado,
            p.ultima_visualizacion,
            p.completado
        FROM usuarios u
        INNER JOIN estudiantes e ON u.id = e.usuario_id
        LEFT JOIN progreso_contenido p ON u.id = p.estudiante_id AND p.contenido_id = ?
        WHERE u.rol = 'Estudiante' 
            AND e.grado = ? 
            AND e.seccion = ?
        ORDER BY 
            CASE 
                WHEN p.porcentaje_visto >= 100 THEN 1
                WHEN p.porcentaje_visto > 0 THEN 2
                ELSE 3
            END,
            p.porcentaje_visto DESC,
            u.nombre ASC
    ");
    $estudiantes->execute([$contenido_id, $contenido['grado'], $contenido['seccion']]);
    $lista_estudiantes = $estudiantes->fetchAll(PDO::FETCH_ASSOC);
    
    // Materiales del contenido
    $materiales = $conexion->prepare("
        SELECT * FROM materiales 
        WHERE contenido_id = ? AND activo = true 
        ORDER BY orden ASC
    ");
    $materiales->execute([$contenido_id]);
    $lista_materiales = $materiales->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error en estadísticas: " . $e->getMessage());
    header('Location: gestion_contenidos.php?error=Error+al+cargar+estadísticas');
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estadísticas - <?php echo htmlspecialchars($contenido['titulo']); ?></title>
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
            --success: #C2D54E;
            --warning: #ffc107;
            --danger: #EF5E8E;
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
            padding: 40px 20px;
            max-width: 1200px;
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
            position: sticky;
            bottom: 0;
        }
        
        /* Tarjetas de estadísticas */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-left: 4px solid;
        }
        
        .stat-card.cyan { border-left-color: var(--primary-cyan); }
        .stat-card.pink { border-left-color: var(--primary-pink); }
        .stat-card.lime { border-left-color: var(--primary-lime); }
        .stat-card.purple { border-left-color: var(--primary-purple); }
        
        .stat-value {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 12px;
        }
        
        .stat-progress {
            height: 6px;
            background: #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .stat-progress-fill {
            height: 100%;
            background: var(--primary-lime);
            border-radius: 3px;
        }
        
        /* Info del contenido */
        .content-info {
            background: var(--surface);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .content-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 16px;
            color: var(--text-dark);
        }
        
        .content-meta {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            padding-bottom: 16px;
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
        
        /* Tabla de estudiantes */
        .table-container {
            background: var(--surface);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .table-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--text-dark);
        }
        
        .table-responsive {
            overflow-x: auto;
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
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .estado-completado {
            background: var(--primary-lime);
            color: var(--text-dark);
        }
        
        .estado-progreso {
            background: var(--primary-cyan);
            color: var(--text-dark);
        }
        
        .estado-sin-iniciar {
            background: #e0e0e0;
            color: var(--text-muted);
        }
        
        .progress-cell {
            min-width: 150px;
        }
        
        .progress-cell .progress-bar {
            width: 100%;
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
            margin: 4px 0;
        }
        
        .progress-cell .progress-fill {
            height: 100%;
            background: var(--primary-lime);
            border-radius: 4px;
        }
        
        .btn-volver {
            display: inline-block;
            padding: 12px 24px;
            background: var(--surface);
            color: var(--text-dark);
            border: 1px solid var(--border);
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-volver:hover {
            background: #f0f0f0;
            transform: translateY(-2px);
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .content-meta {
                flex-direction: column;
                gap: 12px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
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
                <a href="gestion_contenidos.php" class="menu-item">Gestión de Contenidos</a>
                <a href="../../logout.php" class="menu-item">Cerrar sesión</a>
            </div>
        </div>
    </header>

    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image">
    </div>
    
    <div class="banner-content">
        <h1 class="banner-title">Estadísticas del Contenido</h1>
        <p class="banner-subtitle"><?php echo htmlspecialchars($contenido['asignatura']); ?> - <?php echo htmlspecialchars($contenido['grado'] . ' ' . $contenido['seccion']); ?></p>
    </div>

    <main class="main-content">
        <!-- Info del contenido -->
        <div class="content-info">
            <h2 class="content-title"><?php echo htmlspecialchars($contenido['titulo']); ?></h2>
            
            <div class="content-meta">
                <div class="meta-item">
                    <span style="color: var(--primary-cyan);">📅</span>
                    <strong>Publicado:</strong> <?php echo date('d/m/Y', strtotime($contenido['fecha_publicacion'])); ?>
                </div>
                <div class="meta-item">
                    <span style="color: var(--primary-pink);">📚</span>
                    <strong>Asignatura:</strong> <?php echo htmlspecialchars($contenido['asignatura']); ?>
                </div>
                <div class="meta-item">
                    <span style="color: var(--primary-lime);">🎯</span>
                    <strong>Dirigido a:</strong> <?php echo htmlspecialchars($contenido['grado'] . ' ' . $contenido['seccion']); ?>
                </div>
                <div class="meta-item">
                    <span style="color: var(--primary-purple);">📎</span>
                    <strong>Materiales:</strong> <?php echo count($lista_materiales); ?>
                </div>
            </div>
        </div>

        <!-- Tarjetas de estadísticas -->
        <div class="stats-grid">
            <div class="stat-card cyan">
                <div class="stat-value"><?php echo $estadisticas['total_estudiantes'] ?? 0; ?></div>
                <div class="stat-label">Total estudiantes</div>
                <div class="stat-progress">
                    <div class="stat-progress-fill" style="width: 100%"></div>
                </div>
            </div>
            
            <div class="stat-card pink">
                <div class="stat-value"><?php echo round($estadisticas['progreso_promedio'] ?? 0, 1); ?>%</div>
                <div class="stat-label">Progreso promedio</div>
                <div class="stat-progress">
                    <div class="stat-progress-fill" style="width: <?php echo $estadisticas['progreso_promedio'] ?? 0; ?>%"></div>
                </div>
            </div>
            
            <div class="stat-card lime">
                <div class="stat-value"><?php echo $estadisticas['estudiantes_completados'] ?? 0; ?></div>
                <div class="stat-label">Completaron</div>
                <div class="stat-progress">
                    <div class="stat-progress-fill" style="width: <?php echo $estadisticas['total_estudiantes'] > 0 ? ($estadisticas['estudiantes_completados'] / $estadisticas['total_estudiantes'] * 100) : 0; ?>%"></div>
                </div>
            </div>
            
            <div class="stat-card purple">
                <div class="stat-value"><?php echo $estadisticas['estudiantes_en_progreso'] ?? 0; ?></div>
                <div class="stat-label">En progreso</div>
                <div class="stat-progress">
                    <div class="stat-progress-fill" style="width: <?php echo $estadisticas['total_estudiantes'] > 0 ? ($estadisticas['estudiantes_en_progreso'] / $estadisticas['total_estudiantes'] * 100) : 0; ?>%"></div>
                </div>
            </div>
        </div>

        <!-- Tabla de estudiantes -->
        <div class="table-container">
            <h3 class="table-title">Progreso por estudiante</h3>
            
            <?php if (count($lista_estudiantes) > 0): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Estudiante</th>
                                <th>Correo</th>
                                <th>Progreso</th>
                                <th>Estado</th>
                                <th>Última actividad</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lista_estudiantes as $est): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($est['nombre']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($est['correo']); ?></td>
                                    <td class="progress-cell">
                                        <div><?php echo $est['progreso']; ?>%</div>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $est['progreso']; ?>%"></div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php 
                                        $clase_estado = '';
                                        switch($est['estado']) {
                                            case 'Completado':
                                                $clase_estado = 'estado-completado';
                                                break;
                                            case 'En progreso':
                                                $clase_estado = 'estado-progreso';
                                                break;
                                            default:
                                                $clase_estado = 'estado-sin-iniciar';
                                        }
                                        ?>
                                        <span class="estado-badge <?php echo $clase_estado; ?>">
                                            <?php echo $est['estado']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        if ($est['ultima_visualizacion']) {
                                            echo date('d/m/Y H:i', strtotime($est['ultima_visualizacion']));
                                        } else {
                                            echo '<span style="color: var(--text-muted);">—</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="text-align: center; padding: 40px; color: var(--text-muted);">
                    No hay estudiantes registrados en este grado y sección.
                </p>
            <?php endif; ?>
        </div>

        <!-- Botón volver -->
        <div style="text-align: center; margin-top: 30px;">
            <a href="gestion_contenidos.php" class="btn-volver">
                ← Volver a Gestión de Contenidos
            </a>
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
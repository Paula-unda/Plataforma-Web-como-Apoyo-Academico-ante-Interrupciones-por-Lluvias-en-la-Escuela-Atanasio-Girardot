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
$mensaje = $_SESSION['mensaje_temporal'] ?? '';
$error = $_SESSION['error_temporal'] ?? '';
unset($_SESSION['mensaje_temporal'], $_SESSION['error_temporal']);

// Obtener grado y sección del docente
$grado_docente = '';
$seccion_docente = '';

try {
    $conexion = getConexion();
    
    // Obtener datos del docente
    $stmt_docente = $conexion->prepare("SELECT grado, seccion FROM docentes WHERE usuario_id = ?");
    $stmt_docente->execute([$usuario_id]);
    $datos_docente = $stmt_docente->fetch(PDO::FETCH_ASSOC);
    
    if ($datos_docente) {
        $grado_docente = $datos_docente['grado'];
        $seccion_docente = $datos_docente['seccion'];
    }
    
    // Obtener actividades del docente
    $actividades = $conexion->prepare("
        SELECT a.*, 
               COUNT(DISTINCT ee.estudiante_id) as total_entregas,
               COUNT(DISTINCT CASE WHEN ee.estado = 'pendiente' THEN ee.estudiante_id END) as pendientes,
               COUNT(DISTINCT CASE WHEN ee.estado = 'enviado' THEN ee.estudiante_id END) as enviados,
               COUNT(DISTINCT CASE WHEN ee.estado = 'calificado' THEN ee.estudiante_id END) as calificados,
               COUNT(DISTINCT u.id) as total_estudiantes
        FROM actividades a
        LEFT JOIN entregas_estudiantes ee ON a.id = ee.actividad_id
        LEFT JOIN estudiantes e ON e.grado = a.grado AND e.seccion = a.seccion
        LEFT JOIN usuarios u ON u.id = e.usuario_id AND u.rol = 'Estudiante'
        WHERE a.docente_id = ?
        GROUP BY a.id
        ORDER BY a.fecha_publicacion DESC
    ");
    $actividades->execute([$usuario_id]);
    $lista_actividades = $actividades->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener contenidos para vincular
    $contenidos = $conexion->prepare("
        SELECT id, titulo, asignatura FROM contenidos 
        WHERE docente_id = ? AND activo = true
        ORDER BY fecha_publicacion DESC
    ");
    $contenidos->execute([$usuario_id]);
    $lista_contenidos = $contenidos->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error en gestión de actividades: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Actividades - SIEDUCRES</title>
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
        .header { display: flex; justify-content: space-between; align-items: center; padding: 0 24px; height: 60px; background-color: var(--surface); border-bottom: 1px solid var(--border); }
        .logo { height: 40px; }
        .header-right { display: flex; align-items: center; gap: 16px; }
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; background-color: #F0F0F0; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .icon-btn:hover { background-color: #E0E0E0; }
        .icon-btn img { width: 20px; height: 20px; object-fit: contain; }
        .menu-dropdown { position: absolute; top: 60px; right: 24px; background: white; border: 1px solid var(--border); border-radius: 8px; display: none; min-width: 180px; }
        .menu-item { padding: 10px 16px; font-size: 14px; color: var(--text-dark); text-decoration: none; display: block; }
        .menu-item:hover { background-color: #F8F8F8; }
        .banner { position: relative; height: 100px; overflow: hidden; }
        .banner-image { width: 100%; height: 100%; object-fit: cover; }
        .banner-content { text-align: center; padding: 20px; }
        .banner-title { font-size: 36px; font-weight: 700; }
        .main-content { flex: 1; padding: 40px 20px; max-width: 1400px; margin: 0 auto; width: 100%; }
        .footer { height: 50px; background-color: var(--surface); border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; padding: 0 24px; font-size: 13px; color: var(--text-muted); }
        
        .btn-primary { background: var(--primary-lime); color: var(--text-dark); padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .btn-primary:hover { background: #acbe36; transform: translateY(-2px); }
        .btn-secondary { background: #f0f0f0; border: 1px solid var(--border); padding: 10px 20px; border-radius: 8px; text-decoration: none; color: var(--text-dark); display: inline-block; }
        .btn-secondary:hover { background: #e0e0e0; }
        
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .alert-error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: var(--surface); border-radius: 12px; padding: 20px; border-left: 4px solid; }
        .stat-card.cyan { border-left-color: var(--primary-cyan); }
        .stat-card.pink { border-left-color: var(--primary-pink); }
        .stat-card.lime { border-left-color: var(--primary-lime); }
        .stat-card.purple { border-left-color: var(--primary-purple); }
        .stat-value { font-size: 28px; font-weight: 700; }
        .stat-label { font-size: 14px; color: var(--text-muted); }
        
        .table-container { background: var(--surface); border-radius: 16px; padding: 24px; margin-top: 30px; }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px; background: #f8f9fa; font-weight: 600; border-bottom: 2px solid var(--border); }
        td { padding: 12px; border-bottom: 1px solid var(--border); }
        tr:hover td { background: #f8f9fa; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
        .badge-pendiente { background: #fff3cd; color: #856404; }
        .badge-enviado { background: #cce5ff; color: #004085; }
        .badge-calificado { background: #d4edda; color: #155724; }
        .action-btns { display: flex; gap: 8px; }
        .btn-icon { padding: 4px 8px; background: none; border: 1px solid var(--border); border-radius: 4px; text-decoration: none; color: var(--text-muted); }
        .btn-icon:hover { background: #f0f0f0; }
        /* Enlace volver */
        .back-link {
            display: block;
            color: #EF5E8E;
            text-decoration: none;
        }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header_comun.php'; ?>
    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner" class="banner-image">
    </div>
    
    <div class="banner-content">
        <h1 class="banner-title">Gestión de Actividades</h1>
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

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
            <h2>Actividades publicadas</h2>
            <a href="crear_actividad.php" class="btn-primary">+ Nueva Actividad</a>
        </div>

        <?php if (count($lista_actividades) > 0): ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Título</th>
                            <th>Tipo</th>
                            <th>Fecha límite</th>
                            <th>Grado/Sección</th>
                            <th>Entregas</th>
                            <th>Progreso</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lista_actividades as $act): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($act['titulo']); ?></strong></td>
                                <td><?php echo ucfirst($act['tipo']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($act['fecha_entrega'])); ?></td>
                                <td><?php echo $act['grado'] . ' ' . $act['seccion']; ?></td>
                                <td><?php echo $act['total_entregas'] . '/' . $act['total_estudiantes']; ?></td>
                                <td>
                                    <div style="display: flex; gap: 4px; margin-bottom: 4px;">
                                        <span class="badge badge-pendiente" title="Pendientes"><?php echo $act['pendientes'] ?? 0; ?></span>
                                        <span class="badge badge-enviado" title="Enviados"><?php echo $act['enviados'] ?? 0; ?></span>
                                        <span class="badge badge-calificado" title="Calificados"><?php echo $act['calificados'] ?? 0; ?></span>
                                    </div>
                                    <div class="progress-bar" style="height: 4px; background: #e0e0e0;">
                                        <div class="progress-fill" style="width: <?php echo ($act['total_entregas'] / max($act['total_estudiantes'], 1)) * 100; ?>%; height: 100%; background: var(--primary-lime);"></div>
                                    </div>
                                </td>
                                <td class="action-btns">
                                    <a href="ver_actividad.php?id=<?php echo $act['id']; ?>" class="btn-icon" title="Ver entregas">👁️</a>
                                    <a href="editar_actividad.php?id=<?php echo $act['id']; ?>" class="btn-icon" title="Editar">✏️</a>
                                    <a href="#" onclick="eliminarActividad(<?php echo $act['id']; ?>)" class="btn-icon" title="Eliminar">🗑️</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="text-align: center; padding: 60px; color: var(--text-muted);">
                No has creado ninguna actividad aún. ¡Crea tu primera actividad!
            </p>
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

        function eliminarActividad(id) {
            if (confirm('¿Estás seguro de eliminar esta actividad? Se eliminarán también todas las entregas.')) {
                window.location.href = 'eliminar_actividad.php?id=' + id;
            }
        }
    </script>
</body>
</html>
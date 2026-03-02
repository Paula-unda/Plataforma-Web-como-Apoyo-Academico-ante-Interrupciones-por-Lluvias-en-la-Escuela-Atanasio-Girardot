<?php
session_start();
require_once '../../funciones.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Docente') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$actividad_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$mensaje = $_SESSION['mensaje_temporal'] ?? '';
$error = $_SESSION['error_temporal'] ?? '';
unset($_SESSION['mensaje_temporal'], $_SESSION['error_temporal']);

if ($actividad_id <= 0) {
    header('Location: gestion_actividades.php?error=ID+inválido');
    exit();
}

try {
    $conexion = getConexion();
    
    // Verificar actividad y obtener datos
    $actividad = $conexion->prepare("
        SELECT a.*, u.nombre as docente_nombre 
        FROM actividades a
        LEFT JOIN usuarios u ON a.docente_id = u.id
        WHERE a.id = ? AND a.docente_id = ?
    ");
    $actividad->execute([$actividad_id, $usuario_id]);
    $datos_actividad = $actividad->fetch(PDO::FETCH_ASSOC);
    
    if (!$datos_actividad) {
        header('Location: gestion_actividades.php?error=Actividad+no+encontrada');
        exit();
    }
    
    // Obtener entregas de estudiantes
    $entregas = $conexion->prepare("
        SELECT 
            u.id as estudiante_id,
            u.nombre,
            u.correo,
            ee.*,
            CASE 
                WHEN ee.estado IS NULL THEN 'pendiente'
                ELSE ee.estado
            END as estado_actual
        FROM usuarios u
        INNER JOIN estudiantes e ON u.id = e.usuario_id
        LEFT JOIN entregas_estudiantes ee ON u.id = ee.estudiante_id AND ee.actividad_id = ?
        WHERE u.rol = 'Estudiante' 
            AND e.grado = ? 
            AND e.seccion = ?
        ORDER BY 
            CASE 
                WHEN ee.estado = 'calificado' THEN 1
                WHEN ee.estado = 'enviado' THEN 2
                WHEN ee.estado = 'pendiente' THEN 3
                ELSE 4
            END,
            u.nombre ASC
    ");
    $entregas->execute([$actividad_id, $datos_actividad['grado'], $datos_actividad['seccion']]);
    $lista_entregas = $entregas->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener estadísticas
    $stats = $conexion->prepare("
        SELECT 
            COUNT(DISTINCT estudiante_id) as total_entregas,
            COUNT(CASE WHEN estado = 'pendiente' THEN 1 END) as pendientes,
            COUNT(CASE WHEN estado = 'enviado' THEN 1 END) as enviados,
            COUNT(CASE WHEN estado = 'calificado' THEN 1 END) as calificados,
            AVG(calificacion) as promedio_calificaciones
        FROM entregas_estudiantes
        WHERE actividad_id = ?
    ");
    $stats->execute([$actividad_id]);
    $estadisticas = $stats->fetch(PDO::FETCH_ASSOC);
    
    // Obtener contenidos vinculados
    $contenidos = $conexion->prepare("
        SELECT c.* FROM contenidos c
        INNER JOIN actividades_contenidos ac ON c.id = ac.contenido_id
        WHERE ac.actividad_id = ? AND c.activo = true
    ");
    $contenidos->execute([$actividad_id]);
    $contenidos_vinculados = $contenidos->fetchAll(PDO::FETCH_ASSOC);
    
    // Procesar calificación
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calificar'])) {
        $estudiante_id = (int)$_POST['estudiante_id'];
        $calificacion = floatval($_POST['calificacion']);
        $observaciones = trim($_POST['observaciones']);
        
        if ($calificacion >= 0 && $calificacion <= 20) {
            $update = $conexion->prepare("
                UPDATE entregas_estudiantes 
                SET calificacion = ?, observaciones = ?, estado = 'calificado'
                WHERE actividad_id = ? AND estudiante_id = ?
            ");
            $update->execute([$calificacion, $observaciones, $actividad_id, $estudiante_id]);
            
            $_SESSION['mensaje_temporal'] = 'Calificación guardada exitosamente.';
            header("Location: ver_actividad.php?id=$actividad_id");
            exit();
        } else {
            $error = 'La calificación debe estar entre 0 y 20.';
        }
    }
    
} catch (Exception $e) {
    error_log("Error en ver_actividad: " . $e->getMessage());
    header('Location: gestion_actividades.php?error=Error+al+cargar');
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ver Actividad - <?php echo htmlspecialchars($datos_actividad['titulo']); ?></title>
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
            margin-top: auto;
        }
        
        .info-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .info-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 16px;
            color: var(--text-dark);
        }
        
        .info-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 24px;
            margin-bottom: 16px;
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
        
        .meta-item strong { color: var(--text-dark); }
        
        .info-description {
            font-size: 16px;
            line-height: 1.6;
            color: var(--text-muted);
            background: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
            white-space: pre-line;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .stat-card {
            background: var(--surface);
            border-radius: 12px;
            padding: 20px;
            border-left: 4px solid;
        }
        
        .stat-card.cyan { border-left-color: var(--primary-cyan); }
        .stat-card.pink { border-left-color: var(--primary-pink); }
        .stat-card.lime { border-left-color: var(--primary-lime); }
        .stat-card.purple { border-left-color: var(--primary-purple); }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-muted);
        }
        
        .table-container {
            background: var(--surface);
            border-radius: 16px;
            padding: 24px;
            margin-top: 30px;
        }
        
        .table-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
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
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-pendiente {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-enviado {
            background: #cce5ff;
            color: #004085;
        }
        
        .badge-calificado {
            background: #d4edda;
            color: #155724;
        }
        
        .calificar-form {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
            margin-top: 8px;
        }
        
        .calificar-input {
            width: 80px;
            padding: 6px;
            border: 1px solid var(--border);
            border-radius: 4px;
            margin-right: 8px;
        }
        
        .calificar-textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: 4px;
            margin: 8px 0;
            resize: vertical;
        }
        
        .btn-primary {
            background: var(--primary-lime);
            color: var(--text-dark);
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
        }
        
        .btn-primary:hover {
            background: #acbe36;
        }
        
        .btn-secondary {
            background: var(--surface);
            color: var(--text-dark);
            border: 1px solid var(--border);
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-secondary:hover {
            background: #f0f0f0;
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
        
        .archivo-link {
            color: var(--primary-cyan);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .archivo-link:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image">
    </div>
    
    <div class="banner-content">
        <h1 class="banner-title">Detalle de Actividad</h1>
    </div>

    <main class="main-content">
        <?php if ($mensaje): ?>
            <div class="alert alert-success">✅ <?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Información de la actividad -->
        <div class="info-card">
            <h2 class="info-title"><?php echo htmlspecialchars($datos_actividad['titulo']); ?></h2>
            
            <div class="info-meta">
                <div class="meta-item">
                    <span style="color: var(--primary-cyan);">📅</span>
                    <strong>Fecha límite:</strong> <?php echo date('d/m/Y', strtotime($datos_actividad['fecha_entrega'])); ?>
                </div>
                <div class="meta-item">
                    <span style="color: var(--primary-pink);">📋</span>
                    <strong>Tipo:</strong> <?php echo ucfirst($datos_actividad['tipo']); ?>
                </div>
                <div class="meta-item">
                    <span style="color: var(--primary-lime);">🎯</span>
                    <strong>Dirigido a:</strong> <?php echo $datos_actividad['grado'] . ' ' . $datos_actividad['seccion']; ?>
                </div>
            </div>
            
            <div class="info-description">
                <?php echo nl2br(htmlspecialchars($datos_actividad['descripcion'])); ?>
            </div>
            
            <?php if (!empty($contenidos_vinculados)): ?>
                <div style="margin-top: 20px;">
                    <h3 style="font-size: 16px; margin-bottom: 12px;">📎 Contenidos vinculados:</h3>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <?php foreach ($contenidos_vinculados as $cont): ?>
                            <a href="../estudiante/contenido_detalle.php?id=<?php echo $cont['id']; ?>" 
                               style="background: #f0f0f0; padding: 6px 12px; border-radius: 20px; text-decoration: none; color: var(--text-dark); font-size: 13px;">
                                <?php echo htmlspecialchars($cont['titulo']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Estadísticas -->
        <div class="stats-grid">
            <div class="stat-card cyan">
                <div class="stat-value"><?php echo $estadisticas['total_entregas'] ?? 0; ?></div>
                <div class="stat-label">Total entregas</div>
            </div>
            <div class="stat-card pink">
                <div class="stat-value"><?php echo $estadisticas['pendientes'] ?? 0; ?></div>
                <div class="stat-label">Pendientes</div>
            </div>
            <div class="stat-card lime">
                <div class="stat-value"><?php echo $estadisticas['enviados'] ?? 0; ?></div>
                <div class="stat-label">Enviados</div>
            </div>
            <div class="stat-card purple">
                <div class="stat-value"><?php echo $estadisticas['calificados'] ?? 0; ?></div>
                <div class="stat-label">Calificados</div>
            </div>
            <div class="stat-card" style="border-left-color: var(--text-dark);">
                <div class="stat-value"><?php echo $estadisticas['promedio_calificaciones'] ? number_format($estadisticas['promedio_calificaciones'], 1) . '/20' : '—'; ?></div>
                <div class="stat-label">Promedio</div>
            </div>
        </div>

        <!-- Tabla de entregas -->
        <div class="table-container">
            <h3 class="table-title">Entregas de estudiantes</h3>
            
            <?php if (count($lista_entregas) > 0): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Estudiante</th>
                                <th>Estado</th>
                                <th>Archivo</th>
                                <th>Fecha entrega</th>
                                <th>Calificación</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lista_entregas as $est): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($est['nombre']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $est['estado_actual']; ?>">
                                            <?php echo ucfirst($est['estado_actual']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($est['archivo_entregado'])): ?>
                                            <a href="../../uploads/entregas/<?php echo $est['archivo_entregado']; ?>" 
                                               class="archivo-link" target="_blank">
                                                📄 Ver archivo
                                            </a>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if ($est['fecha_entrega']) {
                                            echo date('d/m/Y H:i', strtotime($est['fecha_entrega']));
                                        } else {
                                            echo '<span style="color: var(--text-muted);">—</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($est['calificacion'] !== null): ?>
                                            <strong><?php echo number_format($est['calificacion'], 1); ?>/20</strong>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($est['estado_actual'] === 'enviado' || $est['estado_actual'] === 'calificado'): ?>
                                            <button class="btn-primary" onclick="mostrarCalificar(<?php echo $est['estudiante_id']; ?>)">
                                                Calificar
                                            </button>
                                            
                                            <div id="calificar-<?php echo $est['estudiante_id']; ?>" style="display: none;" class="calificar-form">
                                                <form method="POST">
                                                    <input type="hidden" name="estudiante_id" value="<?php echo $est['estudiante_id']; ?>">
                                                    <input type="hidden" name="calificar" value="1">
                                                    
                                                    <div style="margin-bottom: 8px;">
                                                        <label>Calificación (0-20):</label>
                                                        <input type="number" name="calificacion" step="0.1" min="0" max="20" 
                                                               value="<?php echo $est['calificacion'] ?? ''; ?>" 
                                                               class="calificar-input" required>
                                                    </div>
                                                    
                                                    <div style="margin-bottom: 8px;">
                                                        <label>Observaciones / Retroalimentación:</label>
                                                        <textarea name="observaciones" rows="3" class="calificar-textarea"><?php echo htmlspecialchars($est['observaciones'] ?? ''); ?></textarea>
                                                    </div>
                                                    
                                                    <div style="display: flex; gap: 8px;">
                                                        <button type="submit" class="btn-primary">Guardar</button>
                                                        <button type="button" class="btn-secondary" onclick="ocultarCalificar(<?php echo $est['estudiante_id']; ?>)">Cancelar</button>
                                                    </div>
                                                </form>
                                            </div>
                                        <?php endif; ?>
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

        <!-- Botones de acción -->
        <div style="display: flex; gap: 16px; justify-content: center; margin-top: 30px;">
            <a href="gestion_actividades.php" class="btn-secondary">← Volver a actividades</a>
            <a href="editar_actividad.php?id=<?php echo $actividad_id; ?>" class="btn-secondary">✏️ Editar actividad</a>
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

        function mostrarCalificar(estudianteId) {
            document.getElementById('calificar-' + estudianteId).style.display = 'block';
        }

        function ocultarCalificar(estudianteId) {
            document.getElementById('calificar-' + estudianteId).style.display = 'none';
        }
    </script>
</body>
</html>
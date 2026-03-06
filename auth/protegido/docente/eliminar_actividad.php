<?php
session_start();
require_once '../../funciones.php';

// Verificar que sea docente
if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Docente') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$actividad_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($actividad_id <= 0) {
    $_SESSION['error_temporal'] = 'ID de actividad no válido.';
    header('Location: gestion_actividades.php');
    exit();
}

try {
    $conexion = getConexion();
    
    // Verificar que la actividad pertenezca al docente
    $check = $conexion->prepare("
        SELECT id, titulo, fecha_entrega 
        FROM actividades 
        WHERE id = ? AND docente_id = ?
    ");
    $check->execute([$actividad_id, $usuario_id]);
    $actividad = $check->fetch(PDO::FETCH_ASSOC);
    
    if (!$actividad) {
        $_SESSION['error_temporal'] = 'Actividad no encontrada o no te pertenece.';
        header('Location: gestion_actividades.php');
        exit();
    }
    
    // Verificar si hay entregas asociadas
    $entregas = $conexion->prepare("
        SELECT COUNT(*) as total 
        FROM entregas_estudiantes 
        WHERE actividad_id = ?
    ");
    $entregas->execute([$actividad_id]);
    $total_entregas = $entregas->fetch(PDO::FETCH_ASSOC)['total'];
    
} catch (Exception $e) {
    error_log("Error al cargar actividad: " . $e->getMessage());
    $_SESSION['error_temporal'] = 'Error al cargar la actividad.';
    header('Location: gestion_actividades.php');
    exit();
}

// Procesar confirmación de eliminación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar'])) {
    try {
        $conexion->beginTransaction();
        
        // Obtener archivos de entregas para eliminarlos (si es necesario)
        $archivos = $conexion->prepare("
            SELECT archivo_entregado FROM entregas_estudiantes 
            WHERE actividad_id = ? AND archivo_entregado IS NOT NULL
        ");
        $archivos->execute([$actividad_id]);
        $lista_archivos = $archivos->fetchAll(PDO::FETCH_ASSOC);
        
        // Eliminar relaciones con contenidos
        $delete_relaciones = $conexion->prepare("DELETE FROM actividades_contenidos WHERE actividad_id = ?");
        $delete_relaciones->execute([$actividad_id]);
        
        // Eliminar entregas de estudiantes
        $delete_entregas = $conexion->prepare("DELETE FROM entregas_estudiantes WHERE actividad_id = ?");
        $delete_entregas->execute([$actividad_id]);
        
        // Eliminar la actividad
        $delete_actividad = $conexion->prepare("DELETE FROM actividades WHERE id = ? AND docente_id = ?");
        $delete_actividad->execute([$actividad_id, $usuario_id]);
        
        $conexion->commit();
        
        // Eliminar archivos físicos (opcional)
        foreach ($lista_archivos as $archivo) {
            if (!empty($archivo['archivo_entregado'])) {
                $ruta = '../../uploads/entregas/' . $archivo['archivo_entregado'];
                if (file_exists($ruta)) {
                    unlink($ruta);
                }
            }
        }
        
        $_SESSION['mensaje_temporal'] = 'Actividad eliminada correctamente.';
        header('Location: gestion_actividades.php');
        exit();
        
    } catch (Exception $e) {
        $conexion->rollBack();
        error_log("Error al eliminar actividad: " . $e->getMessage());
        $error = 'Error al eliminar la actividad.';
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eliminar Actividad - SIEDUCRES</title>
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
            --danger: #EF5E8E;
            --warning: #ffc107;
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

        .icon-btn img {
            width: 20px;
            height: 20px;
            object-fit: contain;
        }
        
        .icon-btn:hover {
            background-color: #E0E0E0;
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
        }
        
        .menu-item:hover {
            background-color: #F8F8F8;
        }
        
        .banner {
            height: 100px;
            overflow: hidden;
            position: relative;
        }
        
        .banner-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .banner-content {
            text-align: center;
            padding: 20px;
            max-width: 800px;
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
            max-width: 600px;
            margin: 40px auto;
            padding: 0 20px;
            width: 100%;
        }
        
        .delete-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border-left: 4px solid var(--danger);
            text-align: center;
        }
        
        .warning-icon {
            font-size: 64px;
            color: var(--danger);
            margin-bottom: 20px;
        }
        
        .delete-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 16px;
        }
        
        .delete-message {
            font-size: 16px;
            color: var(--text-muted);
            margin-bottom: 24px;
            line-height: 1.6;
        }
        
        .activity-info {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin: 24px 0;
            text-align: left;
            border: 1px solid var(--border);
        }
        
        .info-item {
            margin-bottom: 12px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        
        .info-label {
            font-weight: 600;
            min-width: 100px;
            color: var(--text-dark);
        }
        
        .info-value {
            color: var(--text-muted);
            flex: 1;
        }
        
        .warning-box {
            background: rgba(239, 94, 142, 0.1);
            border: 1px solid var(--danger);
            border-radius: 8px;
            padding: 16px;
            margin: 20px 0;
            color: var(--danger);
            font-weight: 600;
        }
        
        .action-buttons {
            display: flex;
            gap: 16px;
            margin-top: 32px;
            justify-content: center;
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
            padding: 14px 32px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            font-size: 16px;
        }
        
        .btn-danger:hover {
            background: #d64a7a;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 94, 142, 0.3);
        }
        
        .btn-secondary {
            background: #f0f0f0;
            color: var(--text-dark);
            padding: 14px 32px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            font-size: 16px;
        }
        
        .btn-secondary:hover {
            background: #e0e0e0;
            transform: translateY(-2px);
        }
        
        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
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
        
        @media (max-width: 768px) {
            .banner-title { font-size: 28px; }
            .delete-card { padding: 24px; }
            .action-buttons { flex-direction: column; }
            .btn-danger, .btn-secondary { width: 100%; text-align: center; }
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
        <img src="../../../assets/banner-top.svg" alt="Banner" class="banner-image">
    </div>
    
    <div class="banner-content">
        <h1 class="banner-title">Eliminar Actividad</h1>
    </div>

    <main class="main-content">
        <?php if (isset($error)): ?>
            <div class="alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="delete-card">
            <div class="warning-icon">⚠️</div>
            
            <h2 class="delete-title">¿Eliminar esta actividad?</h2>
            
            <p class="delete-message">
                Esta acción <strong>NO SE PUEDE DESHACER</strong> y eliminará permanentemente:
            </p>
            
            <div class="activity-info">
                <div class="info-item">
                    <span class="info-label">Actividad:</span>
                    <span class="info-value"><strong><?php echo htmlspecialchars($actividad['titulo']); ?></strong></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Fecha límite:</span>
                    <span class="info-value"><?php echo date('d/m/Y', strtotime($actividad['fecha_entrega'])); ?></span>
                </div>
                <?php if ($total_entregas > 0): ?>
                <div class="info-item">
                    <span class="info-label">Entregas:</span>
                    <span class="info-value"><?php echo $total_entregas; ?> estudiante(s) han entregado esta actividad</span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($total_entregas > 0): ?>
                <div class="warning-box">
                    ⚠️ Esta actividad tiene <?php echo $total_entregas; ?> entrega(s) de estudiantes.
                    <br><small>Todos los archivos y calificaciones asociados también serán eliminados.</small>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="confirmar" value="1">
                
                <div class="action-buttons">
                    <a href="gestion_actividades.php" class="btn-secondary">Cancelar</a>
                    <button type="submit" class="btn-danger" onclick="return confirm('¿Estás absolutamente seguro? Esta acción es irreversible.');">
                        🗑️ Sí, eliminar actividad
                    </button>
                </div>
            </form>
        </div>
    </main>

    <footer class="footer">
        <span>v2.0.0</span>
        <span>Soporte Técnico</span>
    </footer>

    <script>
        // Menú hamburguesa
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
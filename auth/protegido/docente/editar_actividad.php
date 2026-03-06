<?php
session_start();
require_once '../../funciones.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Docente') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$error = '';
$actividad_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($actividad_id <= 0) {
    header('Location: gestion_actividades.php?error=ID+inválido');
    exit();
}

try {
    $conexion = getConexion();
    
    // Obtener grado y sección del docente
    $stmt_docente = $conexion->prepare("SELECT grado, seccion FROM docentes WHERE usuario_id = ?");
    $stmt_docente->execute([$usuario_id]);
    $docente = $stmt_docente->fetch(PDO::FETCH_ASSOC);
    
    if (!$docente) {
        header('Location: gestion_actividades.php?error=Docente+sin+grado+asignado');
        exit();
    }
    
    // Obtener datos de la actividad
    $stmt_actividad = $conexion->prepare("
        SELECT * FROM actividades 
        WHERE id = ? AND docente_id = ?
    ");
    $stmt_actividad->execute([$actividad_id, $usuario_id]);
    $actividad = $stmt_actividad->fetch(PDO::FETCH_ASSOC);
    
    if (!$actividad) {
        header('Location: gestion_actividades.php?error=Actividad+no+encontrada');
        exit();
    }
    
    // Obtener contenidos vinculados a esta actividad
    $stmt_vinculados = $conexion->prepare("
        SELECT contenido_id FROM actividades_contenidos 
        WHERE actividad_id = ?
    ");
    $stmt_vinculados->execute([$actividad_id]);
    $vinculados = $stmt_vinculados->fetchAll(PDO::FETCH_COLUMN);
    
    // Obtener todos los contenidos del docente
    $contenidos = $conexion->prepare("
        SELECT id, titulo, asignatura FROM contenidos 
        WHERE docente_id = ? AND activo = true
        ORDER BY fecha_publicacion DESC
    ");
    $contenidos->execute([$usuario_id]);
    $lista_contenidos = $contenidos->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error al cargar datos: " . $e->getMessage());
    header('Location: gestion_actividades.php?error=Error+al+cargar');
    exit();
}

// Procesar formulario de edición
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo = trim($_POST['titulo'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $tipo = $_POST['tipo'] ?? '';
    $fecha_entrega = $_POST['fecha_entrega'] ?? '';
    $contenidos_seleccionados = $_POST['contenidos'] ?? [];
    
    if (empty($titulo) || empty($descripcion) || empty($tipo) || empty($fecha_entrega)) {
        $error = 'Todos los campos son obligatorios.';
    } else {
        try {
            $conexion->beginTransaction();
            
            // Actualizar actividad
            $update = $conexion->prepare("
                UPDATE actividades 
                SET titulo = ?, descripcion = ?, tipo = ?, fecha_entrega = ?
                WHERE id = ? AND docente_id = ?
            ");
            $update->execute([
                $titulo, $descripcion, $tipo, $fecha_entrega,
                $actividad_id, $usuario_id
            ]);
            
            // Eliminar vinculaciones anteriores
            $delete = $conexion->prepare("DELETE FROM actividades_contenidos WHERE actividad_id = ?");
            $delete->execute([$actividad_id]);
            
            // Insertar nuevas vinculaciones
            if (!empty($contenidos_seleccionados)) {
                $link = $conexion->prepare("
                    INSERT INTO actividades_contenidos (actividad_id, contenido_id)
                    VALUES (?, ?)
                ");
                foreach ($contenidos_seleccionados as $cid) {
                    $link->execute([$actividad_id, $cid]);
                }
            }
            
            $conexion->commit();
            
            $_SESSION['mensaje_temporal'] = 'Actividad actualizada exitosamente.';
            header('Location: gestion_actividades.php');
            exit();
            
        } catch (Exception $e) {
            $conexion->rollBack();
            $error = 'Error al actualizar la actividad.';
            error_log("Error editar actividad: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Actividad - SIEDUCRES</title>
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
        body { font-family: 'Inter', sans-serif; background-color: var(--background); color: var(--text-dark); }
        
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
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }
        
        .form-container {
            background: var(--surface);
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-left: 4px solid var(--primary-purple);
        }
        
        .form-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 24px;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-dark);
        }
        
        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-cyan);
        }
        
        .btn-primary {
            background: var(--primary-lime);
            color: var(--text-dark);
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: all 0.2s;
        }
        
        .btn-primary:hover {
            background: #acbe36;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #f0f0f0;
            border: 1px solid var(--border);
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-dark);
            display: inline-block;
            margin-top: 16px;
            transition: all 0.2s;
        }
        
        .btn-secondary:hover {
            background: #e0e0e0;
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
            margin-top: 40px;
        }
        
        .contenido-checkbox {
            margin: 8px 0;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px;
            border-radius: 4px;
            transition: background 0.2s;
        }
        
        .contenido-checkbox:hover {
            background: #f0f0f0;
        }
        
        .contenido-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .info-box {
            background: #e8f4fd;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            border-left: 4px solid var(--primary-cyan);
        }
        
        .text-muted {
            color: var(--text-muted);
            font-size: 12px;
        }
        
        .action-buttons {
            display: flex;
            gap: 16px;
            margin-top: 24px;
        }
        
        @media (max-width: 768px) {
            .banner-title { font-size: 28px; }
            .form-container { padding: 20px; }
            .action-buttons { flex-direction: column; }
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
        <h1 class="banner-title">Editar Actividad</h1>
    </div>

    <main class="main-content">
        <div class="form-container">
            <?php if ($error): ?>
                <div class="alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="form-title">
                <span>✏️ Editando:</span>
                <span style="color: var(--primary-purple); font-size: 18px;"><?php echo htmlspecialchars($actividad['titulo']); ?></span>
            </div>

            <form method="POST">
                <div class="form-group">
                    <label class="form-label">Título de la actividad</label>
                    <input type="text" name="titulo" class="form-control" 
                           value="<?php echo htmlspecialchars($actividad['titulo']); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Tipo de actividad</label>
                    <select name="tipo" class="form-control" required>
                        <option value="">Seleccionar tipo</option>
                        <option value="tarea" <?php echo $actividad['tipo'] === 'tarea' ? 'selected' : ''; ?>>📝 Tarea</option>
                        <option value="examen" <?php echo $actividad['tipo'] === 'examen' ? 'selected' : ''; ?>>📋 Examen</option>
                        <option value="indicacion" <?php echo $actividad['tipo'] === 'indicacion' ? 'selected' : ''; ?>>📌 Indicación</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Fecha de entrega</label>
                    <input type="date" name="fecha_entrega" class="form-control" 
                           value="<?php echo htmlspecialchars($actividad['fecha_entrega']); ?>" 
                           required min="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Descripción / Instrucciones</label>
                    <textarea name="descripcion" class="form-control" rows="8" required><?php echo htmlspecialchars($actividad['descripcion']); ?></textarea>
                </div>

                <?php if (!empty($lista_contenidos)): ?>
                <div class="info-box">
                    <h3 style="font-size: 16px; margin-bottom: 8px;">📎 Vincular a contenidos</h3>
                    <p class="text-muted">Selecciona los contenidos relacionados con esta actividad</p>
                </div>
                
                <div class="form-group">
                    <div style="max-height: 250px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px; padding: 12px;">
                        <?php foreach ($lista_contenidos as $cont): ?>
                            <div class="contenido-checkbox">
                                <input type="checkbox" name="contenidos[]" value="<?php echo $cont['id']; ?>" 
                                       id="cont_<?php echo $cont['id']; ?>"
                                       <?php echo in_array($cont['id'], $vinculados) ? 'checked' : ''; ?>>
                                <label for="cont_<?php echo $cont['id']; ?>">
                                    <?php echo htmlspecialchars($cont['titulo']); ?> 
                                    <span style="color: var(--text-muted); font-size: 12px;">
                                        (<?php echo htmlspecialchars($cont['asignatura']); ?>)
                                    </span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-muted" style="margin-top: 8px;">
                        ℹ️ Puedes seleccionar múltiples contenidos
                    </p>
                </div>
                <?php endif; ?>

                <div class="action-buttons">
                    <button type="submit" class="btn-primary">💾 Guardar cambios</button>
                    <a href="gestion_actividades.php" class="btn-secondary" style="text-align: center;">Cancelar</a>
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
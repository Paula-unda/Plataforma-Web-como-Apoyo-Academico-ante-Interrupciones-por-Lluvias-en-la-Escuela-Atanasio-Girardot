<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/onesignal_config.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Docente') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$error = '';

// 🔴 PRIMERO: Obtener conexión
try {
    $conexion = getConexion();
} catch (Exception $e) {
    error_log("Error de conexión: " . $e->getMessage());
    header('Location: gestion_actividades.php?error=Error+de+conexión');
    exit();
}

// 🔴 SEGUNDO: Obtener grado y sección del docente (AHORA $conexion YA EXISTE)
$grado_docente = '';
$seccion_docente = '';

$stmt_docente = $conexion->prepare("SELECT grado, seccion FROM docentes WHERE usuario_id = ?");
$stmt_docente->execute([$usuario_id]);
$docente = $stmt_docente->fetch(PDO::FETCH_ASSOC);

if ($docente) {
    $grado_docente = $docente['grado'];
    $seccion_docente = $docente['seccion'];
} else {
    $_SESSION['error'] = 'No tienes un grado y sección asignados. Contacta al administrador.';
    header('Location: gestion_actividades.php');
    exit();
}

// 🔴 TERCERO: Obtener contenidos del docente (AHORA $docente YA EXISTE)
try {
    $contenidos = $conexion->prepare("
        SELECT id, titulo, asignatura FROM contenidos 
        WHERE docente_id = ? AND activo = true
        ORDER BY fecha_publicacion DESC
    ");
    $contenidos->execute([$usuario_id]);
    $lista_contenidos = $contenidos->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error al cargar contenidos: " . $e->getMessage());
    $lista_contenidos = [];
}


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
            
            // Insertar actividad
            $insert = $conexion->prepare("
                INSERT INTO actividades (titulo, descripcion, tipo, fecha_entrega, docente_id, grado, seccion)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                RETURNING id
            ");
            $insert->execute([
                $titulo, $descripcion, $tipo, $fecha_entrega, 
                $usuario_id, $grado_docente, $seccion_docente
            ]);
            
            $actividad_id = $insert->fetchColumn();
            
            // Vincular contenidos seleccionados
            if (!empty($contenidos_seleccionados)) {
                $link = $conexion->prepare("
                    INSERT INTO actividades_contenidos (actividad_id, contenido_id)
                    VALUES (?, ?)
                ");
                foreach ($contenidos_seleccionados as $cid) {
                    $link->execute([$actividad_id, $cid]);
                }  
            }
            // Después de insertar la actividad
            $actividad_id = $conexion->lastInsertId();

            // Obtener estudiantes del grado/sección
            $stmt_est = $conexion->prepare("
                SELECT u.id FROM estudiantes e
                JOIN usuarios u ON e.usuario_id = u.id
                WHERE e.grado = ? AND e.seccion = ?
            ");
            $stmt_est->execute([$grado_docente, $seccion_docente]);  // ✅ CORRECTO
            $estudiantes = $stmt_est->fetchAll(PDO::FETCH_COLUMN);

            require_once '../includes/notificaciones_funciones.php';

            foreach ($estudiantes as $estudiante_id) {
                // 1. NOTIFICAR AL ESTUDIANTE
                enviarNotificacion(
                    $conexion,
                    $estudiante_id,
                    "📚 Nueva actividad: " . $titulo,
                    "Tienes una nueva actividad para entregar antes del " . $fecha_entrega,
                    'actividad',
                    $actividad_id,
                    'actividades'
                );
                
                // 2. NOTIFICAR A SUS REPRESENTANTES
                $stmt_rep = $conexion->prepare("
                    SELECT representante_id FROM representantes_estudiantes WHERE estudiante_id = ?
                ");
                $stmt_rep->execute([$estudiante_id]);
                $representantes = $stmt_rep->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($representantes as $rep_id) {
                    enviarNotificacion(
                        $conexion,
                        $rep_id,
                        "📚 Nueva actividad para tu representado",
                        "Tu representado tiene una nueva actividad: " . $titulo,
                        'actividad',
                        $actividad_id,
                        'actividades'
                    );
                }
            }
            
            $conexion->commit();
            
            $_SESSION['mensaje_temporal'] = 'Actividad creada exitosamente.';
            header('Location: gestion_actividades.php');
            exit();
            
        } catch (Exception $e) {
            $conexion->rollBack();
            $error = 'Error al crear la actividad.';
            error_log("Error crear actividad: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Actividad - SIEDUCRES</title>
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
        body { font-family: 'Inter', sans-serif; background-color: var(--background); color: var(--text-dark); }
        .header { display: flex; justify-content: space-between; align-items: center; padding: 0 24px; height: 60px; background-color: var(--surface); border-bottom: 1px solid var(--border); }
        .logo { height: 40px; }
        .header-right { display: flex; align-items: center; gap: 16px; }
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; background-color: #F0F0F0; display: flex; align-items: center; justify-content: center; }
        .banner { height: 100px; overflow: hidden; }
        .banner-image { width: 100%; height: 100%; object-fit: cover; }
        .banner-content { text-align: center; padding: 20px; }
        .banner-title { font-size: 36px; font-weight: 700; }
        .main-content { max-width: 800px; margin: 40px auto; padding: 0 20px; }
        .form-container { background: var(--surface); border-radius: 16px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .form-title { font-size: 24px; font-weight: 700; margin-bottom: 24px; }
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-weight: 600; margin-bottom: 8px; }
        .form-control { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; font-family: 'Inter', sans-serif; }
        .btn-primary { background: var(--primary-lime); color: var(--text-dark); padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; width: 100%; }
        .btn-primary:hover { background: #acbe36; }
        .btn-secondary { background: #f0f0f0; border: 1px solid var(--border); padding: 10px 20px; border-radius: 8px; text-decoration: none; color: var(--text-dark); display: inline-block; margin-top: 16px; }
        .alert-error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .footer { height: 50px; background-color: var(--surface); border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; padding: 0 24px; margin-top: 40px; }
        .contenido-checkbox { margin: 8px 0; display: flex; align-items: center; gap: 8px; }
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
    </style>
</head>
<body>
    <?php require_once '../includes/header_comun.php'; ?>

    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner" class="banner-image">
    </div>
    
    <div class="banner-content">
        <h1 class="banner-title">Crear Nueva Actividad</h1>
    </div>

    <main class="main-content">
        <div class="form-container">
            <?php if ($error): ?>
                <div class="alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label class="form-label">Título de la actividad</label>
                    <input type="text" name="titulo" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Tipo de actividad</label>
                    <select name="tipo" class="form-control" required>
                        <option value="">Seleccionar tipo</option>
                        <option value="tarea">📝 Tarea</option>
                        <option value="indicacion">📌 Indicación</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Fecha de entrega</label>
                    <input type="date" name="fecha_entrega" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Descripción / Instrucciones</label>
                    <textarea name="descripcion" class="form-control" rows="6" required></textarea>
                </div>

                <?php if (!empty($lista_contenidos)): ?>
                <div class="form-group">
                    <label class="form-label">Vincular a contenidos (opcional)</label>
                    <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 8px;">
                        Selecciona los contenidos relacionados con esta actividad
                    </p>
                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px; padding: 12px;">
                        <?php foreach ($lista_contenidos as $cont): ?>
                            <div class="contenido-checkbox">
                                <input type="checkbox" name="contenidos[]" value="<?php echo $cont['id']; ?>" id="cont_<?php echo $cont['id']; ?>">
                                <label for="cont_<?php echo $cont['id']; ?>">
                                    <?php echo htmlspecialchars($cont['titulo']); ?> 
                                    <span style="color: var(--text-muted);">(<?php echo htmlspecialchars($cont['asignatura']); ?>)</span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn-primary">Crear Actividad</button>
                <a href="gestion_actividades.php" class="btn-secondary" style="display: block; text-align: center;">Cancelar</a>
            </form>
        </div>
    </main>

    <footer class="footer">
        <span>v2.0.0</span>
        <span>Soporte Técnico</span>
    </footer>
</body>
</html>
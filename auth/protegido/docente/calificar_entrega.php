<?php
session_start();
require_once '../../../funciones.php';
require_once '../includes/onesignal_config.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Docente') {
    header('Location: ../../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$actividad_id = isset($_GET['actividad_id']) ? (int)$_GET['actividad_id'] : 0;
$estudiante_id = isset($_GET['estudiante_id']) ? (int)$_GET['estudiante_id'] : 0;

if ($actividad_id <= 0 || $estudiante_id <= 0) {
    header('Location: gestionar_actividades.php?error=Datos+inválidos');
    exit();
}

$mensaje = '';
$error = '';

// Procesar calificación
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $calificacion = isset($_POST['calificacion']) ? floatval($_POST['calificacion']) : 0;
        $observaciones = isset($_POST['observaciones']) ? trim($_POST['observaciones']) : '';
        
        if ($calificacion < 0 || $calificacion > 20) {
            throw new Exception('La calificación debe estar entre 0 y 20');
        }
        
        $conexion = getConexion();
        
        // Actualizar entrega
        $query = "
            UPDATE entregas_estudiantes 
            SET 
                calificacion = :calificacion,
                observaciones = :observaciones,
                estado = 'calificado'
            WHERE actividad_id = :actividad_id AND estudiante_id = :estudiante_id
        ";
        
        $stmt = $conexion->prepare($query);
        $stmt->execute([
            ':calificacion' => $calificacion,
            ':observaciones' => $observaciones,
            ':actividad_id' => $actividad_id,
            ':estudiante_id' => $estudiante_id
        ]);
        // Después de guardar la calificación
        require_once '../includes/notificaciones_funciones.php';

        // 1. NOTIFICAR AL ESTUDIANTE
        enviarNotificacion(
            $conexion,
            $estudiante_id,
            "📊 Actividad calificada",
            "Tu actividad '$titulo_actividad' fue calificada con $calificacion/20",
            'calificacion',
            $entrega_id,
            'entregas'
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
                "📊 Actividad calificada",
                "Tu representado obtuvo $calificacion/20 en '$titulo_actividad'",
                'calificacion',
                $entrega_id,
                'entregas'
            );
        }
        $mensaje = 'Calificación guardada exitosamente';
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Obtener datos de la entrega
$conexion = getConexion();
$query = "
    SELECT e.*, u.nombre as estudiante_nombre, a.titulo as actividad_titulo
    FROM entregas_estudiantes e
    INNER JOIN estudiantes est ON e.estudiante_id = est.usuario_id
    INNER JOIN usuarios u ON est.usuario_id = u.id
    INNER JOIN actividades a ON e.actividad_id = a.id
    WHERE e.actividad_id = :actividad_id AND e.estudiante_id = :estudiante_id
";
$stmt = $conexion->prepare($query);
$stmt->execute([
    ':actividad_id' => $actividad_id,
    ':estudiante_id' => $estudiante_id
]);
$entrega = $stmt->fetch();

if (!$entrega) {
    header('Location: gestionar_actividades.php?error=Entrega+no+encontrada');
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calificar Entrega - SIEDUCRES</title>
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
            --success: #C2D54E;
            --danger: #EF5E8E;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--background); color: var(--text-dark); min-height: 100vh; }
        .container { max-width: 800px; margin: 40px auto; padding: 0 20px; }
        .card { background: var(--surface); border-radius: 16px; padding: 32px; box-shadow: 0 6px 16px rgba(0,0,0,0.05); }
        .card-title { font-size: 24px; font-weight: 700; margin-bottom: 24px; color: var(--text-dark); }
        .info-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid var(--border); }
        .info-label { font-weight: 600; color: var(--text-muted); }
        .info-value { font-weight: 500; }
        .form-group { margin: 20px 0; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 8px; }
        .form-control { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; font-family: 'Inter', sans-serif; }
        .btn { padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: var(--primary-cyan); color: white; }
        .btn-primary:hover { background: #3ab3d6; }
        .btn-secondary { background: var(--surface); color: var(--text-dark); border: 1px solid var(--border); }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: rgba(40,167,69,0.1); color: var(--success); }
        .alert-error { background: rgba(220,53,69,0.1); color: var(--danger); }
        .archivo-link { color: var(--primary-cyan); text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1 class="card-title"> Calificar Entrega</h1>
            
            <?php if ($mensaje): ?>
                <div class="alert alert-success"><?php echo $mensaje; ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="info-row">
                <span class="info-label">Estudiante:</span>
                <span class="info-value"><?php echo htmlspecialchars($entrega['estudiante_nombre']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Actividad:</span>
                <span class="info-value"><?php echo htmlspecialchars($entrega['actividad_titulo']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Fecha de entrega:</span>
                <span class="info-value"><?php echo date('d/m/Y H:i', strtotime($entrega['fecha_entrega'])); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Archivo entregado:</span>
                <span class="info-value">
                    <?php if (!empty($entrega['archivo_entregado'])): ?>
                        <a href="../../../uploads/entregas/<?php echo htmlspecialchars($entrega['archivo_entregado']); ?>" class="archivo-link" target="_blank">
                            📎 <?php echo basename($entrega['archivo_entregado']); ?>
                        </a>
                    <?php else: ?>
                        Sin archivo
                    <?php endif; ?>
                </span>
            </div>
            <?php if (!empty($entrega['comentario'])): ?>
            <div class="info-row">
                <span class="info-label">Comentario del estudiante:</span>
                <span class="info-value"><?php echo htmlspecialchars($entrega['comentario']); ?></span>
            </div>
            <?php endif; ?>
            
            <form method="POST" style="margin-top: 24px;">
                <div class="form-group">
                    <label for="calificacion">Calificación (0-20):</label>
                    <input type="number" id="calificacion" name="calificacion" class="form-control" 
                           min="0" max="20" step="0.01" 
                           value="<?php echo htmlspecialchars($entrega['calificacion'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="observaciones">Retroalimentación:</label>
                    <textarea id="observaciones" name="observaciones" class="form-control" rows="4"><?php echo htmlspecialchars($entrega['observaciones'] ?? ''); ?></textarea>
                </div>
                
                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="submit" class="btn btn-primary">💾 Guardar Calificación</button>
                    <a href="gestionar_actividades.php" class="btn btn-secondary">← Volver</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
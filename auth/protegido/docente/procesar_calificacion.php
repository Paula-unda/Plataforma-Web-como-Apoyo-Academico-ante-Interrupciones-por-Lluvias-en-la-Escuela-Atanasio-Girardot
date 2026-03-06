<?php
session_start();
require_once '../../funciones.php';

// Verificar que sea docente
if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Docente') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: calificaciones.php');
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$actividad_id = isset($_POST['actividad_id']) ? (int)$_POST['actividad_id'] : 0;
$estudiante_id = isset($_POST['estudiante_id']) ? (int)$_POST['estudiante_id'] : 0;
$calificacion = isset($_POST['calificacion']) ? floatval($_POST['calificacion']) : -1;
$observaciones = trim($_POST['observaciones'] ?? '');

// Validaciones básicas
if ($actividad_id <= 0 || $estudiante_id <= 0 || $calificacion < 0 || $calificacion > 20) {
    $_SESSION['error_temporal'] = 'Datos inválidos para calificar.';
    header('Location: calificaciones.php' . ($estudiante_id > 0 ? '?estudiante=' . $estudiante_id : ''));
    exit();
}

try {
    $conexion = getConexion();
    
    // Verificar que la actividad pertenezca al docente
    $check_actividad = $conexion->prepare("
        SELECT id, titulo FROM actividades 
        WHERE id = ? AND docente_id = ?
    ");
    $check_actividad->execute([$actividad_id, $usuario_id]);
    $actividad = $check_actividad->fetch(PDO::FETCH_ASSOC);
    
    if (!$actividad) {
        $_SESSION['error_temporal'] = 'No tienes permiso para calificar esta actividad.';
        header('Location: calificaciones.php' . ($estudiante_id > 0 ? '?estudiante=' . $estudiante_id : ''));
        exit();
    }
    
    // Verificar que el estudiante existe
    $check_estudiante = $conexion->prepare("
        SELECT id, nombre FROM usuarios WHERE id = ? AND rol = 'Estudiante'
    ");
    $check_estudiante->execute([$estudiante_id]);
    $estudiante = $check_estudiante->fetch(PDO::FETCH_ASSOC);
    
    if (!$estudiante) {
        $_SESSION['error_temporal'] = 'Estudiante no válido.';
        header('Location: calificaciones.php');
        exit();
    }
    
    // Verificar si ya existe una entrega
    $check_entrega = $conexion->prepare("
        SELECT id FROM entregas_estudiantes 
        WHERE actividad_id = ? AND estudiante_id = ?
    ");
    $check_entrega->execute([$actividad_id, $estudiante_id]);
    $entrega_existente = $check_entrega->fetch();
    
    if ($entrega_existente) {
        // Actualizar entrega existente
        $update = $conexion->prepare("
            UPDATE entregas_estudiantes 
            SET calificacion = ?, observaciones = ?, estado = 'calificado'
            WHERE actividad_id = ? AND estudiante_id = ?
        ");
        $resultado = $update->execute([$calificacion, $observaciones, $actividad_id, $estudiante_id]);
        
        if ($resultado) {
            $_SESSION['mensaje_temporal'] = 'Calificación actualizada correctamente.';
        } else {
            throw new Exception('Error al actualizar la calificación');
        }
    } else {
        // Crear nueva entrega con calificación
        $insert = $conexion->prepare("
            INSERT INTO entregas_estudiantes 
            (actividad_id, estudiante_id, calificacion, observaciones, estado, fecha_entrega)
            VALUES (?, ?, ?, ?, 'calificado', CURRENT_TIMESTAMP)
        ");
        $resultado = $insert->execute([$actividad_id, $estudiante_id, $calificacion, $observaciones]);
        
        if ($resultado) {
            $_SESSION['mensaje_temporal'] = 'Calificación guardada correctamente.';
        } else {
            throw new Exception('Error al guardar la calificación');
        }
    }
    
    // Redirigir de vuelta a la vista del estudiante
    header('Location: calificaciones.php?estudiante=' . $estudiante_id);
    exit();
    
} catch (Exception $e) {
    error_log("Error al calificar: " . $e->getMessage());
    $_SESSION['error_temporal'] = 'Error al guardar la calificación.';
    header('Location: calificaciones.php' . ($estudiante_id > 0 ? '?estudiante=' . $estudiante_id : ''));
    exit();
}
?>
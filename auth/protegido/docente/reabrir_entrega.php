<?php
session_start();
require_once '../../funciones.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Docente') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$conexion = getConexion();
$docente_id = $_SESSION['usuario_id'];
$entrega_id = isset($_GET['entrega_id']) ? (int)$_GET['entrega_id'] : 0;
$actividad_id = isset($_GET['actividad_id']) ? (int)$_GET['actividad_id'] : 0;

if ($entrega_id <= 0 || $actividad_id <= 0) {
    header('Location: gestion_actividades.php?error=Solicitud+inválida');
    exit();
}

// Verificar que esta entrega pertenece a una actividad de este docente
$stmt_check = $conexion->prepare("
    SELECT ee.id 
    FROM entregas_estudiantes ee
    INNER JOIN actividades a ON ee.actividad_id = a.id
    WHERE ee.id = ? AND a.docente_id = ?
");
$stmt_check->execute([$entrega_id, $docente_id]);

if (!$stmt_check->fetch()) {
    header('Location: gestion_actividades.php?error=No+tienes+permiso+para+reabrir+esta+entrega');
    exit();
}

try {
    // 🔴 CAMBIAR ESTADO A 'pendiente', ELIMINAR CALIFICACIÓN Y RESETEAR FECHA
    $stmt_update = $conexion->prepare("
        UPDATE entregas_estudiantes 
        SET estado = 'pendiente', 
            calificacion = NULL, 
            observaciones = NULL,
            fecha_entrega = NULL  /* ← ESTO ES CLAVE */
        WHERE id = ?
    ");
    $stmt_update->execute([$entrega_id]);

    $_SESSION['mensaje_temporal'] = 'Entrega reabierta correctamente. El estudiante ya puede modificar su entrega.';
    header('Location: ver_actividad.php?id=' . $actividad_id);
    exit();

} catch (Exception $e) {
    error_log("Error al reabrir entrega: " . $e->getMessage());
    header('Location: ver_actividad.php?id=' . $actividad_id . '&error=Error+al+reabrir+la+entrega');
    exit();
}
?>
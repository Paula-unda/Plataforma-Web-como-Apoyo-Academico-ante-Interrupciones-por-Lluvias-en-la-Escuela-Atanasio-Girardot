<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/encuestas_funciones.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Administrador') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$conexion = getConexion();
$encuesta_id = $_GET['id'] ?? 0;

try {
    $conexion->beginTransaction();
    
    // Verificar si tiene respuestas
    $stmt_check = $conexion->prepare("SELECT COUNT(*) FROM respuestas_encuesta WHERE encuesta_id = ?");
    $stmt_check->execute([$encuesta_id]);
    $tiene_respuestas = $stmt_check->fetchColumn();
    
    if ($tiene_respuestas > 0) {
        $_SESSION['error'] = "❌ No se puede eliminar: la encuesta ya tiene respuestas registradas";
        header('Location: encuestas.php');
        exit();
    }
    
    // Log antes de eliminar
    logEncuesta($conexion, $encuesta_id, $_SESSION['usuario_id'], 'eliminar', "Encuesta eliminada");
    
    // Eliminar preguntas (ON DELETE CASCADE lo hará automático)
    $stmt_preg = $conexion->prepare("DELETE FROM preguntas_encuesta WHERE encuesta_id = ?");
    $stmt_preg->execute([$encuesta_id]);
    
    // Eliminar encuesta
    $stmt_enc = $conexion->prepare("DELETE FROM encuestas WHERE id = ?");
    $stmt_enc->execute([$encuesta_id]);
    
    $conexion->commit();
    
    $_SESSION['mensaje'] = "✅ Encuesta eliminada exitosamente";
    
} catch (Exception $e) {
    $conexion->rollBack();
    $_SESSION['error'] = "❌ Error al eliminar: " . $e->getMessage();
}

header('Location: encuestas.php');
exit();
?>
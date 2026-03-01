<?php
session_start();
require_once '../../funciones.php';

// Verificar CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    header('Location: foro.php?error=Token+invalido');
    exit();
}

// Verificar sesión activa
if (!sesionActiva() || !in_array($_SESSION['usuario_rol'], ['Estudiante', 'Docente', 'Administrador'])) {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$respuesta_id = isset($_POST['respuesta_id']) ? (int)$_POST['respuesta_id'] : 0;
$tema_id = isset($_POST['tema_id']) ? (int)$_POST['tema_id'] : 0;

if (!$respuesta_id || !$tema_id) {
    header('Location: foro.php?tema=' . $tema_id . '&error=ID+no+válido');
    exit();
}

try {
    $conexion = getConexion();
    
    if (!$conexion) {
        throw new Exception("Error de conexión");
    }
    
    // Verificar que la respuesta existe y obtener datos
    $check = $conexion->prepare("
        SELECT autor_id, fecha_creacion 
        FROM foros_respuestas 
        WHERE id = ?
    ");
    $check->execute([$respuesta_id]);
    $respuesta = $check->fetch(PDO::FETCH_ASSOC);
    
    if (!$respuesta) {
        header('Location: foro.php?tema=' . $tema_id . '&error=Respuesta+no+encontrada');
        exit();
    }
    
    // Calcular minutos transcurridos
    $timestamp_respuesta = strtotime($respuesta['fecha_creacion']);
    $minutos_transcurridos = (time() - $timestamp_respuesta) / 60;
    
    // Verificar permisos
    $puede_borrar = false;
    
    if ($_SESSION['usuario_rol'] === 'Administrador') {
        $puede_borrar = true;
    } elseif ($_SESSION['usuario_rol'] === 'Docente') {
        $puede_borrar = true;
    } elseif ($_SESSION['usuario_rol'] === 'Estudiante' && $_SESSION['usuario_id'] == $respuesta['autor_id']) {
        if ($minutos_transcurridos <= 30) {
            $puede_borrar = true;
        } else {
            header('Location: foro.php?tema=' . $tema_id . '&error=Ya+pasaron+30+minutos');
            exit();
        }
    }
    
    if (!$puede_borrar) {
        header('Location: foro.php?tema=' . $tema_id . '&error=No+tienes+permiso');
        exit();
    }
    
    // Borrar la respuesta
    $delete = $conexion->prepare("DELETE FROM foros_respuestas WHERE id = ?");
    $delete->execute([$respuesta_id]);
    
    header('Location: foro.php?tema=' . $tema_id . '&exito=Respuesta+eliminada');
    
} catch (Exception $e) {
    error_log("Error borrar: " . $e->getMessage());
    header('Location: foro.php?tema=' . $tema_id . '&error=Error+al+borrar');
}
?>
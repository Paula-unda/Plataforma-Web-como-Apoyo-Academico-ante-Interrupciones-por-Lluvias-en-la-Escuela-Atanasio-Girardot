<?php
session_start();
require_once '../../funciones.php';

// Verificar CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    header('Location: foro.php?error=Token+de+seguridad+invalido');
    exit();
}

// Verificar sesión y roles permitidos
if (!sesionActiva() || !in_array($_SESSION['usuario_rol'], ['Estudiante', 'Docente', 'Administrador'])) {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: foro.php');
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$usuario_rol = $_SESSION['usuario_rol'];
$respuesta_id = isset($_POST['respuesta_id']) ? (int)$_POST['respuesta_id'] : 0;
$tema_id = isset($_POST['tema_id']) ? (int)$_POST['tema_id'] : 0;

if (!$respuesta_id || !$tema_id) {
    header('Location: foro.php?error=Datos+incompletos');
    exit();
}

try {
    $conexion = getConexion();
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Obtener información de la respuesta
    $stmt_respuesta = $conexion->prepare("
        SELECT r.*, u.nombre as autor_nombre, t.grado, t.seccion
        FROM foros_respuestas r
        INNER JOIN foros_temas t ON r.tema_id = t.id
        INNER JOIN usuarios u ON r.autor_id = u.id
        WHERE r.id = ?
    ");
    $stmt_respuesta->execute([$respuesta_id]);
    $respuesta = $stmt_respuesta->fetch(PDO::FETCH_ASSOC);
    
    if (!$respuesta) {
        header('Location: foro.php?tema=' . $tema_id . '&error=Respuesta+no+encontrada');
        exit();
    }
    
    // Verificar permisos para borrar
    $puede_borrar = false;
    
    // Regla 1: Administrador puede borrar todo
    if ($usuario_rol === 'Administrador') {
        $puede_borrar = true;
    }
    // Regla 2: Docente puede borrar todo (supervisor)
    elseif ($usuario_rol === 'Docente') {
        $puede_borrar = true;
    }
    // Regla 3: Estudiante puede borrar sus propias respuestas (SIN LÍMITE POR AHORA)
    elseif ($usuario_rol === 'Estudiante' && $respuesta['autor_id'] == $usuario_id) {
        // 🔥 SOLUCIÓN TEMPORAL: El estudiante puede borrar cualquier respuesta propia
        $puede_borrar = true;
    }
    
    if (!$puede_borrar) {
        header('Location: foro.php?tema=' . $tema_id . '&error=No+tienes+permiso+para+borrar+esta+respuesta');
        exit();
    }
    
    // Guardar en log
    $log_query = "
        INSERT INTO logs_eliminaciones 
        (usuario_eliminado_id, usuario_eliminado_nombre, eliminado_por, fecha_eliminacion, ip_address, user_agent, accion)
        VALUES (?, ?, ?, NOW(), ?, ?, ?)
    ";
    $stmt_log = $conexion->prepare($log_query);
    $stmt_log->execute([
        $respuesta['autor_id'],
        $respuesta['autor_nombre'],
        $usuario_id,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null,
        'borrar_foro'
    ]);
    
    // Borrar la respuesta
    $stmt_delete = $conexion->prepare("DELETE FROM foros_respuestas WHERE id = ?");
    $stmt_delete->execute([$respuesta_id]);
    
    header('Location: foro.php?tema=' . $tema_id . '&exito=Respuesta+eliminada+correctamente');
    exit();
    
} catch (PDOException $e) {
    error_log("Error PDO en borrar_respuesta: " . $e->getMessage());
    header('Location: foro.php?tema=' . $tema_id . '&error=Error+en+la+base+de+datos');
    exit();
    
} catch (Exception $e) {
    error_log("Error general en borrar_respuesta: " . $e->getMessage());
    header('Location: foro.php?tema=' . $tema_id . '&error=Error+al+borrar+la+respuesta');
    exit();
}
?>
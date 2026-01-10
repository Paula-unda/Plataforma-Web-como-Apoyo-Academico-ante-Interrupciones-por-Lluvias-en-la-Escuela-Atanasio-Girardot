<?php
session_start();
require_once '../../funciones.php';

// Verificar sesión y rol
if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Administrador') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

// Validar ID recibido
$id = $_GET['id'] ?? null;
if (!$id || !is_numeric($id)) {
    header('Location: gestion_usuarios.php?error=ID+de+usuario+inválido.');
    exit();
}

try {
    $pdo = getConexion();
    
    // Primero verificar que no sea el usuario actual (seguridad)
    if ($id == $_SESSION['usuario_id']) {
        throw new Exception('No puedes eliminar tu propia cuenta.');
    }
    
    // Iniciar transacción para integridad referencial
    $pdo->beginTransaction();
    
    // Eliminar relaciones de representante (si existen)
    $stmt = $pdo->prepare("DELETE FROM representantes_estudiantes WHERE representante_id = :id OR estudiante_id = :id");
    $stmt->execute([':id' => $id]);
    
    // Eliminar datos de estudiante (si existen)
    $stmt = $pdo->prepare("DELETE FROM estudiantes WHERE usuario_id = :id");
    $stmt->execute([':id' => $id]);
    
    // Eliminar usuario principal
    $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = :id");
    $affected = $stmt->execute([':id' => $id]);
    
    if ($affected) {
        $pdo->commit();
        header('Location: gestion_usuarios.php?mensaje=Usuario+eliminado+exitosamente.');
    } else {
        throw new Exception('No se encontró el usuario.');
    }
    
} catch (Exception $e) {
    $pdo->rollback();
    error_log("Error al eliminar usuario: " . $e->getMessage());
    header('Location: gestion_usuarios.php?error=' . urlencode($e->getMessage()));
}
<?php
session_start();
require_once '../../funciones.php';

// Verificar que sea docente
if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Docente') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$contenido_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($contenido_id <= 0) {
    header('Location: gestion_contenidos.php?error=ID+no+válido');
    exit();
}

try {
    $conexion = getConexion();
    
    // Verificar que el contenido pertenezca a este docente
    $check = $conexion->prepare("SELECT id, titulo FROM contenidos WHERE id = ? AND docente_id = ?");
    $check->execute([$contenido_id, $usuario_id]);
    $contenido = $check->fetch(PDO::FETCH_ASSOC);
    
    if (!$contenido) {
        header('Location: gestion_contenidos.php?error=Contenido+no+encontrado+o+no+te+pertenece');
        exit();
    }
    
    // Eliminar materiales asociados (por foreign key, pero por seguridad lo hacemos explícito)
    $delete_materiales = $conexion->prepare("DELETE FROM materiales WHERE contenido_id = ?");
    $delete_materiales->execute([$contenido_id]);
    
    // Eliminar progreso de estudiantes (por si acaso)
    $delete_progreso = $conexion->prepare("DELETE FROM progreso_contenido WHERE contenido_id = ?");
    $delete_progreso->execute([$contenido_id]);
    
    // Eliminar el contenido
    $delete_contenido = $conexion->prepare("DELETE FROM contenidos WHERE id = ? AND docente_id = ?");
    $delete_contenido->execute([$contenido_id, $usuario_id]);
    
    // Opcional: Eliminar archivos físicos del servidor
    if (!empty($contenido['archivo_adjunto'])) {
        $archivo_path = '../../../uploads/contenidos/' . $contenido['archivo_adjunto'];
        if (file_exists($archivo_path)) {
            unlink($archivo_path);
        }
    }
    
    // También eliminar archivos de materiales
    $materiales = $conexion->prepare("SELECT archivo FROM materiales WHERE contenido_id = ?");
    $materiales->execute([$contenido_id]);
    foreach ($materiales as $mat) {
        if (!empty($mat['archivo'])) {
            $archivo_mat = '../../../uploads/materiales/' . $mat['archivo'];
            if (file_exists($archivo_mat)) {
                unlink($archivo_mat);
            }
        }
    }
    
    header('Location: gestion_contenidos.php?exito=Contenido+eliminado+correctamente');
    
} catch (Exception $e) {
    error_log("Error al eliminar contenido: " . $e->getMessage());
    header('Location: gestion_contenidos.php?error=Error+al+eliminar+el+contenido');
}
?>
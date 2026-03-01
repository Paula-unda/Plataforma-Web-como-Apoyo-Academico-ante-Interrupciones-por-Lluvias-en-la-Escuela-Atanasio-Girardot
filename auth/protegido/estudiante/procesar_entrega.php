<?php
session_start();
require_once '../../funciones.php';
header('Content-Type: application/json');

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Estudiante') {
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit();
}

try {
    $actividad_id = isset($_POST['actividad_id']) ? (int)$_POST['actividad_id'] : 0;
    $estudiante_id = $_SESSION['usuario_id'];
    
    // ✅ NUEVO: Soportar borrar entrega
    if (isset($_POST['accion']) && $_POST['accion'] === 'borrar') {
        $conexion = getConexion();
        
        // Verificar si existe la entrega
        $check = $conexion->prepare("SELECT fecha_entrega FROM entregas_estudiantes WHERE actividad_id = :actividad_id AND estudiante_id = :estudiante_id");
        $check->execute([':actividad_id' => $actividad_id, ':estudiante_id' => $estudiante_id]);
        $entrega = $check->fetch();
        
        if (!$entrega) {
            throw new Exception('No hay entrega para borrar');
        }
        
        // Verificar si pasó 15 minutos
        $fecha_entrega = strtotime($entrega['fecha_entrega']);
        $tiempo_actual = time();
        $diferencia_minutos = ($tiempo_actual - $fecha_entrega) / 60;
        
        if ($diferencia_minutos > 15) {
            throw new Exception('Ya pasó el tiempo límite de 15 minutos para borrar la entrega');
        }
        
        // Borrar entrega
        $delete = $conexion->prepare("DELETE FROM entregas_estudiantes WHERE actividad_id = :actividad_id AND estudiante_id = :estudiante_id");
        $delete->execute([':actividad_id' => $actividad_id, ':estudiante_id' => $estudiante_id]);
        
        echo json_encode(['success' => true, 'mensaje' => 'Entrega borrada']);
        exit();
    }
    
    // ✅ Entrega normal
    $comentario = isset($_POST['comentario']) ? trim($_POST['comentario']) : '';
    
    if ($actividad_id <= 0) {
        throw new Exception('ID inválido');
    }
    
    $archivo_nombre = '';
    if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
        $tipos_permitidos = ['application/pdf', 'application/msword', 'image/jpeg', 'image/jpg', 'image/png'];
        $archivo_temporal = $_FILES['archivo']['tmp_name'];
        $archivo_tipo = $_FILES['archivo']['type'];
        $archivo_original = $_FILES['archivo']['name'];
        
        if (!in_array($archivo_tipo, $tipos_permitidos)) {
            throw new Exception('Tipo de archivo no permitido');
        }
        
        $extension = pathinfo($archivo_original, PATHINFO_EXTENSION);
        $archivo_nombre = 'entrega_' . $estudiante_id . '_' . $actividad_id . '_' . time() . '.' . $extension;
        $ruta_destino = __DIR__ . '/../../uploads/entregas/' . $archivo_nombre;
        

        // Por esto:
        $directorio = dirname($ruta_destino);
        if (!file_exists($directorio)) {
            // Intentar crear el directorio con permisos 755
            if (!mkdir($directorio, 0755, true)) {
                error_log("Error: No se pudo crear el directorio de entregas");
                throw new Exception('Error en el servidor al preparar la carpeta de entregas');
            }
            
            // Asegurar permisos después de crear
            chmod($directorio, 0755);
        }

        // Verificar que el directorio es escribible
        if (!is_writable($directorio)) {
            error_log("Error: Directorio de entregas no tiene permisos de escritura");
            throw new Exception('Error de permisos en el servidor');
        }
        
        if (!move_uploaded_file($archivo_temporal, $ruta_destino)) {
            throw new Exception('Error al guardar archivo');
        }
    }
    
    $resultado = registrarEntregaEstudiante($actividad_id, $estudiante_id, $archivo_nombre, '', $comentario);
    
    if ($resultado) {
        echo json_encode(['success' => true, 'mensaje' => 'Entrega registrada']);
    } else {
        throw new Exception('Error al registrar en BD');
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
<?php
session_start();
require_once '../../funciones.php';
header('Content-Type: application/json');

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Estudiante') {
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado']);
    exit();
}

$estudiante_id = $_SESSION['usuario_id'];
$actividad_id = isset($_POST['actividad_id']) ? (int)$_POST['actividad_id'] : 0;

if ($actividad_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID de actividad inválido']);
    exit();
}

try {
    $conexion = getConexion();
    
    // Verificar si ya existe una entrega
    $stmt_check = $conexion->prepare("
        SELECT id, estado, fecha_entrega FROM entregas_estudiantes 
        WHERE actividad_id = ? AND estudiante_id = ?
    ");
    $stmt_check->execute([$actividad_id, $estudiante_id]);
    $entrega_existente = $stmt_check->fetch(PDO::FETCH_ASSOC);
    
    // Verificar si se puede modificar (15 minutos) O si el docente la reabrió (estado pendiente)
if ($entrega_existente) {
    // Si está pendiente (reabierta), permitir modificar sin límite de tiempo
    if ($entrega_existente['estado'] === 'pendiente') {
        // Permitir modificar
    } 
    // Si está calificado, no permitir
    elseif ($entrega_existente['estado'] === 'calificado') {
        echo json_encode(['success' => false, 'error' => 'Esta actividad ya está calificada, no se puede modificar']);
        exit();
    }
    // Si está enviado o atrasado, verificar tiempo
    else {
        // Limpiar la fecha (eliminar milisegundos)
        $fecha_limpia = preg_replace('/\..*/', '', $entrega_existente['fecha_entrega']);
        $fecha_entrega_timestamp = strtotime($fecha_limpia);
        $tiempo_actual = time();
        $diferencia_minutos = ($tiempo_actual - $fecha_entrega_timestamp) / 60;
        
        // Compensar diferencia de zona horaria si es necesario
        if ($diferencia_minutos > 180) {
            $diferencia_minutos = $diferencia_minutos - 240;
        }
        
        error_log("📝 [procesar] Fecha entrega: " . $entrega_existente['fecha_entrega']);
        error_log("📝 [procesar] Diferencia minutos: " . $diferencia_minutos);
        
        if ($diferencia_minutos > 15) {
            echo json_encode(['success' => false, 'error' => 'Han pasado más de 15 minutos, no puedes modificar la entrega']);
            exit();
        }
    }
}
    
    // Procesar archivo subido
    $archivo_nombre = '';
    if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../../../uploads/entregas/';
        
        // Crear directorio si no existe
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $extension = pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION);
        $archivo_nombre = 'entrega_' . $actividad_id . '_' . $estudiante_id . '_' . time() . '.' . $extension;
        $ruta_completa = $upload_dir . $archivo_nombre;
        
        if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $ruta_completa)) {
            echo json_encode(['success' => false, 'error' => 'Error al subir el archivo']);
            exit();
        }
    } elseif (isset($_POST['accion']) && $_POST['accion'] === 'borrar') {
        // Si es acción de borrar, no requiere archivo
        $archivo_nombre = null;
    } elseif (!$entrega_existente) {
        // Si es nueva entrega y no hay archivo, error
        echo json_encode(['success' => false, 'error' => 'Debes seleccionar un archivo']);
        exit();
    }
    
    $comentario = trim($_POST['comentario'] ?? '');
    
    // Procesar acción de borrar
    if (isset($_POST['accion']) && $_POST['accion'] === 'borrar') {
        if (!$entrega_existente) {
            echo json_encode(['success' => false, 'error' => 'No hay entrega para borrar']);
            exit();
        }
        
        // Eliminar archivo físico si existe
        if (!empty($entrega_existente['archivo_entregado'])) {
            $archivo_path = '../../../uploads/entregas/' . $entrega_existente['archivo_entregado'];
            if (file_exists($archivo_path)) {
                unlink($archivo_path);
            }
        }
        
        // Eliminar registro de la base de datos
        $stmt_delete = $conexion->prepare("DELETE FROM entregas_estudiantes WHERE id = ?");
        $stmt_delete->execute([$entrega_existente['id']]);
        
        echo json_encode(['success' => true, 'mensaje' => 'Entrega borrada correctamente']);
        exit();
    }
    
    // Procesar actualización o nueva entrega
    if ($entrega_existente) {
        // Actualizar entrega existente
        if ($archivo_nombre) {
            // Eliminar archivo anterior si existe
            $stmt_old = $conexion->prepare("SELECT archivo_entregado FROM entregas_estudiantes WHERE id = ?");
            $stmt_old->execute([$entrega_existente['id']]);
            $old_file = $stmt_old->fetchColumn();
            
            if ($old_file) {
                $old_path = '../../../uploads/entregas/' . $old_file;
                if (file_exists($old_path)) {
                    unlink($old_path);
                }
            }
            
            // Actualizar con nuevo archivo
            $stmt = $conexion->prepare("
                UPDATE entregas_estudiantes 
                SET archivo_entregado = ?, comentario = ?, fecha_entrega = CURRENT_TIMESTAMP, estado = 'enviado'
                WHERE id = ?
            ");
            $stmt->execute([$archivo_nombre, $comentario, $entrega_existente['id']]);
        } else {
            // Actualizar solo comentario
            $stmt = $conexion->prepare("
                UPDATE entregas_estudiantes 
                SET comentario = ?, fecha_entrega = CURRENT_TIMESTAMP, estado = 'enviado'
                WHERE id = ?
            ");
            $stmt->execute([$comentario, $entrega_existente['id']]);
        }
        
        $mensaje = 'Entrega actualizada correctamente';
    } else {
        // Crear nueva entrega
        $stmt = $conexion->prepare("
            INSERT INTO entregas_estudiantes (actividad_id, estudiante_id, archivo_entregado, comentario, estado)
            VALUES (?, ?, ?, ?, 'enviado')
        ");
        $stmt->execute([$actividad_id, $estudiante_id, $archivo_nombre, $comentario]);
        $mensaje = 'Entrega registrada correctamente';
    }
    
    // 🔴 AHORA DEFINIMOS LAS VARIABLES ANTES DE USARLAS
    $success = true;
    $error = null;
    
    // Guardar mensaje en sesión para mostrarlo después de la redirección
    $_SESSION['mensaje_exito'] = $mensaje;
    

    // Redirigir de vuelta a la página de detalle
    header('Location: detalle_actividad.php?id=' . $actividad_id);
    exit();
    
} catch (Exception $e) {
    error_log("Error en procesar_entrega: " . $e->getMessage());
    
    // 🔴 DEFINIMOS LAS VARIABLES DE ERROR
    $success = false;
    $error = 'Error al procesar la entrega: ' . $e->getMessage();
    
    echo json_encode(['success' => $success, 'error' => $error]);
}
?>
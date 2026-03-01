<?php
// ⚠️ ABSOLUTAMENTE NADA antes de <?php
session_start();
require_once '../../funciones.php';

// ⚠️ Header ANTES de cualquier output
header('Content-Type: application/json');

// Verificar sesión
if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Estudiante') {
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado']);
    exit();
}

try {
    // Leer datos
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        throw new Exception('No se recibieron datos JSON');
    }
    
    // ✅ OBTENER Y VALIDAR DATOS
    $contenido_id = isset($data['contenido_id']) ? (int)$data['contenido_id'] : 0;
    $porcentaje = isset($data['porcentaje']) ? floatval($data['porcentaje']) : 0;
    $estudiante_id = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;
    
    // ✅ VALIDACIONES
    if ($contenido_id <= 0) {
        throw new Exception('contenido_id inválido: ' . $contenido_id);
    }
    
    if ($estudiante_id <= 0) {
        throw new Exception('estudiante_id inválido: ' . $estudiante_id);
    }
    
    error_log("=== actualizar_progreso.php ===");
    error_log("CONTENIDO_ID: " . $contenido_id);
    error_log("ESTUDIANTE_ID: " . $estudiante_id);
    error_log("PORCENTAJE: " . $porcentaje);
    
    $conexion = getConexion();
    
    // ✅ Convertir a STRING para evitar ambigüedad
    $porcentaje_str = strval($porcentaje);
    
    // Verificar si ya existe
    $check = $conexion->prepare("SELECT id FROM progreso_contenido WHERE contenido_id = $1 AND estudiante_id = $2");
    $check->execute([$contenido_id, $estudiante_id]);
    $existe = $check->fetch();
    
    error_log("¿EXISTE REGISTRO? " . ($existe ? 'SI' : 'NO'));
    
    if ($existe) {
        // ✅ ACTUALIZAR - Usar CAST ::numeric EXPLÍCITO
        $query = "
            UPDATE progreso_contenido 
            SET porcentaje_visto = $1::numeric, 
                ultima_visualizacion = CURRENT_TIMESTAMP,
                completado = CASE WHEN $1::numeric >= 100 THEN true ELSE false END
            WHERE contenido_id = $2 AND estudiante_id = $3
        ";
        
        error_log("QUERY UPDATE: " . $query);
        
        $stmt = $conexion->prepare($query);
        $resultado = $stmt->execute([
            $porcentaje_str,    // $1 = porcentaje_visto
            $contenido_id,      // $2 = contenido_id
            $estudiante_id      // $3 = estudiante_id
        ]);
        
        error_log("UPDATE RESULTADO: " . ($resultado ? 'EXITO' : 'FALLO'));
        error_log("FILAS AFECTADAS: " . $stmt->rowCount());
        
    } else {
        // ✅ INSERTAR - Usar CAST ::numeric EXPLÍCITO
        $query = "
            INSERT INTO progreso_contenido (
                contenido_id, 
                estudiante_id, 
                porcentaje_visto, 
                completado, 
                ultima_visualizacion
            ) VALUES (
                $1,
                $2,
                $3::numeric,
                CASE WHEN $3::numeric >= 100 THEN true ELSE false END,
                CURRENT_TIMESTAMP
            )
        ";
        
        error_log("QUERY INSERT: " . $query);
        
        $stmt = $conexion->prepare($query);
        $resultado = $stmt->execute([
            $contenido_id,      // $1 = contenido_id
            $estudiante_id,     // $2 = estudiante_id
            $porcentaje_str     // $3 = porcentaje_visto
        ]);
        
        error_log("INSERT RESULTADO: " . ($resultado ? 'EXITO' : 'FALLO'));
        error_log("FILAS AFECTADAS: " . $stmt->rowCount());
        
        if (!$resultado) {
            $error_info = $stmt->errorInfo();
            error_log("ERROR DETALLADO: " . print_r($error_info, true));
            throw new Exception('Error al insertar: ' . implode(', ', $error_info));
        }
    }
    
    $_SESSION['progreso_fecha_' . $contenido_id] = date('Y-m-d H:i:s');
    
    echo json_encode(['success' => true, 'porcentaje' => $porcentaje]);
    
} catch (Exception $e) {
    error_log("❌ EXCEPTION: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
// ⚠️ ABSOLUTAMENTE NADA después de esto
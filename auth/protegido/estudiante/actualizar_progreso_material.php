<?php
session_start();
require_once '../../funciones.php';
header('Content-Type: application/json');

error_log("=== INICIO actualizar_progreso_material.php ===");

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Estudiante') {
    error_log("❌ Acceso no autorizado");
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
error_log("📦 Datos recibidos: " . print_r($data, true));

$estudiante_id = $_SESSION['usuario_id'];
$contenido_id = isset($data['contenido_id']) ? (int)$data['contenido_id'] : 0;
$material_id = isset($data['material_id']) ? (int)$data['material_id'] : null;
$tipo = $data['tipo'] ?? null; // 'video', 'documento', o null para materiales adicionales

if ($contenido_id <= 0) {
    error_log("❌ ID de contenido inválido: " . $contenido_id);
    echo json_encode(['success' => false, 'error' => 'ID de contenido inválido']);
    exit();
}

try {
    $conexion = getConexion();
    error_log("✅ Conexión a BD exitosa");
    
    // =====================================================
    // PASO 1: Determinar el total de elementos
    // =====================================================
    
    // Contar recursos principales (video y documento)
    $stmt_cont = $conexion->prepare("SELECT enlace, archivo_adjunto FROM contenidos WHERE id = ?");
    $stmt_cont->execute([$contenido_id]);
    $cont = $stmt_cont->fetch(PDO::FETCH_ASSOC);
    
    $recursos_principales = 0;
    if (!empty($cont['enlace'])) $recursos_principales++;
    if (!empty($cont['archivo_adjunto'])) $recursos_principales++;
    
    error_log("📊 Recursos principales: " . $recursos_principales);
    
    // Contar materiales adicionales
    $stmt_mat = $conexion->prepare("SELECT COUNT(*) FROM materiales WHERE contenido_id = ? AND activo = true");
    $stmt_mat->execute([$contenido_id]);
    $total_materiales = $stmt_mat->fetchColumn();
    
    error_log("📦 Materiales adicionales: " . $total_materiales);
    
    $total_items = $recursos_principales + $total_materiales;
    error_log("🎯 Total items: " . $total_items);
    
    // =====================================================
    // PASO 2: Procesar según el tipo
    // =====================================================
    
    if ($material_id) {
        // ===== MATERIAL ADICIONAL =====
        error_log("🎬 Procesando material adicional ID: " . $material_id);
        
        // Verificar si ya existe el registro para este material
        $stmt_check = $conexion->prepare("
            SELECT id FROM progreso_contenido 
            WHERE estudiante_id = ? AND contenido_id = ? AND material_id = ?
        ");
        $stmt_check->execute([$estudiante_id, $contenido_id, $material_id]);
        
        if ($stmt_check->fetch()) {
            // Actualizar existente
            $stmt_update = $conexion->prepare("
                UPDATE progreso_contenido 
                SET porcentaje_visto = 100, 
                    completado = true, 
                    ultima_visualizacion = CURRENT_TIMESTAMP
                WHERE estudiante_id = ? AND contenido_id = ? AND material_id = ?
            ");
            $stmt_update->execute([$estudiante_id, $contenido_id, $material_id]);
        } else {
            // Insertar nuevo
            $stmt_insert = $conexion->prepare("
                INSERT INTO progreso_contenido 
                (estudiante_id, contenido_id, material_id, porcentaje_visto, completado, ultima_visualizacion)
                VALUES (?, ?, ?, 100, true, CURRENT_TIMESTAMP)
            ");
            $stmt_insert->execute([$estudiante_id, $contenido_id, $material_id]);
        }
        
        // Guardar en sesión
        $_SESSION['material_completado_' . $material_id] = true;
        
    } else {
        // ===== RECURSOS PRINCIPALES (VIDEO Y DOCUMENTO) =====
        error_log("🎬 Procesando recurso principal: " . ($tipo ?? 'desconocido'));
        
        // Verificar si ya existe un registro principal para este contenido
        $stmt_check = $conexion->prepare("
            SELECT id, principales_completados FROM progreso_contenido 
            WHERE estudiante_id = ? AND contenido_id = ? AND material_id IS NULL
        ");
        $stmt_check->execute([$estudiante_id, $contenido_id]);
        $registro_principal = $stmt_check->fetch(PDO::FETCH_ASSOC);
        
        // En la sección donde actualizas el registro principal:
        if ($registro_principal) {
            // Ya existe un registro principal, actualizamos
            $id_principal = $registro_principal['id'];
            $completados_actuales = (int)($registro_principal['principales_completados'] ?? 0);
            
            // Incrementar el contador
            $nuevos_completados = $completados_actuales + 1;
            
            // Calcular porcentaje basado en principales completados
            $porcentaje_principal = round(($nuevos_completados / $recursos_principales) * 100);
            $porcentaje_principal = min($porcentaje_principal, 100); // 🔴 Limitar a 100
            
            // Determinar si ya completó todos los principales
            $principal_completado = ($nuevos_completados >= $recursos_principales);
            
            // Actualizar registro existente
            $stmt_update = $conexion->prepare("
                UPDATE progreso_contenido 
                SET principales_completados = ?,
                    porcentaje_visto = ?,
                    completado = ?,
                    ultima_visualizacion = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt_update->execute([
                $nuevos_completados,
                $porcentaje_principal,
                $principal_completado ? 'true' : 'false',
                $id_principal
            ]);
            
            error_log("✅ Registro principal actualizado - Completados: $nuevos_completados de $recursos_principales, Porcentaje: $porcentaje_principal%");
            
        } else {
            // No existe registro principal, lo creamos
            $completados_iniciales = 1;
            $porcentaje_principal = round(($completados_iniciales / $recursos_principales) * 100);
            $porcentaje_principal = min($porcentaje_principal, 100); // 🔴 Limitar a 100
            $principal_completado = ($completados_iniciales >= $recursos_principales);
            
            // Insertar nuevo
            $stmt_insert = $conexion->prepare("
                INSERT INTO progreso_contenido 
                (estudiante_id, contenido_id, material_id, porcentaje_visto, completado, principales_completados, ultima_visualizacion)
                VALUES (?, ?, NULL, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt_insert->execute([
                $estudiante_id, 
                $contenido_id, 
                $porcentaje_principal, 
                $principal_completado ? 'true' : 'false',
                $completados_iniciales
            ]);
            
            error_log("✅ Nuevo registro principal creado - Completados: 1 de $recursos_principales, Porcentaje: $porcentaje_principal%");
        }
        
        // Guardar en sesión según el tipo
        if ($tipo === 'video') {
            $_SESSION['video_completado_' . $contenido_id] = true;
        } elseif ($tipo === 'documento') {
            $_SESSION['documento_completado_' . $contenido_id] = true;
        }
    }
    
    // =====================================================
    // PASO 3: Calcular porcentaje TOTAL (principales + materiales)
    // =====================================================

    $completados = 0;

    // 1. Obtener el progreso de los principales
    $stmt_princ = $conexion->prepare("
        SELECT principales_completados FROM progreso_contenido 
        WHERE estudiante_id = ? AND contenido_id = ? AND material_id IS NULL
    ");
    $stmt_princ->execute([$estudiante_id, $contenido_id]);
    $princ = $stmt_princ->fetch(PDO::FETCH_ASSOC);

    if ($princ && isset($princ['principales_completados'])) {
        $completados += (int)$princ['principales_completados'];
        error_log("✅ Principales completados: " . $princ['principales_completados']);
    }

    // 2. Contar materiales adicionales completados
    $stmt_mat_completados = $conexion->prepare("
        SELECT COUNT(*) FROM progreso_contenido 
        WHERE estudiante_id = ? AND contenido_id = ? AND material_id IS NOT NULL AND completado = true
    ");
    $stmt_mat_completados->execute([$estudiante_id, $contenido_id]);
    $materiales_completados = $stmt_mat_completados->fetchColumn();
    $completados += $materiales_completados;
    error_log("✅ Materiales completados: " . $materiales_completados);

    error_log("✅ TOTAL COMPLETADOS: " . $completados . " de " . $total_items);

    // 🔴 IMPORTANTE: Asegurar que el porcentaje NUNCA exceda 100
    $porcentaje_total = $total_items > 0 ? round(($completados / $total_items) * 100, 2) : 0;
    $porcentaje_total = min($porcentaje_total, 100); // Limitar a 100 máximo

    error_log("📊 Porcentaje final: " . $porcentaje_total . "%");
    
    echo json_encode([
        'success' => true,
        'porcentaje' => $porcentaje_total,
        'completado' => $porcentaje_total >= 100,
        'mensaje' => 'Progreso actualizado correctamente'
    ]);
    
} catch (Exception $e) {
    error_log("❌ Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
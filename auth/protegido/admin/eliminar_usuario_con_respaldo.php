<?php
session_start();
require_once '../../funciones.php';

// ACTIVAR DEPURACIÓN - MUESTRA ERRORES
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Administrador') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}



// Aceptar tanto POST como GET
$id = $_POST['id'] ?? $_GET['id'] ?? null;


if (!$id || !is_numeric($id)) {
    die("❌ ERROR: ID inválido - Recibido: " . ($id ?? 'NULL'));
}

// Convertir a entero
$id = (int)$id;



try {
    $pdo = getConexion();
    
    
    // Verificar que no sea el usuario actual
    if ($id == $_SESSION['usuario_id']) {
        throw new Exception('No puedes eliminar tu propia cuenta.');
    }
    

    
    // Obtener IP y User Agent para el log
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $pdo->beginTransaction();
 
    
    // 1. OBTENER TODOS LOS DATOS DEL USUARIO ANTES DE ELIMINAR
    $stmt = $pdo->prepare("
        SELECT 
            u.*,
            e.grado as estudiante_grado,
            e.seccion as estudiante_seccion,
            d.grado as docente_grado,
            d.seccion as docente_seccion
        FROM usuarios u
        LEFT JOIN estudiantes e ON u.id = e.usuario_id
        LEFT JOIN docentes d ON u.id = d.usuario_id
        WHERE u.id = ?
    ");
    $stmt->execute([$id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        throw new Exception('Usuario no encontrado.');
    }
    

    
    // 2. OBTENER RELACIONES ESPECÍFICAS SEGÚN ROL
    $estudiantes_asignados = null;
    $representantes_asignados = null;
    
    if ($usuario['rol'] === 'Representante') {
        
        $stmt_rep = $pdo->prepare("
            SELECT 
                u.id,
                u.nombre,
                u.correo,
                e.grado,
                e.seccion
            FROM representantes_estudiantes re
            JOIN usuarios u ON re.estudiante_id = u.id
            LEFT JOIN estudiantes e ON u.id = e.usuario_id
            WHERE re.representante_id = ?
        ");
        $stmt_rep->execute([$id]);
        $estudiantes = $stmt_rep->fetchAll(PDO::FETCH_ASSOC);
        $estudiantes_asignados = json_encode($estudiantes, JSON_UNESCAPED_UNICODE);
        
    }
    
    if ($usuario['rol'] === 'Estudiante') {

        $stmt_est = $pdo->prepare("
            SELECT 
                u.id,
                u.nombre,
                u.correo
            FROM representantes_estudiantes re
            JOIN usuarios u ON re.representante_id = u.id
            WHERE re.estudiante_id = ?
        ");
        $stmt_est->execute([$id]);
        $representantes = $stmt_est->fetchAll(PDO::FETCH_ASSOC);
        $representantes_asignados = json_encode($representantes, JSON_UNESCAPED_UNICODE);
       
    }
    
    // 3. GUARDAR COPIA DE SEGURIDAD EN JSON
    $backup_completo = json_encode($usuario, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    
    // 4. INSERTAR EN TABLA DE ELIMINADOS
    
    
    $stmt_insert = $pdo->prepare("
        INSERT INTO usuarios_eliminados (
            usuario_id, nombre, correo, contrasena, contrasena_temporal,
            rol, grado, seccion,
            estudiantes_asignados,
            representantes_asignados, eliminado_por, backup_completo
        ) VALUES (
            :usuario_id, :nombre, :correo, :contrasena, :contrasena_temporal,
            :rol, :grado, :seccion,
            :estudiantes_asignados,
            :representantes_asignados, :eliminado_por, :backup_completo
        )
    ");
    
    // Determinar grado y sección según rol
    $grado = null;
    $seccion = null;
    if ($usuario['rol'] === 'Estudiante') {
        $grado = $usuario['estudiante_grado'];
        $seccion = $usuario['estudiante_seccion'];
    } elseif ($usuario['rol'] === 'Docente') {
        $grado = $usuario['docente_grado'];
        $seccion = $usuario['docente_seccion'];
    }
    
    $resultado_insert = $stmt_insert->execute([
        ':usuario_id' => $id,
        ':nombre' => $usuario['nombre'],
        ':correo' => $usuario['correo'],
        ':contrasena' => $usuario['contrasena'],
        ':contrasena_temporal' => $usuario['contrasena_temporal'],
        ':rol' => $usuario['rol'],
        ':grado' => $grado,
        ':seccion' => $seccion,
        ':estudiantes_asignados' => $estudiantes_asignados,
        ':representantes_asignados' => $representantes_asignados,
        ':eliminado_por' => $_SESSION['usuario_id'],
        ':backup_completo' => $backup_completo
    ]);
    
    if ($resultado_insert) {
        
    } else {
        throw new Exception("Error al insertar en usuarios_eliminados");
    }
    
    // 5. REGISTRAR EN LOG
    $stmt_log = $pdo->prepare("
        INSERT INTO logs_eliminaciones 
        (usuario_eliminado_id, usuario_eliminado_nombre, eliminado_por, ip_address, user_agent, accion)
        VALUES (?, ?, ?, ?, ?, 'ELIMINAR')
    ");
    $stmt_log->execute([$id, $usuario['nombre'], $_SESSION['usuario_id'], $ip_address, $user_agent]);
    
    
    
    
    // Eliminar relaciones de representante
    $stmt = $pdo->prepare("DELETE FROM representantes_estudiantes WHERE representante_id = ? OR estudiante_id = ?");
    $stmt->execute([$id, $id]);
   
    
    // Eliminar datos de estudiante
    $stmt = $pdo->prepare("DELETE FROM estudiantes WHERE usuario_id = ?");
    $stmt->execute([$id]);
   
    
    // Eliminar datos de docente
    $stmt = $pdo->prepare("DELETE FROM docentes WHERE usuario_id = ?");
    $stmt->execute([$id]);

    
    // Eliminar entregas de actividades
    $stmt = $pdo->prepare("DELETE FROM entregas_estudiantes WHERE estudiante_id = ?");
    $stmt->execute([$id]);
   
    
    // Eliminar progreso de contenidos
    $stmt = $pdo->prepare("DELETE FROM progreso_contenido WHERE estudiante_id = ?");
    $stmt->execute([$id]);
    
    
    // Eliminar respuestas de foro
    $stmt = $pdo->prepare("DELETE FROM foros_respuestas WHERE autor_id = ?");
    $stmt->execute([$id]);
    
    // Reasignar temas de foro a admin (ID 1)
    $stmt = $pdo->prepare("UPDATE foros_temas SET autor_id = ? WHERE autor_id = ?");
    $stmt->execute([1, $id]);
    
    
    // Finalmente, eliminar usuario principal
    $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
   
    
    $pdo->commit();
    
    
    // COMENTA ESTAS LÍNEAS PARA VER LA DEPURACIÓN
    header('Location: gestion_usuarios.php?mensaje=Usuario+eliminado+y+guardado+en+papelera+de+reciclaje.');
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    
    error_log("Error al eliminar usuario: " . $e->getMessage());
    header('Location: gestion_usuarios.php?error=' . urlencode($e->getMessage()));
}
?>
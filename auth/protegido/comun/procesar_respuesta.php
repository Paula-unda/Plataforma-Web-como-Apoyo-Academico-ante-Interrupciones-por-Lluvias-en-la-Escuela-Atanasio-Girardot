<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/notificaciones_funciones.php';

// Evitar respuestas duplicadas por refresh
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $respuesta_hash = md5($_POST['tema_id'] . $_POST['contenido'] . json_encode($_POST));
    
    if (isset($_SESSION['ultima_respuesta']) && $_SESSION['ultima_respuesta'] === $respuesta_hash) {
        header('Location: foro.php?tema=' . $_POST['tema_id'] . '&error=Respuesta+duplicada');
        exit();
    }
    
    $_SESSION['ultima_respuesta'] = $respuesta_hash;
}

// Verificar CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    header('Location: foro.php?error=Token+de+seguridad+invalido');
    exit();
}

// Verificar sesión y roles permitidos
if (!sesionActiva() || !in_array($_SESSION['usuario_rol'], ['Estudiante', 'Docente'])) {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: foro.php');
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$usuario_rol = $_SESSION['usuario_rol'];
$usuario_nombre = $_SESSION['usuario_nombre'];
$tema_id = isset($_POST['tema_id']) ? (int)$_POST['tema_id'] : 0;
$contenido = trim($_POST['contenido'] ?? '');
$tipo_mensaje = $_POST['tipo_mensaje'] ?? 'publico'; // 🔥 NUEVO: publico o privado
$privacidad = $_POST['privacidad'] ?? 'publico'; // Para retrocompatibilidad

// 🔥 IMPORTANTE: DEBUG - Registrar lo que llega
error_log("=== PROCESAR RESPUESTA DEBUG (NUEVO SISTEMA) ===");
error_log("POST data: " . print_r($_POST, true));
error_log("Tipo de mensaje: " . $tipo_mensaje);

// Validaciones básicas
if (!$tema_id || empty($contenido)) {
    header('Location: foro.php?tema=' . $tema_id . '&error=Datos+incompletos');
    exit();
}

try {
    $conexion = getConexion();
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Verificar que el tema existe
    $stmt_tema = $conexion->prepare("
        SELECT t.*, u.nombre as autor_nombre 
        FROM foros_temas t
        INNER JOIN usuarios u ON t.autor_id = u.id
        WHERE t.id = ?
    ");
    $stmt_tema->execute([$tema_id]);
    $tema = $stmt_tema->fetch(PDO::FETCH_ASSOC);
    
    if (!$tema) {
        header('Location: foro.php?error=Tema+no+encontrado');
        exit();
    }
    
    // Obtener grado/sección del usuario actual
    $grado_usuario = '';
    $seccion_usuario = '';
    
    if ($usuario_rol === 'Estudiante') {
        $stmt_grado = $conexion->prepare("
            SELECT grado, seccion FROM estudiantes WHERE usuario_id = ?
        ");
        $stmt_grado->execute([$usuario_id]);
        $datos = $stmt_grado->fetch(PDO::FETCH_ASSOC);
        if ($datos) {
            $grado_usuario = $datos['grado'];
            $seccion_usuario = $datos['seccion'];
        }
    } elseif ($usuario_rol === 'Docente') {
        $stmt_grado = $conexion->prepare("
            SELECT grado, seccion FROM docentes WHERE usuario_id = ?
        ");
        $stmt_grado->execute([$usuario_id]);
        $datos = $stmt_grado->fetch(PDO::FETCH_ASSOC);
        if ($datos) {
            $grado_usuario = $datos['grado'];
            $seccion_usuario = $datos['seccion'];
        }
    }
    
    // Verificar que el usuario pertenece al mismo grado/sección que el tema
    if ($tema['grado'] != $grado_usuario || $tema['seccion'] != $seccion_usuario) {
        header('Location: foro.php?error=No+puedes+responder+a+este+tema');
        exit();
    }
    
    // 🔥 NUEVO: Procesar según el tipo de mensaje (PÚBLICO o PRIVADO)
    $destinatario_tipo = 'publico';
    $destinatario_ids = [];
    
    // Función para validar estudiantes
    function validarEstudiantes($conexion, $ids_array, $grado, $seccion) {
        if (empty($ids_array)) return [];
        
        $placeholders = implode(',', array_fill(0, count($ids_array), '?'));
        $stmt = $conexion->prepare("
            SELECT u.id 
            FROM usuarios u
            INNER JOIN estudiantes e ON u.id = e.usuario_id
            WHERE u.id IN ($placeholders)
            AND e.grado = ? AND e.seccion = ?
        ");
        $params = array_merge($ids_array, [$grado, $seccion]);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    // Función para validar docentes
    function validarDocentes($conexion, $ids_array, $grado, $seccion) {
        if (empty($ids_array)) return [];
        
        $placeholders = implode(',', array_fill(0, count($ids_array), '?'));
        $stmt = $conexion->prepare("
            SELECT u.id 
            FROM usuarios u
            INNER JOIN docentes d ON u.id = d.usuario_id
            WHERE u.id IN ($placeholders)
            AND d.grado = ? AND d.seccion = ?
        ");
        $params = array_merge($ids_array, [$grado, $seccion]);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    if ($tipo_mensaje === 'publico') {
        // Mensaje público - sin destinatarios específicos
        error_log("Opción: PÚBLICO - sin destinatarios");
        $destinatario_tipo = 'publico';
        
    } else {
        // Mensaje privado - obtener destinatarios
        error_log("Opción: PRIVADO - procesando destinatarios");
        
        $companero_ids = isset($_POST['companero_ids']) ? $_POST['companero_ids'] : '';
        $docente_ids = isset($_POST['docente_ids']) ? $_POST['docente_ids'] : '';
        
        error_log("Compañeros IDs: " . $companero_ids);
        error_log("Docentes IDs: " . $docente_ids);
        
        // Procesar IDs de estudiantes
        $estudiantes_validos = [];
        if (!empty($companero_ids)) {
            $est_array = explode(',', $companero_ids);
            $est_array = array_filter(array_map('intval', $est_array));
            
            if (!empty($est_array)) {
                $validos = validarEstudiantes($conexion, $est_array, $grado_usuario, $seccion_usuario);
                $estudiantes_validos = $validos;
                error_log("Estudiantes válidos: " . count($estudiantes_validos));
            }
        }
        
        // Procesar IDs de docentes
        $docentes_validos = [];
        if (!empty($docente_ids)) {
            $doc_array = explode(',', $docente_ids);
            $doc_array = array_filter(array_map('intval', $doc_array));
            
            if (!empty($doc_array)) {
                $validos = validarDocentes($conexion, $doc_array, $grado_usuario, $seccion_usuario);
                $docentes_validos = $validos;
                error_log("Docentes válidos: " . count($docentes_validos));
            }
        }
        
        // Combinar todos los destinatarios
        $destinatario_ids = array_merge($estudiantes_validos, $docentes_validos);
        
        // Verificar que hay al menos un destinatario
        if (empty($destinatario_ids)) {
            header('Location: foro.php?tema=' . $tema_id . '&error=Debes+seleccionar+al+menos+una+persona');
            exit();
        }
        
        // Determinar el tipo para la BD (docente, companero o ambos)
        if (!empty($docentes_validos) && !empty($estudiantes_validos)) {
            $destinatario_tipo = 'ambos';
        } elseif (!empty($docentes_validos)) {
            $destinatario_tipo = 'docente';
        } else {
            $destinatario_tipo = 'companero';
        }
        
        error_log("Tipo destinatario: $destinatario_tipo");
        error_log("Total destinatarios: " . count($destinatario_ids));
    }
    
    // Insertar respuesta
    $es_privado = ($tipo_mensaje === 'privado') ? 'true' : 'false';
    $destinatario_id_str = !empty($destinatario_ids) ? implode(',', $destinatario_ids) : null;
    
    error_log("Insertando respuesta - Privado: $es_privado, Tipo: $destinatario_tipo, IDs: " . ($destinatario_id_str ?? 'ninguno'));
    
    $query = "
        INSERT INTO foros_respuestas 
        (tema_id, autor_id, contenido, es_privado, destinatario_tipo, destinatario_id, fecha_creacion)
        VALUES 
        (?, ?, ?, ?::boolean, ?, ?, CURRENT_TIMESTAMP)
        RETURNING id
    ";
    
    $stmt = $conexion->prepare($query);
    $stmt->execute([
        $tema_id,
        $usuario_id,
        $contenido,
        $es_privado,
        $destinatario_tipo,
        $destinatario_id_str
    ]);
    
    $respuesta_id = $stmt->fetchColumn();
    
    if (!$respuesta_id) {
        throw new Exception("Error al insertar la respuesta");
    }
    
    error_log("✅ Respuesta insertada con ID: $respuesta_id");
    
    // ENVIAR NOTIFICACIONES
    try {
        // 1. Notificar al autor del tema (si no es el mismo usuario)
        if ($tema['autor_id'] != $usuario_id) {
            enviarNotificacion(
                $conexion,
                $tema['autor_id'],
                "💬 Nueva respuesta en tu tema",
                $usuario_nombre . " respondió a tu tema '" . $tema['titulo'] . "'",
                'foro',
                $tema_id,
                'foros_temas'
            );
        }
        
        // 2. Notificar a destinatarios específicos (si es mensaje privado)
        if (!empty($destinatario_ids)) {
            $titulo_notif = "🔒 Mensaje privado";
            $mensaje_notif = $usuario_nombre . " te envió un mensaje privado en el tema '" . $tema['titulo'] . "'";
            
            foreach ($destinatario_ids as $dest_id) {
                if ($dest_id != $usuario_id) { // No notificarse a sí mismo
                    enviarNotificacion(
                        $conexion,
                        $dest_id,
                        $titulo_notif,
                        $mensaje_notif,
                        'foro',
                        $tema_id,
                        'foros_respuestas'
                    );
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Error enviando notificaciones: " . $e->getMessage());
    }
    
    // Redirigir con éxito
    $mensaje_exito = 'Respuesta+enviada+correctamente';
    if (!empty($destinatario_ids)) {
        $mensaje_exito = 'Respuesta+enviada+correctamente+a+' . count($destinatario_ids) . '+persona(s)';
    }
    
    header('Location: foro.php?tema=' . $tema_id . '&exito=' . $mensaje_exito);
    exit();
    
} catch (PDOException $e) {
    error_log("Error PDO en procesar_respuesta: " . $e->getMessage());
    header('Location: foro.php?tema=' . $tema_id . '&error=Error+en+la+base+de+datos');
    exit();
    
} catch (Exception $e) {
    error_log("Error general en procesar_respuesta: " . $e->getMessage());
    header('Location: foro.php?tema=' . $tema_id . '&error=Error+al+enviar+la+respuesta');
    exit();
}
?>
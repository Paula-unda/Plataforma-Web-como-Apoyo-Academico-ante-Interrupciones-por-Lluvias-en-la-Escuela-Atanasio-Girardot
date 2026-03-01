<?php
session_start();
require_once '../../funciones.php';

// Evitar respuestas duplicadas por refresh
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Crear un identificador único para esta respuesta
    $respuesta_hash = md5($_POST['tema_id'] . $_POST['contenido'] . json_encode($_POST));
    
    // Verificar si ya se envió esta misma respuesta
    if (isset($_SESSION['ultima_respuesta']) && $_SESSION['ultima_respuesta'] === $respuesta_hash) {
        header('Location: foro.php?tema=' . $_POST['tema_id'] . '&error=Respuesta+duplicada');
        exit();
    }
    
    // Guardar el hash de esta respuesta
    $_SESSION['ultima_respuesta'] = $respuesta_hash;
}

// Verificar CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    header('Location: foro.php?error=Token+de+seguridad+invalido');
    exit();
}

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Estudiante') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: foro.php');
    exit();
}

$estudiante_id = $_SESSION['usuario_id'];
$tema_id = isset($_POST['tema_id']) ? (int)$_POST['tema_id'] : 0;
$contenido = trim($_POST['contenido'] ?? '');
$privacidad = $_POST['privacidad'] ?? 'publico';

// Obtener compañeros seleccionados
$companero_ids = [];
if (isset($_POST['companero_ids_input']) && !empty($_POST['companero_ids_input'])) {
    $companero_ids = array_map('intval', explode(',', $_POST['companero_ids_input']));
} elseif (isset($_POST['companero_ids']) && is_array($_POST['companero_ids'])) {
    $companero_ids = array_map('intval', $_POST['companero_ids']);
} elseif (isset($_POST['companero_id']) && !empty($_POST['companero_id'])) {
    $companero_ids = [(int)$_POST['companero_id']];
}
$companero_ids = array_filter($companero_ids);

// Obtener docentes seleccionados
$docente_ids = [];
if (isset($_POST['docente_ids_input']) && !empty($_POST['docente_ids_input'])) {
    $docente_ids = array_map('intval', explode(',', $_POST['docente_ids_input']));
} elseif (isset($_POST['docente_ids']) && is_array($_POST['docente_ids'])) {
    $docente_ids = array_map('intval', $_POST['docente_ids']);
} elseif (isset($_POST['docente_id']) && !empty($_POST['docente_id'])) {
    $docente_ids = [(int)$_POST['docente_id']];
}
$docente_ids = array_filter($docente_ids);

// Validaciones según el tipo de privacidad
if ($privacidad === 'companero' && empty($companero_ids)) {
    header('Location: foro.php?tema=' . $tema_id . '&error=Debes+seleccionar+al+menos+un+compa%C3%B1ero');
    exit();
}

if ($privacidad === 'docente' && empty($docente_ids)) {
    header('Location: foro.php?tema=' . $tema_id . '&error=Debes+seleccionar+al+menos+un+docente');
    exit();
}

if ($privacidad === 'ambos' && (empty($companero_ids) || empty($docente_ids))) {
    header('Location: foro.php?tema=' . $tema_id . '&error=Debes+seleccionar+docente+y+compa%C3%B1ero');
    exit();
}

if (!$tema_id || empty($contenido)) {
    header('Location: foro.php?tema=' . $tema_id . '&error=Datos+incompletos');
    exit();
}

try {
    $conexion = getConexion();
    
    if (!$conexion) {
        throw new Exception("Error de conexión a la base de datos");
    }
    
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Verificar que el tema existe
    $check_tema = $conexion->prepare("SELECT id FROM foros_temas WHERE id = ?");
    $check_tema->execute([$tema_id]);
    if (!$check_tema->fetch()) {
        header('Location: foro.php?error=Tema+no+encontrado');
        exit();
    }
    
    $es_privado = ($privacidad !== 'publico') ? 'true' : 'false';
    $destinatario_tipo = $privacidad;
    
    // Combinar todos los IDs según el tipo de privacidad
    $todos_ids = [];
    if ($privacidad === 'companero') {
        $todos_ids = $companero_ids;
    } elseif ($privacidad === 'docente') {
        $todos_ids = $docente_ids;
    } elseif ($privacidad === 'ambos') {
        $todos_ids = array_merge($docente_ids, $companero_ids);
    }
    
    $destinatario_id = !empty($todos_ids) ? implode(',', $todos_ids) : null;
    
    $query = "
        INSERT INTO foros_respuestas 
        (tema_id, autor_id, contenido, es_privado, destinatario_tipo, destinatario_id, fecha_creacion)
        VALUES 
        (?, ?, ?, ?::boolean, ?, ?, CURRENT_TIMESTAMP)
    ";
    
    $stmt = $conexion->prepare($query);
    $resultado = $stmt->execute([
        $tema_id,
        $estudiante_id,
        $contenido,
        $es_privado,
        $destinatario_tipo,
        $destinatario_id
    ]);
    
    if ($resultado) {
        $mensaje = 'Respuesta+enviada+correctamente';
        if (!empty($todos_ids)) {
            $mensaje = 'Respuesta+enviada+correctamente+a+' . count($todos_ids) . '+persona(s)';
        }
        header('Location: foro.php?tema=' . $tema_id . '&exito=' . $mensaje);
        exit();
    } else {
        throw new Exception("Error al insertar la respuesta");
    }
    
} catch (PDOException $e) {
    error_log("Error PDO: " . $e->getMessage());
    header('Location: foro.php?tema=' . $tema_id . '&error=Error+en+la+base+de+datos');
    exit();
    
} catch (Exception $e) {
    error_log("Error general: " . $e->getMessage());
    header('Location: foro.php?tema=' . $tema_id . '&error=Error+al+enviar+la+respuesta');
    exit();
}
?>
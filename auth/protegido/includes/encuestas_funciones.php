<?php
// /auth/protegido/includes/encuestas_funciones.php

/**
 * Verifica si un usuario puede responder una encuesta
 */
function puedeResponderEncuesta($conexion, $usuario_id, $encuesta_id) {
    // Verificar si ya respondió
    $stmt = $conexion->prepare("
        SELECT COUNT(*) FROM respuestas_encuesta 
        WHERE encuesta_id = ? AND usuario_id = ?
    ");
    $stmt->execute([$encuesta_id, $usuario_id]);
    return $stmt->fetchColumn() == 0;
}

/**
 * Obtiene las preguntas de una encuesta
 */
function obtenerPreguntasEncuesta($conexion, $encuesta_id) {
    $stmt = $conexion->prepare("
        SELECT * FROM preguntas_encuesta 
        WHERE encuesta_id = ? 
        ORDER BY orden ASC, id ASC
    ");
    $stmt->execute([$encuesta_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Obtiene las respuestas de una encuesta
 */
function obtenerRespuestasEncuesta($conexion, $encuesta_id) {
    $stmt = $conexion->prepare("
        SELECT 
            re.*,
            u.nombre as usuario_nombre,
            u.rol as usuario_rol
        FROM respuestas_encuesta re
        JOIN usuarios u ON re.usuario_id = u.id
        WHERE re.encuesta_id = ?
        ORDER BY re.fecha_respuesta DESC
    ");
    $stmt->execute([$encuesta_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Calcula estadísticas de una encuesta
 */
function calcularEstadisticasEncuesta($conexion, $encuesta_id) {
    $stats = [];
    
    // Total de respuestas
    $stmt = $conexion->prepare("
        SELECT COUNT(DISTINCT usuario_id) as total_respondieron
        FROM respuestas_encuesta 
        WHERE encuesta_id = ?
    ");
    $stmt->execute([$encuesta_id]);
    $stats['total_respondieron'] = $stmt->fetchColumn();
    
    // Respuestas por pregunta
    $preguntas = obtenerPreguntasEncuesta($conexion, $encuesta_id);
    $stats['preguntas'] = [];
    
    foreach ($preguntas as $p) {
        $stmt = $conexion->prepare("
            SELECT respuesta, COUNT(*) as total
            FROM respuestas_encuesta
            WHERE encuesta_id = ? AND pregunta_id = ?
            GROUP BY respuesta
            ORDER BY total DESC
        ");
        $stmt->execute([$encuesta_id, $p['id']]);
        $stats['preguntas'][$p['id']] = [
            'pregunta' => $p['pregunta'],
            'tipo' => $p['tipo'],
            'respuestas' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    }
    
    return $stats;
}

/**
 * Registra acción en log
 */
function logEncuesta($conexion, $encuesta_id, $usuario_id, $accion, $detalles = null) {
    // Verificar si la tabla logs_encuestas existe
    try {
        $stmt = $conexion->prepare("
            INSERT INTO logs_encuestas (encuesta_id, usuario_id, accion, detalles)
            VALUES (?, ?, ?, ?)
        ");
        return $stmt->execute([$encuesta_id, $usuario_id, $accion, $detalles]);
    } catch (Exception $e) {
        // Si la tabla no existe, ignoramos el log
        return false;
    }
}
?>
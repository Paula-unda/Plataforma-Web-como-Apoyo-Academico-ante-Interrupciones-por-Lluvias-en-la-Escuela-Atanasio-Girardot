<?php
// /auth/includes/notificaciones_funciones.php
require_once __DIR__ . '/../../../vendor/autoload.php'; 

require_once 'onesignal_config.php';

use onesignal\client\api\DefaultApi;
use onesignal\client\Configuration;
use onesignal\client\model\Notification;
use onesignal\client\model\StringMap;
use GuzzleHttp\Client;

// =====================================================
// FUNCIÓN PRINCIPAL: ENVÍA NOTIFICACIÓN COMPLETA
// =====================================================

/**
 * Envía notificación a un usuario (BD + EMAIL + PUSH)
 * @param PDO $conexion Conexión a la base de datos
 * @param int $usuario_id ID del usuario destinatario
 * @param string $titulo Título de la notificación
 * @param string $mensaje Mensaje de la notificación
 * @param string $tipo Tipo (actividad, calificacion, contenido, foro, encuesta, sistema)
 * @param int|null $referencia_id ID del elemento relacionado
 * @param string|null $referencia_tipo Tipo de referencia
 * @return bool Éxito o fracaso
 */
function enviarNotificacion($conexion, $usuario_id, $titulo, $mensaje, $tipo = 'sistema', $referencia_id = null, $referencia_tipo = null) {
    
    // 1. GUARDAR EN BASE DE DATOS (siempre)
    try {
        $stmt = $conexion->prepare("
            INSERT INTO notificaciones 
            (usuario_id, titulo, mensaje, tipo, referencia_id, referencia_tipo, prioridad) 
            VALUES (?, ?, ?, ?, ?, ?, 'normal')
        ");
        $stmt->execute([$usuario_id, $titulo, $mensaje, $tipo, $referencia_id, $referencia_tipo]);
        $notificacion_id = $conexion->lastInsertId();
        error_log("✅ Notificación guardada en BD. ID: $notificacion_id");
    } catch (Exception $e) {
        error_log("❌ Error guardando notificación: " . $e->getMessage());
        return false;
    }
    
    // 2. OBTENER DATOS DEL USUARIO
    try {
        $stmt_user = $conexion->prepare("SELECT nombre, correo FROM usuarios WHERE id = ?");
        $stmt_user->execute([$usuario_id]);
        $usuario = $stmt_user->fetch(PDO::FETCH_ASSOC);
        
        if (!$usuario) {
            error_log("❌ Usuario no encontrado: $usuario_id");
            return false;
        }
    } catch (Exception $e) {
        error_log("❌ Error obteniendo datos del usuario: " . $e->getMessage());
        return false;
    }
    
    // 3. ENVIAR EMAIL (si la función existe)
    if (function_exists('enviarCorreoNotificacion')) {
        try {
            enviarCorreoNotificacion($usuario['correo'], $usuario['nombre'], $titulo, $mensaje, $tipo);
        } catch (Exception $e) {
            error_log("❌ Error enviando email: " . $e->getMessage());
            // Continuamos aunque falle el email
        }
    }
    
    // 4. ENVIAR PUSH (OneSignal)
    try {
        $push_result = enviarPushOneSignal($usuario_id, $titulo, $mensaje, $tipo, $referencia_id);
        if ($push_result) {
            error_log("✅ Push enviado correctamente");
        } else {
            error_log("⚠️ Push no enviado (OneSignal)");
        }
    } catch (Exception $e) {
        error_log("❌ Error enviando push: " . $e->getMessage());
        // Continuamos aunque falle el push
    }
    
    return true;
}

// =====================================================
// FUNCIÓN PARA ENVIAR PUSH CON ONESIGNAL
// =====================================================

/**
 * Envía notificación push usando OneSignal
 * @param int $usuario_id ID del usuario
 * @param string $titulo Título de la notificación
 * @param string $mensaje Mensaje de la notificación
 * @param string $tipo Tipo de notificación
 * @param int|null $referencia_id ID de referencia
 * @return bool Éxito o fracaso
 */
function enviarPushOneSignal($usuario_id, $titulo, $mensaje, $tipo, $referencia_id = null) {
    
    // Verificar que las constantes están definidas
    if (!defined('ONESIGNAL_APP_ID') || !defined('ONESIGNAL_REST_API_KEY')) {
        error_log("❌ OneSignal no configurado - constantes faltantes");
        return false;
    }
    
    try {
        // Configurar OneSignal
        $config = Configuration::getDefaultConfiguration()
            ->setAppKeyToken(ONESIGNAL_REST_API_KEY);
        
        $apiInstance = new DefaultApi(
            new Client(),
            $config
        );
        
        // Crear contenido de la notificación
        $content = new StringMap();
        $content->setEn($mensaje);
        
        $headings = new StringMap();
        $headings->setEn($titulo);
        
        // Crear objeto de notificación
        $notification = new Notification();
        $notification->setAppId(ONESIGNAL_APP_ID);
        $notification->setContents($content);
        $notification->setHeadings($headings);
        
        // Enviar a usuario específico por External ID (TU ID de usuario)
        $notification->setIncludeExternalUserIds([(string)$usuario_id]);
        
        // Datos adicionales para manejar en el cliente
        $notification->setData([
            'tipo' => $tipo,
            'referencia_id' => $referencia_id,
            'usuario_id' => $usuario_id,
            'timestamp' => time()
        ]);
        
        // Configurar según el tipo
        switch ($tipo) {
            case 'calificacion':
                $notification->setIosBadgeType('Increase');
                $notification->setIosBadgeCount(1);
                $notification->setPriority(5);
                break;
            case 'actividad':
                $notification->setPriority(10); // Alta prioridad
                break;
            case 'foro':
                $notification->setPriority(3); // Baja prioridad
                break;
        }
        
        // Opciones adicionales
        $notification->setAndroidChannelId('default');
        $notification->setSmallIcon('ic_notification');
        $notification->setLargeIcon('https://tusitio.com/assets/logo.png');
        
        // Enviar
        $result = $apiInstance->createNotification($notification);
        error_log("✅ OneSignal push enviado: " . print_r($result, true));
        return true;
        
    } catch (Exception $e) {
        error_log("❌ Error enviando OneSignal push: " . $e->getMessage());
        return false;
    }
}

// =====================================================
// FUNCIÓN PARA ENVIAR NOTIFICACIÓN A MÚLTIPLES USUARIOS
// =====================================================

/**
 * Envía notificación a MÚLTIPLES usuarios
 * @param PDO $conexion Conexión a la base de datos
 * @param array $usuarios_ids Array de IDs de usuarios
 * @param string $titulo Título de la notificación
 * @param string $mensaje Mensaje de la notificación
 * @param string $tipo Tipo de notificación
 * @param int|null $referencia_id ID de referencia
 * @param string|null $referencia_tipo Tipo de referencia
 * @return int Número de notificaciones enviadas exitosamente
 */
function enviarNotificacionMultiple($conexion, $usuarios_ids, $titulo, $mensaje, $tipo = 'sistema', $referencia_id = null, $referencia_tipo = null) {
    $exitos = 0;
    
    if (empty($usuarios_ids)) {
        return 0;
    }
    
    // Para OneSignal push múltiple, podemos enviar un solo push a varios usuarios
    try {
        $push_result = enviarPushOneSignalMultiple($usuarios_ids, $titulo, $mensaje, $tipo, $referencia_id);
        error_log("📱 Push múltiple: " . ($push_result ? 'OK' : 'Falló'));
    } catch (Exception $e) {
        error_log("❌ Error push múltiple: " . $e->getMessage());
    }
    
    // Para BD y email, procesamos uno por uno
    foreach ($usuarios_ids as $usuario_id) {
        if (enviarNotificacion($conexion, $usuario_id, $titulo, $mensaje, $tipo, $referencia_id, $referencia_tipo)) {
            $exitos++;
        }
    }
    
    return $exitos;
}

// =====================================================
// FUNCIÓN PARA ENVIAR PUSH A MÚLTIPLES USUARIOS
// =====================================================

/**
 * Envía notificación push a MÚLTIPLES usuarios con OneSignal
 */
function enviarPushOneSignalMultiple($usuarios_ids, $titulo, $mensaje, $tipo, $referencia_id = null) {
    
    if (!defined('ONESIGNAL_APP_ID') || !defined('ONESIGNAL_REST_API_KEY')) {
        return false;
    }
    
    try {
        $config = Configuration::getDefaultConfiguration()
            ->setAppKeyToken(ONESIGNAL_REST_API_KEY);
        
        $apiInstance = new DefaultApi(
            new Client(),
            $config
        );
        
        $content = new StringMap();
        $content->setEn($mensaje);
        
        $headings = new StringMap();
        $headings->setEn($titulo);
        
        $notification = new Notification();
        $notification->setAppId(ONESIGNAL_APP_ID);
        $notification->setContents($content);
        $notification->setHeadings($headings);
        
        // Convertir IDs a strings
        $external_ids = array_map('strval', $usuarios_ids);
        $notification->setIncludeExternalUserIds($external_ids);
        
        $notification->setData([
            'tipo' => $tipo,
            'referencia_id' => $referencia_id,
            'timestamp' => time()
        ]);
        
        $result = $apiInstance->createNotification($notification);
        return true;
        
    } catch (Exception $e) {
        error_log("❌ Error push múltiple: " . $e->getMessage());
        return false;
    }
}

// =====================================================
// FUNCIONES AUXILIARES (MANTENER LAS QUE YA TIENES)
// =====================================================

/**
 * Marcar notificaciones como leídas
 */
function marcarNotificacionesLeidas($conexion, $usuario_id, $notificacion_id = null) {
    if ($notificacion_id) {
        $stmt = $conexion->prepare("
            UPDATE notificaciones 
            SET leido = true, fecha_lectura = CURRENT_TIMESTAMP 
            WHERE id = ? AND usuario_id = ?
        ");
        return $stmt->execute([$notificacion_id, $usuario_id]);
    } else {
        $stmt = $conexion->prepare("
            UPDATE notificaciones 
            SET leido = true, fecha_lectura = CURRENT_TIMESTAMP 
            WHERE usuario_id = ? AND leido = false
        ");
        return $stmt->execute([$usuario_id]);
    }
}

/**
 * Obtener notificaciones no leídas
 */
function obtenerNotificacionesNoLeidas($conexion, $usuario_id) {
    $stmt = $conexion->prepare("
        SELECT * FROM notificaciones 
        WHERE usuario_id = ? AND leido = false 
        ORDER BY fecha_envio DESC
    ");
    $stmt->execute([$usuario_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Obtener todas las notificaciones de un usuario
 */
function obtenerTodasNotificaciones($conexion, $usuario_id, $limite = 50) {
    $stmt = $conexion->prepare("
        SELECT * FROM notificaciones 
        WHERE usuario_id = ? 
        ORDER BY fecha_envio DESC
        LIMIT ?
    ");
    $stmt->execute([$usuario_id, $limite]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Eliminar notificaciones antiguas (para mantenimiento)
 */
function limpiarNotificacionesAntiguas($conexion, $dias = 30) {
    $stmt = $conexion->prepare("
        DELETE FROM notificaciones 
        WHERE fecha_envio < NOW() - INTERVAL ? DAY
        AND leido = true
    ");
    return $stmt->execute([$dias]);
}
?>
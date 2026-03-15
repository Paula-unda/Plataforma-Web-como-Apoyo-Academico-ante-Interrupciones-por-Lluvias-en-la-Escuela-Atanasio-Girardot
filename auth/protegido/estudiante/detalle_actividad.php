<?php
session_start();
date_default_timezone_set('America/Caracas'); // o la zona horaria de tu país
require_once '../../funciones.php';
require_once '../includes/onesignal_config.php';

// Recuperar mensajes de sesión (si existen)
$mensaje_exito = $_SESSION['mensaje_exito'] ?? '';
$mensaje_error = $_SESSION['mensaje_error'] ?? '';
unset($_SESSION['mensaje_exito'], $_SESSION['mensaje_error']);

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Estudiante') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

// Obtener ID de la actividad
$actividad_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($actividad_id <= 0) {
    header('Location: actividades.php?error=Actividad+no+encontrada');
    exit();
}

// Obtener datos de la actividad
$actividad = obtenerActividadPorId($actividad_id, $_SESSION['usuario_id']);
if (!$actividad) {
    header('Location: actividades.php?error=Actividad+no+disponible');
    exit();
}

// ✅ Filtrar tipo de actividad (NO mostrar examen)
$tipos_permitidos = ['tarea', 'indicacion'];
if (!in_array($actividad['tipo'], $tipos_permitidos)) {
    header('Location: actividades.php?error=Tipo+de+actividad+no+disponible');
    exit();
}

// ✅ Verificar si ya hay entrega
$entrega = obtenerEntregaEstudiante($actividad_id, $_SESSION['usuario_id']);

// ✅ Verificar si puede reentregar (solo dentro de 15 minutos después de enviar O si el docente reabrió)
$puede_entregar = false;
$tiempo_para_borrar = 0;
$diferencia_minutos = 0;

if (!$entrega) {
    // No hay entrega, puede entregar
    $puede_entregar = true;
    error_log("📝 No hay entrega previa - Puede entregar");
} elseif ($entrega['estado'] === 'calificado') {
    // Si ya está calificado, no puede entregar
    $puede_entregar = false;
    error_log("📝 Entrega calificada - No puede modificar");
} elseif ($entrega['estado'] === 'pendiente') {
    // Si el docente reabrió, puede entregar
    $puede_entregar = true;
    
    // Verificar si ya tiene fecha de entrega (por si acaso)
    if (empty($entrega['fecha_entrega'])) {
        // Es una reapertura fresca, dar 15 minutos completos
        $tiempo_para_borrar = 15;
        error_log("📝 Estado pendiente (recién reabierto) - 15 minutos disponibles");
    } else {
        // Si ya tenía fecha (caso raro), calcular tiempo restante
        $fecha_limpia = preg_replace('/\..*/', '', $entrega['fecha_entrega']);
        $fecha_entrega_timestamp = strtotime($fecha_limpia);
        $tiempo_actual = time();
        $diferencia_minutos = ($tiempo_actual - $fecha_entrega_timestamp) / 60;
        
        if ($diferencia_minutos > 180) {
            $diferencia_minutos = $diferencia_minutos - 240;
        }
        
        $tiempo_restante = 15 - $diferencia_minutos;
        $tiempo_para_borrar = $tiempo_restante > 0 ? ceil($tiempo_restante) : 0;
        
        if ($tiempo_para_borrar <= 0) {
            $puede_entregar = false;
        }
        error_log("📝 Estado pendiente con fecha - Tiempo restante: $tiempo_para_borrar min");
    }

} else {
    // Si es 'enviado' o 'atrasado', calcular tiempo exacto
    // Limpiar la fecha (eliminar los milisegundos .754753)
    $fecha_limpia = preg_replace('/\..*/', '', $entrega['fecha_entrega']);
    $fecha_entrega_timestamp = strtotime($fecha_limpia);
    $tiempo_actual = time();
    
    // 🔴 COMPENSAR DIFERENCIA DE ZONA HORARIA
    // Si la diferencia es muy grande (> 180 minutos), asumimos que es por zona horaria
    $diferencia_minutos = ($tiempo_actual - $fecha_entrega_timestamp) / 60;
    
    // Si la diferencia es mayor a 3 horas (180 minutos), restamos 4 horas (240 minutos)
    if ($diferencia_minutos > 180) {
        $diferencia_minutos = $diferencia_minutos - 240;
    }
    
    error_log("📝 Estado: " . $entrega['estado']);
    error_log("📝 Fecha original: " . $entrega['fecha_entrega']);
    error_log("📝 Fecha limpia: " . $fecha_limpia);
    error_log("📝 Timestamp entrega: " . $fecha_entrega_timestamp);
    error_log("📝 Timestamp actual: " . $tiempo_actual);
    error_log("📝 Diferencia minutos (cruda): " . (($tiempo_actual - $fecha_entrega_timestamp) / 60));
    error_log("📝 Diferencia minutos (corregida): " . $diferencia_minutos);
    
    if ($diferencia_minutos <= 15) {
        $puede_entregar = true;
        $tiempo_para_borrar = ceil(15 - $diferencia_minutos);
        error_log("✅ DENTRO de 15 minutos - Puede modificar. Tiempo restante: $tiempo_para_borrar min");
    } else {
        $puede_entregar = false;
        error_log("❌ FUERA de 15 minutos - No puede modificar. Diferencia: $diferencia_minutos min");
    }
}
// Determinar estado visual
$estado_texto = $entrega ? $entrega['estado'] : 'pendiente';
$estado_badge = '';
switch ($estado_texto) {
    case 'pendiente': 
        $estado_badge = '<span class="badge pendiente">Pendiente</span>'; 
        break;
    case 'enviado': 
        $estado_badge = '<span class="badge enviado">Entregado</span>'; 
        break;
    case 'atrasado': 
        $estado_badge = '<span class="badge atrasado">Atrasado</span>'; 
        break;
    case 'calificado': 
        $estado_badge = '<span class="badge calificado">Calificado</span>'; 
        break;
}

// Obtener contenidos vinculados
// Obtener contenidos vinculados (intento con función)
$contenidos_vinculados = obtenerContenidosDeActividad($actividad_id);

// Obtener contenidos vinculados - CONSULTA DIRECTA
try {
    $conexion = getConexion();
    $query = "
        SELECT c.*
        FROM actividades_contenidos ac
        INNER JOIN contenidos c ON ac.contenido_id = c.id
        WHERE ac.actividad_id = :id
    ";
    $stmt = $conexion->prepare($query);
    $stmt->execute(['id' => $actividad_id]);
    $contenidos_vinculados = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error en consulta directa: " . $e->getMessage());
    $contenidos_vinculados = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($actividad['titulo']); ?> - SIEDUCRES</title>
    <?php require_once '../includes/favicon.php'; ?>
    <?php require_once '../includes/header_onesignal.php'; ?> 
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            /* ✅ COLORES DEL PROYECTO */
            --primary-cyan: #4BC4E7;
            --primary-pink: #EF5E8E;
            --primary-lime: #C2D54E;
            --primary-purple: #9B8AFB;
            
            --text-dark: #333333;
            --text-muted: #666666;
            --border: #E0E0E0;
            --surface: #FFFFFF;
            --background: #F5F5F5;
            --success: #C2D54E;  /* ✅ Lime en lugar de verde */
            --warning: #ffc107;
            --danger: #EF5E8E;   /* ✅ Pink en lugar de rojo */
        }
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--background); 
            color: var(--text-dark); 
            min-height: 100vh; 
            display: flex; 
            flex-direction: column; 
        }
        
        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 0 24px; 
            height: 60px; 
            background-color: var(--surface); 
            border-bottom: 1px solid var(--border); 
            box-shadow: 0 1px 3px rgba(0,0,0,0.05); 
            position: relative; 
            z-index: 100; 
        }
        .logo { height: 40px; }
        .header-right { display: flex; align-items: center; gap: 16px; }
        .icon-btn { 
            width: 36px; 
            height: 36px; 
            border-radius: 50%; 
            background-color: #F0F0F0; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            cursor: pointer; 
            transition: background 0.2s; 
        }
        .icon-btn:hover { background-color: #E0E0E0; }
        .icon-btn img { width: 20px; height: 20px; object-fit: contain; }
        .menu-dropdown { 
            position: absolute; 
            top: 60px; 
            right: 24px; 
            background: white; 
            border: 1px solid var(--border); 
            border-radius: 8px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.1); 
            display: none; 
            min-width: 180px; 
        }
        .menu-item { 
            padding: 10px 16px; 
            font-size: 14px; 
            color: var(--text-dark); 
            text-decoration: none; 
            display: block; 
            transition: background 0.2s; 
        }
        .menu-item:hover { background-color: #F8F8F8; }
        .banner { position: relative; height: 100px; overflow: hidden; }
        .banner-image { width: 100%; height: 100%; object-fit: cover; object-position: top; }
        .banner-content { 
            text-align: center; 
            position: relative; 
            z-index: 2; 
            max-width: 800px; 
            padding: 20px; 
            margin: 0 auto; 
        }
        .banner-title { font-size: 36px; font-weight: 700; color: var(--text-dark); margin-bottom: 8px; }
        .banner-subtitle { font-size: 18px; color: var(--text-muted); }
        .footer { 
            height: 50px; 
            background-color: var(--surface); 
            border-top: 1px solid var(--border); 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 0 24px; 
            font-size: 13px; 
            color: var(--text-muted); 
            position: sticky; 
            bottom: 0; 
            left: 0; 
            right: 0; 
        }

        .main-content { 
            flex: 1; 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            justify-content: center; 
            padding: 40px 20px; 
        }
        .content-container { width: 100%; max-width: 900px; }
        .info-card { 
            background: var(--surface); 
            border-radius: 16px; 
            padding: 32px; 
            margin-bottom: 24px; 
            box-shadow: 0 6px 16px rgba(0,0,0,0.05); 
        }
        .content-title { font-size: 28px; font-weight: 700; margin-bottom: 24px; color: var(--text-dark); }
        .content-meta { 
            display: flex; 
            flex-wrap: wrap; 
            gap: 20px; 
            padding: 16px 0; 
            margin-bottom: 24px; 
            border-bottom: 1px solid var(--border); 
        }
        .meta-item { display: flex; align-items: center; gap: 8px; font-size: 14px; color: var(--text-muted); }
        .meta-item strong { color: var(--text-dark); font-weight: 600; }
        .content-description { 
            font-size: 16px; 
            line-height: 1.6; 
            color: var(--text-muted); 
            margin-bottom: 24px; 
            text-align: justify; 
            white-space: pre-line; 
        }

        /* ✅ ESTADO - Color Cyan del Proyecto (SIN DEGRADADO) */
        .estado-section { 
            margin: 24px 0; 
            padding: 20px; 
            border-radius: 12px; 
            background: var(--primary-cyan); /* ✅ Color sólido, no degradado */
            color: var(--text-dark); /* ✅ Texto negro */
        }
        .estado-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .estado-title { font-size: 18px; font-weight: 600; color: var(--text-dark); }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 600; }
        .badge.pendiente { background: rgba(255,255,255,0.5); color: var(--text-dark); }
        .badge.enviado { background: var(--primary-lime); color: var(--text-dark); }
        .badge.atrasado { background: var(--warning); color: var(--text-dark); }
        .badge.calificado { background: var(--primary-purple); color: white; font-weight: 700; }

        .vinculados-section { margin: 24px 0; padding: 20px; background: #f8f9fa; border-radius: 12px; }
        .vinculados-title { font-size: 18px; font-weight: 600; margin-bottom: 12px; color: var(--text-dark); }
        .vinculado-item { padding: 10px; margin: 8px 0; background: white; border-radius: 8px; border-left: 3px solid var(--primary-cyan); }
        .vinculado-item a { color: var(--primary-cyan); text-decoration: none; font-weight: 500; }
        .vinculado-item a:hover { text-decoration: underline; }

        .entrega-section { margin: 32px 0; padding: 24px; background: #f8f9fa; border-radius: 16px; border: 1px dashed var(--border); }
        .entrega-title { font-size: 20px; font-weight: 700; margin-bottom: 16px; color: var(--text-dark); }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 14px; color: var(--text-dark); }
        .form-control { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; font-family: 'Inter', sans-serif; }
        .form-control:disabled { background-color: #e0e0e0; cursor: not-allowed; }
        
        /* ✅ BOTONES - Colores del Proyecto */
        .btn-submit { 
            background: var(--primary-lime); 
            color: var(--text-dark); 
            padding: 12px 24px; 
            border: none; 
            border-radius: 8px; 
            font-weight: 600; 
            cursor: pointer; 
            transition: all 0.2s; 
            margin-top: 16px; 
        }
        .btn-submit:hover { background: #a8bc42; transform: translateY(-2px); }
        .btn-submit:disabled { background: #cccccc; cursor: not-allowed; transform: none; }
        
        .btn-secondary { 
            background: var(--surface); 
            color: var(--text-dark); 
            border: 1px solid var(--border); 
            padding: 10px 20px; 
            border-radius: 8px; 
            text-decoration: none; 
            display: inline-block; 
            margin-top: 16px; 
            font-weight: 600; 
        }
        .btn-secondary:hover { background: #f0f0f0; }
        
        .btn-primary { 
            background: var(--primary-cyan); 
            color: var(--text-dark); 
            border: none; 
            padding: 10px 20px; 
            border-radius: 8px; 
            text-decoration: none; 
            display: inline-block; 
            margin-top: 16px; 
            font-weight: 600; 
        }
        .btn-primary:hover { background: #3ab3d6; }
        
        .btn-danger { 
            background: var(--primary-pink); 
            color: white; 
            border: none; 
            padding: 10px 20px; 
            border-radius: 8px; 
            text-decoration: none; 
            display: inline-block; 
            margin-top: 16px; 
            font-weight: 600; 
        }
        .btn-danger:hover { background: #d64a7a; }

        .mensaje { padding: 12px 20px; border-radius: 8px; margin: 16px 0; display: none; }
        .mensaje.exito { background: rgba(194,213,78,0.2); color: var(--text-dark); border: 1px solid var(--primary-lime); display: block; }
        .mensaje.error { background: rgba(239,94,142,0.2); color: var(--text-dark); border: 1px solid var(--primary-pink); display: block; }

        /* ✅ TABLA DE ESTADO - Más legible */
        .estado-entrega-table { 
        margin-top: 20px; 
        background: white;
        border: 1px solid var(--primary-cyan); /* ✅ Borde exterior cyan */
        border-radius: 9px; /* ✅ Bordes redondeados */
        padding: 5px; /* ✅ Espacio interno */
        box-shadow: 0 4px 12px rgba(75, 196, 231, 0.2); /* ✅ Sombra suave cyan */
    }
    .estado-entrega-table h3 { 
        margin-bottom: 16px; 
        color: var(--text-dark);
        padding-bottom: 12px;
        border-bottom: 2px solid var(--primary-cyan); /* ✅ Línea debajo del título */
    }
        .estado-entrega-table table { 
            width: 100%; 
            border-collapse: collapse; 
            background: white; 
            border-radius: 8px; 
            overflow: hidden; 
            border: 2px solid var(--primary-cyan); /* ✅ Borde exterior cyan */
        }
        .estado-entrega-table td { 
            padding: 14px 16px; 
            border: 1px solid var(--primary-cyan); /* ✅ Bordes internos cyan */
            color: var(--text-dark);
        }
        .estado-entrega-table td:first-child { 
            font-weight: 600; 
            width: 30%; 
            background: rgba(75, 196, 231, 0.1); /* ✅ Fondo cyan muy suave */
            color: var(--text-dark);
            border-right: 2px solid var(--primary-cyan); /* ✅ Línea vertical más gruesa */
        }
        .estado-entrega-table .archivo-link { 
            color: var(--primary-cyan); 
            text-decoration: none; 
            font-weight: 600; 
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .estado-entrega-table .archivo-link:hover { 
            text-decoration: underline; 
            color: var(--primary-purple);
        }
        /* ✅ Colores para calificación en tabla */
        .estado-entrega-table .calificacion-alta { 
            color: var(--text-dark);
            font-weight: 700; 
            font-size: 18px;
        }
        .estado-entrega-table .calificacion-baja { 
            color: var(--text-dark); 
            font-weight: 700; 
            font-size: 18px;
        }
        

        /* ✅ Botones debajo de la tabla */
        .botones-accion { margin-top: 24px; display: flex; gap: 12px; flex-wrap: wrap; }

        /* ✅ Texto de calificación en estado-section */
        .calificacion-texto-alta {
            color: var(--text-dark); 
            font-weight: 700;
            font-size: 18px;
        }
        .calificacion-texto-baja {
            color: var(--text-dark); 
            font-weight: 700;
            font-size: 18px;
        }

        @media (max-width: 768px) {
            .banner-title { font-size: 28px; }
            .banner { height: 160px; }
            .info-card { padding: 24px; }
            .content-title { font-size: 24px; }
            .botones-accion { flex-direction: column; }
            .botones-accion .btn { width: 100%; text-align: center; }
        }
        /* ✅ ESTILOS RESPONSIVOS PARA MÓVILES */
        @media (max-width: 768px) {
            .estado-entrega-table {
                margin-top: 24px;
                padding: 12px; /* ✅ Menos padding en móvil */
                border: 2px solid var(--primary-cyan);
                border-radius: 12px;
                background: white;
                box-shadow: 0 4px 12px rgba(75, 196, 231, 0.2);
            }
            
            .estado-entrega-table h3 {
                font-size: 16px; /* ✅ Título más pequeño */
                margin-bottom: 12px;
                padding-bottom: 8px;
                border-bottom: 2px solid var(--primary-cyan);
            }
            
            .estado-entrega-table table {
                font-size: 13px; /* ✅ Texto más pequeño */
            }
            
            .estado-entrega-table td {
                padding: 10px 8px; /* ✅ Menos padding en celdas */
                border: 1px solid var(--primary-cyan);
            }
            
            .estado-entrega-table td:first-child {
                width: 40%; /* ✅ Más espacio para etiquetas */
                font-size: 12px;
                border-right: 2px solid var(--primary-cyan);
            }
            
            .estado-entrega-table .archivo-link {
                font-size: 12px; /* ✅ Enlace más pequeño */
                word-break: break-all; /* ✅ Rompe texto largo */
            }
            
            .botones-accion {
                flex-direction: column; /* ✅ Botones en columna */
                gap: 8px;
            }
            
            .botones-accion .btn {
                width: 100%; /* ✅ Botones ancho completo */
                text-align: center;
            }
            
            .info-card {
                padding: 20px; /* ✅ Menos padding en tarjeta */
            }
            
            .content-title {
                font-size: 20px; /* ✅ Título más pequeño */
            }
            
            .estado-section {
                padding: 16px; /* ✅ Menos padding en estado */
            }
            
            .estado-title {
                font-size: 16px; /* ✅ Título más pequeño */
            }
        }

        /* ✅ Para pantallas muy pequeñas (menos de 480px) */
        @media (max-width: 480px) {
            .estado-entrega-table td {
                padding: 8px 6px;
                font-size: 11px; /* ✅ Texto aún más pequeño */
            }
            
            .estado-entrega-table td:first-child {
                width: 45%; /* ✅ Más espacio para etiquetas */
                font-size: 11px;
            }
            
            .estado-entrega-table .archivo-link {
                font-size: 11px;
            }
        }
        @media (max-width: 768px) {
        .banner-title { font-size: 28px; }
        .banner { height: 160px; }
        .info-card { padding: 24px; }
        .content-title { font-size: 24px; }
        .botones-accion { flex-direction: column; }
        .botones-accion .btn { width: 100%; text-align: center; }
        
        /* ✅ CORRECCIÓN: Descripción no se sale del contenedor */
        .content-description {
            word-wrap: break-word;
            overflow-wrap: break-word;
            hyphens: auto;
            max-width: 100%;
            padding: 0 5px;
        }
        
        /* ✅ Asegurar que todo el contenido respete los límites */
        .content-container,
        .info-card,
        .content-description,
        .estado-section,
        .vinculados-section,
        .entrega-section {
            max-width: 100%;
            overflow-x: hidden;
        }
        
        /* ✅ Tabla responsive mejorada */
        .estado-entrega-table {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .estado-entrega-table table {
            min-width: 100%;
        }
        
        .estado-entrega-table td {
            word-break: break-word;
        }
    }
    @media (max-width: 480px) {
        .estado-entrega-table td {
            padding: 8px 6px;
            font-size: 11px;
        }
        
        .estado-entrega-table td:first-child {
            width: 45%;
            font-size: 11px;
        }
        
        .estado-entrega-table .archivo-link {
            font-size: 11px;
            word-break: break-all;
        }
        
        /* ✅ Ajuste adicional para descripción */
        .content-description {
            font-size: 14px;
            line-height: 1.5;
        }
    }
    </style>
</head>
<body>
    <?php require_once '../includes/header_comun.php'; ?>

    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image">
    </div>
    <div class="banner-content">
        <h1 class="banner-title"><?php echo htmlspecialchars($actividad['titulo']); ?></h1>
        <p class="banner-subtitle"><?php echo ucfirst(htmlspecialchars($actividad['tipo'])); ?> • <?php echo htmlspecialchars($actividad['asignatura'] ?? 'Actividad educativa'); ?></p>
    </div>

    <main class="main-content">
        <div class="content-container">
            
            <!-- Mensajes de éxito/error desde sesión -->
            <?php if ($mensaje_exito): ?>
                <div class="mensaje mensaje-exito" style="display: block;">
                    <?php echo htmlspecialchars($mensaje_exito); ?>
                </div>
            <?php endif; ?>

            <?php if ($mensaje_error): ?>
                <div class="mensaje mensaje-error" style="display: block;">
                    <?php echo htmlspecialchars($mensaje_error); ?>
                </div>
            <?php endif; ?>
            <div class="info-card">
                <h1 class="content-title"><?php echo htmlspecialchars($actividad['titulo']); ?></h1>
                
                <div class="content-meta">
                    <div class="meta-item">
                        <strong> Docente:</strong> <?php echo htmlspecialchars($actividad['docente_nombre'] ?? 'No especificado'); ?>
                    </div>
                    <div class="meta-item">
                        <strong> Tipo:</strong> <?php echo ucfirst(htmlspecialchars($actividad['tipo'])); ?>
                    </div>
                    <div class="meta-item">
                        <strong> Fecha límite:</strong> 
                        <?php 
                        $fecha = !empty($actividad['fecha_entrega']) && $actividad['fecha_entrega'] !== '0000-00-00' 
                            ? date('d/m/Y', strtotime($actividad['fecha_entrega'])) 
                            : 'Fecha no especificada';
                        echo $fecha;
                        ?>
                    </div>
                    <div class="meta-item">
                        <strong> Dirigido a:</strong> <?php echo htmlspecialchars($actividad['grado'] ?? 'Todos'); ?> <?php echo htmlspecialchars($actividad['seccion'] ?? ''); ?>
                    </div>
                </div>

                <!-- ✅ ESTADO DE TU ENTREGA (Arriba - Color sólido cyan, texto negro) -->
                <div class="estado-section">
                    <div class="estado-header">
                        <span class="estado-title">Estado de tu entrega</span>
                        <?php echo $estado_badge; ?>
                    </div>
                    <div>
                        <?php if ($entrega): ?>
                            <?php if (!empty($entrega['fecha_entrega'])): ?>
                                <p style="color: var(--text-dark);"> Entregado el: <strong><?php echo date('d/m/Y H:i', strtotime($entrega['fecha_entrega'])); ?></strong></p>
                            <?php endif; ?>     

                            <!-- ✅ CALIFICACIÓN CON COLORES DEL PROYECTO (Lime/Pink) -->
                            <?php if ($entrega['calificacion'] !== null && $entrega['calificacion'] !== '' && $entrega['calificacion'] >= 0): ?>
                                <p style="margin-top:12px; font-size:18px; font-weight:700; color: var(--text-dark);">
                                     Calificación: 
                                    <span style="color: var(--text-dark); font-size: 20px;">
                                        <?php echo number_format(floatval($entrega['calificacion']), 2); ?>/20
                                    </span>
                                </p>
                                <?php if (!empty($entrega['observaciones'])): ?>
                                    <p style="color: var(--text-dark); margin-top: 8px;"><strong>Observaciones:</strong> <?php echo nl2br(htmlspecialchars($entrega['observaciones'])); ?></p>
                                <?php endif; ?>
                            <?php else: ?>
                                <p style="margin-top:12px; color: var(--text-dark); opacity: 0.8;">⏳ Esperando calificación del docente...</p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p style="color: var(--text-dark);"> Aún no has entregado esta actividad</p>
                        <?php endif; ?>
                    </div>
                </div>

                <p class="content-description">
                    <?php echo htmlspecialchars($actividad['descripcion']); ?>
                </p>

                <?php if (!empty($contenidos_vinculados)): ?>
                <div class="vinculados-section">
                    <div class="vinculados-title"> Contenidos relacionados</div>
                    <?php foreach ($contenidos_vinculados as $cont): ?>
                    <div class="vinculado-item">
                        <a href="contenido_detalle.php?id=<?php echo $cont['id']; ?>">
                            • <?php echo htmlspecialchars($cont['titulo']); ?>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- ✅ FORMULARIO DE ENTREGA (solo si puede entregar) -->
                <?php if ($puede_entregar): ?>
                    <div class="entrega-section">
                        <h2 class="entrega-title"> Entrega de actividad</h2>
                        
                        <?php if ($tiempo_para_borrar > 0): ?>
                            <p style="color: var(--warning); margin-bottom: 16px; font-weight: 600;">
                                 Puedes modificar tu entrega por <?php echo $tiempo_para_borrar; ?> minutos más
                            </p>
                        <?php endif; ?>
                        
                        <div class="mensaje mensaje-exito" id="mensaje-exito" style="display:none;">
                            ¡Entrega registrada exitosamente! Tu trabajo ha sido enviado correctamente.
                        </div>
                        <div class="mensaje mensaje-error" id="mensaje-error" style="display:none;">
                            Error al enviar la entrega. Por favor, inténtalo de nuevo.
                        </div>
                        
                        <form action="procesar_entrega.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="actividad_id" value="<?php echo $actividad_id; ?>">
                            
                            <div class="form-group">
                                <label for="comentario">Comentario (opcional)</label>
                                <textarea id="comentario" name="comentario" class="form-control" rows="3"><?php echo $entrega ? htmlspecialchars($entrega['comentario'] ?? '') : ''; ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="archivo">Archivo de entrega (PDF, DOC, JPG, PNG)</label>
                                <input type="file" id="archivo" name="archivo" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" <?php echo !$puede_entregar ? 'disabled' : ''; ?>>
                                <?php if ($entrega && !empty($entrega['archivo_entregado'])): ?>
                                    <p style="margin-top: 8px; font-size: 13px;">
                                        Archivo actual: <a href="../../../uploads/entregas/<?php echo htmlspecialchars($entrega['archivo_entregado']); ?>" target="_blank"><?php echo htmlspecialchars(basename($entrega['archivo_entregado'])); ?></a>
                                    </p>
                                <?php endif; ?>
                            </div>
                            
                            <button type="submit" class="btn-submit" <?php echo !$puede_entregar ? 'disabled' : ''; ?>>
                                <?php echo $entrega ? 'Actualizar entrega' : 'Enviar entrega'; ?>
                            </button>
                        </form>
                    </div>
                <?php elseif ($entrega && $entrega['estado'] !== 'calificado'): ?>
    
                    <?php 
                    // Calcular tiempo transcurrido para mostrar mensaje correcto
                    $fecha_entrega = strtotime($entrega['fecha_entrega']);
                    $diferencia_minutos = (time() - $fecha_entrega) / 60;
                    ?>
                    
                    <?php if ($diferencia_minutos <= 15): ?>
                        <!-- Mensaje cuando DENTRO de los 15 minutos -->
                        <div class="entrega-section" style="background: #e8f5e9; border: 1px solid #4BC4E7;">
                            <h2 class="entrega-title" style="color: var(--text-dark);"> ⏳ Puedes modificar tu entrega</h2>
                            <p style="color: var(--text-muted); margin-top: 12px;">
                                Ya has entregado esta actividad, pero aún estás dentro de los 15 minutos permitidos para modificarla.
                                Usa el botón "Borrar entrega" si deseas enviar un nuevo archivo.
                            </p>
                        </div>
                    <?php else: ?>
                        <!-- Mensaje cuando FUERA de los 15 minutos -->
                        <div class="entrega-section" style="background: #fff3cd; border: 1px solid #0a0a0a;">
                            <h2 class="entrega-title" style="color: var(--text-dark);"> Tiempo de modificación expirado</h2>
                            <p style="color: var(--text-muted); margin-top: 12px;">
                                Ya has entregado esta actividad y ha pasado el tiempo límite de 15 minutos para modificarla. 
                                Puedes ver el estado de tu entrega en la tabla de abajo.
                            </p>
                        </div>
                    <?php endif; ?>
                    
                <?php elseif ($entrega && $entrega['estado'] === 'calificado'): ?>
                    <!-- ✅ Mensaje cuando ya fue calificado -->
                    <div class="entrega-section" style="background: #e8f5e9; border: 1px solid #c8e6c9;">
                        <h2 class="entrega-title" style="color: var(--text-dark);"> Actividad calificada</h2>
                        <p style="color: var(--text-muted); margin-top: 12px;">
                            Tu entrega ha sido revisada y calificada por el docente.
                        </p>
                    </div>
                <?php endif; ?>

                <!-- ✅ TABLA DE ESTADO DE ENTREGA (SIEMPRE VISIBLE - Más legible) -->
                <div class="estado-entrega-table">
                    <h3> Estado de la entrega:</h3>
                    <table>
                        <tr>
                            <td>Estado de la entrega:</td>
                            <td><?php echo $entrega ? ucfirst($entrega['estado']) : 'No se ha enviado nada en esta tarea'; ?></td>
                        </tr>
                        <tr>
                            <td>Fecha y hora de entrega:</td>
                            <td>
                                <?php 
                                if ($entrega && !empty($entrega['fecha_entrega']) && $entrega['fecha_entrega'] !== NULL) {
                                    // Verificar que la fecha sea válida antes de usar strtotime
                                    $timestamp = strtotime($entrega['fecha_entrega']);
                                    if ($timestamp && $timestamp > 0) {
                                        echo date('d/m/Y \a \l\a\s H:i', $timestamp);
                                    } else {
                                        echo '<span style="color: var(--text-muted);">—</span>';
                                    }
                                } else {
                                    echo '<span style="color: var(--text-muted);">—</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Archivo entregado:</td>
                            <td>
                                <?php 
                                if ($entrega && !empty($entrega['archivo_entregado'])) {
                                    echo '<a href="../../../uploads/entregas/' . htmlspecialchars($entrega['archivo_entregado']) . '" class="archivo-link" target="_blank" download>';
                                    echo ' ' . htmlspecialchars(basename($entrega['archivo_entregado']));
                                    echo '</a>';
                                } else {
                                    echo '<span style="color: var(--text-muted);">—</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Estado de la calificación:</td>
                            <td>
                                <?php 
                                if ($entrega && isset($entrega['calificacion']) && $entrega['calificacion'] !== null && $entrega['calificacion'] !== '' && $entrega['calificacion'] >= 0) {
                                    echo '<span style="color: var(--text-dark); font-weight: 600;"> Calificado</span>';
                                } else {
                                    echo '<span style="color: var(--text-muted);"> Pendiente / Por calificar</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Calificación:</td>
                            <td>
                                <?php 
                                if ($entrega && isset($entrega['calificacion']) && $entrega['calificacion'] !== null && $entrega['calificacion'] !== '' && $entrega['calificacion'] >= 0) {
                                    $nota = floatval($entrega['calificacion']);
                                    echo '<span style="color: var(--text-dark); font-weight: 700; font-size: 18px;">';
                                    echo number_format($nota, 2) . '/20';
                                    echo '</span>';
                                } else {
                                    echo '<span style="color: var(--text-muted);">0/20</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Retroalimentación:</td>
                            <td><?php echo ($entrega && !empty($entrega['observaciones'])) ? htmlspecialchars($entrega['observaciones']) : '<span style="color: var(--text-muted);">Sin retroalimentación aún</span>'; ?></td>
                        </tr>
                    </table>
                </div>

                <!-- ✅ BOTÓN DE CONTENIDO VINCULADO -->
                <?php if (!empty($contenidos_vinculados)): ?>
                <div style="margin: 30px 0; text-align: center;">
                    <a href="contenido_detalle.php?id=<?php echo $contenidos_vinculados[0]['id']; ?>" 
                    style="display: inline-block; 
                            background: linear-gradient(135deg, var(--primary-purple), var(--primary-cyan));
                            color: white; 
                            padding: 18px 50px; 
                            border-radius: 60px; 
                            text-decoration: none; 
                            font-size: 18px; 
                            font-weight: 700; 
                            box-shadow: 0 8px 20px rgba(75, 196, 231, 0.4);
                            transition: all 0.3s;
                            border: none;">
                        📚 VER CONTENIDO DE ESTUDIO: <?php echo htmlspecialchars($contenidos_vinculados[0]['titulo']); ?>
                    </a>
                    <p style="color: var(--text-muted); margin-top: 10px; font-size: 14px;">
                        Contenido vinculado a esta actividad
                    </p>
                </div>
                <?php endif; ?>
  

                <!-- Botones secundarios -->
                <div style="display: flex; gap: 15px; justify-content: center; margin: 20px 0; flex-wrap: wrap;">
                    <a href="actividades.php" 
                    style="display: inline-block; 
                            background: var(--surface); 
                            color: var(--text-dark); 
                            padding: 12px 30px; 
                            border-radius: 30px; 
                            text-decoration: none; 
                            font-weight: 600; 
                            border: 2px solid var(--border);">
                        ← Volver a actividades
                    </a>
                    
                    <?php if ($entrega && $entrega['estado'] !== 'calificado' && $tiempo_para_borrar > 0): ?>
                        <button onclick="borrarEntrega()"
                                style="display: inline-block; 
                                    background: var(--primary-pink); 
                                    color: white; 
                                    padding: 12px 30px; 
                                    border-radius: 30px; 
                                    border: none; 
                                    font-weight: 600; 
                                    cursor: pointer;">
                            🗑️ Borrar entrega
                        </button>
                    <?php endif; ?>
                </div>
                
            </div>
        </div>
    </main>

    <footer class="footer">
        <span>v2.0.0</span>
        <span>Soporte Técnico</span>
    </footer>

    <script>
        // Toggle menú hamburguesa
        document.getElementById('menu-toggle').addEventListener('click', function() {
            const dropdown = document.getElementById('dropdown');
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        });
        document.addEventListener('click', function(e) {
            const dropdown = document.getElementById('dropdown');
            const toggle = document.getElementById('menu-toggle');
            if (!toggle.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });

        

        // Función para borrar entrega (dentro de 15 minutos)
        function borrarEntrega() {
            if (!confirm('¿Estás seguro de que deseas borrar tu entrega? Solo puedes hacer esto dentro de los 15 minutos posteriores al envío.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('actividad_id', <?php echo $actividad_id; ?>);
            formData.append('accion', 'borrar');
            
            fetch('procesar_entrega.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                console.log('Respuesta borrar:', data);
                
                if (data.success) {
                    // ✅ Mostrar mensaje y recargar
                    alert('✅ Entrega borrada exitosamente');
                    window.location.href = 'detalle_actividad.php?id=' + <?php echo $actividad_id; ?>;
                } else {
                    alert('❌ Error: ' + (data.error || 'No se pudo borrar la entrega'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('❌ Error de conexión');
            });
        }
    </script>
</body>
</html>
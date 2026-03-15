<?php
session_start();
require_once '../../funciones.php';
require_once '../../../vendor/autoload.php';
require_once '../includes/onesignal_config.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!sesionActiva()) {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$conexion = getConexion();
$usuario_rol = $_SESSION['usuario_rol'];
$usuario_id = $_SESSION['usuario_id'];

// =====================================================
// OBTENER FILTROS SEGÚN EL ROL
// =====================================================

// Para DOCENTE: obtener su grado y sección
$grado_docente = '';
$seccion_docente = '';
if ($usuario_rol === 'Docente') {
    $stmt = $conexion->prepare("SELECT grado, seccion FROM docentes WHERE usuario_id = ?");
    $stmt->execute([$usuario_id]);
    $docente = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($docente) {
        $grado_docente = $docente['grado'];
        $seccion_docente = $docente['seccion'];
    }
}

// Para ESTUDIANTE: obtener su ID
$estudiante_id = 0;
if ($usuario_rol === 'Estudiante') {
    $estudiante_id = $usuario_id;
}

// Para REPRESENTANTE: obtener los IDs de sus estudiantes
$estudiantes_representado = [];
if ($usuario_rol === 'Representante') {
    $stmt = $conexion->prepare("
        SELECT estudiante_id FROM representantes_estudiantes WHERE representante_id = ?
    ");
    $stmt->execute([$usuario_id]);
    $estudiantes_representado = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// =====================================================
// PERÍODOS PARA FILTRO (todos los roles)
// =====================================================
$periodos = $conexion->query("
    SELECT * FROM periodos_escolares 
    WHERE activo = true 
    ORDER BY año_escolar DESC, lapso ASC
")->fetchAll(PDO::FETCH_ASSOC);

// =====================================================
// CONSTRUIR CONSULTAS SEGÚN EL ROL
// =====================================================

$periodo_id = $_GET['periodo_id'] ?? ($periodos[0]['id'] ?? 0);
$grado_filtro = $_GET['grado'] ?? $grado_docente;
$seccion_filtro = $_GET['seccion'] ?? $seccion_docente;

// Obtener período activo
$periodo_activo = null;
if ($periodo_id) {
    $stmt = $conexion->prepare("SELECT * FROM periodos_escolares WHERE id = ?");
    $stmt->execute([$periodo_id]);
    $periodo_activo = $stmt->fetch(PDO::FETCH_ASSOC);
}

// =====================================================
// 1. MÉTRICAS GENERALES (según rol) - CORREGIDO
// =====================================================

$metricas = [];

if ($usuario_rol === 'Administrador') {
    // Admin ve TODO el sistema
    $metricas['total_usuarios'] = $conexion->query("SELECT COUNT(*) FROM usuarios WHERE activo = true")->fetchColumn();
    $metricas['total_estudiantes'] = $conexion->query("SELECT COUNT(*) FROM estudiantes")->fetchColumn();
    $metricas['total_docentes'] = $conexion->query("SELECT COUNT(*) FROM docentes")->fetchColumn();
    $metricas['total_representantes'] = $conexion->query("
        SELECT COUNT(DISTINCT representante_id) FROM representantes_estudiantes
    ")->fetchColumn();
    
} elseif ($usuario_rol === 'Docente') {
    // CORREGIDO: Separar prepare y execute correctamente
    $stmt_est = $conexion->prepare("SELECT COUNT(*) FROM estudiantes WHERE grado = ? AND seccion = ?");
    $stmt_est->execute([$grado_docente, $seccion_docente]);
    $metricas['total_estudiantes'] = $stmt_est->fetchColumn();
    
    $stmt_act = $conexion->prepare("SELECT COUNT(*) FROM actividades WHERE docente_id = ? AND activo = true");
    $stmt_act->execute([$usuario_id]);
    $metricas['total_actividades'] = $stmt_act->fetchColumn();
    
} elseif ($usuario_rol === 'Estudiante') {
    // CORREGIDO
    $stmt_act = $conexion->prepare("SELECT COUNT(*) FROM entregas_estudiantes WHERE estudiante_id = ?");
    $stmt_act->execute([$usuario_id]);
    $metricas['mis_actividades'] = $stmt_act->fetchColumn();
    
    $stmt_cont = $conexion->prepare("SELECT COUNT(*) FROM progreso_contenido WHERE estudiante_id = ?");
    $stmt_cont->execute([$usuario_id]);
    $metricas['mis_contenidos'] = $stmt_cont->fetchColumn();
    
} elseif ($usuario_rol === 'Representante') {
    // Representante ve los estudiantes asociados
    $metricas['total_estudiantes'] = count($estudiantes_representado);
}

// =====================================================
// 2. ACTIVIDADES (con filtro de período) - CORREGIDO
// =====================================================

$sql_actividades = "
    SELECT 
        a.id,
        a.titulo,
        a.tipo,
        a.grado,
        a.seccion,
        a.fecha_entrega,
        COUNT(DISTINCT ee.id) as entregas,
        COUNT(DISTINCT CASE WHEN ee.estado = 'calificado' THEN ee.id END) as calificadas,
        COALESCE(ROUND(AVG(ee.calificacion)::numeric, 2), 0) as promedio
    FROM actividades a
    LEFT JOIN entregas_estudiantes ee ON a.id = ee.actividad_id
    WHERE 1=1
";

$params = [];

// Filtro por período
if ($periodo_activo) {
    $sql_actividades .= " AND a.fecha_entrega BETWEEN ? AND ?";
    $params[] = $periodo_activo['fecha_inicio'];
    $params[] = $periodo_activo['fecha_fin'];
}

// Filtro por rol
if ($usuario_rol === 'Docente') {
    $sql_actividades .= " AND a.docente_id = ?";
    $params[] = $usuario_id;
    
    // 🔴 NO FILTRAR POR GRADO/SECCIÓN, solo por docente_id
    // Así el docente ve TODAS sus actividades, tengan o no grado/sección
    
} elseif ($usuario_rol === 'Estudiante') {
    $sql_actividades .= " AND a.grado = (SELECT grado FROM estudiantes WHERE usuario_id = ?) 
                          AND a.seccion = (SELECT seccion FROM estudiantes WHERE usuario_id = ?)";
    $params[] = $usuario_id;
    $params[] = $usuario_id;
    
} elseif ($usuario_rol === 'Representante' && !empty($estudiantes_representado)) {
    // Para representante, mostrar actividades de los grados/secciones de sus estudiantes
    $placeholders = implode(',', array_fill(0, count($estudiantes_representado), '?'));
    $sql_actividades .= " AND (a.grado, a.seccion) IN (
        SELECT DISTINCT grado, seccion FROM estudiantes WHERE usuario_id IN ($placeholders)
    )";
    $params = array_merge($params, $estudiantes_representado);
    
} elseif ($usuario_rol === 'Administrador') {
    // Admin ve TODAS las actividades, sin filtro
}

$sql_actividades .= " GROUP BY a.id ORDER BY a.fecha_entrega DESC LIMIT 50";

$stmt = $conexion->prepare($sql_actividades);
$stmt->execute($params);
$actividades = $stmt->fetchAll(PDO::FETCH_ASSOC);
// =====================================================
// 3. CONTENIDOS PARA ADMIN Y DOCENTE (CORREGIDO)
// =====================================================

// Inicializar siempre como array vacío
$contenidos_globales = [];

// Solo ejecutar la consulta si es Admin o Docente
if ($usuario_rol === 'Administrador' || $usuario_rol === 'Docente') {
    
    // Primero, obtener el total de estudiantes por grado/sección
    $sql_contenidos = "
        SELECT 
            c.id,
            c.titulo,
            c.asignatura,
            c.grado,
            c.seccion,
            -- Total de estudiantes en este grado/sección
            COALESCE(estudiantes_por_clase.total_estudiantes, 0) as total_estudiantes_clase,
            -- Estudiantes que completaron el contenido
            COALESCE(completados.estudiantes_completaron, 0) as estudiantes_completaron,
            -- Porcentaje de la clase que completó
            CASE 
                WHEN COALESCE(estudiantes_por_clase.total_estudiantes, 0) > 0 
                THEN ROUND((COALESCE(completados.estudiantes_completaron, 0) * 100.0 / estudiantes_por_clase.total_estudiantes), 2)
                ELSE 0
            END as porcentaje_clase
        FROM contenidos c
        -- Subconsulta para obtener total de estudiantes por grado/sección
        LEFT JOIN (
            SELECT 
                grado, 
                seccion,
                COUNT(*) as total_estudiantes
            FROM estudiantes e
            INNER JOIN usuarios u ON e.usuario_id = u.id
            WHERE u.activo = true
            GROUP BY grado, seccion
        ) estudiantes_por_clase ON c.grado = estudiantes_por_clase.grado AND c.seccion = estudiantes_por_clase.seccion
        -- Subconsulta para contar estudiantes que completaron el contenido
        LEFT JOIN (
            SELECT 
                contenido_id,
                COUNT(DISTINCT estudiante_id) as estudiantes_completaron
            FROM progreso_contenido
            WHERE material_id IS NULL 
              AND completado = true
            GROUP BY contenido_id
        ) completados ON c.id = completados.contenido_id
        WHERE 1=1
    ";
    
    $params_contenidos = [];

    // Filtro por período (si existe)
    if ($periodo_activo) {
        $sql_contenidos .= " AND (c.fecha_publicacion BETWEEN ? AND ?)";
        $params_contenidos[] = $periodo_activo['fecha_inicio'];
        $params_contenidos[] = $periodo_activo['fecha_fin'];
    }

    // Filtro por rol - DOCENTE solo ve su clase
    if ($usuario_rol === 'Docente' && $grado_docente) {
        $sql_contenidos .= " AND c.grado = ? AND c.seccion = ?";
        $params_contenidos[] = $grado_docente;
        $params_contenidos[] = $seccion_docente;
    }

    $sql_contenidos .= " ORDER BY c.fecha_publicacion DESC LIMIT 20";

    $stmt = $conexion->prepare($sql_contenidos);
    $stmt->execute($params_contenidos);
    $contenidos_globales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 🔍 LOG PARA DEPURACIÓN
    error_log("=== CONTENIDOS GLOBALES ===");
    error_log("Rol: " . $usuario_rol);
    error_log("Total contenidos encontrados: " . count($contenidos_globales));
}
// =====================================================
// 4. PARA ESTUDIANTE: SU HISTORIAL DETALLADO (SIN DUPLICADOS)
// =====================================================

$historial_estudiante = [];
if ($usuario_rol === 'Estudiante' && $periodo_activo) {
    
    // Obtener datos del estudiante
    $stmt = $conexion->prepare("SELECT grado, seccion FROM estudiantes WHERE usuario_id = ?");
    $stmt->execute([$usuario_id]);
    $est = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($est) {
        // Actividades del estudiante (ya está bien)
        $stmt_act = $conexion->prepare("
            SELECT 
                a.titulo,
                a.tipo,
                a.fecha_entrega,
                COALESCE(ee.estado, 'pendiente') as estado,
                ee.calificacion
            FROM actividades a
            LEFT JOIN entregas_estudiantes ee ON a.id = ee.actividad_id AND ee.estudiante_id = ?
            WHERE a.grado = ? AND a.seccion = ?
            AND a.fecha_entrega BETWEEN ? AND ?
            ORDER BY a.fecha_entrega DESC
        ");
        $stmt_act->execute([
            $usuario_id,
            $est['grado'],
            $est['seccion'],
            $periodo_activo['fecha_inicio'],
            $periodo_activo['fecha_fin']
        ]);
        $actividades = $stmt_act->fetchAll(PDO::FETCH_ASSOC);
        
        // Contenidos del estudiante (SIN DUPLICADOS)
        $stmt_cont = $conexion->prepare("
            SELECT 
                c.titulo,
                c.asignatura,
                COALESCE(
                    (SELECT porcentaje_visto 
                     FROM progreso_contenido 
                     WHERE contenido_id = c.id 
                       AND estudiante_id = ? 
                       AND material_id IS NULL
                    ), 0
                ) as progreso
            FROM contenidos c
            WHERE c.grado = ? AND c.seccion = ?
            AND c.fecha_publicacion BETWEEN ? AND ?
            ORDER BY c.fecha_publicacion DESC
        ");
        $stmt_cont->execute([
            $usuario_id,
            $est['grado'],
            $est['seccion'],
            $periodo_activo['fecha_inicio'],
            $periodo_activo['fecha_fin']
        ]);
        $contenidos = $stmt_cont->fetchAll(PDO::FETCH_ASSOC);
        
        $completadas = 0;
        foreach ($actividades as $a) {
            if ($a['estado'] === 'calificado') {
                $completadas++;
            }
        }
        
        $historial_estudiante = [
            'actividades' => $actividades,
            'contenidos' => $contenidos,
            'total_actividades' => count($actividades),
            'actividades_completadas' => $completadas
        ];
    }
}

// Variable para contenidos del estudiante (SOLO SU PROGRESO PERSONAL)
$contenidos_estudiante = [];
if ($usuario_rol === 'Estudiante' && !empty($historial_estudiante)) {
    $contenidos_estudiante = $historial_estudiante['contenidos'] ?? [];
}
// =====================================================
// EXPORTAR A PDF - VERSIÓN CORREGIDA
// =====================================================
if (isset($_GET['exportar_pdf'])) {
    
    error_log("=== EXPORTANDO PDF ===");
    
    // Determinar qué contenidos mostrar según el rol
    if ($usuario_rol === 'Estudiante') {
        // Para ESTUDIANTE: usar su historial personal
        $contenidos_pdf = $contenidos_estudiante ?? [];
        error_log("PDF Estudiante - Contenidos personales: " . count($contenidos_pdf));
        
    } elseif ($usuario_rol === 'Representante' && isset($_GET['estudiante_id'])) {
        // Para REPRESENTANTE: usar contenidos del estudiante seleccionado
        $estudiante_id = $_GET['estudiante_id'];
        
        // Verificar que el representante tiene permiso
        if (in_array($estudiante_id, $estudiantes_representado)) {
            // Obtener grado y sección del estudiante
            $stmt_est = $conexion->prepare("SELECT grado, seccion FROM estudiantes WHERE usuario_id = ?");
            $stmt_est->execute([$estudiante_id]);
            $est = $stmt_est->fetch(PDO::FETCH_ASSOC);
            
            if ($est) {
                // Consulta para contenidos del estudiante (SIN DUPLICADOS)
                $sql_cont = "
                    SELECT 
                        c.titulo,
                        c.asignatura,
                        COALESCE(
                            (SELECT porcentaje_visto 
                             FROM progreso_contenido 
                             WHERE contenido_id = c.id 
                               AND estudiante_id = ? 
                               AND material_id IS NULL
                            ), 0
                        ) as progreso
                    FROM contenidos c
                    WHERE c.grado = ? AND c.seccion = ?
                ";
                
                // Agregar filtro de período si existe
                if ($periodo_activo) {
                    $sql_cont .= " AND c.fecha_publicacion BETWEEN ? AND ?";
                    $params_cont = [
                        $estudiante_id,
                        $est['grado'],
                        $est['seccion'],
                        $periodo_activo['fecha_inicio'],
                        $periodo_activo['fecha_fin']
                    ];
                } else {
                    $params_cont = [
                        $estudiante_id,
                        $est['grado'],
                        $est['seccion']
                    ];
                }
                
                $sql_cont .= " ORDER BY c.fecha_publicacion DESC";
                
                $stmt_cont = $conexion->prepare($sql_cont);
                $stmt_cont->execute($params_cont);
                $contenidos_pdf = $stmt_cont->fetchAll(PDO::FETCH_ASSOC);
                error_log("PDF Representante - Contenidos del estudiante: " . count($contenidos_pdf));
            } else {
                $contenidos_pdf = [];
            }
        } else {
            $contenidos_pdf = [];
        }
        
    } else {
        // Para ADMIN y DOCENTE: usar contenidos globales
        $contenidos_pdf = $contenidos_globales ?? [];
        error_log("PDF Admin/Docente - Contenidos globales: " . count($contenidos_pdf));
        error_log("Contenidos globales RAW: " . print_r($contenidos_globales, true));
    }
    
    // Para ESTUDIANTE y REPRESENTANTE, las actividades también deben ser las correctas
    $actividades_pdf = $actividades;
    if ($usuario_rol === 'Estudiante' && !empty($historial_estudiante)) {
        $actividades_pdf = $historial_estudiante['actividades'] ?? [];
    } elseif ($usuario_rol === 'Representante' && isset($_GET['estudiante_id'])) {
        // Obtener actividades del estudiante seleccionado
        $estudiante_id = $_GET['estudiante_id'];
        if (in_array($estudiante_id, $estudiantes_representado)) {
            $stmt_est = $conexion->prepare("SELECT grado, seccion FROM estudiantes WHERE usuario_id = ?");
            $stmt_est->execute([$estudiante_id]);
            $est = $stmt_est->fetch(PDO::FETCH_ASSOC);
            
            if ($est) {
                // Actividades del estudiante seleccionado
                $stmt_act = $conexion->prepare("
                    SELECT 
                        a.titulo,
                        a.tipo,
                        a.fecha_entrega,
                        COALESCE(ee.estado, 'pendiente') as estado,
                        ee.calificacion
                    FROM actividades a
                    LEFT JOIN entregas_estudiantes ee ON a.id = ee.actividad_id AND ee.estudiante_id = ?
                    WHERE a.grado = ? AND a.seccion = ?
                    ORDER BY a.fecha_entrega DESC
                ");
                $stmt_act->execute([$estudiante_id, $est['grado'], $est['seccion']]);
                $actividades_pdf = $stmt_act->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
    
    // Pasar todas las variables necesarias a la plantilla
    $pdf_data = [
        'metricas' => $metricas,
        'actividades' => $actividades_pdf,
        'contenidos' => $contenidos_pdf,
        'periodo_activo' => $periodo_activo,
        'usuario_rol' => $usuario_rol,
        'usuario_nombre' => $_SESSION['usuario_nombre']
    ];
    
    // Si es representante, añadir información del estudiante
    if ($usuario_rol === 'Representante' && isset($_GET['estudiante_id'])) {
        $stmt_nom = $conexion->prepare("SELECT nombre FROM usuarios WHERE id = ?");
        $stmt_nom->execute([$_GET['estudiante_id']]);
        $pdf_data['estudiante_nombre'] = $stmt_nom->fetchColumn();
        $pdf_data['estudiante_id'] = $_GET['estudiante_id'];
    }
    
    ob_start();
    include 'plantilla_reporte_rol.php';
    $html = ob_get_clean();
    
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    $options->set('defaultFont', 'Arial');
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    
    $nombre_archivo = "reporte_{$usuario_rol}_" . date('Y-m-d') . ".pdf";
    $dompdf->stream($nombre_archivo, array("Attachment" => true));
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes - SIEDUCRES</title>
    <?php require_once '../includes/favicon.php'; ?>
    <?php require_once '../includes/header_onesignal.php'; ?> 
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --text-dark: #333333; --text-muted: #666666; --border: #E0E0E0;
            --surface: #FFFFFF; --background: #F5F5F5;
            --primary-cyan: #4BC4E7; --primary-pink: #EF5E8E;
            --primary-lime: #C2D54E; --primary-purple: #9b8afb;
        }
        body {
            font-family: 'Inter', sans-serif; background-color: var(--background);
            color: var(--text-dark); min-height: 100vh; display: flex; flex-direction: column;
        }
        .header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 0 24px; height: 60px; background-color: var(--surface);
            border-bottom: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .header-left { display: flex; align-items: center; gap: 16px; }
        .logo { height: 40px; }
        .header-right { display: flex; align-items: center; gap: 16px; }
        .icon-btn {
            width: 36px; height: 36px; border-radius: 50%; background-color: #F0F0F0;
            display: flex; align-items: center; justify-content: center; cursor: pointer;
        }
        .icon-btn img { width: 20px; height: 20px; object-fit: contain; }
        .menu-dropdown {
            position: absolute; top: 60px; right: 24px; background: white;
            border: 1px solid var(--border); border-radius: 8px; display: none; min-width: 180px;
        }
        .menu-item {
            padding: 10px 16px; font-size: 14px; color: var(--text-dark);
            text-decoration: none; display: block;
        }
        .banner {
            position: relative; height: 100px; overflow: hidden;
        }
        .banner-image {
            width: 100%; height: 100%; object-fit: cover; object-position: top;
        }
        .banner-content {
            text-align: center; position: relative; z-index: 2;
            max-width: 800px; padding: 20px; margin: 0 auto;
        }
        .banner-title {
            font-size: 36px; font-weight: 700; color: var(--text-dark);
        }
        .main-content {
            flex: 1; padding: 40px 20px; max-width: 1400px; margin: 0 auto; width: 100%;
        }
        .card {
            background: white; border-radius: 16px; padding: 24px; margin-bottom: 30px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.1); border: 1px solid var(--border);
        }
        .card-header {
            background-color: var(--primary-cyan);
            margin: -24px -24px 20px -24px; padding: 20px;
            border-radius: 16px 16px 0 0; color: white;
        }
        .filtros-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px; margin-bottom: 20px;
        }
        .filtro-group {
            display: flex; flex-direction: column;
        }
        .filtro-group label {
            font-weight: 600; color: #555; margin-bottom: 5px;
        }
        .filtro-group select, .filtro-group input {
            padding: 10px; border: 1px solid var(--border); border-radius: 8px;
        }
        .acciones-container {
            display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px;
        }
        .btn-primary {
            background-color: var(--primary-cyan); color: white;
            padding: 12px 24px; border: none; border-radius: 8px;
            font-weight: 600; cursor: pointer; transition: transform 0.3s;
        }
        .btn-primary:hover { transform: translateY(-2px); }
        .btn-secondary {
            background-color: var(--primary-pink); color: white;
            padding: 12px 24px; border: none; border-radius: 8px;
            font-weight: 600; cursor: pointer; transition: transform 0.3s;
        }
        .btn-secondary:hover { transform: translateY(-2px); }
        .metricas-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px; margin-bottom: 30px;
        }
        .metrica-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 12px; padding: 20px; text-align: center;
            border-left: 4px solid var(--primary-cyan);
        }
        .metrica-valor {
            font-size: 36px; font-weight: 700; color: var(--primary-cyan);
            margin-bottom: 5px;
        }
        .metrica-etiqueta { font-size: 14px; color: var(--text-muted); }
        table {
            width: 100%; border-collapse: collapse; margin-top: 15px;
        }
        th {
            background-color: var(--primary-cyan); color: white;
            padding: 12px; text-align: left; font-weight: 600;
        }
        td { padding: 12px; border-bottom: 1px solid var(--border); }
        tr:hover { background-color: #f9f9f9; }
        .badge {
            padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;
        }
        .badge-exito { background-color: var(--primary-lime); color: #333; }
        .badge-info { background-color: var(--primary-cyan); color: white; }
        .footer {
            height: 50px; background-color: var(--surface); border-top: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
            padding: 0 24px; font-size: 13px; color: var(--text-muted);
            position: sticky; bottom: 0;
        }
        /* Por defecto en desktop */
        .mobile-view {
            display: none;
        }

        .desktop-view {
            display: block;
        }
        body {
            padding-top: 60px;  /* ← ALTURA DEL HEADER */
        }
        /* Enlace volver */
        .back-link {
            display: block;
            color: var(--primary-pink);
            text-decoration: none;
        }

        /* En móvil */
        @media (max-width: 768px) {
            .desktop-view {
                display: none;
            }
            
            .mobile-view {
                display: block;
            }
        }
        @media (max-width: 768px) {
            /* ===== DISEÑO RESPONSIVE QUE SE REORGANIZA ===== */
            
            /* Ajustes generales */
            .banner-title { 
                font-size: 28px; 
            }
            
            .banner-content {
                padding: 15px 10px;
            }
            
            /* Header más compacto */
            .header {
                padding: 0 12px;
            }
            
            .header-left .logo {
                height: 32px;
            }
            
            /* Tarjetas con menos padding */
            .card {
                padding: 16px;
                margin-bottom: 20px;
            }
            
            .card-header {
                margin: -16px -16px 16px -16px;
                padding: 16px;
            }
            
            .card-header h2 {
                font-size: 1.2rem;
            }
            
            /* Métricas en 2 columnas */
            .metricas-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            
            .metrica-card {
                padding: 12px;
            }
            
            .metrica-valor {
                font-size: 24px;
            }
            
            .metrica-etiqueta {
                font-size: 11px;
            }
            
            /* Filtros en columna */
            .filtros-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .acciones-container {
                flex-direction: column;
            }
            
            .btn-primary, .btn-secondary {
                width: 100%;
                text-align: center;
            }
            
            /* ===== TABLAS CONVIERTEN A CARDS ===== */
            /* Ocultar tablas en móvil */
            .card table {
                display: none;
            }
            
            /* Mostrar vista de cards en móvil */
            .card .mobile-cards {
                display: block;
            }
            
            /* Estilo para las cards móviles */
            .actividad-card {
                background: #f8f9fa;
                border-radius: 12px;
                padding: 16px;
                margin-bottom: 12px;
                border: 1px solid var(--border);
                transition: transform 0.2s;
            }
            
            .actividad-card:active {
                transform: scale(0.98);
            }
            
            .actividad-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 12px;
                padding-bottom: 8px;
                border-bottom: 1px dashed var(--border);
            }
            
            .actividad-titulo {
                font-weight: 700;
                color: var(--text-dark);
                font-size: 1rem;
            }
            
            .actividad-tipo {
                background-color: var(--primary-cyan);
                color: white;
                padding: 4px 8px;
                border-radius: 20px;
                font-size: 0.7rem;
                font-weight: 600;
            }
            
            .actividad-detalle {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px;
                margin-bottom: 8px;
            }
            
            .detalle-item {
                font-size: 0.85rem;
            }
            
            .detalle-label {
                color: var(--text-muted);
                display: block;
                font-size: 0.7rem;
            }
            
            .detalle-valor {
                font-weight: 600;
                color: var(--text-dark);
            }
            
            .actividad-estado {
                display: inline-block;
                padding: 4px 8px;
                border-radius: 20px;
                font-size: 0.75rem;
                font-weight: 600;
            }
            
            .estado-pendiente {
                background: #fff3cd;
                color: #856404;
            }
            
            .estado-enviado {
                background: #d4edda;
                color: #155724;
            }
            
            .estado-calificado {
                background: #cce5ff;
                color: #004085;
            }
            
            /* Cards para contenidos */
            .contenido-card-mobile {
                background: #f8f9fa;
                border-radius: 12px;
                padding: 16px;
                margin-bottom: 12px;
                border: 1px solid var(--border);
            }
            
            .contenido-titulo-mobile {
                font-weight: 700;
                color: var(--text-dark);
                margin-bottom: 8px;
            }
            
            .contenido-asignatura-mobile {
                color: var(--primary-purple);
                font-size: 0.85rem;
                margin-bottom: 12px;
            }
            
            .progreso-container {
                margin-top: 8px;
            }
            
            .progreso-label {
                display: flex;
                justify-content: space-between;
                font-size: 0.8rem;
                margin-bottom: 4px;
            }
            
            .progreso-barra-mobile {
                height: 10px;
                background: #e0e0e0;
                border-radius: 5px;
                overflow: hidden;
            }
            
            .progreso-llenado-mobile {
                height: 100%;
                background: var(--primary-cyan);
                border-radius: 5px;
            }
        }
        
    </style>
</head>
<body>

    <?php require_once '../includes/header_comun.php'; ?>

    <!-- Banner -->
    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image">
    </div>

    <!-- Título dinámico según rol -->
    <div class="banner-content">
        <h1 class="banner-title">
            <?php 
            if ($usuario_rol === 'Administrador') echo "Reportes del Sistema";
            elseif ($usuario_rol === 'Docente') echo "Reportes de mi Clase";
            elseif ($usuario_rol === 'Estudiante') echo "Mi Historial Académico";
            elseif ($usuario_rol === 'Representante') echo "Reportes de mis Representados";
            ?>
        </h1>
    </div>
    <!-- 🔴 FLECHA DE VOLVER SEGÚN EL ROL -->
    <?php
    // Determinar la página de inicio según el rol
    $pagina_inicio = '';
    switch ($usuario_rol) {
        case 'Administrador':
            $pagina_inicio = '../admin/index.php';
            break;
        case 'Docente':
            $pagina_inicio = '../docente/index.php';
            break;
        case 'Estudiante':
            $pagina_inicio = '../estudiante/index.php';
            break;
        case 'Representante':
            $pagina_inicio = '../representante/index.php';
            break;
        default:
            $pagina_inicio = '../../index.php';
    }
    ?>
    <div style="max-width: 1400px; margin: 15px 0 15px 40px; padding: 0; width: 100%; text-align: left;">
        <a href="<?php echo $pagina_inicio; ?>" 
        style="display: inline-block; color: #EF5E8E; text-decoration: none; font-weight: 500; font-size: 14px; transition: transform 0.2s;"
        onmouseover="this.style.transform='translateX(-4px)'" 
        onmouseout="this.style.transform='translateX(0)'">
            ← Volver al Panel Principal
        </a>
    </div>

    <!-- Contenido principal -->
    <main class="main-content">
        
        <!-- Filtros comunes -->
        <div class="card">
            <div class="card-header">
                <h2>Filtrar Información</h2>
            </div>
            
            <form method="GET" action="">
                <div class="filtros-grid">
                    <div class="filtro-group">
                        <label>Período:</label>
                        <select name="periodo_id">
                            <option value="">Todos</option>
                            <?php foreach ($periodos as $p): ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo $periodo_id == $p['id'] ? 'selected' : ''; ?>>
                                    <?php echo $p['nombre']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php if ($usuario_rol === 'Administrador' || $usuario_rol === 'Representante'): ?>
                    <!-- Admin y Representante pueden ver selectores adicionales -->
                    <?php endif; ?>
                </div>
                
                <div class="acciones-container">
                    <button type="submit" class="btn-primary">Aplicar Filtros</button>
                    
                    <?php if ($usuario_rol !== 'Representante'): ?>
                        <!-- El botón de PDF general SOLO para roles que no son Representante -->
                        <a href="?exportar_pdf=1&periodo_id=<?php echo $periodo_id; ?>" class="btn-secondary">📄 Exportar PDF</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Métricas según rol -->
        <?php if (!empty($metricas)): ?>
        <div class="card">
            <div class="card-header">
                <h2>Resumen</h2>
            </div>
            
            <div class="metricas-grid">
                <?php foreach ($metricas as $etiqueta => $valor): ?>
                <div class="metrica-card">
                    <div class="metrica-valor"><?php echo $valor; ?></div>
                    <div class="metrica-etiqueta"><?php echo ucfirst(str_replace('_', ' ', $etiqueta)); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Para ESTUDIANTE: mostrar historial detallado -->
        <?php if ($usuario_rol === 'Estudiante' && $historial_estudiante): ?>
        <div class="card">
            <div class="card-header">
                <h2>Mis Actividades</h2>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Actividad</th>
                        <th>Tipo</th>
                        <th>Fecha Entrega</th>
                        <th>Estado</th>
                        <th>Calificación</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historial_estudiante['actividades'] as $act): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($act['titulo']); ?></td>
                        <td><span class="badge badge-info"><?php echo $act['tipo']; ?></span></td>
                        <td><?php echo date('d/m/Y', strtotime($act['fecha_entrega'])); ?></td>
                        <td>
                            <span class="badge <?php 
                                echo $act['estado'] === 'calificado' ? 'badge-exito' : 'badge-info'; 
                            ?>"><?php echo $act['estado']; ?></span>
                        </td>
                        <td><?php echo $act['calificacion'] ?? '-'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card">
            <div class="card-header">
                <h2>Mi Progreso en Contenidos</h2>
            </div>
            
            <!-- VISTA DESKTOP (tabla) -->
            <div class="desktop-view">
                <table>
                    <thead>
                        <tr>
                            <th>Contenido</th>
                            <th>Asignatura</th>
                            <th>Mi Progreso</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($contenidos_estudiante)): ?>
                            <tr>
                                <td colspan="3" style="text-align: center; padding: 30px; color: #999;">
                                    No hay contenidos para mostrar en este período
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($contenidos_estudiante as $c): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($c['titulo']); ?></td>
                                <td><?php echo $c['asignatura']; ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="flex:1; height: 8px; background: #eee; border-radius: 4px;">
                                            <div style="width: <?php echo $c['progreso']; ?>%; height: 8px; background: var(--primary-cyan); border-radius: 4px;"></div>
                                        </div>
                                        <span><?php echo $c['progreso']; ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- VISTA MÓVIL (cards) -->
            <div class="mobile-view">
                <?php if (empty($contenidos_estudiante)): ?>
                    <p style="text-align: center; padding: 20px; color: #999;">
                        No hay contenidos para mostrar
                    </p>
                <?php else: ?>
                    <?php foreach ($contenidos_estudiante as $c): ?>
                    <div class="contenido-card-mobile">
                        <div class="contenido-titulo-mobile"><?php echo htmlspecialchars($c['titulo']); ?></div>
                        <div class="contenido-asignatura-mobile"><?php echo $c['asignatura']; ?></div>
                        
                        <div class="progreso-container">
                            <div class="progreso-label">
                                <span>Mi progreso</span>
                                <span><?php echo $c['progreso']; ?>%</span>
                            </div>
                            <div class="progreso-barra-mobile">
                                <div class="progreso-llenado-mobile" style="width: <?php echo $c['progreso']; ?>%"></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

            <?php if ($usuario_rol === 'Representante' && !empty($estudiantes_representado)): ?>
    
                <?php 
                // Obtener el primer estudiante (o el seleccionado)
                $estudiante_a_mostrar = $_GET['estudiante_id'] ?? $estudiantes_representado[0];
                
                // Verificar que el representante tiene permiso para ver este estudiante
                if (in_array($estudiante_a_mostrar, $estudiantes_representado)) {
                    
                    // Obtener datos del estudiante
                    $stmt_est = $conexion->prepare("
                        SELECT u.id, u.nombre, e.grado, e.seccion
                        FROM estudiantes e
                        JOIN usuarios u ON e.usuario_id = u.id
                        WHERE u.id = ?
                    ");
                    $stmt_est->execute([$estudiante_a_mostrar]);
                    $estudiante_info = $stmt_est->fetch(PDO::FETCH_ASSOC);
                    
                    // =====================================================
                    // ACTIVIDADES DEL ESTUDIANTE SELECCIONADO
                    // =====================================================
                    $stmt_act = $conexion->prepare("
                        SELECT 
                            a.titulo,
                            a.tipo,
                            a.fecha_entrega,
                            COALESCE(ee.estado, 'pendiente') as estado,
                            ee.calificacion
                        FROM actividades a
                        LEFT JOIN entregas_estudiantes ee ON a.id = ee.actividad_id AND ee.estudiante_id = ?
                        WHERE a.grado = ? AND a.seccion = ?
                        ORDER BY a.fecha_entrega DESC
                    ");
                    $stmt_act->execute([
                        $estudiante_a_mostrar,
                        $estudiante_info['grado'],
                        $estudiante_info['seccion']
                    ]);
                    $actividades_estudiante = $stmt_act->fetchAll(PDO::FETCH_ASSOC);
                    
                    // =====================================================
                    // CONTENIDOS DEL ESTUDIANTE SELECCIONADO (SIN DUPLICADOS)
                    // =====================================================
                    $contenidos_estudiante_seleccionado = [];
                    $stmt_cont = $conexion->prepare("
                        SELECT 
                            c.titulo,
                            c.asignatura,
                            COALESCE(
                                (SELECT porcentaje_visto 
                                FROM progreso_contenido 
                                WHERE contenido_id = c.id 
                                AND estudiante_id = ? 
                                AND material_id IS NULL
                                ), 0
                            ) as progreso
                        FROM contenidos c
                        WHERE c.grado = ? AND c.seccion = ?
                        ORDER BY c.fecha_publicacion DESC
                    ");
                    $stmt_cont->execute([
                        $estudiante_a_mostrar,
                        $estudiante_info['grado'],
                        $estudiante_info['seccion']
                    ]);
                    $contenidos_estudiante_seleccionado = $stmt_cont->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Debug para verificar
                    error_log("=== REPRESENTANTE ===");
                    error_log("Estudiante: " . $estudiante_info['nombre']);
                    error_log("Actividades encontradas: " . count($actividades_estudiante));
                    error_log("Contenidos encontrados: " . count($contenidos_estudiante_seleccionado));
                ?>
                
                <!-- Selector de estudiantes (si tiene más de uno) -->
                <?php if (count($estudiantes_representado) > 1): ?>
                <div class="card">
                    <div class="card-header">
                        <h2>👥 Seleccionar Estudiante</h2>
                    </div>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; padding: 10px;">
                        <?php foreach ($estudiantes_representado as $est_id): 
                            $stmt_nom = $conexion->prepare("SELECT nombre FROM usuarios WHERE id = ?");
                            $stmt_nom->execute([$est_id]);
                            $nombre_est = $stmt_nom->fetchColumn();
                        ?>
                        <a href="?estudiante_id=<?php echo $est_id; ?>&periodo_id=<?php echo $periodo_id; ?>" 
                        style="padding: 8px 16px; background: <?php echo $est_id == $estudiante_a_mostrar ? 'var(--primary-cyan)' : '#f0f0f0'; ?>; 
                                color: <?php echo $est_id == $estudiante_a_mostrar ? 'white' : '#333'; ?>; 
                                border-radius: 30px; text-decoration: none;">
                            <?php echo $nombre_est; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Título con el nombre del estudiante -->
                <div class="card">
                    <div class="card-header">
                        <h2>📊 Historial de <?php echo htmlspecialchars($estudiante_info['nombre']); ?></h2>
                    </div>
                    
                    <!-- Datos del estudiante -->
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                        <p><strong>Grado:</strong> <?php echo $estudiante_info['grado']; ?> - Sección <?php echo $estudiante_info['seccion']; ?></p>
                    </div>
                </div>
                
                <!-- ACTIVIDADES -->
                <div class="card">
                    <div class="card-header">
                        <h2>📝 Actividades de <?php echo htmlspecialchars($estudiante_info['nombre']); ?></h2>
                    </div>
                    
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Actividad</th>
                                    <th>Tipo</th>
                                    <th>Fecha Entrega</th>
                                    <th>Estado</th>
                                    <th>Calificación</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($actividades_estudiante)): ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; padding: 30px; color: #999;">
                                            No hay actividades para mostrar
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($actividades_estudiante as $act): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($act['titulo']); ?></td>
                                        <td><span class="badge badge-info"><?php echo $act['tipo']; ?></span></td>
                                        <td><?php echo date('d/m/Y', strtotime($act['fecha_entrega'])); ?></td>
                                        <td>
                                            <span class="badge <?php 
                                                echo $act['estado'] === 'calificado' ? 'badge-exito' : 'badge-info'; 
                                            ?>"><?php echo $act['estado']; ?></span>
                                        </td>
                                        <td><?php echo $act['calificacion'] ?: '-'; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- CONTENIDOS -->
                <div class="card">
                    <div class="card-header">
                        <h2>📖 Progreso en Contenidos de <?php echo htmlspecialchars($estudiante_info['nombre']); ?></h2>
                    </div>
                    
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Contenido</th>
                                    <th>Asignatura</th>
                                    <th>Progreso</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($contenidos_estudiante_seleccionado)): ?>
                                    <tr>
                                        <td colspan="3" style="text-align: center; padding: 30px; color: #999;">
                                            No hay contenidos para mostrar
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($contenidos_estudiante_seleccionado as $c): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($c['titulo']); ?></td>
                                        <td><?php echo $c['asignatura']; ?></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <div style="flex:1; height: 8px; background: #eee; border-radius: 4px;">
                                                    <div style="width: <?php echo $c['progreso']; ?>%; height: 8px; background: var(--primary-cyan); border-radius: 4px;"></div>
                                                </div>
                                                <span><?php echo $c['progreso']; ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Botón para generar PDF -->
                <div style="margin-top: 20px; text-align: center;">
                    <a href="?exportar_pdf=1&periodo_id=<?php echo $periodo_id; ?>&estudiante_id=<?php echo $estudiante_a_mostrar; ?>" 
                    class="btn-secondary" style="display: inline-block; padding: 12px 30px;">
                        📄 Generar PDF de <?php echo htmlspecialchars($estudiante_info['nombre']); ?>
                    </a>
                </div>
                
                <?php } else { ?>
                    <div class="card">
                        <div class="card-header">
                            <h2>⛔ Acceso no autorizado</h2>
                        </div>
                        <p style="text-align: center; padding: 30px;">No tienes permiso para ver este estudiante.</p>
                    </div>
                <?php } ?>
                
            
            <?php elseif ($usuario_rol !== 'Estudiante'): ?>
                <!-- Para ADMIN y DOCENTE: mostrar actividades del sistema/clase -->
                <div class="card">
                    <div class="card-header">
                        <h2>Actividades Recientes</h2>
                    </div>
                    
                    <!-- VISTA DESKTOP (tabla) -->
                    <div class="desktop-view">
                        <table>
                            <thead>
                                <tr>
                                    <th>Actividad</th>
                                    <th>Tipo</th>
                                    <th>Grado</th>
                                    <th>Sección</th>
                                    <th>Entregas</th>
                                    <th>Calificadas</th>
                                    <th>Promedio</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($actividades)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 30px; color: #999;">
                                            No hay actividades para mostrar en este período
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($actividades as $a): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($a['titulo']); ?></strong></td>
                                        <td><span class="badge badge-info"><?php echo $a['tipo']; ?></span></td>
                                        <td><?php echo $a['grado']; ?></td>
                                        <td><?php echo $a['seccion']; ?></td>
                                        <td><?php echo $a['entregas']; ?></td>
                                        <td><?php echo $a['calificadas']; ?></td>
                                        <td><?php echo $a['promedio'] ?: '-'; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- VISTA MÓVIL (cards) -->
                    <div class="mobile-view">
                        <?php if (empty($actividades)): ?>
                            <p style="text-align: center; padding: 20px; color: #999;">
                                No hay actividades para mostrar
                            </p>
                        <?php else: ?>
                            <?php foreach ($actividades as $a): ?>
                            <div class="actividad-card">
                                <div class="actividad-header">
                                    <span class="actividad-titulo"><?php echo htmlspecialchars($a['titulo']); ?></span>
                                    <span class="actividad-tipo"><?php echo $a['tipo']; ?></span>
                                </div>
                                
                                <div class="actividad-detalle">
                                    <div class="detalle-item">
                                        <span class="detalle-label">Grado/Sección</span>
                                        <span class="detalle-valor"><?php echo $a['grado']; ?>-<?php echo $a['seccion']; ?></span>
                                    </div>
                                    <div class="detalle-item">
                                        <span class="detalle-label">Fecha entrega</span>
                                        <span class="detalle-valor"><?php echo date('d/m/Y', strtotime($a['fecha_entrega'])); ?></span>
                                    </div>
                                    <div class="detalle-item">
                                        <span class="detalle-label">Entregas</span>
                                        <span class="detalle-valor"><?php echo $a['entregas']; ?></span>
                                    </div>
                                    <div class="detalle-item">
                                        <span class="detalle-label">Calificadas</span>
                                        <span class="detalle-valor"><?php echo $a['calificadas']; ?></span>
                                    </div>
                                </div>
                                
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px;">
                                    <span class="actividad-estado <?php 
                                        echo $a['promedio'] ? 'estado-calificado' : 'estado-pendiente'; 
                                    ?>">
                                        Promedio: <?php echo $a['promedio'] ?: 'Sin calificar'; ?>
                                    </span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- SECCIÓN DE CONTENIDOS PARA ADMIN/DOCENTE -->
                <div class="card">
                    <div class="card-header">
                        <h2>Contenidos Disponibles</h2>
                    </div>
                    
                    <!-- VISTA DESKTOP (tabla) -->
                    <div class="desktop-view">
                        <table>
                            <thead>
                                <tr>
                                    <th>Contenido</th>
                                    <th>Asignatura</th>
                                    <th>Grado</th>
                                    <th>Sección</th>
                                    <th>Estudiantes que completaron</th>
                                    <th>% de la clase</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($contenidos_globales)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 30px; color: #999;">
                                            No hay contenidos para mostrar en este período
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($contenidos_globales as $c): 
                                        // Obtener el total de estudiantes para este grado/sección
                                        $stmt_total = $conexion->prepare("
                                            SELECT COUNT(*) 
                                            FROM estudiantes e
                                            INNER JOIN usuarios u ON e.usuario_id = u.id
                                            WHERE u.rol = 'Estudiante' 
                                                AND e.grado = ? 
                                                AND e.seccion = ?
                                        ");
                                        $stmt_total->execute([$c['grado'], $c['seccion']]);
                                        $total_estudiantes = $stmt_total->fetchColumn();
                                        
                                        // 🔴 CORREGIDO: usar estudiantes_completaron en lugar de estudiantes
                                        $estudiantes_completaron = $c['estudiantes_completaron'] ?? 0;
                                        
                                        // Calcular porcentaje de estudiantes que completaron
                                        $porcentaje_clase = $total_estudiantes > 0 
                                            ? round(($estudiantes_completaron / $total_estudiantes) * 100) 
                                            : 0;
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($c['titulo']); ?></td>
                                        <td><?php echo $c['asignatura']; ?></td>
                                        <td><?php echo $c['grado']; ?></td>
                                        <td><?php echo $c['seccion']; ?></td>
                                        <td>
                                            <strong><?php echo $estudiantes_completaron; ?> / <?php echo $total_estudiantes; ?></strong>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <span style="min-width: 45px; font-weight: 600;"><?php echo $porcentaje_clase; ?>%</span>
                                                <div class="progress-bar" style="flex: 1; height: 8px; background: #eee; border-radius: 4px;">
                                                    <div class="progress-fill" style="width: <?php echo $porcentaje_clase; ?>%; height: 8px; background: var(--primary-cyan); border-radius: 4px;"></div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- VISTA MÓVIL (cards) -->
                    <div class="mobile-view">
                        <?php if (empty($contenidos_globales)): ?>
                            <p style="text-align: center; padding: 20px; color: #999;">
                                No hay contenidos para mostrar
                            </p>
                        <?php else: ?>
                            <?php foreach ($contenidos_globales as $c): 
                                $stmt_total = $conexion->prepare("
                                    SELECT COUNT(*) 
                                    FROM estudiantes e
                                    INNER JOIN usuarios u ON e.usuario_id = u.id
                                    WHERE u.rol = 'Estudiante' 
                                        AND e.grado = ? 
                                        AND e.seccion = ?
                                ");
                                $stmt_total->execute([$c['grado'], $c['seccion']]);
                                $total_estudiantes = $stmt_total->fetchColumn();
                                
                                // 🔴 CORREGIDO: usar estudiantes_completaron
                                $estudiantes_completaron = $c['estudiantes_completaron'] ?? 0;
                                $porcentaje_clase = $total_estudiantes > 0 
                                    ? round(($estudiantes_completaron / $total_estudiantes) * 100) 
                                    : 0;
                            ?>
                            <div class="contenido-card-mobile">
                                <div class="contenido-titulo-mobile"><?php echo htmlspecialchars($c['titulo']); ?></div>
                                <div class="contenido-asignatura-mobile"><?php echo $c['asignatura']; ?></div>
                                
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px;">
                                    <div>
                                        <span style="color: #666; font-size: 11px;">Grado/Sección</span>
                                        <div style="font-weight: 600;"><?php echo $c['grado']; ?>-<?php echo $c['seccion']; ?></div>
                                    </div>
                                    <div>
                                        <span style="color: #666; font-size: 11px;">Completaron</span>
                                        <div style="font-weight: 600;"><?php echo $estudiantes_completaron; ?>/<?php echo $total_estudiantes; ?></div>
                                    </div>
                                </div>
                                
                                <div class="progreso-container">
                                    <div class="progreso-label">
                                        <span>% de la clase que completó</span>
                                        <span><?php echo $porcentaje_clase; ?>%</span>
                                    </div>
                                    <div class="progreso-barra-mobile">
                                        <div class="progreso-llenado-mobile" style="width: <?php echo $porcentaje_clase; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <span>v2.0.0</span>
        <span>Soporte Técnico</span>
    </footer>

    <script>
        document.getElementById('menu-toggle').addEventListener('click', function(e) {
            e.stopPropagation();
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
    </script>
</body>
</html>
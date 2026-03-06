<?php
session_start();
require_once '../../funciones.php';
require_once '../../../vendor/autoload.php';

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
} elseif ($usuario_rol === 'Estudiante') {
    $sql_actividades .= " AND a.grado = (SELECT grado FROM estudiantes WHERE usuario_id = ?) 
                          AND a.seccion = (SELECT seccion FROM estudiantes WHERE usuario_id = ?)";
    $params[] = $usuario_id;
    $params[] = $usuario_id;
} elseif ($usuario_rol === 'Representante' && !empty($estudiantes_representado)) {
    $placeholders = implode(',', array_fill(0, count($estudiantes_representado), '?'));
    $sql_actividades .= " AND a.grado IN (SELECT DISTINCT grado FROM estudiantes WHERE usuario_id IN ($placeholders))";
    $params = array_merge($params, $estudiantes_representado);
}

$sql_actividades .= " GROUP BY a.id ORDER BY a.fecha_entrega DESC LIMIT 20";

$stmt = $conexion->prepare($sql_actividades);
$stmt->execute($params);
$actividades = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =====================================================
// 3. CONTENIDOS MÁS VISTOS (CORREGIDO - MUESTRA TODOS)
// =====================================================

$sql_contenidos = "
    SELECT 
        c.id,
        c.titulo,
        c.asignatura,
        c.grado,
        c.seccion,
        COUNT(DISTINCT pc.estudiante_id) as estudiantes,
        COALESCE(ROUND(AVG(pc.porcentaje_visto)::numeric, 2), 0) as promedio_visto
    FROM contenidos c
    LEFT JOIN progreso_contenido pc ON c.id = pc.contenido_id
    WHERE 1=1
";

$params_contenidos = [];

// Filtro por período (si existe)
if ($periodo_activo) {
    $sql_contenidos .= " AND (c.fecha_publicacion BETWEEN ? AND ?)";
    $params_contenidos[] = $periodo_activo['fecha_inicio'];
    $params_contenidos[] = $periodo_activo['fecha_fin'];
}

// Filtro por rol para contenidos
if ($usuario_rol === 'Docente' && $grado_docente) {
    $sql_contenidos .= " AND c.grado = ? AND c.seccion = ?";
    $params_contenidos[] = $grado_docente;
    $params_contenidos[] = $seccion_docente;
} elseif ($usuario_rol === 'Estudiante') {
    $sql_contenidos .= " AND c.grado = (SELECT grado FROM estudiantes WHERE usuario_id = ?)";
    $params_contenidos[] = $usuario_id;
} elseif ($usuario_rol === 'Administrador') {
    // Admin ve todos, sin filtro adicional
}

$sql_contenidos .= " GROUP BY c.id, c.titulo, c.asignatura, c.grado, c.seccion 
                     ORDER BY c.fecha_publicacion DESC 
                     LIMIT 20";

// EJECUTAR CONSULTA (SIN ECHO)
$stmt = $conexion->prepare($sql_contenidos);
$stmt->execute($params_contenidos);
$contenidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
// =====================================================
// 4. PARA ESTUDIANTE: SU HISTORIAL DETALLADO
// =====================================================

$historial_estudiante = [];
if ($usuario_rol === 'Estudiante' && $periodo_activo) {
    // Intentar obtener de historial_academico primero
    $stmt = $conexion->prepare("
        SELECT * FROM historial_academico 
        WHERE estudiante_id = ? AND periodo_id = ?
    ");
    $stmt->execute([$usuario_id, $periodo_id]);
    $historial_estudiante = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Si no existe, calcular en vivo
    if (!$historial_estudiante) {
        $historial_estudiante = calcularHistorialEnVivo($conexion, $usuario_id, $periodo_activo);
    }
}

// Función para calcular historial en vivo
function calcularHistorialEnVivo($conexion, $estudiante_id, $periodo) {
    // Obtener datos del estudiante
    $stmt = $conexion->prepare("SELECT grado, seccion FROM estudiantes WHERE usuario_id = ?");
    $stmt->execute([$estudiante_id]);
    $est = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$est) {
        return [
            'actividades' => [],
            'contenidos' => [],
            'total_actividades' => 0,
            'actividades_completadas' => 0
        ];
    }
    
    // Actividades
    $stmt = $conexion->prepare("
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
    $stmt->execute([
        $estudiante_id,
        $est['grado'],
        $est['seccion'],
        $periodo['fecha_inicio'],
        $periodo['fecha_fin']
    ]);
    $actividades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Contenidos
    $stmt = $conexion->prepare("
        SELECT 
            c.titulo,
            c.asignatura,
            COALESCE(pc.porcentaje_visto, 0) as progreso
        FROM contenidos c
        LEFT JOIN progreso_contenido pc ON c.id = pc.contenido_id AND pc.estudiante_id = ?
        WHERE c.grado = ? AND c.seccion = ?
        AND c.fecha_publicacion BETWEEN ? AND ?
    ");
    $stmt->execute([
        $estudiante_id,
        $est['grado'],
        $est['seccion'],
        $periodo['fecha_inicio'],
        $periodo['fecha_fin']
    ]);
    $contenidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $completadas = 0;
    foreach ($actividades as $a) {
        if ($a['estado'] === 'calificado') {
            $completadas++;
        }
    }
    
    return [
        'actividades' => $actividades,
        'contenidos' => $contenidos,
        'total_actividades' => count($actividades),
        'actividades_completadas' => $completadas
    ];
}

// =====================================================
// EXPORTAR A PDF - CON DEPURACIÓN SEGURA
// =====================================================
if (isset($_GET['exportar_pdf'])) {
    
    // 🔍 DEPURACIÓN SOLO PARA PDF (en archivo de log, no en pantalla)
    error_log("=== EXPORTANDO PDF ===");
    error_log("Total actividades: " . count($actividades));
    error_log("Total contenidos: " . count($contenidos));
    
    // Verificar si hay contenidos en la base de datos
    $check = $conexion->query("SELECT COUNT(*) FROM contenidos")->fetchColumn();
    error_log("Total contenidos en BD: $check");
    
    if ($check > 0 && empty($contenidos)) {
        // Mostrar algunos contenidos de ejemplo en el log
        $ejemplos = $conexion->query("SELECT id, titulo, grado, seccion FROM contenidos LIMIT 3")->fetchAll();
        error_log("Ejemplos de contenidos: " . print_r($ejemplos, true));
    }
    
    // Pasar todas las variables necesarias a la plantilla
    $pdf_data = [
        'metricas' => $metricas,
        'actividades' => $actividades,
        'contenidos' => $contenidos,
        'periodo_activo' => $periodo_activo,
        'usuario_rol' => $usuario_rol,
        'usuario_nombre' => $_SESSION['usuario_nombre']
    ];
    
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
        @media (max-width: 768px) {
            .banner-title { font-size: 28px; }
            .metricas-grid { grid-template-columns: 1fr; }
            .filtros-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <!-- Header -->
    <header class="header">
        <div class="header-left">
            <img src="../../../assets/logo.svg" alt="SIEDUCRES" class="logo">
        </div>
        <div class="header-right">
            <div class="icon-btn" onclick="window.location.href='../comun/notificaciones.php'">
                <img src="../../../assets/icon-bell.svg" alt="Notificaciones">
            </div>
            <div class="icon-btn" onclick="window.location.href='perfil.php'">
                <img src="../../../assets/icon-user.svg" alt="Perfil">
            </div>
            <div class="icon-btn" id="menu-toggle">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="#333333">
                    <path d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/>
                </svg>
            </div>
            <div class="menu-dropdown" id="dropdown">
                <a href="<?php 
                    if ($usuario_rol === 'Administrador') echo '../admin/index.php';
                    elseif ($usuario_rol === 'Docente') echo '../docente/index.php';
                    elseif ($usuario_rol === 'Estudiante') echo '../estudiante/index.php';
                    elseif ($usuario_rol === 'Representante') echo '../representante/index.php';
                ?>" class="menu-item">Panel Principal</a>
                <a href="../../logout.php" class="menu-item">Cerrar sesión</a>
            </div>
        </div>
    </header>

    <!-- Banner -->
    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image">
    </div>

    <!-- Título dinámico según rol -->
    <div class="banner-content">
        <h1 class="banner-title">
            <?php 
            if ($usuario_rol === 'Administrador') echo "📊 Reportes del Sistema";
            elseif ($usuario_rol === 'Docente') echo "📊 Reportes de mi Clase";
            elseif ($usuario_rol === 'Estudiante') echo "📊 Mi Historial Académico";
            elseif ($usuario_rol === 'Representante') echo "📊 Reportes de mis Representados";
            ?>
        </h1>
    </div>

    <!-- Contenido principal -->
    <main class="main-content">
        
        <!-- Filtros comunes -->
        <div class="card">
            <div class="card-header">
                <h2>🔍 Filtrar Información</h2>
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
                    <button type="submit" class="btn-primary">🔍 Aplicar Filtros</button>
                    <a href="?exportar_pdf=1&periodo_id=<?php echo $periodo_id; ?>" class="btn-secondary">📄 Exportar PDF</a>
                </div>
            </form>
        </div>

        <!-- Métricas según rol -->
        <?php if (!empty($metricas)): ?>
        <div class="card">
            <div class="card-header">
                <h2>📈 Resumen</h2>
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
                <h2>📝 Mis Actividades</h2>
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
                <h2>📖 Mi Progreso en Contenidos</h2>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Contenido</th>
                        <th>Asignatura</th>
                        <th>Progreso</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historial_estudiante['contenidos'] as $cont): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($cont['titulo']); ?></td>
                        <td><?php echo $cont['asignatura']; ?></td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="flex:1; height: 8px; background: #eee; border-radius: 4px;">
                                    <div style="width: <?php echo $cont['progreso']; ?>%; height: 8px; background: var(--primary-cyan); border-radius: 4px;"></div>
                                </div>
                                <span><?php echo $cont['progreso']; ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

            <!-- Para ADMIN y DOCENTE: mostrar actividades del sistema/clase -->
            <?php if ($usuario_rol !== 'Estudiante'): ?>
                <div class="card">
                    <div class="card-header">
                        <h2>📝 Actividades Recientes</h2>
                    </div>
                    
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
                                        📭 No hay actividades para mostrar en este período
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

                <div class="card">
                    <div class="card-header">
                        <h2>📚 Contenidos Disponibles</h2>
                    </div>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>Contenido</th>
                                <th>Asignatura</th>
                                <th>Grado</th>
                                <th>Sección</th>
                                <th>Estudiantes que vieron</th>
                                <th>% Promedio Visto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($contenidos)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 30px; color: #999;">
                                        📭 No hay contenidos para mostrar en este período
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($contenidos as $c): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($c['titulo']); ?></td>
                                    <td><?php echo $c['asignatura']; ?></td>
                                    <td><?php echo $c['grado']; ?></td>
                                    <td><?php echo $c['seccion']; ?></td>
                                    <td><?php echo $c['estudiantes']; ?></td>
                                    <td><?php echo $c['promedio_visto']; ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
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
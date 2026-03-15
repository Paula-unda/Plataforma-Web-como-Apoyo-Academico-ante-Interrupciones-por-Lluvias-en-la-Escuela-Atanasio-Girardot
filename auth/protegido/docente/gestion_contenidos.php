<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/onesignal_config.php';

// Recuperar mensajes de sesión
$mensaje = '';
$error = '';

if (isset($_SESSION['mensaje_temporal'])) {
    if ($_SESSION['tipo_mensaje'] === 'exito') {
        $mensaje = $_SESSION['mensaje_temporal'];
    } else {
        $error = $_SESSION['mensaje_temporal'];
    }
    unset($_SESSION['mensaje_temporal']);
    unset($_SESSION['tipo_mensaje']);
}

// Verificar que sea docente
if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Docente') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}


$usuario_id = $_SESSION['usuario_id'];


// Obtener grado y sección del docente (si tiene asignados)
$grado_docente = '';
$seccion_docente = '';

try {
    $conexion = getConexion();
    
    // Intentar obtener grado/sección del docente (si existe tabla docentes)
    $stmt_docente = $conexion->prepare("
        SELECT grado, seccion FROM docentes WHERE usuario_id = ?
    ");
    $stmt_docente->execute([$usuario_id]);
    $datos_docente = $stmt_docente->fetch(PDO::FETCH_ASSOC);
    
    if ($datos_docente) {
        $grado_docente = $datos_docente['grado'];
        $seccion_docente = $datos_docente['seccion'];
    }
} catch (Exception $e) {
    error_log("Error obteniendo datos del docente: " . $e->getMessage());
}

// Procesar formulario de creación/edición
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo = trim($_POST['titulo'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $asignatura = trim($_POST['asignatura'] ?? '');
    $enlace = trim($_POST['enlace'] ?? '');
    $archivo_adjunto = '';
    
    // Validaciones básicas
    if (empty($titulo) || empty($descripcion) || empty($asignatura)) {
        $error = 'Todos los campos obligatorios deben ser completados.';
    } elseif (empty($grado_docente) || empty($seccion_docente)) {
        $error = 'No tienes un grado y sección asignados. No puedes publicar contenido.';
    } else {
        try {
            $conexion = getConexion();
            
            // Manejo de archivo subido
            if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../../../uploads/contenidos/';
                
                // Crear directorio si no existe
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_name = time() . '_' . basename($_FILES['archivo']['name']);
                $target_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['archivo']['tmp_name'], $target_path)) {
                    $archivo_adjunto = $file_name;
                } else {
                    $error = 'Error al subir el archivo.';
                }
            }
            
            if (empty($error)) {
                $query = "
                    INSERT INTO contenidos 
                    (titulo, descripcion, asignatura, grado, seccion, docente_id, enlace, archivo_adjunto, fecha_publicacion)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE)
                ";
                
                $stmt = $conexion->prepare($query);
                $stmt->execute([
                    $titulo, 
                    $descripcion, 
                    $asignatura, 
                    $grado_docente, 
                    $seccion_docente, 
                    $usuario_id, 
                    $enlace, 
                    $archivo_adjunto
                ]);
                
                // ✅ OBTENER EL ID DEL CONTENIDO RECIÉN INSERTADO
                $contenido_id = $conexion->lastInsertId();
                
                // ✅ PROCESAR MATERIALES ADICIONALES
                if (isset($_POST['materiales']) && is_array($_POST['materiales'])) {
                    $material_query = "
                        INSERT INTO materiales (contenido_id, titulo, tipo, url, archivo, orden)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ";
                    $material_stmt = $conexion->prepare($material_query);
                    
                    foreach ($_POST['materiales'] as $orden => $material) {
                        $tipo = $material['tipo'] ?? '';
                        $titulo_mat = trim($material['titulo'] ?? '');
                        $url = trim($material['url'] ?? '');
                        $archivo = '';
                        
                        // Procesar archivo si se subió
                        if (isset($_FILES['materiales']['name'][$orden]['archivo']) && 
                            $_FILES['materiales']['error'][$orden]['archivo'] === UPLOAD_ERR_OK) {
                            
                            $file_info = $_FILES['materiales'];
                            $file_name = time() . '_' . $orden . '_' . basename($file_info['name'][$orden]['archivo']);
                            $target_path = '../../../uploads/materiales/' . $file_name;
                            
                            if (!is_dir('../../../uploads/materiales/')) {
                                mkdir('../../../uploads/materiales/', 0777, true);
                            }
                            
                            if (move_uploaded_file($file_info['tmp_name'][$orden]['archivo'], $target_path)) {
                                $archivo = $file_name;
                            }
                        }
                        
                        if (!empty($titulo_mat) && (!empty($url) || !empty($archivo))) {
                            $material_stmt->execute([
                                $contenido_id, 
                                $titulo_mat, 
                                $tipo, 
                                $url, 
                                $archivo,
                                $orden
                            ]);
                        }
                    }
                }
                
                $mensaje = 'Contenido publicado exitosamente.';
            }
        } catch (Exception $e) {
            error_log("Error al guardar contenido: " . $e->getMessage());
            $error = 'Error al guardar el contenido.';
        }
    }
    // ========================================
    // REDIRECCIÓN DESPUÉS DEL POST (PRG Pattern)
    // ========================================
    if ($mensaje) {
        $_SESSION['mensaje_temporal'] = $mensaje;
        $_SESSION['tipo_mensaje'] = 'exito';
        header('Location: gestion_contenidos.php');
        exit();
    }
    
    if ($error) {
        $_SESSION['mensaje_temporal'] = $error;
        $_SESSION['tipo_mensaje'] = 'error';
        header('Location: gestion_contenidos.php');
        exit();
    }

}

// Obtener contenidos publicados por este docente
$contenidos_docente = [];
try {
    $conexion = getConexion();
    
    // Primero, obtener el total de estudiantes en el grado/sección del docente
    $stmt_total_estudiantes = $conexion->prepare("
        SELECT COUNT(*) as total
        FROM estudiantes e
        INNER JOIN usuarios u ON e.usuario_id = u.id
        WHERE u.rol = 'Estudiante' 
            AND e.grado = ? 
            AND e.seccion = ?
    ");
    $stmt_total_estudiantes->execute([$grado_docente, $seccion_docente]);
    $total_estudiantes_clase = $stmt_total_estudiantes->fetchColumn();
    
    // Consulta principal con conteo de estudiantes que han visto
    $query = "
        SELECT 
            c.id, 
            c.titulo, 
            c.asignatura, 
            c.grado, 
            c.seccion, 
            c.fecha_publicacion, 
            c.docente_id, 
            c.enlace, 
            c.archivo_adjunto,
            c.activo, 
            c.creado_en, 
            c.videos_adicionales,
            ? as total_estudiantes_clase,
            COUNT(DISTINCT CASE WHEN p.completado = true AND p.material_id IS NULL THEN p.estudiante_id END) as estudiantes_vieron
        FROM contenidos c
        LEFT JOIN progreso_contenido p ON c.id = p.contenido_id AND p.material_id IS NULL
        WHERE c.docente_id = ?
        GROUP BY 
            c.id, 
            c.titulo, 
            c.asignatura, 
            c.grado, 
            c.seccion, 
            c.fecha_publicacion, 
            c.docente_id, 
            c.enlace, 
            c.archivo_adjunto,
            c.activo, 
            c.creado_en, 
            c.videos_adicionales
    ";
    
    $stmt = $conexion->prepare($query);
    $stmt->execute([$total_estudiantes_clase, $usuario_id]);
    $contenidos_docente = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ordenar en PHP por fecha más reciente primero
    usort($contenidos_docente, function($a, $b) {
        $fecha_a = strtotime($a['fecha_publicacion'] ?? '1970-01-01');
        $fecha_b = strtotime($b['fecha_publicacion'] ?? '1970-01-01');
        
        if ($fecha_a == $fecha_b) {
            return $b['id'] - $a['id'];
        }
        return $fecha_b - $fecha_a;
    });
    
    error_log("=== CONTENIDOS DEL DOCENTE ORDENADOS ===");
    foreach ($contenidos_docente as $i => $c) {
        error_log(($i+1) . ". ID: " . $c['id'] . " | Fecha: " . $c['fecha_publicacion'] . " | Título: " . $c['titulo']);
        error_log("   👥 Vieron: " . ($c['estudiantes_vieron'] ?? 0) . " de " . ($c['total_estudiantes_clase'] ?? 0));
    }
    
} catch (Exception $e) {
    error_log("Error obteniendo contenidos: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Contenidos - SIEDUCRES</title>
    <?php require_once '../includes/favicon.php'; ?>
    <?php require_once '../includes/header_onesignal.php'; ?> 
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --text-dark: #333333; --text-muted: #666666; --border: #E0E0E0;
            --surface: #FFFFFF; --background: #F5F5F5; --primary: #4a90e2;
            --success: #28a745; --warning: #ffc107; --danger: #dc3545;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--background); color: var(--text-dark); min-height: 100vh; display: flex; flex-direction: column; }
        .header { display: flex; justify-content: space-between; align-items: center; padding: 0 24px; height: 60px; background-color: var(--surface); border-bottom: 1px solid var(--border); position: relative; z-index: 100; }
        .logo { height: 40px; }
        .header-right { display: flex; align-items: center; gap: 16px; }
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; background-color: #F0F0F0; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background 0.2s; }
        .icon-btn img { width: 20px; height: 20px; object-fit: contain; }
        .icon-btn:hover { background-color: #E0E0E0; }
        .menu-dropdown { position: absolute; top: 60px; right: 24px; background: white; border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); display: none; min-width: 180px; }
        .menu-item { padding: 10px 16px; font-size: 14px; color: var(--text-dark); text-decoration: none; display: block; transition: background 0.2s; }
        .menu-item:hover { background-color: #F8F8F8; }
        .banner { position: relative; height: 100px; overflow: hidden; }
        .banner-image { width: 100%; height: 100%; object-fit: cover; object-position: top; }
        .banner-content { text-align: center; position: relative; z-index: 2; max-width: 800px; padding: 20px; margin: 0 auto; }
        .banner-title { font-size: 36px; font-weight: 700; color: var(--text-dark); margin-bottom: 8px; }
        .main-content { flex: 1; padding: 40px 20px; max-width: 1200px; margin: 0 auto; width: 100%; }
        .footer { height: 50px; background-color: var(--surface); border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; padding: 0 24px; font-size: 13px; color: var(--text-muted); position: sticky; bottom: 0; }
        
        /* Formulario */
        .form-container { background: var(--surface); border-radius: 16px; padding: 32px; margin-bottom: 40px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .form-title { font-size: 20px; font-weight: 700; margin-bottom: 24px; color: var(--text-dark); }
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
        .form-group { margin-bottom: 20px; }
        .form-group.full-width { grid-column: span 2; }
        .form-label { display: block; font-weight: 600; font-size: 14px; margin-bottom: 8px; color: var(--text-dark); }
        .form-control { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 14px; }
        .form-control:focus { outline: none; border-color: var(--primary); }
        .form-select { appearance: none; background-image: url("image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23333333' viewBox='0 0 16 16'%3E%3Cpath d='M8 11l-5-5h10l-5 5z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; background-size: 16px; }
        .form-textarea { min-height: 120px; resize: vertical; }
        .btn-primary { background: var(--primary); color: white; padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: background 0.2s; font-size: 16px; }
        .btn-primary:hover { background: #3a7bc8; }
        
        /* Tabla de contenidos */
        .table-container { background: var(--surface); border-radius: 16px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .table-title { font-size: 20px; font-weight: 700; margin-bottom: 24px; }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px; background: #f8f9fa; font-weight: 600; color: var(--text-dark); border-bottom: 2px solid var(--border); }
        td { padding: 12px; border-bottom: 1px solid var(--border); color: var(--text-muted); }
        tr:hover td { background: #f8f9fa; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .action-btns { display: flex; gap: 8px; }
        .btn-icon { padding: 4px 8px; background: none; border: 1px solid var(--border); border-radius: 4px; cursor: pointer; transition: all 0.2s; text-decoration: none; color: var(--text-muted); }
        .btn-icon:hover { background: #f0f0f0; }
        .progress-bar { width: 100%; height: 6px; background: #e0e0e0; border-radius: 3px; overflow: hidden; margin-top: 4px; }
        .progress-fill { height: 100%; background: var(--success); border-radius: 3px; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .alert-error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .btn-secondary {
            background: #f0f0f0;
            border: 1px solid var(--border);
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 14px;
        }
        .btn-secondary:hover {
            background: #e0e0e0;
        }
        .btn-icon.eliminar {
            color: #dc3545;
        }

        .btn-icon.eliminar:hover {
            background-color: rgba(220, 53, 69, 0.1);
            border-color: #dc3545;
        }
        /* Enlace volver */
        .back-link {
            display: block;
            color: #EF5E8E;
            text-decoration: none;
        }
        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-group.full-width { grid-column: span 1; }
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header_comun.php'; ?>

    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image">
    </div>
    
    <div class="banner-content">
        <h1 class="banner-title">Gestión de Contenidos</h1>
    </div>
    <!-- 🔴 FLECHA DE VOLVER A LA IZQUIERDA -->
    <?php if (basename($_SERVER['PHP_SELF']) != 'index.php'): ?>
        <div style="max-width: 1200px; margin: 10px 0 10px 40px; padding: 0; width: 100%;">
            <a href="index.php" class="back-link">← Volver al Panel</a>
        </div>
    <?php endif; ?>

    <main class="main-content">
        <?php if ($mensaje): ?>
            <div class="alert alert-success">✅ <?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Formulario para publicar contenido -->
        <div class="form-container">
            <h2 class="form-title">Publicar nuevo contenido</h2>
            <form method="POST" enctype="multipart/form-data">
                <!-- ======================================== -->
                <!-- SECCIÓN 1: INFORMACIÓN BÁSICA DEL CONTENIDO -->
                <!-- ======================================== -->
                <div style="background: #e8f4fd; padding: 16px; border-radius: 8px; margin-bottom: 24px; border-left: 4px solid var(--primary);">
                    <h3 style="font-size: 18px; margin-bottom: 8px;">Información básica</h3>
                    <p style="color: var(--text-muted); font-size: 14px;">Completa los datos principales del contenido educativo</p>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Título del contenido *</label>
                        <input type="text" name="titulo" class="form-control" placeholder="Ej: Clase de Matemáticas - Semana 1" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Asignatura *</label>
                        <input type="text" name="asignatura" class="form-control" placeholder="Ej: Matemáticas, Historia, Ciencias..." required>
                    </div>
                </div>
                
                <!-- ======================================== -->
                <!-- SECCIÓN 2: RECURSOS PRINCIPALES (OPCIONALES) -->
                <!-- ======================================== -->
                <div style="background: #f0f0f0; padding: 16px; border-radius: 8px; margin: 24px 0; border-left: 4px solid #ffc107;">
                    <h3 style="font-size: 18px; margin-bottom: 8px;">📎 Recursos principales de la clase</h3>
                    <p style="color: var(--text-muted); font-size: 14px;">Puedes agregar UN documento y/o UN video como material principal (opcional)</p>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
                    <!-- Documento principal -->
                    <div style="background: #f9f9f9; padding: 16px; border-radius: 8px; border: 1px solid #ddd;">
                        <label class="form-label" style="color: #28a745;">Documento principal</label>
                        <p style="font-size: 12px; color: #666; margin-bottom: 8px;">Sube un archivo (PDF, Word, etc.)</p>
                        <input type="file" name="archivo" class="form-control" accept=".pdf,.doc,.docx,.ppt,.pptx">
                    </div>
                    
                    <!-- Video principal -->
                    <div style="background: #f9f9f9; padding: 16px; border-radius: 8px; border: 1px solid #ddd;">
                        <label class="form-label" style="color: #4a90e2;">Video principal</label>
                        <p style="font-size: 12px; color: #666; margin-bottom: 8px;">Pega el enlace de YouTube</p>
                        <input type="url" name="enlace" class="form-control" placeholder="https://youtube.com/watch?v=...">
                    </div>
                </div>
                
                <!-- ======================================== -->
                <!-- SECCIÓN 3: MATERIALES ADICIONALES -->
                <!-- ======================================== -->
                <div style="background: #f0f0f0; padding: 16px; border-radius: 8px; margin: 24px 0; border-left: 4px solid #9b8afb;">
                    <h3 style="font-size: 18px; margin-bottom: 8px;">Materiales adicionales</h3>
                    <p style="color: var(--text-muted); font-size: 14px;">Agrega materiales extras (cada uno con su propio título)</p>
                </div>
                
                <div id="materiales-container" style="margin-bottom: 20px;">
                    <!-- Los materiales adicionales se agregarán aquí dinámicamente -->
                </div>
                
                <button type="button" id="agregar-material" class="btn-secondary" style="margin-bottom: 24px; width: 100%;">
                    + Agregar otro material adicional
                </button>
                
                <!-- ======================================== -->
                <!-- SECCIÓN 4: DESCRIPCIÓN -->
                <!-- ======================================== -->
                <div style="background: #e8f4fd; padding: 16px; border-radius: 8px; margin: 24px 0; border-left: 4px solid var(--primary);">
                    <h3 style="font-size: 18px; margin-bottom: 8px;">Descripción de la clase *</h3>
                    <p style="color: var(--text-muted); font-size: 14px;">Explica de qué trata este contenido</p>
                </div>
                
                <div class="form-group full-width">
                    <textarea name="descripcion" class="form-control form-textarea" placeholder="Escribe aquí la descripción detallada de tu clase..." required></textarea>
                </div>
                
                <button type="submit" class="btn-primary" style="margin-top: 24px;">Publicar contenido</button>
            </form>
        </div>

        <!-- Lista de contenidos publicados -->
        <div class="table-container">
            <h2 class="table-title">Mis contenidos publicados</h2>
            
            <?php if (count($contenidos_docente) > 0): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Título</th>
                                <th>Asignatura</th>
                                <th>Grado/Sección</th>
                                <th>Fecha</th>
                                <th>Estudiantes que vieron</th>
                                <th>% de la clase</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contenidos_docente as $cont): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($cont['titulo']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($cont['asignatura']); ?></td>
                                    <td>
                                        <?php 
                                        if ($cont['grado'] && $cont['seccion']) {
                                            echo htmlspecialchars($cont['grado'] . ' ' . $cont['seccion']);
                                        } elseif ($cont['grado']) {
                                            echo htmlspecialchars($cont['grado'] . ' (todas)');
                                        } else {
                                            echo 'Todos';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($cont['fecha_publicacion'])); ?></td>
                                    
                                    <!-- ✅ NUEVO: Estudiantes que vieron / Total -->
                                    <td>
                                        <strong><?php echo ($cont['estudiantes_vieron'] ?? 0); ?> / <?php echo ($cont['total_estudiantes_clase'] ?? 0); ?></strong>
                                        <br>
                                        <small style="color: #666; font-size: 11px;">
                                            <?php 
                                            $total = $cont['total_estudiantes_clase'] ?? 0;
                                            $vieron = $cont['estudiantes_vieron'] ?? 0;
                                            $porcentaje = $total > 0 ? round(($vieron / $total) * 100) : 0;
                                            echo $porcentaje . '% de la clase';
                                            ?>
                                        </small>
                                    </td>
                                    
                                    <!-- ✅ NUEVO: Barra de porcentaje de estudiantes que vieron -->
                                    <td style="min-width: 120px;">
                                        <?php 
                                        $total = $cont['total_estudiantes_clase'] ?? 0;
                                        $vieron = $cont['estudiantes_vieron'] ?? 0;
                                        $porcentaje = $total > 0 ? round(($vieron / $total) * 100) : 0;
                                        ?>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span style="font-weight: 600; min-width: 40px;"><?php echo $porcentaje; ?>%</span>
                                            <div class="progress-bar" style="flex: 1;">
                                                <div class="progress-fill" style="width: <?php echo $porcentaje; ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                    
                                    <td class="action-btns">
                                        <a href="editar_contenido.php?id=<?php echo $cont['id']; ?>" class="btn-icon" title="Editar">✏️</a>
                                        <a href="estadisticas_contenido.php?id=<?php echo $cont['id']; ?>" class="btn-icon" title="Estadísticas">📊</a>
                                        <a href="previsualizar_contenido.php?id=<?php echo $cont['id']; ?>" class="btn-icon" title="Previsualizar">👁️</a>
                                        <a href="#" onclick="eliminarContenido(<?php echo $cont['id']; ?>, '<?php echo addslashes($cont['titulo']); ?>')" class="btn-icon eliminar" title="Eliminar">🗑️</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="text-align: center; padding: 40px; color: var(--text-muted);">
                    Aún no has publicado ningún contenido.
                </p>
            <?php endif; ?>
        </div>
    </main>

    <footer class="footer">
        <span>v2.0.0</span>
        <span>Soporte Técnico</span>
    </footer>

    <script>

        // Variable global para llevar un contador único
        let materialCounter = 0;

        // ✅ SCRIPT MEJORADO para agregar materiales adicionales
        document.getElementById('agregar-material')?.addEventListener('click', function() {
            const container = document.getElementById('materiales-container');
            const uniqueId = materialCounter++; // Usar contador único, no length
            
            const materialDiv = document.createElement('div');
            materialDiv.className = 'material-item';
            materialDiv.dataset.uniqueId = uniqueId; // Guardar ID único
            materialDiv.style.cssText = 'background: #f9f9f9; padding: 20px; border-radius: 8px; margin-bottom: 20px; position: relative; border: 1px solid #ddd;';
            
            materialDiv.innerHTML = `
                <button type="button" class="eliminar-material" style="position: absolute; top: 10px; right: 10px; background: #dc3545; color: white; border: none; width: 30px; height: 30px; border-radius: 50%; font-size: 18px; cursor: pointer; display: flex; align-items: center; justify-content: center;">×</button>
                
                <h4 style="font-size: 16px; margin-bottom: 16px; color: #666;">Material adicional</h4>
                
                <div style="margin-bottom: 16px;">
                    <label class="form-label">Tipo de material *</label>
                    <select name="materiales[${uniqueId}][tipo]" class="form-control tipo-material" required>
                        <option value="">-- ¿Qué tipo de material es? --</option>
                        <option value="video">VIDEO (YouTube)</option>
                        <option value="documento">DOCUMENTO (PDF, Word)</option>
                        <option value="enlace">ENLACE (página web)</option>
                    </select>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label class="form-label">Título del material *</label>
                    <input type="text" name="materiales[${uniqueId}][titulo]" class="form-control" placeholder="Ej: Video explicativo, Guía de ejercicios, etc." required>
                </div>
                
                <!-- Campos que se mostrarán según el tipo seleccionado -->
                <div class="campos-url" style="display: none; margin-bottom: 16px;">
                    <label class="form-label">URL</label>
                    <input type="url" name="materiales[${uniqueId}][url]" class="form-control url-field" placeholder="https://...">
                    <small style="color: #666; display: block; margin-top: 4px;">Para videos de YouTube o enlaces web</small>
                </div>
                
                <div class="campos-archivo" style="display: none; margin-bottom: 16px;">
                    <label class="form-label">Archivo</label>
                    <input type="file" name="materiales[${uniqueId}][archivo]" class="form-control file-field" accept=".pdf,.doc,.docx,.mp3,.mp4,.webm">
                    <small style="color: #666; display: block; margin-top: 4px;">Para documentos o videos locales</small>
                </div>
                
                <div class="mensaje-ayuda" style="background: #fff3cd; padding: 8px; border-radius: 4px; font-size: 13px; color: #856404; display: none;"></div>
            `;
            
            container.appendChild(materialDiv);
            
            const selectTipo = materialDiv.querySelector('.tipo-material');
            const camposUrl = materialDiv.querySelector('.campos-url');
            const camposArchivo = materialDiv.querySelector('.campos-archivo');
            const urlField = materialDiv.querySelector('.url-field');
            const fileField = materialDiv.querySelector('.file-field');
            const mensajeAyuda = materialDiv.querySelector('.mensaje-ayuda');
            
            selectTipo.addEventListener('change', function() {
                const tipo = this.value;
                
                // Ocultar todo primero
                camposUrl.style.display = 'none';
                camposArchivo.style.display = 'none';
                urlField.required = false;
                fileField.required = false;
                urlField.value = '';
                fileField.value = '';
                mensajeAyuda.style.display = 'none';
                
                if (tipo === 'video') {
                    camposUrl.style.display = 'block';
                    urlField.required = true;
                    mensajeAyuda.style.display = 'block';
                    mensajeAyuda.innerHTML = 'Para VIDEO: SOLO debes pegar la URL de YouTube (no subas archivo)';
                } 
                else if (tipo === 'enlace') {
                    camposUrl.style.display = 'block';
                    urlField.required = true;
                    mensajeAyuda.style.display = 'block';
                    mensajeAyuda.innerHTML = 'Para ENLACE: SOLO debes pegar la URL de la página web (no subas archivo)';
                }
                else if (tipo === 'documento') {
                    camposArchivo.style.display = 'block';
                    fileField.required = true;
                    mensajeAyuda.style.display = 'block';
                    mensajeAyuda.innerHTML = 'Para DOCUMENTO: SOLO debes subir un archivo (PDF, Word) - no pegues URL';
                }
                
            });
            
            materialDiv.querySelector('.eliminar-material').addEventListener('click', function() {
                materialDiv.remove();
            });
        });
    
        // Función para eliminar contenido
        function eliminarContenido(id, titulo) {
            if (confirm('¿Estás seguro de que deseas eliminar el contenido "' + titulo + '"?\nEsta acción NO SE PUEDE DESHACER y eliminará también todos los materiales asociados.')) {
                window.location.href = 'eliminar_contenido.php?id=' + id;
            }
        }
    </script>
</body>
</html>
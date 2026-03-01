<?php
session_start();
require_once '../../funciones.php';

// Verificar que sea docente
if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Docente') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$mensaje = '';
$error = '';
$contenido_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($contenido_id <= 0) {
    header('Location: gestion_contenidos.php?error=ID+no+válido');
    exit();
}

try {
    $conexion = getConexion();
    
    // Verificar que el contenido pertenezca a este docente
    $check = $conexion->prepare("
        SELECT c.*, u.nombre as docente_nombre 
        FROM contenidos c
        LEFT JOIN usuarios u ON c.docente_id = u.id
        WHERE c.id = ? AND c.docente_id = ?
    ");
    $check->execute([$contenido_id, $usuario_id]);
    $contenido = $check->fetch(PDO::FETCH_ASSOC);
    
    if (!$contenido) {
        header('Location: gestion_contenidos.php?error=Contenido+no+encontrado');
        exit();
    }
    
    // Obtener materiales adicionales
    $materiales = $conexion->prepare("
        SELECT * FROM materiales 
        WHERE contenido_id = ? AND activo = true 
        ORDER BY orden ASC
    ");
    $materiales->execute([$contenido_id]);
    $lista_materiales = $materiales->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error al cargar contenido: " . $e->getMessage());
    header('Location: gestion_contenidos.php?error=Error+al+cargar+contenido');
    exit();
}

// Procesar formulario de edición
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo = trim($_POST['titulo'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $asignatura = trim($_POST['asignatura'] ?? '');
    $enlace = trim($_POST['enlace'] ?? '');
    $archivo_adjunto = $contenido['archivo_adjunto']; // Mantener el actual por defecto
    
    // Verificar si se debe eliminar el documento principal
    $eliminar_documento = isset($_POST['eliminar_documento']) && $_POST['eliminar_documento'] === '1';
    $eliminar_video = isset($_POST['eliminar_video']) && $_POST['eliminar_video'] === '1';

    // Si se eliminó el documento, borrar archivo y poner null
    if ($eliminar_documento && !empty($contenido['archivo_adjunto'])) {
        $old_file = '../../../uploads/contenidos/' . $contenido['archivo_adjunto'];
        if (file_exists($old_file)) {
            unlink($old_file);
        }
        $archivo_adjunto = null; // Importante: poner null, no string vacío
    }

    // Si se eliminó el video, poner enlace vacío
    if ($eliminar_video) {
        $enlace = '';
    }

    // Solo si se subió un archivo nuevo, actualizar
    if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../../../uploads/contenidos/';
        $file_name = time() . '_' . basename($_FILES['archivo']['name']);
        $target_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['archivo']['tmp_name'], $target_path)) {
            // Eliminar archivo anterior si existe (y no se marcó eliminar aparte)
            if (!empty($contenido['archivo_adjunto']) && !$eliminar_documento) {
                $old_file = $upload_dir . $contenido['archivo_adjunto'];
                if (file_exists($old_file)) {
                    unlink($old_file);
                }
            }
            $archivo_adjunto = $file_name;
        } else {
            $error = 'Error al subir el archivo.';
        }
    }
    // Validaciones básicas
    if (empty($titulo) || empty($descripcion) || empty($asignatura)) {
        $error = 'Todos los campos obligatorios deben ser completados.';
    } else {
        try {
            $conexion = getConexion();
            
            // Manejo de nuevo archivo subido (si se subió uno nuevo)
            if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../../../uploads/contenidos/';
                $file_name = time() . '_' . basename($_FILES['archivo']['name']);
                $target_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['archivo']['tmp_name'], $target_path)) {
                    // Eliminar archivo anterior si existe
                    if (!empty($contenido['archivo_adjunto'])) {
                        $old_file = $upload_dir . $contenido['archivo_adjunto'];
                        if (file_exists($old_file)) {
                            unlink($old_file);
                        }
                    }
                    $archivo_adjunto = $file_name;
                } else {
                    $error = 'Error al subir el archivo.';
                }
            }
            
            if (empty($error)) {
                // Actualizar contenido principal
                $query = "
                    UPDATE contenidos 
                    SET titulo = ?, descripcion = ?, asignatura = ?, enlace = ?, archivo_adjunto = ?
                    WHERE id = ? AND docente_id = ?
                ";
                
                $stmt = $conexion->prepare($query);
                $stmt->execute([
                    $titulo, 
                    $descripcion, 
                    $asignatura, 
                    $enlace, 
                    $archivo_adjunto,
                    $contenido_id,
                    $usuario_id
                ]);
                
                // Eliminar materiales existentes (opcional - o actualizar)
                // Por simplicidad, eliminamos y volvemos a insertar
                if (isset($_POST['actualizar_materiales']) && $_POST['actualizar_materiales'] === '1') {
                    
                    // Eliminar archivos físicos de materiales anteriores
                    foreach ($lista_materiales as $mat) {
                        if (!empty($mat['archivo'])) {
                            $old_file = '../../../uploads/materiales/' . $mat['archivo'];
                            if (file_exists($old_file)) {
                                unlink($old_file);
                            }
                        }
                    }
                    
                    // Eliminar registros de materiales
                    $del_materiales = $conexion->prepare("DELETE FROM materiales WHERE contenido_id = ?");
                    $del_materiales->execute([$contenido_id]);
                    
                    // Insertar nuevos materiales
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
                }
                
                $_SESSION['mensaje_temporal'] = 'Contenido actualizado exitosamente.';
                $_SESSION['tipo_mensaje'] = 'exito';
                header('Location: gestion_contenidos.php');
                exit();
            }
            // Procesar eliminación de materiales marcados
            if (isset($_POST['eliminar_materiales']) && is_array($_POST['eliminar_materiales'])) {
                foreach ($_POST['eliminar_materiales'] as $material_id) {
                    // Obtener información del material para eliminar archivo
                    $get_material = $conexion->prepare("SELECT archivo FROM materiales WHERE id = ? AND contenido_id = ?");
                    $get_material->execute([$material_id, $contenido_id]);
                    $mat = $get_material->fetch();
                    
                    if ($mat && !empty($mat['archivo'])) {
                        $file_path = '../../../uploads/materiales/' . $mat['archivo'];
                        if (file_exists($file_path)) {
                            unlink($file_path);
                        }
                    }
                    
                    // Eliminar registro
                    $del_material = $conexion->prepare("DELETE FROM materiales WHERE id = ? AND contenido_id = ?");
                    $del_material->execute([$material_id, $contenido_id]);
                }
            }

            // Procesar eliminación de archivos de materiales (sin eliminar el material)
            if (isset($_POST['eliminar_archivo_material']) && is_array($_POST['eliminar_archivo_material'])) {
                foreach ($_POST['eliminar_archivo_material'] as $material_id) {
                    // Obtener nombre del archivo
                    $get_archivo = $conexion->prepare("SELECT archivo FROM materiales WHERE id = ? AND contenido_id = ?");
                    $get_archivo->execute([$material_id, $contenido_id]);
                    $archivo = $get_archivo->fetchColumn();
                    
                    if ($archivo) {
                        $file_path = '../../../uploads/materiales/' . $archivo;
                        if (file_exists($file_path)) {
                            unlink($file_path);
                        }
                        
                        // Actualizar material, quitando el archivo
                        $update = $conexion->prepare("UPDATE materiales SET archivo = NULL WHERE id = ?");
                        $update->execute([$material_id]);
                    }
                }
            }

            // Procesar materiales existentes (actualizar)
            if (isset($_POST['materiales']) && is_array($_POST['materiales'])) {
                foreach ($_POST['materiales'] as $orden => $material) {
                    // Si tiene ID, es un material existente
                    if (isset($material['id']) && !empty($material['id'])) {
                        $material_id = $material['id'];
                        $tipo = $material['tipo'];
                        $titulo = trim($material['titulo']);
                        $url = trim($material['url'] ?? '');
                        $archivo = null;
                        
                        // Verificar si se subió un nuevo archivo
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
                                
                                // Eliminar archivo anterior si existe
                                $old_file = $conexion->prepare("SELECT archivo FROM materiales WHERE id = ?");
                                $old_file->execute([$material_id]);
                                $old_name = $old_file->fetchColumn();
                                
                                if ($old_name) {
                                    $old_path = '../../../uploads/materiales/' . $old_name;
                                    if (file_exists($old_path)) {
                                        unlink($old_path);
                                    }
                                }
                            }
                        }
                        
                        // Actualizar material
                        if ($tipo === 'documento') {
                            $update = $conexion->prepare("UPDATE materiales SET titulo = ?, tipo = ?, url = NULL, archivo = COALESCE(?, archivo) WHERE id = ?");
                            $update->execute([$titulo, $tipo, $archivo, $material_id]);
                        } else {
                            $update = $conexion->prepare("UPDATE materiales SET titulo = ?, tipo = ?, url = ?, archivo = NULL WHERE id = ?");
                            $update->execute([$titulo, $tipo, $url, $material_id]);
                        }
                    } else {
                        // Es un material nuevo (sin ID)
                        $tipo = $material['tipo'];
                        $titulo = trim($material['titulo']);
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
                        
                        if (!empty($titulo) && (!empty($url) || !empty($archivo))) {
                            $insert = $conexion->prepare("
                                INSERT INTO materiales (contenido_id, titulo, tipo, url, archivo, orden)
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $insert->execute([$contenido_id, $titulo, $tipo, $url, $archivo, $orden]);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error al actualizar contenido: " . $e->getMessage());
            $error = 'Error al guardar los cambios.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Contenido - <?php echo htmlspecialchars($contenido['titulo']); ?></title>
    <?php require_once '../includes/favicon.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary-cyan: #4BC4E7;
            --primary-pink: #EF5E8E;
            --primary-lime: #C2D54E;
            --primary-purple: #9B8AFB;
            --text-dark: #333333;
            --text-muted: #666666;
            --border: #E0E0E0;
            --surface: #FFFFFF;
            --background: #F5F5F5;
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
            position: relative;
            z-index: 100;
        }
        
        .logo { height: 40px; }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
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
        
        .banner {
            position: relative;
            height: 100px;
            overflow: hidden;
        }
        
        .banner-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: top;
        }
        
        .banner-content {
            text-align: center;
            position: relative;
            z-index: 2;
            max-width: 800px;
            padding: 20px;
            margin: 0 auto;
        }
        
        .banner-title {
            font-size: 36px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 8px;
        }
        
        .main-content {
            flex: 1;
            padding: 40px 20px;
            max-width: 1000px;
            margin: 0 auto;
            width: 100%;
        }
        
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
        }
        
        /* Formulario */
        .form-container {
            background: var(--surface);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 40px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-left: 4px solid var(--primary-purple);
        }
        
        .form-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 24px;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group.full-width {
            grid-column: span 2;
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 8px;
            color: var(--text-dark);
        }
        
        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-cyan);
        }
        
        .form-textarea {
            min-height: 150px;
            resize: vertical;
        }
        
        .btn-primary {
            background: var(--primary-lime);
            color: var(--text-dark);
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 16px;
        }
        
        .btn-primary:hover {
            background: #acbe36;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #f0f0f0;
            border: 1px solid var(--border);
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 14px;
            text-decoration: none;
            color: var(--text-dark);
            display: inline-block;
        }
        
        .btn-secondary:hover {
            background: #e0e0e0;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .info-box {
            background: #e8f4fd;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            border-left: 4px solid var(--primary-cyan);
        }
        
        .current-file {
            background: #f0f0f0;
            padding: 12px;
            border-radius: 8px;
            margin-top: 8px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .material-item {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            position: relative;
            border: 1px solid #ddd;
        }
        
        .material-counter {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 10px;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        
        .action-buttons {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .form-group.full-width {
                grid-column: span 1;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-left">
            <img src="../../../assets/logo.svg" alt="SIEDUCRES" class="logo">
        </div>
        <div class="header-right">
            <div class="icon-btn">
                <img src="../../../assets/icon-bell.svg" alt="Notificaciones">
            </div>
            <div class="icon-btn">
                <img src="../../../assets/icon-user.svg" alt="Perfil">
            </div>
            <div class="icon-btn" id="menu-toggle">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="#333333">
                    <path d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/>
                </svg>
            </div>
            <div class="menu-dropdown" id="dropdown">
                <a href="index.php" class="menu-item">Panel Principal</a>
                <a href="gestion_contenidos.php" class="menu-item">Gestión de Contenidos</a>
                <a href="../../logout.php" class="menu-item">Cerrar sesión</a>
            </div>
        </div>
    </header>

    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image">
    </div>
    
    <div class="banner-content">
        <h1 class="banner-title">Editar Contenido</h1>
    </div>

    <main class="main-content">
        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="form-container">
            <h2 class="form-title">
                <span>✏️ Editando:</span>
                <span style="color: var(--primary-purple);"><?php echo htmlspecialchars($contenido['titulo']); ?></span>
            </h2>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="actualizar_materiales" value="1">
                
                <!-- Información básica -->
                <div class="info-box">
                    <h3 style="font-size: 18px; margin-bottom: 8px;">📋 Información básica</h3>
                    <p style="color: var(--text-muted);">Los campos con * son obligatorios</p>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Título del contenido *</label>
                        <input type="text" name="titulo" class="form-control" 
                               value="<?php echo htmlspecialchars($contenido['titulo']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Asignatura *</label>
                        <input type="text" name="asignatura" class="form-control" 
                               value="<?php echo htmlspecialchars($contenido['asignatura']); ?>" required>
                    </div>
                </div>
                
                <!-- Recursos principales -->
                <div class="info-box" style="margin-top: 30px;">
                    <h3 style="font-size: 18px; margin-bottom: 8px;">📎 Recursos principales</h3>
                    <p style="color: var(--text-muted);">Estos son los recursos principales del contenido</p>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
                    <!-- Documento principal -->
                    <div style="background: #f9f9f9; padding: 16px; border-radius: 8px; border: 1px solid #ddd;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <label class="form-label" style="color: var(--primary-lime); margin: 0;">📄 Documento principal</label>
                            <?php if (!empty($contenido['archivo_adjunto'])): ?>
                                <label class="checkbox-label" style="color: #dc3545;">
                                    <input type="checkbox" name="eliminar_documento" value="1" onchange="toggleDocumentoEliminar(this)">
                                    <span style="font-size: 14px;">Eliminar documento</span>
                                </label>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($contenido['archivo_adjunto'])): ?>
                            <div class="current-file" id="documento-actual">
                                <span style="font-size: 24px;">📄</span>
                                <span style="flex: 1; word-break: break-all;"><?php echo $contenido['archivo_adjunto']; ?></span>
                                <a href="../../../uploads/contenidos/<?php echo $contenido['archivo_adjunto']; ?>" 
                                target="_blank" class="btn-secondary" style="padding: 4px 12px;">Ver</a>
                            </div>
                            <p style="font-size: 12px; color: #666; margin: 8px 0;" id="documento-mensaje">
                                Si marcas "Eliminar documento", el archivo se borrará al guardar.
                            </p>
                        <?php endif; ?>
                        
                        <div id="documento-nuevo" style="<?php echo !empty($contenido['archivo_adjunto']) ? 'display: none;' : ''; ?>">
                            <label class="form-label">Subir nuevo documento (opcional)</label>
                            <input type="file" name="archivo" class="form-control" accept=".pdf,.doc,.docx,.ppt,.pptx">
                        </div>
                        
                        <?php if (empty($contenido['archivo_adjunto'])): ?>
                            <p style="font-size: 12px; color: #666; margin-top: 8px;">
                                No hay documento actual. Sube uno si deseas.
                            </p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Video principal -->
                    <div style="background: #f9f9f9; padding: 16px; border-radius: 8px; border: 1px solid #ddd;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <label class="form-label" style="color: var(--primary-cyan); margin: 0;">🎥 Video principal</label>
                            <?php if (!empty($contenido['enlace'])): ?>
                                <label class="checkbox-label" style="color: #dc3545;">
                                    <input type="checkbox" name="eliminar_video" value="1" onchange="toggleVideoEliminar(this)">
                                    <span style="font-size: 14px;">Eliminar video</span>
                                </label>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($contenido['enlace'])): ?>
                            <div class="current-file" id="video-actual" style="flex-direction: column; align-items: flex-start;">
                                <div style="display: flex; width: 100%; align-items: center; gap: 12px;">
                                    <span style="font-size: 24px;">🎬</span>
                                    <span style="flex: 1; word-break: break-all;"><?php echo $contenido['enlace']; ?></span>
                                    <a href="<?php echo $contenido['enlace']; ?>" target="_blank" class="btn-secondary" style="padding: 4px 12px;">Ver</a>
                                </div>
                            </div>
                            <p style="font-size: 12px; color: #666; margin: 8px 0;" id="video-mensaje">
                                Si marcas "Eliminar video", se quitará el enlace al guardar.
                            </p>
                        <?php endif; ?>
                        
                        <div id="video-nuevo" style="<?php echo !empty($contenido['enlace']) ? 'display: none;' : ''; ?>">
                            <label class="form-label">Agregar video de YouTube</label>
                            <input type="url" name="enlace" class="form-control" placeholder="https://youtube.com/watch?v=...">
                        </div>
                        
                        <?php if (empty($contenido['enlace'])): ?>
                            <p style="font-size: 12px; color: #666; margin-top: 8px;">
                                No hay video actual. Agrega uno si deseas.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Materiales adicionales -->
                <div class="info-box" style="margin-top: 30px;">
                    <h3 style="font-size: 18px; margin-bottom: 8px;">➕ Materiales adicionales</h3>
                    <p style="color: var(--text-muted);">Agrega, edita o elimina los materiales adicionales</p>
                </div>

                <div id="materiales-container" style="margin-bottom: 20px;">
                    <?php if (!empty($lista_materiales)): ?>
                        <?php foreach ($lista_materiales as $index => $material): ?>
                            <div class="material-item" data-id="<?php echo $material['id']; ?>" data-index="<?php echo $index; ?>">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                    <span class="material-counter" style="font-weight: 600; color: var(--primary-purple);">
                                        Material #<?php echo $index + 1; ?>
                                    </span>
                                    <label class="checkbox-label" style="color: #dc3545;">
                                        <input type="checkbox" name="eliminar_materiales[]" value="<?php echo $material['id']; ?>" 
                                            class="eliminar-material-checkbox" onchange="toggleMaterialEliminar(this, <?php echo $index; ?>)">
                                        <span style="font-size: 14px;">Eliminar este material</span>
                                    </label>
                                </div>
                                
                                <div id="material-<?php echo $index; ?>-contenido">
                                    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 12px; margin-bottom: 12px;">
                                        <div>
                                            <label class="form-label">Tipo</label>
                                            <select name="materiales[<?php echo $index; ?>][tipo]" class="form-control material-tipo" 
                                                    data-index="<?php echo $index; ?>" required
                                                    onchange="cambiarTipoMaterial(<?php echo $index; ?>, this.value)">
                                                <option value="video" <?php echo $material['tipo'] === 'video' ? 'selected' : ''; ?>>VIDEO (YouTube)</option>
                                                <option value="documento" <?php echo $material['tipo'] === 'documento' ? 'selected' : ''; ?>>DOCUMENTO (PDF, Word)</option>
                                                <option value="enlace" <?php echo $material['tipo'] === 'enlace' ? 'selected' : ''; ?>>ENLACE (web)</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="form-label">Título</label>
                                            <input type="text" name="materiales[<?php echo $index; ?>][titulo]" 
                                                class="form-control" value="<?php echo htmlspecialchars($material['titulo']); ?>" required>
                                        </div>
                                    </div>
                                    
                                    <!-- Campo para ID del material (para saber si es existente o nuevo) -->
                                    <input type="hidden" name="materiales[<?php echo $index; ?>][id]" value="<?php echo $material['id']; ?>">
                                    
                                    <!-- Contenedor de URL (para video/enlace) -->
                                    <div id="material-<?php echo $index; ?>-url" style="margin-bottom: 12px; display: <?php echo ($material['tipo'] === 'video' || $material['tipo'] === 'enlace') ? 'block' : 'none'; ?>;">
                                        <label class="form-label">URL</label>
                                        <input type="url" name="materiales[<?php echo $index; ?>][url]" 
                                            class="form-control" 
                                            value="<?php echo htmlspecialchars($material['url'] ?? ''); ?>"
                                            <?php echo ($material['tipo'] === 'video' || $material['tipo'] === 'enlace') ? 'required' : ''; ?>>
                                    </div>
                                    
                                    <!-- Contenedor de archivo actual (para documento) -->
                                    <div id="material-<?php echo $index; ?>-archivo-actual" style="margin-bottom: 12px; display: <?php echo $material['tipo'] === 'documento' ? 'block' : 'none'; ?>;">
                                        <?php if (!empty($material['archivo'])): ?>
                                            <label class="form-label">Archivo actual</label>
                                            <div class="current-file" style="margin-bottom: 8px;">
                                                <span style="font-size: 20px;">📄</span>
                                                <span style="flex: 1; word-break: break-all;"><?php echo basename($material['archivo']); ?></span>
                                                <a href="../../../uploads/materiales/<?php echo $material['archivo']; ?>" 
                                                target="_blank" class="btn-secondary" style="padding: 4px 12px;">Ver</a>
                                            </div>
                                            <label class="checkbox-label" style="margin-bottom: 8px;">
                                                <input type="checkbox" name="eliminar_archivo_material[]" value="<?php echo $material['id']; ?>" 
                                                    onchange="toggleArchivoMaterial(this, <?php echo $index; ?>)">
                                                <span style="font-size: 13px;">Eliminar este archivo</span>
                                            </label>
                                        <?php endif; ?>
                                        
                                        <!-- Campo para subir nuevo archivo -->
                                        <div id="material-<?php echo $index; ?>-archivo-nuevo" style="margin-top: 8px;">
                                            <label class="form-label"><?php echo !empty($material['archivo']) ? 'Reemplazar archivo (opcional)' : 'Subir archivo *'; ?></label>
                                            <input type="file" name="materiales[<?php echo $index; ?>][archivo]" 
                                                class="form-control" accept=".pdf,.doc,.docx">
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Mensaje cuando se marca para eliminar -->
                                <div id="material-<?php echo $index; ?>-eliminar-msg" style="display: none; background: #f8d7da; padding: 12px; border-radius: 6px; margin-top: 10px; color: #721c24;">
                                    ⚠️ Este material será eliminado cuando guardes los cambios.
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align: center; padding: 20px; color: var(--text-muted); background: #f9f9f9; border-radius: 8px;">
                            No hay materiales adicionales. Puedes agregarlos con el botón "+ Agregar material".
                        </p>
                    <?php endif; ?>
                </div>

                <button type="button" id="agregar-material" class="btn-secondary" style="width: 100%; margin-bottom: 24px;">
                    + Agregar nuevo material adicional
                </button>
                
                <!-- Descripción -->
                <div class="info-box" style="margin-top: 30px;">
                    <h3 style="font-size: 18px; margin-bottom: 8px;">📝 Descripción *</h3>
                </div>
                
                <div class="form-group full-width">
                    <textarea name="descripcion" class="form-control form-textarea" required><?php echo htmlspecialchars($contenido['descripcion']); ?></textarea>
                </div>
                
                <!-- Botones de acción -->
                <div class="action-buttons">
                    <a href="gestion_contenidos.php" class="btn-secondary">Cancelar</a>
                    <button type="submit" class="btn-primary">💾 Guardar cambios</button>
                </div>
            </form>
        </div>
    </main>

    <footer class="footer">
        <span>v2.0.0</span>
        <span>Soporte Técnico</span>
    </footer>

    <script>
        let materialCounter = <?php echo count($lista_materiales); ?>;

        // Script mejorado para agregar materiales (con todas las funcionalidades)
        document.getElementById('agregar-material').addEventListener('click', function() {
            const container = document.getElementById('materiales-container');
            const index = materialCounter++;
            
            const materialDiv = document.createElement('div');
            materialDiv.className = 'material-item';
            materialDiv.dataset.index = index;
            materialDiv.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <span class="material-counter" style="font-weight: 600; color: var(--primary-purple);">
                        Material nuevo #${index + 1}
                    </span>
                    <button type="button" class="eliminar-material-btn" style="background: #dc3545; color: white; border: none; padding: 4px 12px; border-radius: 4px; cursor: pointer;">
                        Eliminar material
                    </button>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label class="form-label">Tipo</label>
                        <select name="materiales[${index}][tipo]" class="form-control material-tipo-nuevo" data-index="${index}" required>
                            <option value="video">VIDEO (YouTube)</option>
                            <option value="documento">DOCUMENTO (PDF, Word)</option>
                            <option value="enlace">ENLACE (web)</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Título</label>
                        <input type="text" name="materiales[${index}][titulo]" class="form-control" required>
                    </div>
                </div>
                
                <!-- Contenedor de URL (para video/enlace) -->
                <div class="material-url-${index}" style="margin-bottom: 12px;">
                    <label class="form-label">URL</label>
                    <input type="url" name="materiales[${index}][url]" class="form-control" placeholder="https://...">
                </div>
                
                <!-- Contenedor de archivo (para documento) -->
                <div class="material-archivo-${index}" style="margin-bottom: 12px; display: none;">
                    <label class="form-label">Archivo</label>
                    <input type="file" name="materiales[${index}][archivo]" class="form-control" accept=".pdf,.doc,.docx">
                </div>
            `;
            
            container.appendChild(materialDiv);
            
            // Configurar el cambio de tipo para el nuevo material
            const selectTipo = materialDiv.querySelector(`.material-tipo-nuevo`);
            const urlDiv = materialDiv.querySelector(`.material-url-${index}`);
            const archivoDiv = materialDiv.querySelector(`.material-archivo-${index}`);
            const urlInput = urlDiv.querySelector('input');
            const archivoInput = archivoDiv.querySelector('input');
            
            selectTipo.addEventListener('change', function() {
                if (this.value === 'video' || this.value === 'enlace') {
                    urlDiv.style.display = 'block';
                    archivoDiv.style.display = 'none';
                    urlInput.required = true;
                    archivoInput.required = false;
                } else {
                    urlDiv.style.display = 'none';
                    archivoDiv.style.display = 'block';
                    urlInput.required = false;
                    urlInput.value = '';
                    archivoInput.required = true;
                }
            });
            
            // Botón para eliminar el material completamente
            materialDiv.querySelector('.eliminar-material-btn').addEventListener('click', function() {
                if (confirm('¿Eliminar este material completamente?')) {
                    materialDiv.remove();
                }
            });
        });

        document.querySelectorAll('.eliminar-material').forEach(btn => {
            btn.addEventListener('click', function() {
                if (confirm('¿Eliminar este material?')) {
                    this.closest('.material-item').remove();
                }
            });
        });

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
        // Funciones para mostrar/ocultar campos al eliminar recursos principales
        function toggleDocumentoEliminar(checkbox) {
            const documentoActual = document.getElementById('documento-actual');
            const documentoNuevo = document.getElementById('documento-nuevo');
            const mensaje = document.getElementById('documento-mensaje');
            
            if (checkbox.checked) {
                // Si marca eliminar, ocultamos el actual y mostramos campo para nuevo
                if (documentoActual) documentoActual.style.display = 'none';
                if (mensaje) mensaje.style.display = 'none';
                documentoNuevo.style.display = 'block';
                documentoNuevo.querySelector('input').required = false; // No obligatorio
            } else {
                // Si desmarca eliminar, mostramos el actual y ocultamos campo nuevo
                if (documentoActual) documentoActual.style.display = 'flex';
                if (mensaje) mensaje.style.display = 'block';
                documentoNuevo.style.display = 'none';
            }
        }

        function toggleVideoEliminar(checkbox) {
            const videoActual = document.getElementById('video-actual');
            const videoNuevo = document.getElementById('video-nuevo');
            const mensaje = document.getElementById('video-mensaje');
            
            if (checkbox.checked) {
                // Si marca eliminar, ocultamos el actual y mostramos campo para nuevo
                if (videoActual) videoActual.style.display = 'none';
                if (mensaje) mensaje.style.display = 'none';
                videoNuevo.style.display = 'block';
                videoNuevo.querySelector('input').required = false; // No obligatorio
            } else {
                // Si desmarca eliminar, mostramos el actual y ocultamos campo nuevo
                if (videoActual) videoActual.style.display = 'flex';
                if (mensaje) mensaje.style.display = 'block';
                videoNuevo.style.display = 'none';
            }
        }
        // Función para cambiar el tipo de material (URL o Archivo)
        function cambiarTipoMaterial(index, tipo) {
            const urlContainer = document.getElementById(`material-${index}-url`);
            const archivoContainer = document.getElementById(`material-${index}-archivo-actual`);
            const urlField = urlContainer?.querySelector('input[type="url"]');
            
            if (tipo === 'video' || tipo === 'enlace') {
                if (urlContainer) urlContainer.style.display = 'block';
                if (archivoContainer) archivoContainer.style.display = 'none';
                if (urlField) urlField.required = true;
            } else {
                if (urlContainer) urlContainer.style.display = 'none';
                if (archivoContainer) archivoContainer.style.display = 'block';
                if (urlField) {
                    urlField.required = false;
                    urlField.value = '';
                }
            }
        }

        // Función para mostrar/ocultar cuando se marca eliminar material
        function toggleMaterialEliminar(checkbox, index) {
            const contenidoDiv = document.getElementById(`material-${index}-contenido`);
            const msgDiv = document.getElementById(`material-${index}-eliminar-msg`);
            const checkboxesArchivo = document.querySelectorAll(`input[name="eliminar_archivo_material[]"][value="${checkbox.value}"]`);
            
            if (checkbox.checked) {
                if (contenidoDiv) contenidoDiv.style.opacity = '0.5';
                if (msgDiv) msgDiv.style.display = 'block';
                // Deshabilitar el checkbox de eliminar archivo si existe
                checkboxesArchivo.forEach(cb => {
                    cb.disabled = true;
                    cb.checked = false;
                });
            } else {
                if (contenidoDiv) contenidoDiv.style.opacity = '1';
                if (msgDiv) msgDiv.style.display = 'none';
                checkboxesArchivo.forEach(cb => {
                    cb.disabled = false;
                });
            }
        }

        // Función para marcar que se eliminará el archivo pero no el material
        function toggleArchivoMaterial(checkbox, index) {
            // Solo cambia visualmente, no necesita ocultar nada porque el archivo se reemplazará
            const msgSpan = document.createElement('span');
            msgSpan.style.fontSize = '12px';
            msgSpan.style.color = '#dc3545';
            msgSpan.style.marginLeft = '10px';
            msgSpan.textContent = '(archivo será eliminado)';
            
            const parent = checkbox.parentNode;
            if (checkbox.checked) {
                if (!parent.querySelector('.archivo-eliminar-msg')) {
                    const msg = document.createElement('span');
                    msg.className = 'archivo-eliminar-msg';
                    msg.style.fontSize = '12px';
                    msg.style.color = '#dc3545';
                    msg.style.marginLeft = '10px';
                    msg.textContent = '(archivo será eliminado)';
                    parent.appendChild(msg);
                }
            } else {
                const msg = parent.querySelector('.archivo-eliminar-msg');
                if (msg) msg.remove();
            }
        }
    </script>
</body>
</html>
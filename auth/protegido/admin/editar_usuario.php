<?php
session_start();
ob_start(); 
require_once '../../funciones.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Administrador') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$mensaje = '';
$error = '';
$usuario = null;
$estudiantes = [];
$estudiantes_asignados = [];

try {
    $pdo = getConexion();

    // Obtener lista de estudiantes (para representantes)
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombre, e.grado, e.seccion
        FROM usuarios u
        INNER JOIN estudiantes e ON u.id = e.usuario_id
        WHERE u.rol = 'Estudiante' AND u.activo = true
        ORDER BY u.nombre
    ");
    $stmt->execute();
    $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Obtener usuario a editar
    // Obtener ID: primero de POST (al guardar), luego de GET (al cargar)
    $id = $_POST['usuario_id'] ?? $_GET['id'] ?? null;
    if (!$id || !is_numeric($id)) {
        throw new Exception('ID de usuario inválido.');
    }

    $stmt = $pdo->prepare("
        SELECT id, nombre, correo, contrasena, contrasena_temporal, rol, activo, telefono
        FROM usuarios 
        WHERE id = :id
    ");
    $stmt->execute([':id' => $id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        throw new Exception('Usuario no encontrado.');
    }

    // Si contrasena_temporal está vacía, generarla desde el nombre (solo para mostrar/editar)
    if (empty($usuario['contrasena_temporal'])) {
        $letra = strtoupper(substr($usuario['nombre'], 0, 1));
        $anio = date('Y');
        $usuario['contrasena_temporal'] = $letra . $anio . 'siudecres+';

        $upd = $pdo->prepare("
            UPDATE usuarios 
            SET contrasena_temporal = :temp 
            WHERE id = :id AND (contrasena_temporal IS NULL OR contrasena_temporal = '')
        ");
        $upd->execute([
            ':temp' => $usuario['contrasena_temporal'],
            ':id' => $id
        ]);
    }


    // Obtener datos adicionales según rol
    if ($usuario['rol'] === 'Estudiante') {
        $stmt = $pdo->prepare("
            SELECT grado, seccion 
            FROM estudiantes 
            WHERE usuario_id = :id
        ");
        $stmt->execute([':id' => $id]);
        $estudiante_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $usuario['grado'] = $estudiante_data['grado'] ?? '';
        $usuario['seccion'] = $estudiante_data['seccion'] ?? '';
    } elseif ($usuario['rol'] === 'Representante') {
        $stmt = $pdo->prepare("
            SELECT estudiante_id 
            FROM representantes_estudiantes 
            WHERE representante_id = :id
        ");
        $stmt->execute([':id' => $id]);
        $estudiantes_asignados = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Procesar actualización
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nombre = trim($_POST['nombre'] ?? '');
        $correo = trim($_POST['correo'] ?? '');
        $rol = $_POST['rol'] ?? $usuario['rol'];
        $activo = !empty($_POST['activo']);
        $telefono = trim($_POST['telefono'] ?? '');
        $grado = trim($_POST['grado'] ?? '');
        $seccion = trim($_POST['seccion'] ?? '');
        $contrasena_plana = trim($_POST['contrasena'] ?? '');

        // Validaciones
        if (empty($nombre)) throw new Exception('El nombre es obligatorio.');
        if (empty($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) throw new Exception('El correo es inválido.');
        if (!in_array($rol, ['Administrador', 'Docente', 'Estudiante', 'Representante'])) throw new Exception('Rol no válido.');

        if ($rol === 'Estudiante') {
            if (empty($grado)) throw new Exception('El grado es obligatorio.');
            if (empty($seccion)) throw new Exception('La sección es obligatoria.');
        }

        if (empty($contrasena_plana)) {
            $contrasena_plana = $usuario['contrasena_temporal'] ?? '';
            
            if ($contrasena_plana === '') {
                $letra = strtoupper(substr($usuario['nombre'], 0, 1));
                $anio = date('Y');
                $contrasena_plana = $letra . $anio . 'siudecres+';
            }
        }

        // Generar el hash desde la contraseña plana (nueva o actual)
        $contrasena_hash = password_hash($contrasena_plana, PASSWORD_BCRYPT);
        $update_pass = true;

        // Actualizar usuario
        $stmt = $pdo->prepare("
            UPDATE usuarios 
            SET nombre = :nombre, correo = :correo, rol = :rol, 
                activo = :activo, telefono = :telefono,
                contrasena = :contrasena,
                contrasena_temporal = :contrasena_temporal
            WHERE id = :id
        ");
        $params = [
            ':nombre' => $nombre,
            ':correo' => $correo,
            ':rol' => $rol,
            ':activo' => $activo,
            ':telefono' => $telefono,
            ':contrasena' => $contrasena_hash,
            ':contrasena_temporal' => $contrasena_plana,  // 
            ':id' => $id
        ];
        $stmt->execute($params);

        // Actualizar datos adicionales
        if ($rol === 'Estudiante') {
            $stmt = $pdo->prepare("
                INSERT INTO estudiantes (usuario_id, grado, seccion)
                VALUES (:usuario_id, :grado, :seccion)
                ON CONFLICT (usuario_id) 
                DO UPDATE SET grado = :grado, seccion = :seccion
            ");
            $stmt->execute([
                ':usuario_id' => $id,
                ':grado' => $grado,
                ':seccion' => $seccion
            ]);
        } elseif ($rol === 'Representante') {
            // Eliminar relaciones actuales
            $stmt = $pdo->prepare("DELETE FROM representantes_estudiantes WHERE representante_id = :id");
            $stmt->execute([':id' => $id]);
            
            $estudiantes_seleccion_raw = $_POST['estudiantes_seleccion'] ?? '';
            if (is_array($estudiantes_seleccion_raw)) {
                $estudiantes_seleccion = $estudiantes_seleccion_raw;
            } else {
                // Es una cadena como "12,15,20" → convertirla a array
                $estudiantes_seleccion = $estudiantes_seleccion_raw ? explode(',', $estudiantes_seleccion_raw) : [];
            }
                        


            // Insertar nuevas
            foreach ($estudiantes_seleccion as $est_id) {
                if (is_numeric($est_id)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO representantes_estudiantes (representante_id, estudiante_id)
                        VALUES (:representante_id, :estudiante_id)
                    ");
                    $stmt->execute([
                        ':representante_id' => $id,
                        ':estudiante_id' => (int)$est_id
                    ]);
                }
            }
        }

        $mensaje = "Usuario actualizado exitosamente.";
        // Recargar datos
        $stmt = $pdo->prepare("SELECT id, nombre, correo, rol, activo, telefono FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    }

} catch (Exception $e) {
    $error = $e->getMessage();
}

ob_end_flush();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Usuario - SIEDUCRES</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-cyan: #4BC4E7;
            --primary-pink: #EF5E8E;
            --primary-lime: #C2D54E;
            --primary-purple: #9B8AFB;
            --white: #FFFFFF;
            --canvas-bg: #F5F5F5;
            --text-main: #000000;
            --text-muted: #666666;
            --border-dark: #000000;
            --border-light: #CCCCCC;
        }
        /* Botón de retroceso */
        .back-button {
            position: absolute;
            top: 168px; 
            left: 24px;
            z-index: 10;
        }

        .btn-back {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #666666;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            background: white;
            padding: 6px 12px;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            transition: all 0.2s;
        }

        .btn-back:hover {
            color: #4BC4E7;
            background: #f0f9fc;
            transform: translateX(-4px);
        }

        .btn-back svg {
            width: 16px;
            height: 16px;
        }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--canvas-bg);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Encabezado */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 24px;
            height: 60px;
            background-color: var(--white);
            border-bottom: 1px solid var(--border-light);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            position: relative;
            z-index: 100;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .logo {
            height: 40px;
        }

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

        .icon-btn:hover {
            background-color: #E0E0E0;
        }

        .icon-btn svg {
            width: 20px;
            height: 20px;
        }

        .menu-dropdown {
            position: absolute;
            top: 60px;
            right: 24px;
            background: white;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            display: none;
            min-width: 180px;
            z-index: 101;
        }

        .menu-item {
            padding: 10px 16px;
            font-size: 14px;
            color: var(--text-main);
            text-decoration: none;
            display: block;
            transition: background 0.2s;
        }

        .menu-item:hover {
            background-color: #F8F8F8;
        }

        /* Banner */
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
            color: var(--text-main);
            margin-bottom: 8px;
        }

        /* Contenido principal */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 40px 20px;
        }

        .page-header {
            width: 100%;
            max-width: 800px;
            margin-bottom: 32px;
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 16px;
        }

        /* Formulario */
        .form-container {
            width: 100%;
            max-width: 800px;
            background: var(--white);
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 16px;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            margin-bottom: 16px;
        }

        label {
            display: block;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 8px;
            color: var(--text-main);
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
        }

        .form-select {
            appearance: none;
            background-image: url("image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23333333' viewBox='0 0 16 16'%3E%3Cpath d='M8 11l-5-5h10l-5 5z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
        }

        .conditional-fields {
            background-color: #f8f9fa;
            border-radius: 16px;
            padding: 20px;
            margin-top: 20px;
            border-left: 4px solid var(--primary-cyan);
        }

        .conditional-title {
            font-weight: 700;
            color: var(--primary-cyan);
            margin-bottom: 12px;
            font-size: 16px;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-success {
            background-color: #e8f7fc;
            border: 1px solid var(--primary-cyan);
            color: var(--text-main);
        }

        .alert-error {
            background-color: #fde8ec;
            border: 1px solid var(--primary-pink);
            color: var(--text-main);
        }

        .btn-primary {
            background-color: var(--primary-cyan);
            color: var(--text-main);
            border: none;
            border-radius: 4px;
            padding: 12px 24px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.2s;
            width: 100%;
        }

        .btn-primary:hover {
            background-color: #3ab3d6;
        }

        /* Pie de página */
        .footer {
            height: 50px;
            background-color: var(--white);
            border-top: 1px solid var(--border-light);
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
        /* Estilo para selector con chips */
        .select-chip-wrapper {
            position: relative;
        }

        .select-chip-wrapper .form-control {
            padding-right: 40px;
        }

        .dropdown-list {
            display: none;
            position: absolute;
            width: 100%;
            background: white;
            border: 1px solid var(--border-light);
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .dropdown-item {
            padding: 8px 16px;
            cursor: pointer;
            transition: background 0.2s;
        }

        .dropdown-item:hover {
            background-color: #f0f0f0;
        }

        .chips-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
            min-height: 40px;
            padding: 8px 0;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            background: #f9f9f9;
        }

        .chip {
            display: flex;
            align-items: center;
            background: var(--primary-cyan);
            color: white;
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 14px;
            gap: 8px;
            cursor: pointer;
        }

        .chip .remove {
            font-size: 12px;
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .chip .remove:hover {
            opacity: 1;
        }
        
        /* Íconos del encabezado */
        .header-right .icon-btn img {
            width: 20px;
            height: 20px;
            object-fit: contain;
        }
    </style>
</head>
<body>

        <!-- Encabezado -->
        <header class="header">
        <div class="header-left">
            <img src="../../../assets/logo.svg" alt="SIEDUCRES" class="logo">
        </div>
        <div class="header-right">
            <!-- Ícono de notificaciones -->
            <div class="icon-btn">
                <img src="../../../assets/icon-bell.svg" alt="Notificaciones">
            </div>
            <!-- Ícono de perfil -->
            <div class="icon-btn">
                <img src="../../../assets/icon-user.svg" alt="Perfil">
            </div>
            <!-- Menú hamburguesa (queda igual, es un SVG) -->
            <div class="icon-btn" id="menu-toggle">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="#333333">
                    <path d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/>
                </svg>
            </div>

            
        </div>
    </header>
    <!-- Menú desplegable de perfil -->
    <div class="menu-dropdown" id="dropdown">
        <a href="../../logout.php" class="menu-item">Cerrar sesión</a>
    </div>
             
    <!-- Banner -->
    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image">
        <div class="banner-content">
            <h1 class="banner-title">¡Bienvenidos a SIEDUCRES!</h1>
        </div>
    </div>

    <!-- Botón de retroceso -->
    <div class="back-button">
        <a href="gestion_usuarios.php" class="btn-back">
            <svg viewBox="0 0 24 24" fill="currentColor">
                <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
            </svg>
            Volver a Gestión
        </a>
    </div>

    <!-- Contenido principal -->
    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">Editar usuario: <?= htmlspecialchars($usuario['nombre'] ?? '') ?></h1>
        </div>

        <div class="form-container">
            <?php if ($mensaje): ?>
                <div class="alert alert-success"><?= $mensaje ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($usuario): ?>
            <form method="POST" id="editarForm">
                <input type="hidden" name="usuario_id" value="<?= htmlspecialchars($usuario['id']) ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label for="nombre">Nombre completo *</label>
                        <input type="text" id="nombre" name="nombre" class="form-control" 
                               value="<?= htmlspecialchars($usuario['nombre']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="correo">Correo electrónico *</label>
                        <input type="email" id="correo" name="correo" class="form-control" 
                               value="<?= htmlspecialchars($usuario['correo']) ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="rol">Rol *</label>
                        <select id="rol" name="rol" class="form-control form-select" 
                                required onchange="mostrarCamposCondicionales()">
                            <option value="Administrador" <?= $usuario['rol'] === 'Administrador' ? 'selected' : '' ?>>Administrador</option>
                            <option value="Docente" <?= $usuario['rol'] === 'Docente' ? 'selected' : '' ?>>Docente</option>
                            <option value="Estudiante" <?= $usuario['rol'] === 'Estudiante' ? 'selected' : '' ?>>Estudiante</option>
                            <option value="Representante" <?= $usuario['rol'] === 'Representante' ? 'selected' : '' ?>>Representante</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="activo" value="1" <?= $usuario['activo'] ? 'checked' : '' ?>>
                            Cuenta activa
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="telefono">Teléfono</label>
                    <input type="tel" id="telefono" name="telefono" class="form-control" 
                           value="<?= htmlspecialchars($usuario['telefono']) ?>" placeholder="+58...">
                </div>

                <div class="form-group">
                    <label for="contrasena">Contraseña (editable)</label>
                    <div style="position: relative;">
                        <input type="text" id="contrasena" name="contrasena" class="form-control"
                            value="<?= htmlspecialchars($usuario['contrasena_temporal'] ?? '') ?>"
                            placeholder="Dejar vacío para mantener la actual">
                
                    </div>
                    <div id="pass-strength" style="margin-top: 6px; font-size: 12px; height: 14px;"></div>
                </div>
                <div style="margin-top: 16px; padding: 14px; background-color: #e6f7ff; border-left: 4px solid var(--primary-cyan); border-radius: 6px; box-shadow: 0 2px 4px rgba(75, 196, 231, 0.1);">
                    <strong style="color: #006699; font-size: 16px; display: block; margin-bottom: 6px;">
                        🔑 Contraseña actual:
                    </strong>
                    <code style="
                        font-size: 18px;
                        font-weight: 600;
                        color: #004d7a;
                        background: white;
                        padding: 8px 12px;
                        border-radius: 6px;
                        border: 1px solid #cce6f5;
                        letter-spacing: 1px;
                        display: inline-block;
                        margin-top: 4px;
                        font-family: 'Courier New', monospace;
                    ">
                        <?= htmlspecialchars($usuario['contrasena_temporal'] ?? '—') ?>
                    </code>
                    <br>
                    <small style="font-size: 13px; color: #555; font-style: italic; display: block; margin-top: 8px;">
                         <strong>Edítala directamente en el campo de arriba.</strong>  
                
                    </small>
                </div>
                <!-- Campos condicionales -->
                <?php if ($usuario['rol'] === 'Estudiante'): ?>
                <div id="camposEstudiante" class="conditional-fields">
                    <div class="conditional-title">Datos del estudiante</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="grado">Grado *</label>
                            <select id="grado" name="grado" class="form-control form-select" required>
                                <option value="">Seleccionar</option>
                                <option value="1ro" <?= ($usuario['grado'] ?? '') === '1ro' ? 'selected' : '' ?>>1ro</option>
                                <option value="2do" <?= ($usuario['grado'] ?? '') === '2do' ? 'selected' : '' ?>>2do</option>
                                <option value="3ero" <?= ($usuario['grado'] ?? '') === '3ero' ? 'selected' : '' ?>>3ero</option>
                                <option value="4to" <?= ($usuario['grado'] ?? '') === '4to' ? 'selected' : '' ?>>4to</option>
                                <option value="5to" <?= ($usuario['grado'] ?? '') === '5to' ? 'selected' : '' ?>>5to</option>
                                <option value="6to" <?= ($usuario['grado'] ?? '') === '6to' ? 'selected' : '' ?>>6to</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="seccion">Sección *</label>
                            <select id="seccion" name="seccion" class="form-control form-select" required>
                                <option value="">Seleccionar</option>
                                <option value="A" <?= ($usuario['seccion'] ?? '') === 'A' ? 'selected' : '' ?>>A</option>
                                <option value="B" <?= ($usuario['seccion'] ?? '') === 'B' ? 'selected' : '' ?>>B</option>
                                <option value="C" <?= ($usuario['seccion'] ?? '') === 'C' ? 'selected' : '' ?>>C</option>
                                <option value="D" <?= ($usuario['seccion'] ?? '') === 'D' ? 'selected' : '' ?>>D</option>
                                <option value="U" <?= ($usuario['seccion'] ?? '') === 'U' ? 'selected' : '' ?>>Única</option>
                            </select>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($usuario['rol'] === 'Representante'): ?>
                <div id="camposRepresentante" class="conditional-fields">
                    <div class="conditional-title"> Estudiantes a cargo</div>
                    <div class="form-group">
                        <label for="estudiantes_seleccion">Seleccionar estudiantes</label>
                        <div class="select-chip-wrapper">
                            <input type="text" id="searchEstudiantes" class="form-control" placeholder="Buscar estudiante..." autocomplete="off">
                            <div id="estudiantesList" class="dropdown-list">
                                <?php foreach ($estudiantes as $est): ?>
                                    <div class="dropdown-item" data-id="<?= $est['id'] ?>">
                                        <?= htmlspecialchars($est['nombre']) ?> 
                                        (<?= htmlspecialchars($est['grado']) ?>-<?= htmlspecialchars($est['seccion']) ?>)
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div id="selectedChips" class="chips-container">
                                <?php foreach ($estudiantes_asignados as $est_id):
                                    $est = array_filter($estudiantes, fn($e) => $e['id'] == $est_id);
                                    $est = reset($est);
                                    if ($est):
                                ?>
                                    <div class="chip" data-id="<?= $est_id ?>">
                                        <?= htmlspecialchars($est['nombre']) ?> (<?= htmlspecialchars($est['grado']) ?>-<?= htmlspecialchars($est['seccion']) ?>)
                                        <span class="remove">×</span>
                                    </div>
                                <?php endif; endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn-primary">Guardar cambios</button>
            </form>
            <?php else: ?>
                <p class="alert alert-error">No se pudo cargar el usuario.</p>
            <?php endif; ?>
        </div>
    </main>

    <footer class="footer">
        <span>v2.0.0</span>
        <span>Soporte Técnico</span>
    </footer>

    <script>
        function togglePassword() {
            const input = document.getElementById('contrasena');
            input.type = input.type === 'password' ? 'text' : 'password';
        }

        function mostrarCamposCondicionales() {
            const rol = document.getElementById('rol').value;
            document.getElementById('camposEstudiante').style.display = rol === 'Estudiante' ? 'block' : 'none';
            document.getElementById('camposRepresentante').style.display = rol === 'Representante' ? 'block' : 'none';
        }

        // Chips de estudiantes (igual que en registrar)
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchEstudiantes');
            const dropdownList = document.getElementById('estudiantesList');
            const selectedChips = document.getElementById('selectedChips');
            const form = document.getElementById('editarForm');

            searchInput.addEventListener('focus', () => dropdownList.style.display = 'block');
            
            searchInput.addEventListener('blur', () => {
                setTimeout(() => {
                    if (!dropdownList.matches(':hover') && !searchInput.matches(':focus')) {
                        dropdownList.style.display = 'none';
                    }
                }, 200);
            });

            searchInput.addEventListener('input', function() {
                const filter = this.value.toLowerCase();
                dropdownList.querySelectorAll('.dropdown-item').forEach(item => {
                    item.style.display = item.textContent.toLowerCase().includes(filter) ? 'block' : 'none';
                });
            });

            dropdownList.addEventListener('click', function(e) {
                if (e.target.classList.contains('dropdown-item')) {
                    const id = e.target.getAttribute('data-id');
                    const name = e.target.textContent;
                    if (selectedChips.querySelector(`[data-id="${id}"]`)) return;

                    const chip = document.createElement('div');
                    chip.className = 'chip';
                    chip.dataset.id = id;
                    chip.innerHTML = `${name} <span class="remove">×</span>`;
                    chip.querySelector('.remove').addEventListener('click', e => {
                        e.stopPropagation(); chip.remove(); updateHiddenField();
                    });
                    selectedChips.appendChild(chip);
                    updateHiddenField();
                    searchInput.value = '';
                    dropdownList.style.display = 'none';
                }
            });

            function updateHiddenField() {
                const ids = Array.from(selectedChips.children).map(chip => chip.dataset.id);
                let hidden = document.querySelector('input[name="estudiantes_seleccion"]');
                if (!hidden) {
                    hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'estudiantes_seleccion';
                    form.appendChild(hidden);
                }
                hidden.value = ids.join(',');
            }

            // Inicializar
            updateHiddenField();
        });

        // Permitir edición manual (no bloquear el campo)
        document.getElementById('contrasena').readOnly = false;

            // Validación de seguridad para la contraseña
        document.getElementById('editarForm').addEventListener('submit', function(e) {
            const passField = document.getElementById('contrasena');
            const pass = passField.value;
            const originalPass = <?= json_encode($usuario['contrasena_temporal'] ?? '') ?>;
            
            // Si la contraseña no se modificó, permitir el envío
            if (pass === originalPass) {
                return; 
            }
            
            // Si se modificó, validar seguridad
            if (pass && (
                pass.length < 8 ||
                !/[A-Z]/.test(pass) ||
                !/[a-z]/.test(pass) ||
                !/\d/.test(pass) ||
                !/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(pass)
            )) {
                e.preventDefault();
                alert('⚠️ La contraseña no cumple con los requisitos mínimos de seguridad.\n\nRequisitos:\n• ≥8 caracteres\n• 1 mayúscula\n• 1 minúscula\n• 1 número\n• 1 símbolo (!@#$%^&* etc.)');
                passField.focus();
            }
        });

        document.getElementById('editarForm').addEventListener('submit', function(e) {
            const pass = document.getElementById('contrasena').value;
            if (pass && (
                pass.length < 8 ||
                !/[A-Z]/.test(pass) ||
                !/[a-z]/.test(pass) ||
                !/\d/.test(pass) ||
                !/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(pass)
            )) {
                e.preventDefault();
                alert('⚠️ La contraseña no cumple con los requisitos mínimos de seguridad.\n\nRequisitos:\n• ≥8 caracteres\n• 1 mayúscula\n• 1 minúscula\n• 1 número\n• 1 símbolo (!@#$%^&* etc.)');
                document.getElementById('contrasena').focus();
            }
        });

        // Toggle menú hamburguesa
        document.getElementById('menu-toggle').addEventListener('click', function() {
            const dropdown = document.getElementById('dropdown');
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        });

        // Cerrar menú al hacer clic fuera
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
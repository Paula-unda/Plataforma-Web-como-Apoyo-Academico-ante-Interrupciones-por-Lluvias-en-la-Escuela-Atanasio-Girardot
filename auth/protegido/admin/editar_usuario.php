<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/onesignal_config.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Administrador') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$conexion = getConexion();
$mensaje = '';
$error = '';

$usuario_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($usuario_id <= 0) {
    header('Location: gestion_usuarios.php?error=ID+de+usuario+inválido');
    exit();
}

// Obtener datos del usuario
$stmt = $conexion->prepare("
    SELECT u.*, 
           e.grado as estudiante_grado, 
           e.seccion as estudiante_seccion,
           d.grado as docente_grado,
           d.seccion as docente_seccion
    FROM usuarios u
    LEFT JOIN estudiantes e ON u.id = e.usuario_id
    LEFT JOIN docentes d ON u.id = d.usuario_id
    WHERE u.id = ?
");
$stmt->execute([$usuario_id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    header('Location: gestion_usuarios.php?error=Usuario+no+encontrado');
    exit();
}

// 🔴 Obtener lista de todos los estudiantes
$estudiantes = [];
try {
    $stmt_est = $conexion->prepare("
        SELECT u.id, u.nombre, e.grado, e.seccion
        FROM usuarios u
        INNER JOIN estudiantes e ON u.id = e.usuario_id
        WHERE u.rol = 'Estudiante' AND u.activo = true
        ORDER BY u.nombre
    ");
    $stmt_est->execute();
    $estudiantes = $stmt_est->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error al cargar estudiantes: " . $e->getMessage());
}

// 🔴 Obtener estudiantes asignados si es representante
$estudiantes_asignados = [];
if ($usuario['rol'] === 'Representante') {
    $stmt_rep = $conexion->prepare("
        SELECT u.id, u.nombre, e.grado, e.seccion
        FROM representantes_estudiantes re
        INNER JOIN usuarios u ON re.estudiante_id = u.id
        INNER JOIN estudiantes e ON u.id = e.usuario_id
        WHERE re.representante_id = ?
        ORDER BY u.nombre
    ");
    $stmt_rep->execute([$usuario_id]);
    $estudiantes_asignados = $stmt_rep->fetchAll(PDO::FETCH_ASSOC);
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $rol = $_POST['rol'] ?? $usuario['rol'];
    $activo = isset($_POST['activo']) ? true : false;
    $contrasena = $_POST['contrasena'] ?? '';
    
    // Datos específicos según rol
    $grado = $_POST['grado'] ?? null;
    $seccion = $_POST['seccion'] ?? null;
    $grado_docente = $_POST['grado_docente'] ?? null;
    $seccion_docente = $_POST['seccion_docente'] ?? null;
    
    // 🔴 Estudiantes seleccionados para representante
    $estudiantes_seleccion = [];
    if ($rol === 'Representante' && isset($_POST['estudiantes_seleccion'])) {
        $estudiantes_seleccion = array_filter(explode(',', $_POST['estudiantes_seleccion']));
    }
    
    // Validaciones
    if (empty($nombre) || empty($correo)) {
        $error = 'El nombre y correo son obligatorios';
    } elseif ($rol === 'Representante' && empty($estudiantes_seleccion)) {
        $error = 'Debe seleccionar al menos un estudiante';
    } else {
        try {
            $conexion->beginTransaction();
            
            // Actualizar usuario principal
            if (!empty($contrasena)) {
                // Si se proporcionó nueva contraseña
                $contrasena_hash = hashearContrasena($contrasena);
                $stmt_update = $conexion->prepare("
                    UPDATE usuarios 
                    SET nombre = ?, correo = ?, rol = ?, activo = ?, contrasena = ?, contrasena_temporal = ?
                    WHERE id = ?
                ");
                // 👇 AHORA HAY 7 PARÁMETROS (6 SET + 1 WHERE)
                $stmt_update->execute([
                    $nombre,        // 1. nombre
                    $correo,        // 2. correo
                    $rol,           // 3. rol
                    $activo ? 'true' : 'false', // 4. activo
                    $contrasena_hash, // 5. contrasena (hash)
                    $contrasena,    // 6. contrasena_temporal (texto plano)
                    $usuario_id     // 7. WHERE id
                ]);
            } else {
                // Mantener contraseña actual
                $stmt_update = $conexion->prepare("
                    UPDATE usuarios 
                    SET nombre = ?, correo = ?, rol = ?, activo = ?
                    WHERE id = ?
                ");
                // 👇 AHORA HAY 5 PARÁMETROS (4 SET + 1 WHERE)
                $stmt_update->execute([
                    $nombre,        // 1. nombre
                    $correo,        // 2. correo
                    $rol,           // 3. rol
                    $activo ? 'true' : 'false', // 4. activo
                    $usuario_id     // 5. WHERE id
                ]);
            }
            // Eliminar datos específicos anteriores
            $conexion->prepare("DELETE FROM estudiantes WHERE usuario_id = ?")->execute([$usuario_id]);
            $conexion->prepare("DELETE FROM docentes WHERE usuario_id = ?")->execute([$usuario_id]);
            $conexion->prepare("DELETE FROM representantes_estudiantes WHERE representante_id = ?")->execute([$usuario_id]);
            
            // Insertar nuevos datos según rol
            if ($rol === 'Estudiante' && !empty($grado)) {
                $stmt_est = $conexion->prepare("
                    INSERT INTO estudiantes (usuario_id, grado, seccion)
                    VALUES (?, ?, ?)
                ");
                $stmt_est->execute([$usuario_id, $grado, $seccion ?: null]);
            }
            
            if ($rol === 'Docente' && !empty($grado_docente)) {
                $stmt_doc = $conexion->prepare("
                    INSERT INTO docentes (usuario_id, grado, seccion)
                    VALUES (?, ?, ?)
                ");
                $stmt_doc->execute([$usuario_id, $grado_docente, $seccion_docente ?: null]);
            }
            
            // 🔴 Insertar relaciones para representante
            if ($rol === 'Representante' && !empty($estudiantes_seleccion)) {
                $stmt_rel = $conexion->prepare("
                    INSERT INTO representantes_estudiantes (representante_id, estudiante_id)
                    VALUES (?, ?)
                ");
                foreach ($estudiantes_seleccion as $est_id) {
                    $stmt_rel->execute([$usuario_id, $est_id]);
                }
            }
            
            $conexion->commit();
            $mensaje = "Usuario actualizado correctamente";
            
            // Recargar datos del usuario
            $stmt = $conexion->prepare("
                SELECT u.*, 
                       e.grado as estudiante_grado, 
                       e.seccion as estudiante_seccion,
                       d.grado as docente_grado,
                       d.seccion as docente_seccion
                FROM usuarios u
                LEFT JOIN estudiantes e ON u.id = e.usuario_id
                LEFT JOIN docentes d ON u.id = d.usuario_id
                WHERE u.id = ?
            ");
            $stmt->execute([$usuario_id]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // 🔴 Recargar estudiantes asignados
            if ($rol === 'Representante') {
                $stmt_rep = $conexion->prepare("
                    SELECT u.id, u.nombre, e.grado, e.seccion
                    FROM representantes_estudiantes re
                    INNER JOIN usuarios u ON re.estudiante_id = u.id
                    INNER JOIN estudiantes e ON u.id = e.usuario_id
                    WHERE re.representante_id = ?
                    ORDER BY u.nombre
                ");
                $stmt_rep->execute([$usuario_id]);
                $estudiantes_asignados = $stmt_rep->fetchAll(PDO::FETCH_ASSOC);
            }
            
        } catch (Exception $e) {
            $conexion->rollBack();
            $error = "Error al actualizar: " . $e->getMessage();
        }
    }
}

// Función segura para mostrar valores
function mostrarValor($valor) {
    return isset($valor) && $valor !== '' ? htmlspecialchars($valor) : '';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Usuario - SIEDUCRES</title>
    <?php require_once '../includes/favicon.php'; ?>
    <?php require_once '../includes/header_onesignal.php'; ?> 
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
        body { font-family: 'Inter', sans-serif; background-color: var(--background); color: var(--text-dark); min-height: 100vh; display: flex; flex-direction: column; }
        .header { display: flex; justify-content: space-between; align-items: center; padding: 0 24px; height: 60px; background-color: var(--surface); border-bottom: 1px solid var(--border); }
        .logo { height: 40px; }
        .header-right { display: flex; align-items: center; gap: 16px; }
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; background-color: #F0F0F0; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .icon-btn img { width: 20px; height: 20px; object-fit: contain; }
        .menu-dropdown { position: absolute; top: 60px; right: 24px; background: white; border: 1px solid var(--border); border-radius: 8px; display: none; min-width: 180px; }
        .menu-item { padding: 10px 16px; font-size: 14px; color: var(--text-dark); text-decoration: none; display: block; }
        .banner { position: relative; height: 100px; overflow: hidden; }
        .banner-image { width: 100%; height: 100%; object-fit: cover; object-position: top; }
        .banner-content { text-align: center; position: relative; z-index: 2; max-width: 800px; padding: 20px; margin: 0 auto; }
        .banner-title { font-size: 36px; font-weight: 700; color: var(--text-dark); }
        .main-content { flex: 1; padding: 40px 20px; max-width: 800px; margin: 0 auto; width: 100%; }
        .form-container { background: var(--surface); border-radius: 16px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-weight: 600; font-size: 14px; margin-bottom: 8px; color: var(--text-dark); }
        .form-control { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 14px; }
        .form-control:focus { outline: none; border-color: var(--primary-cyan); }
        .form-checkbox { display: flex; align-items: center; gap: 8px; }
        .form-checkbox input { width: 18px; height: 18px; }
        .btn-primary { background: var(--primary-cyan); color: white; padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: background 0.2s; width: 100%; font-size: 16px; }
        .btn-primary:hover { background: #3ab3d6; }
        .btn-secondary { background: #f0f0f0; color: var(--text-dark); padding: 10px 20px; border: 1px solid var(--border); border-radius: 8px; text-decoration: none; display: inline-block; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #e8f7fc; border: 1px solid var(--primary-cyan); color: var(--text-dark); }
        .alert-error { background: #fde8ec; border: 1px solid var(--primary-pink); color: var(--text-dark); }
        .info-box { background: #e8f4fd; padding: 16px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid var(--primary-cyan); }
        .footer { height: 50px; background-color: var(--surface); border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; padding: 0 24px; font-size: 13px; color: var(--text-muted); position: sticky; bottom: 0; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .rol-section { background: #f9f9f9; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #ddd; }
        
        /* 🔴 Estilos para chips */
        .select-chip-wrapper { position: relative; }
        .dropdown-list {
            display: none;
            position: absolute;
            width: 100%;
            background: white;
            border: 1px solid var(--border);
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .dropdown-item {
            padding: 12px 16px;
            cursor: pointer;
            transition: background 0.2s;
            border-bottom: 1px solid #eee;
        }
        .dropdown-item:hover { background-color: #f0f0f0; }
        .chips-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
            min-height: 50px;
            padding: 10px;
            border: 2px solid var(--primary-purple);
            border-radius: 8px;
            background: #f9f9f9;
        }
        .chip {
            display: flex;
            align-items: center;
            background: var(--primary-purple);
            color: white;
            border-radius: 30px;
            padding: 6px 14px;
            font-size: 14px;
            gap: 8px;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .chip .remove {
            font-size: 16px;
            cursor: pointer;
            opacity: 0.8;
            transition: opacity 0.2s;
            font-weight: bold;
            margin-left: 4px;
        }
        .chip .remove:hover { opacity: 1; }
        
        @media (max-width: 768px) {
            .chip { padding: 8px 16px; font-size: 16px; }
            .dropdown-item { padding: 15px 16px; }
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header_comun.php'; ?>

    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner" class="banner-image" onerror="this.style.display='none'">
    </div>

    <div class="banner-content">
        <h1 class="banner-title">Editar usuario: <?php echo htmlspecialchars($usuario['nombre']); ?></h1>
    </div>

    <main class="main-content">
        <?php if ($mensaje): ?>
            <div class="alert alert-success">✅ <?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="form-container">
            <form method="POST" id="registroForm">
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Nombre completo *</label>
                        <input type="text" name="nombre" class="form-control" 
                               value="<?php echo mostrarValor($usuario['nombre']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Correo electrónico *</label>
                        <input type="email" name="correo" class="form-control" 
                               value="<?php echo mostrarValor($usuario['correo']); ?>" required>
                    </div>
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Rol *</label>
                        <select name="rol" class="form-control" id="rolSelect" required>
                            <option value="Estudiante" <?php echo $usuario['rol'] === 'Estudiante' ? 'selected' : ''; ?>>Estudiante</option>
                            <option value="Docente" <?php echo $usuario['rol'] === 'Docente' ? 'selected' : ''; ?>>Docente</option>
                            <option value="Representante" <?php echo $usuario['rol'] === 'Representante' ? 'selected' : ''; ?>>Representante</option>
                            <option value="Administrador" <?php echo $usuario['rol'] === 'Administrador' ? 'selected' : ''; ?>>Administrador</option>
                        </select>
                    </div>
                    
                    <div class="form-group form-checkbox">
                        <input type="checkbox" name="activo" id="activo" <?php echo $usuario['activo'] ? 'checked' : ''; ?>>
                        <label for="activo">Cuenta activa</label>
                    </div>
                </div>
                
                <!-- Contraseña -->
                <div class="info-box" style="margin-top: 30px;">
                    <h3 style="margin-bottom: 8px;">🔐 Contraseña</h3>
                    <p>Deja en blanco para mantener la contraseña actual</p>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nueva contraseña</label>
                    <input type="text" name="contrasena" class="form-control" placeholder="Nueva contraseña (opcional)">
                </div>
                
                <?php if (!empty($usuario['contrasena_temporal'])): ?>
                <div class="info-box" style="background: #fff3cd; border-left-color: #ffc107;">
                    <p><strong>🔑 Contraseña temporal actual:</strong> <?php echo htmlspecialchars($usuario['contrasena_temporal']); ?></p>
                </div>
                <?php endif; ?>
                
                <!-- Datos específicos según rol -->
                <div id="estudianteSection" class="rol-section" style="display: <?php echo $usuario['rol'] === 'Estudiante' ? 'block' : 'none'; ?>;">
                    <h3 style="margin-bottom: 15px;">📚 Datos del estudiante</h3>
                    
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">Grado *</label>
                            <select name="grado" class="form-control">
                                <option value="">Seleccionar</option>
                                <option value="1ro" <?php echo ($usuario['estudiante_grado'] ?? '') === '1ro' ? 'selected' : ''; ?>>1ro</option>
                                <option value="2do" <?php echo ($usuario['estudiante_grado'] ?? '') === '2do' ? 'selected' : ''; ?>>2do</option>
                                <option value="3ero" <?php echo ($usuario['estudiante_grado'] ?? '') === '3ero' ? 'selected' : ''; ?>>3ero</option>
                                <option value="4to" <?php echo ($usuario['estudiante_grado'] ?? '') === '4to' ? 'selected' : ''; ?>>4to</option>
                                <option value="5to" <?php echo ($usuario['estudiante_grado'] ?? '') === '5to' ? 'selected' : ''; ?>>5to</option>
                                <option value="6to" <?php echo ($usuario['estudiante_grado'] ?? '') === '6to' ? 'selected' : ''; ?>>6to</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Sección *</label>
                            <select name="seccion" class="form-control">
                                <option value="">Seleccionar</option>
                                <option value="A" <?php echo ($usuario['estudiante_seccion'] ?? '') === 'A' ? 'selected' : ''; ?>>A</option>
                                <option value="B" <?php echo ($usuario['estudiante_seccion'] ?? '') === 'B' ? 'selected' : ''; ?>>B</option>
                                <option value="C" <?php echo ($usuario['estudiante_seccion'] ?? '') === 'C' ? 'selected' : ''; ?>>C</option>
                                <option value="D" <?php echo ($usuario['estudiante_seccion'] ?? '') === 'D' ? 'selected' : ''; ?>>D</option>
                                <option value="U" <?php echo ($usuario['estudiante_seccion'] ?? '') === 'U' ? 'selected' : ''; ?>>U</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div id="docenteSection" class="rol-section" style="display: <?php echo $usuario['rol'] === 'Docente' ? 'block' : 'none'; ?>;">
                    <h3 style="margin-bottom: 15px;">👨‍🏫 Datos del docente</h3>
                    
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">Grado a cargo *</label>
                            <select name="grado_docente" class="form-control">
                                <option value="">Seleccionar</option>
                                <option value="1ro" <?php echo ($usuario['docente_grado'] ?? '') === '1ro' ? 'selected' : ''; ?>>1ro</option>
                                <option value="2do" <?php echo ($usuario['docente_grado'] ?? '') === '2do' ? 'selected' : ''; ?>>2do</option>
                                <option value="3ero" <?php echo ($usuario['docente_grado'] ?? '') === '3ero' ? 'selected' : ''; ?>>3ero</option>
                                <option value="4to" <?php echo ($usuario['docente_grado'] ?? '') === '4to' ? 'selected' : ''; ?>>4to</option>
                                <option value="5to" <?php echo ($usuario['docente_grado'] ?? '') === '5to' ? 'selected' : ''; ?>>5to</option>
                                <option value="6to" <?php echo ($usuario['docente_grado'] ?? '') === '6to' ? 'selected' : ''; ?>>6to</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Sección a cargo *</label>
                            <select name="seccion_docente" class="form-control">
                                <option value="">Seleccionar</option>
                                <option value="A" <?php echo ($usuario['docente_seccion'] ?? '') === 'A' ? 'selected' : ''; ?>>A</option>
                                <option value="B" <?php echo ($usuario['docente_seccion'] ?? '') === 'B' ? 'selected' : ''; ?>>B</option>
                                <option value="C" <?php echo ($usuario['docente_seccion'] ?? '') === 'C' ? 'selected' : ''; ?>>C</option>
                                <option value="D" <?php echo ($usuario['docente_seccion'] ?? '') === 'D' ? 'selected' : ''; ?>>D</option>
                                <option value="U" <?php echo ($usuario['docente_seccion'] ?? '') === 'U' ? 'selected' : ''; ?>>U</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- 🔴 SECCIÓN REPRESENTANTE CON CHIPS (IGUAL QUE EN REGISTRO) -->
                <div id="representanteSection" class="rol-section" style="display: <?php echo $usuario['rol'] === 'Representante' ? 'block' : 'none'; ?>; border-left: 4px solid var(--primary-purple);">
                    <h3 style="margin-bottom: 15px; color: var(--primary-purple);">👥 Estudiantes a cargo</h3>
                    
                    <div class="form-group">
                        <label class="form-label">Seleccionar estudiantes *</label>
                        
                        <!-- Campo de entrada para buscar -->
                        <div class="select-chip-wrapper">
                            <input type="text" id="searchEstudiantes" class="form-control" 
                                   placeholder="Buscar estudiante por nombre..." autocomplete="off"
                                   style="border: 2px solid var(--primary-purple);">

                            <!-- Lista desplegable de estudiantes -->
                            <div id="estudiantesList" class="dropdown-list" 
                                 style="border: 2px solid var(--primary-purple); border-top: none;">
                                <?php if (empty($estudiantes)): ?>
                                    <div class="dropdown-item" style="color: #999;">No hay estudiantes disponibles</div>
                                <?php else: ?>
                                    <?php foreach ($estudiantes as $est): ?>
                                        <div class="dropdown-item" 
                                             data-id="<?= $est['id'] ?>"
                                             data-nombre="<?= htmlspecialchars($est['nombre']) ?>"
                                             data-grado="<?= $est['grado'] ?>"
                                             data-seccion="<?= $est['seccion'] ?>">
                                            <?= htmlspecialchars($est['nombre']) ?> 
                                            (<?= $est['grado'] ?>-<?= $est['seccion'] ?>)
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Contenedor de chips (estudiantes seleccionados) -->
                            <div id="selectedChips" class="chips-container">
                                <?php foreach ($estudiantes_asignados as $est): ?>
                                    <div class="chip" data-id="<?= $est['id'] ?>">
                                        <?= htmlspecialchars($est['nombre']) ?> (<?= $est['grado'] ?>-<?= $est['seccion'] ?>)
                                        <span class="remove" onclick="eliminarChip(this, '<?= $est['id'] ?>')">×</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Campo oculto con los IDs seleccionados -->
                        <input type="hidden" name="estudiantes_seleccion" id="estudiantes_seleccion" 
                               value="<?php echo implode(',', array_column($estudiantes_asignados, 'id')); ?>">
                        
                        <small class="text-muted" style="display: block; margin-top: 10px;">
                            ✅ Haz clic en un estudiante de la lista para agregarlo.<br>
                            ❌ Haz clic en la "x" del chip para quitarlo.
                        </small>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 30px;">
                    <button type="submit" class="btn-primary">Guardar cambios</button>
                    <a href="gestion_usuarios.php" class="btn-secondary">Cancelar</a>
                </div>
            </form>
        </div>
    </main>

    <footer class="footer">
        <span>v2.0.0</span>
        <span>Soporte Técnico</span>
    </footer>

    <script>
        // Mostrar/ocultar secciones según el rol
        document.getElementById('rolSelect').addEventListener('change', function() {
            const rol = this.value;
            document.getElementById('estudianteSection').style.display = rol === 'Estudiante' ? 'block' : 'none';
            document.getElementById('docenteSection').style.display = rol === 'Docente' ? 'block' : 'none';
            document.getElementById('representanteSection').style.display = rol === 'Representante' ? 'block' : 'none';
        });

        // Función para eliminar un chip
        function eliminarChip(elemento, id) {
            elemento.parentElement.remove();
            actualizarHiddenField();
        }

        // Función para actualizar el campo oculto con los IDs seleccionados
        function actualizarHiddenField() {
            const chips = document.querySelectorAll('#selectedChips .chip');
            const ids = Array.from(chips).map(chip => chip.dataset.id);
            document.getElementById('estudiantes_seleccion').value = ids.join(',');
        }

        // Inicializar cuando el DOM esté listo
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchEstudiantes');
            const dropdownList = document.getElementById('estudiantesList');
            const selectedChips = document.getElementById('selectedChips');

            if (searchInput && dropdownList && selectedChips) {
                // Mostrar dropdown al hacer foco
                searchInput.addEventListener('focus', () => {
                    dropdownList.style.display = 'block';
                });
                
                // Ocultar dropdown al perder foco
                searchInput.addEventListener('blur', () => {
                    setTimeout(() => {
                        if (!dropdownList.matches(':hover') && !searchInput.matches(':focus')) {
                            dropdownList.style.display = 'none';
                        }
                    }, 200);
                });

                // Filtrar mientras se escribe
                searchInput.addEventListener('input', function() {
                    const filter = this.value.toLowerCase();
                    dropdownList.querySelectorAll('.dropdown-item').forEach(item => {
                        const texto = item.textContent.toLowerCase();
                        item.style.display = texto.includes(filter) ? 'block' : 'none';
                    });
                });

                // Click en un estudiante de la lista
                dropdownList.addEventListener('click', function(e) {
                    const item = e.target.closest('.dropdown-item');
                    if (!item || !item.dataset.id) return;
                    
                    const id = item.dataset.id;
                    const nombre = item.dataset.nombre;
                    const grado = item.dataset.grado;
                    const seccion = item.dataset.seccion;
                    
                    // Verificar si ya está seleccionado
                    if (selectedChips.querySelector(`[data-id="${id}"]`)) {
                        alert('Este estudiante ya está asignado');
                        return;
                    }
                    
                    // Crear nuevo chip
                    const chip = document.createElement('div');
                    chip.className = 'chip';
                    chip.dataset.id = id;
                    chip.innerHTML = `${nombre} (${grado}-${seccion}) <span class="remove" onclick="eliminarChip(this, '${id}')">×</span>`;
                    
                    selectedChips.appendChild(chip);
                    actualizarHiddenField();
                    searchInput.value = '';
                    dropdownList.style.display = 'none';
                });
            }

            // Generar contraseña automáticamente
            document.querySelector('input[name="nombre"]')?.addEventListener('input', function() {
                const nombre = this.value.trim();
                const campo = document.querySelector('input[name="contrasena"]');
                if (nombre && !campo.value) {
                    const letra = nombre.charAt(0).toUpperCase();
                    const anio = new Date().getFullYear();
                    campo.value = letra + anio + 'siudecres+';
                }
            });
        });

        // Menú hamburguesa
        document.getElementById('menu-toggle')?.addEventListener('click', function() {
            const dropdown = document.getElementById('dropdown');
            if (dropdown) dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        });

        document.addEventListener('click', function(e) {
            const dropdown = document.getElementById('dropdown');
            const toggle = document.getElementById('menu-toggle');
            if (dropdown && toggle && !toggle.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    </script>
</body>
</html>
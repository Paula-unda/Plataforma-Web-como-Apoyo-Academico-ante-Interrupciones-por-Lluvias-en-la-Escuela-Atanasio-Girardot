<?php
session_start();
require_once '../../funciones.php';
// Evitar caché del navegador
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!sesionActiva() || !in_array($_SESSION['usuario_rol'], ['Estudiante', 'Docente', 'Administrador'])) {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$usuario_rol = $_SESSION['usuario_rol'];

// Obtener grado y sección del usuario
$grado_usuario = '';
$seccion_usuario = '';

try {
    $conexion = getConexion();
    if ($usuario_rol === 'Estudiante') {
        $stmt_grado = $conexion->prepare("
            SELECT grado, seccion FROM estudiantes WHERE usuario_id = ?
        ");
        $stmt_grado->execute([$usuario_id]);
        $datos_usuario = $stmt_grado->fetch(PDO::FETCH_ASSOC);
        if ($datos_usuario) {
            $grado_usuario = $datos_usuario['grado'];
            $seccion_usuario = $datos_usuario['seccion'];
        }
    } elseif ($usuario_rol === 'Docente') {
        // Por ahora, si no hay tabla docentes, el docente puede ver todo
        // Cuando tengan tabla docentes, aquí se obtendrá su grado/sección
        $grado_usuario = ''; // Vacío significa que ve todos los temas
        $seccion_usuario = '';
    }
} catch (Exception $e) {
    error_log("Error obteniendo grado/sección: " . $e->getMessage());
}

$tema_id = isset($_GET['tema']) ? (int)$_GET['tema'] : null;
$temas = [];
$tema_detalle = null;
$respuestas = [];
$companeros = [];

error_log("=== FORO DEBUG ===");
error_log("usuario_id: $usuario_id | rol: $usuario_rol | tema_id: " . ($tema_id ?? 'NULL'));
error_log("grado: $grado_usuario | seccion: $seccion_usuario");

try {
    $conexion = getConexion();
    
    if (!$conexion) {
        throw new Exception("Error de conexión");
    }
    
    //OBTENER TEMAS USANDO t.grado y t.seccion
    if (($usuario_rol === 'Estudiante' || $usuario_rol === 'Docente') && !empty($grado_usuario)) {
        // Estudiantes y Docentes con grado/asignación: ven temas de SU grado/sección
        $query_temas = "
            SELECT t.id, t.titulo, u.nombre as autor_nombre, COUNT(r.id) as total_respuestas
            FROM foros_temas t
            INNER JOIN usuarios u ON t.autor_id = u.id
            LEFT JOIN foros_respuestas r ON t.id = r.tema_id
            WHERE t.grado = ? AND t.seccion = ?
            GROUP BY t.id, u.nombre, t.fecha_creacion
            ORDER BY t.fecha_creacion DESC
        ";
        $stmt_temas = $conexion->prepare($query_temas);
        $stmt_temas->execute([$grado_usuario, $seccion_usuario]);
        error_log("Query con filtro: grado=$grado_usuario, seccion=$seccion_usuario");
        
    } else {
        // Admin o usuarios sin grado: ven todos los temas
        $query_temas = "
            SELECT t.id, t.titulo, u.nombre as autor_nombre, COUNT(r.id) as total_respuestas
            FROM foros_temas t
            INNER JOIN usuarios u ON t.autor_id = u.id
            LEFT JOIN foros_respuestas r ON t.id = r.tema_id
            GROUP BY t.id, u.nombre, t.fecha_creacion
            ORDER BY t.fecha_creacion DESC
        ";
        $stmt_temas = $conexion->prepare($query_temas);
        $stmt_temas->execute();
        error_log("Query sin filtro (Admin)");
    }
    
    $temas = $stmt_temas->fetchAll(PDO::FETCH_ASSOC);
    error_log("Total temas encontrados: " . count($temas));
    
    // OBTENER DETALLE DEL TEMA - CON VALIDACIÓN DE GRADO/SECCIÓN
    if ($tema_id) {
        error_log("Buscando tema_id: $tema_id");
        
        // Validar que el tema pertenece al grado/sección del usuario
        if (($usuario_rol === 'Estudiante' || $usuario_rol === 'Docente') && !empty($grado_usuario)) {
            $stmt = $conexion->prepare("
                SELECT t.id, t.titulo, t.descripcion, t.fecha_creacion, 
                       u.nombre as autor_nombre, u.id as autor_id
                FROM foros_temas t
                INNER JOIN usuarios u ON t.autor_id = u.id
                WHERE t.id = ? AND t.grado = ? AND t.seccion = ?
            ");
            $stmt->execute([$tema_id, $grado_usuario, $seccion_usuario]);
            
        } else {
            // Admin: sin filtro
            $stmt = $conexion->prepare("
                SELECT t.id, t.titulo, t.descripcion, t.fecha_creacion, 
                       u.nombre as autor_nombre, u.id as autor_id
                FROM foros_temas t
                INNER JOIN usuarios u ON t.autor_id = u.id
                WHERE t.id = ?
            ");
            $stmt->execute([$tema_id]);
        }
        
        $tema_detalle = $stmt->fetch(PDO::FETCH_ASSOC);
        error_log("Tema encontrado: " . ($tema_detalle ? 'SI' : 'NO'));
        
    if ($tema_detalle) {

        // OBTENER RESPUESTAS CON INFORMACIÓN DE DESTINATARIOS Y FECHA LOCAL
        $stmt_resp = $conexion->prepare("
            SELECT r.id, r.contenido, 
                r.fecha_creacion AT TIME ZONE 'America/Caracas' as fecha_local,
                r.fecha_creacion,
                r.es_privado, r.destinatario_tipo, r.destinatario_id, r.autor_id,
                u.nombre as autor_nombre
            FROM foros_respuestas r
            INNER JOIN usuarios u ON r.autor_id = u.id
            WHERE r.tema_id = ?
            ORDER BY r.fecha_creacion ASC
        ");
        $stmt_resp->execute([$tema_id]);
        $respuestas = $stmt_resp->fetchAll(PDO::FETCH_ASSOC);
        error_log("Respuestas encontradas: " . count($respuestas));

        // PROCESAR CADA RESPUESTA PARA OBTENER NOMBRES DE DESTINATARIOS
        foreach ($respuestas as &$respuesta) {
            $respuesta['nombres_destinatarios'] = [];
            
            if (!empty($respuesta['destinatario_id'])) {
                $ids = explode(',', $respuesta['destinatario_id']);
                
                // Obtener nombres de los usuarios destinatarios
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt_nombres = $conexion->prepare("
                    SELECT id, nombre FROM usuarios WHERE id IN ($placeholders)
                ");
                $stmt_nombres->execute($ids);
                $nombres = $stmt_nombres->fetchAll(PDO::FETCH_KEY_PAIR);
                
                foreach ($ids as $id) {
                    if (isset($nombres[$id])) {
                        $respuesta['nombres_destinatarios'][$id] = $nombres[$id];
                    }
                }
            }
        }
        
        // OBTENER COMPAÑEROS (solo para estudiantes)
        if ($usuario_rol === 'Estudiante' && !empty($grado_usuario)) {
            $stmt_comp = $conexion->prepare("
                SELECT u.id, u.nombre
                FROM usuarios u
                INNER JOIN estudiantes e ON u.id = e.usuario_id
                WHERE u.rol = 'Estudiante'
                AND u.id != ?
                AND e.grado = ?
                AND e.seccion = ?
                ORDER BY u.nombre
            ");
            $stmt_comp->execute([$usuario_id, $grado_usuario, $seccion_usuario]);
            $companeros = $stmt_comp->fetchAll(PDO::FETCH_ASSOC);
            error_log("Compañeros encontrados: " . count($companeros));
        }
        
        // OBTENER DOCENTES DEL MISMO GRADO/SECCIÓN
        $docentes = [];
        if (!empty($grado_usuario) && !empty($seccion_usuario)) {
            $stmt_doc = $conexion->prepare("
                SELECT u.id, u.nombre, u.correo, d.grado, d.seccion
                FROM usuarios u
                INNER JOIN docentes d ON u.id = d.usuario_id
                WHERE u.rol = 'Docente'
                AND d.grado = ? AND d.seccion = ?
                ORDER BY u.nombre
            ");
            $stmt_doc->execute([$grado_usuario, $seccion_usuario]);
            $docentes = $stmt_doc->fetchAll(PDO::FETCH_ASSOC);
            error_log("Docentes encontrados en tu sección: " . count($docentes));
        }
    
        } else {
            error_log("❌ Tema NO encontrado o no pertenece a tu grado/sección");
        }
    }
    
} catch (Exception $e) {
    error_log("❌ ERROR FORO: " . $e->getMessage());
    error_log("❌ TRACE: " . $e->getTraceAsString());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Foro - SIEDUCRES</title>
    <?php require_once '../includes/favicon.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Tus estilos existentes se mantienen igual */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #00CED1;
            --secondary: #B19BFF;
            --canvas: #FFFFFF;
            --sidebar: #F8F9FA;
            --text-heading: #000000;
            --text-body: #333333;
            --text-meta: #666666;
            --border: #E0E0E0;
            --shadow: rgba(0, 0, 0, 0.05);
            --success: #28a745;
            --warning: #ffc107;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--canvas); color: var(--text-body); min-height: 100vh; display: flex; flex-direction: column; }
        
        /* Header */
        .header { display: flex; justify-content: space-between; align-items: center; padding: 0 24px; height: 60px; background-color: var(--canvas); border-bottom: 1px solid var(--border); }
        .logo { height: 40px; }
        .header-right { display: flex; align-items: center; gap: 16px; }
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; background-color: #F0F0F0; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .icon-btn img { width: 20px; height: 20px; object-fit: contain; }
        .menu-dropdown { position: absolute; top: 60px; right: 24px; background: white; border: 1px solid var(--border); border-radius: 8px; display: none; min-width: 180px; z-index: 1000; }
        .menu-item { padding: 10px 16px; font-size: 14px; color: var(--text-body); text-decoration: none; display: block; }
        .menu-item:hover { background-color: #F8F8F8; }
        
        /* Layout Split-Pane */
        .foro-container { display: flex; flex: 1; overflow: hidden; height: calc(100vh - 140px); /* Ajustado para header y banner */ }
        .sidebar { width: 25%; background-color: var(--sidebar); border-right: 1px solid var(--border); overflow-y: auto; }
        .content-area { width: 75%; overflow-y: auto; padding: 40px; }
        
        /* Sidebar - Lista de Temas */
        .sidebar-header { padding: 20px; border-bottom: 1px solid var(--border); background: var(--canvas); }
        .sidebar-title { font-size: 18px; font-weight: 700; color: var(--text-heading); margin-bottom: 8px; }
        .btn-nuevo-tema { width: 100%; background: var(--primary); color: #000; padding: 12px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; margin-top: 12px; }
        .btn-nuevo-tema:hover { opacity: 0.9; }
        
        .tema-item { padding: 15px; border-bottom: 1px solid var(--secondary); cursor: pointer; transition: background 0.2s; }
        .tema-item:hover { background: rgba(177, 155, 255, 0.1); }
        .tema-item.active { background: rgba(0, 206, 209, 0.1); border-left: 3px solid var(--primary); }
        .tema-titulo { font-size: 14px; font-weight: 600; color: var(--text-heading); margin-bottom: 4px; }
        .tema-meta { font-size: 12px; color: var(--text-meta); }
        
        /* Content Area - Detalle del Tema */
        .tema-header { margin-bottom: 32px; }
        .tema-titulo-principal { font-size: 32px; font-weight: 700; color: var(--text-heading); margin-bottom: 12px; }
        .tema-info { display: flex; gap: 20px; font-size: 14px; color: var(--text-meta); margin-bottom: 16px; }
        .tema-descripcion { font-size: 16px; color: var(--text-body); line-height: 1.6; padding: 20px; background: var(--sidebar); border-radius: 12px; }
        
        /* Respuestas */
        .respuestas-section { margin-top: 40px; }
        .respuestas-title { font-size: 24px; font-weight: 700; color: var(--text-heading); margin-bottom: 24px; padding-bottom: 12px; border-bottom: 1px solid var(--border); }
        
        .respuesta-card { padding: 20px; background: var(--canvas); border: 1px solid var(--border); border-radius: 12px; margin-bottom: 16px; }
        .respuesta-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .respuesta-autor { font-weight: 600; color: var(--text-heading); }
        .respuesta-fecha { font-size: 12px; color: var(--text-meta); }
        .respuesta-contenido { font-size: 14px; color: var(--text-body); line-height: 1.6; }
        .badge-privado { background: var(--secondary); color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; }
        
        /* Formulario de Respuesta */
        .formulario-respuesta { margin-top: 32px; padding: 24px; background: var(--sidebar); border-radius: 12px; }
        .formulario-title { font-size: 18px; font-weight: 700; margin-bottom: 16px; }
        
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-weight: 600; margin-bottom: 8px; color: var(--text-heading); }
        .form-textarea { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 14px; resize: vertical; min-height: 120px; }
        .form-textarea:focus { outline: none; border-color: var(--primary); }
        
        .privacidad-options { display: flex; gap: 16px; flex-wrap: wrap; }
        .privacidad-option { display: flex; align-items: center; gap: 8px; padding: 12px; background: var(--canvas); border: 1px solid var(--border); border-radius: 8px; cursor: pointer; }
        .privacidad-option input[type="radio"] { accent-color: var(--primary); }
        .privacidad-option:hover { border-color: var(--primary); }
        
        .btn-enviar { background: var(--primary); color: #000; padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; width: 100%; margin-top: 16px; }
        .btn-enviar:hover { opacity: 0.9; }
        
        /* Mensaje sin selección */
        .no-seleccion { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; text-align: center; color: var(--text-meta); }
        .no-seleccion-icon { font-size: 64px; margin-bottom: 20px; opacity: 0.3; }
        
        /* Banner */
        .banner { position: relative; height: 80px; overflow: hidden; background: linear-gradient(135deg, var(--primary), var(--secondary)); }
        .banner-content { text-align: center; position: relative; z-index: 2; padding: 20px; }
        .banner-title { font-size: 28px; font-weight: 700; color: white; }
        /* Estilos para mensajes de éxito y error */
        .mensaje-exito {
            background-color: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
            animation: slideDown 0.3s ease-out;
        }

        .mensaje-error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
            animation: slideDown 0.3s ease-out;
        }
        .btn-borrar {
            background: none;
            border: none;
            cursor: pointer;
            color: #dc3545;
            font-size: 16px;
            padding: 4px 8px;
            border-radius: 4px;
            transition: background-color 0.2s;
        }

        .btn-borrar:hover {
            background-color: rgba(220, 53, 69, 0.1);
        }



        .btn-borrar {
            background: none;
            border: none;
            cursor: pointer;
            color: #dc3545;
            font-size: 20px;  
            padding: 8px 12px; 
            border-radius: 4px;
            transition: background-color 0.2s;
            display: inline-block; 
            opacity: 1; 
            visibility: visible; 
        }

        .btn-borrar:hover {
            background-color: rgba(220, 53, 69, 0.1);
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        @media (max-width: 768px) {
            .foro-container { flex-direction: column; height: auto; }
            .sidebar { width: 100%; height: 300px; }
            .content-area { width: 100%; padding: 20px; }
        }
        @media (max-width: 768px) {
            #select-docente select {
                font-size: 16px; 
                padding: 14px;    
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-left">
            <img src="../../../assets/logo.svg" alt="SIEDUCRES" class="logo" onerror="this.src='https://via.placeholder.com/120x40?text=SIEDUCRES'">
        </div>
        <div class="header-right">
            <div class="icon-btn">
                <img src="../../../assets/icon-bell.svg" alt="Notificaciones" onerror="this.src='https://via.placeholder.com/20x20?text=🔔'">
            </div>
            <div class="icon-btn">
                <img src="../../../assets/icon-user.svg" alt="Perfil" onerror="this.src='https://via.placeholder.com/20x20?text=👤'">
            </div>
            <div class="icon-btn" id="menu-toggle">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="#333333">
                    <path d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/>
                </svg>
            </div>
            <div class="menu-dropdown" id="dropdown">
                <a href="dashboard_estudiante.php" class="menu-item">Panel Principal</a>
                <a href="../../logout.php" class="menu-item">Cerrar sesión</a>
            </div>
        </div>
    </header>

    <!-- Banner -->
    <div class="banner">
        <div class="banner-content">
            <h1 class="banner-title">Foro de Discusión</h1>
        </div>
    </div>

    <!-- Contenedor Split-Pane -->
    <div class="foro-container">
        <!-- Sidebar - Lista de Temas -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2 class="sidebar-title">Temas del Foro</h2>
                <button class="btn-nuevo-tema" onclick="window.location.href='crear_tema.php'">+ Nuevo Tema</button>
            </div>
            
            <?php if (count($temas) > 0): ?>
                <?php foreach ($temas as $tema): ?>
                    <div class="tema-item <?php echo ($tema['id'] == $tema_id) ? 'active' : ''; ?>" 
                         onclick="window.location.href='foro.php?tema=<?php echo $tema['id']; ?>'">
                        <div class="tema-titulo"><?php echo htmlspecialchars($tema['titulo']); ?></div>
                        <div class="tema-meta">
                            Por <?php echo htmlspecialchars($tema['autor_nombre']); ?> • 
                            <?php echo $tema['total_respuestas']; ?> respuestas
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="padding: 20px; text-align: center; color: var(--text-meta);">
                    No hay temas aún
                </div>
            <?php endif; ?>
        </aside>

        <!-- Content Area - Detalle del Tema -->
        <main class="content-area">
    
            <!-- MOSTRAR MENSAJES DE ÉXITO/ERROR -->
            <?php if (isset($_GET['exito'])): ?>
                <div class="mensaje-exito">
                    <?php echo htmlspecialchars($_GET['exito']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
                <div class="mensaje-error">
                    <?php echo htmlspecialchars($_GET['error']); ?>
                </div>
            <?php endif; ?>
            <!-- FIN DE MENSAJES -->
            
            <?php if ($tema_detalle): ?>
                <!-- Detalle del Tema -->
                <div class="tema-header">
                    <h1 class="tema-titulo-principal"><?php echo htmlspecialchars($tema_detalle['titulo']); ?></h1>
                    <div class="tema-info">
                        <span>Por <?php echo htmlspecialchars($tema_detalle['autor_nombre']); ?></span>
                        <span>
                            <?php 
                            // FORZAR la zona horaria de Caracas
                            $fecha = new DateTime($tema_detalle['fecha_creacion'], new DateTimeZone('UTC'));
                            $fecha->setTimezone(new DateTimeZone('America/Caracas'));
                            echo $fecha->format('d/m/Y H:i:s');
                            ?>
                        </span>
                    </div>
                    <div class="tema-descripcion">
                        <?php echo nl2br(htmlspecialchars($tema_detalle['descripcion'])); ?>
                    </div>
                </div>

                <!-- Sección de Respuestas -->
                <div class="respuestas-section">
                    <h2 class="respuestas-title">Respuestas (<?php echo count($respuestas); ?>)</h2>
                    
                    <?php if (count($respuestas) > 0): ?>
                        <?php foreach ($respuestas as $respuesta): ?>
                            <div class="respuesta-card" id="respuesta-<?php echo $respuesta['id']; ?>">
                                <div class="respuesta-header">
                                    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">
                                        <span class="respuesta-autor"><?php echo htmlspecialchars($respuesta['autor_nombre']); ?></span>
                                        
                                        <!-- BADGES DE TIPO DE MENSAJE -->
                                        <?php if ($respuesta['es_privado'] == 't' || $respuesta['es_privado'] === true): ?>
                                            <?php if ($respuesta['destinatario_tipo'] == 'docente'): ?>
                                                <span class="badge-privado" style="background: #FF6B6B;"> Solo Docentes</span>
                                            <?php elseif ($respuesta['destinatario_tipo'] == 'companero'): ?>
                                                <span class="badge-privado" style="background: #4ECDC4;"> Mensaje Privado</span>
                                            <?php elseif ($respuesta['destinatario_tipo'] == 'ambos'): ?>
                                                <span class="badge-privado" style="background: #9B8AFB;"> Docentes + Compañero</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <span class="respuesta-fecha">
                                            <?php 
                                            if (isset($respuesta['fecha_local']) && !empty($respuesta['fecha_local'])) {
                                                echo date('d/m/Y H:i:s', strtotime($respuesta['fecha_local']));
                                            } elseif (isset($respuesta['fecha_creacion']) && !empty($respuesta['fecha_creacion'])) {
                                                $timestamp = strtotime($respuesta['fecha_creacion']);
                                                $hora_caracas = $timestamp - (4 * 3600);
                                                echo date('d/m/Y H:i:s', $hora_caracas);
                                            } else {
                                                echo 'Fecha no disponible';
                                            }
                                            ?>
                                        </span>
                                        
                                        <!-- Botón de borrar -->
                                        <?php
                                        $puede_borrar = false;

                                        if ($_SESSION['usuario_rol'] === 'Administrador') {
                                            $puede_borrar = true;
                                        } elseif ($_SESSION['usuario_rol'] === 'Docente') {
                                            $puede_borrar = true;
                                        } elseif ($_SESSION['usuario_rol'] === 'Estudiante' && 
                                                isset($respuesta['autor_id']) && 
                                                $_SESSION['usuario_id'] == $respuesta['autor_id']) {
                                            
                                            if (isset($respuesta['fecha_creacion'])) {
                                                $timestamp_respuesta = strtotime($respuesta['fecha_creacion']);
                                                $minutos_transcurridos = (time() - $timestamp_respuesta) / 60;
                                                
                                                if ($minutos_transcurridos <= 30) {
                                                    $puede_borrar = true;
                                                }
                                            }
                                        }

                                        // Para pruebas
                                        //$puede_borrar = true;

                                        if ($puede_borrar):
                                        ?>
                                        <form action="borrar_respuesta.php" method="POST" style="display: inline;" onsubmit="return confirm('¿Estás seguro de que quieres eliminar esta respuesta?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="respuesta_id" value="<?php echo $respuesta['id']; ?>">
                                            <input type="hidden" name="tema_id" value="<?php echo $tema_id; ?>">
                                            <button type="submit" class="btn-borrar" title="Eliminar respuesta">
                                                🗑️
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="respuesta-contenido">
                                    <?php echo nl2br(htmlspecialchars($respuesta['contenido'])); ?>
                                </div>
                                
                                <!-- SECCIÓN DE DESTINATARIOS - SOLO SI HAY DESTINATARIOS -->
                                <?php if (!empty($respuesta['destinatario_id']) && $respuesta['destinatario_tipo'] != 'docente'): ?>
                                    <div style="margin-top: 10px; padding-top: 10px; border-top: 1px dashed var(--border); font-size: 12px; position: relative;">
                                        <small style="color: var(--text-meta); display: flex; align-items: center; gap: 5px; flex-wrap: wrap;">
                                            <?php if ($respuesta['destinatario_tipo'] == 'companero'): ?>
                                                <span> Para:</span>
                                            <?php elseif ($respuesta['destinatario_tipo'] == 'ambos'): ?>
                                                <span> Para:</span>
                                            <?php else: ?>
                                                <span> Para:</span>
                                            <?php endif; ?>
                                            
                                            <?php 
                                            $nombres_array = array_values($respuesta['nombres_destinatarios']);
                                            $total = count($nombres_array);
                                            $mostrar = array_slice($nombres_array, 0, 2);
                                            ?>
                                            
                                            <?php foreach ($mostrar as $nombre): ?>
                                                <span style="background: #f0f0f0; padding: 2px 8px; border-radius: 12px;">
                                                    <?php echo htmlspecialchars($nombre); ?>
                                                </span>
                                            <?php endforeach; ?>
                                            
                                            <?php if ($total > 2): ?>
                                                <span class="destinatarios-mas" style="background: #e0e0e0; padding: 2px 8px; border-radius: 12px; cursor: pointer; position: relative;"
                                                    onclick="mostrarTodosDestinatarios(this, <?php echo htmlspecialchars(json_encode($nombres_array)); ?>)">
                                                    +<?php echo $total - 2; ?> más
                                                </span>
                                                
                                                <div class="destinatarios-tooltip" style="display: none; position: absolute; background: white; border: 1px solid #ccc; border-radius: 8px; padding: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); z-index: 1000; max-width: 250px;">
                                                    <strong>Todos los destinatarios:</strong>
                                                    <ul style="margin: 5px 0 0 0; padding-left: 20px;">
                                                        <?php foreach ($nombres_array as $nombre): ?>
                                                            <li><?php echo htmlspecialchars($nombre); ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- BADGE DE MÚLTIPLES DESTINATARIOS - AHORA AL FINAL -->
                                <?php 
                                $num_destinatarios = 0;
                                if (!empty($respuesta['destinatario_id'])) {
                                    if (strpos($respuesta['destinatario_id'], ',') !== false) {
                                        $num_destinatarios = count(explode(',', $respuesta['destinatario_id']));
                                    } else {
                                        $num_destinatarios = 1;
                                    }
                                }
                                ?>
                                <?php if ($num_destinatarios > 1): ?>
                                    <div style="margin-top: 5px; font-size: 11px; color: #666;">
                                        <small>Enviado a <?php echo $num_destinatarios; ?> personas</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: var(--text-meta);">
                            Sé el primero en responder
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Formulario de Respuesta -->
                <div class="formulario-respuesta">
                    <h3 class="formulario-title">Tu Respuesta</h3>
                    <form action="procesar_respuesta.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="tema_id" value="<?php echo $tema_id; ?>">
                        
                        <div class="form-group">
                            <label class="form-label">Mensaje</label>
                            <textarea name="contenido" class="form-textarea" placeholder="Escribe tu respuesta aquí..." required></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Privacidad</label>
                            <div class="privacidad-options">
                                <label class="privacidad-option">
                                    <input type="radio" name="privacidad" value="publico" checked>
                                    <span>Público (todos ven)</span>
                                </label>
                                <label class="privacidad-option">
                                    <input type="radio" name="privacidad" value="docente">
                                    <span>Solo Docentes</span>
                                </label>
                                <label class="privacidad-option">
                                    <input type="radio" name="privacidad" value="companero">
                                    <span>Compañero Específico</span>
                                </label>
                                <label class="privacidad-option">
                                    <input type="radio" name="privacidad" value="ambos">
                                    <span>Docentes + Compañero</span>
                                </label>
                            </div>
                        </div>
                        
                        <!-- Selector de compañero unificado para móvil/desktop -->
                        <div class="form-group" id="select-companero" style="display: none;">
                            <label class="form-label">
                                <span id="companero-label">Seleccionar Compañero(s)</span>
                            </label>
                            
                            <!-- Campo oculto para almacenar selecciones -->
                            <input type="hidden" name="companero_ids_input" id="companero_ids_input" value="">
                            
                            <!-- Selector visual -->
                            <div class="companero-selector" style="border: 1px solid var(--border); border-radius: 8px; overflow: hidden;">
                                <!-- Campo de búsqueda -->
                                <input type="text" id="companero-search" class="form-textarea" 
                                    placeholder="Buscar compañero..." 
                                    style="border: none; border-bottom: 1px solid var(--border); border-radius: 0; min-height: 40px;"
                                    onkeyup="filtrarCompaneros()">
                                
                                <!-- Lista de compañeros con checkboxes -->
                                <div class="companeros-list" style="max-height: 200px; overflow-y: auto; padding: 5px;">
                                    <?php if (!empty($companeros)): ?>
                                        <?php foreach ($companeros as $comp): ?>
                                            <label class="companero-item" style="display: flex; align-items: center; padding: 8px; cursor: pointer; border-bottom: 1px solid var(--border);" data-nombre="<?php echo strtolower(htmlspecialchars($comp['nombre'])); ?>">
                                                <input type="checkbox" class="companero-checkbox" value="<?php echo $comp['id']; ?>" style="margin-right: 10px; width: 20px; height: 20px;" onchange="actualizarSeleccionados()">
                                                <span><?php echo htmlspecialchars($comp['nombre']); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p style="text-align: center; padding: 20px; color: var(--text-meta);">
                                            No hay otros estudiantes disponibles
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Contador de seleccionados -->
                            <div class="seleccionados-info" style="display: flex; justify-content: space-between; align-items: center; margin-top: 5px;">
                                <small style="color: #666;">
                                    <span id="contador-seleccionados">0</span> compañero(s) seleccionado(s)
                                </small>
                                <button type="button" onclick="limpiarSeleccion()" style="background: none; border: none; color: var(--primary); cursor: pointer; font-size: 12px;">
                                    Limpiar
                                </button>
                            </div>
                        </div>
                        <!-- Selector de docentes unificado (igual que compañeros) -->
                        <div class="form-group" id="select-docente" style="display: none;">
                            <label class="form-label">
                                <span id="docente-label">Seleccionar Docente(s)</span>
                            </label>
                            
                            <!-- Campo oculto para almacenar selecciones -->
                            <input type="hidden" name="docente_ids_input" id="docente_ids_input" value="">
                            
                            <!-- Selector visual -->
                            <div class="docente-selector" style="border: 1px solid var(--border); border-radius: 8px; overflow: hidden;">
                                <!-- Campo de búsqueda -->
                                <input type="text" id="docente-search" class="form-textarea" 
                                    placeholder="Buscar docente..." 
                                    style="border: none; border-bottom: 1px solid var(--border); border-radius: 0; min-height: 40px;"
                                    onkeyup="filtrarDocentes()">
                                
                                <!-- Lista de docentes con checkboxes -->
                                <div class="docentes-list" style="max-height: 200px; overflow-y: auto; padding: 5px;">
                                    <?php if (!empty($docentes)): ?>
                                        <?php foreach ($docentes as $doc): ?>
                                            <label class="docente-item" style="display: flex; align-items: center; padding: 8px; cursor: pointer; border-bottom: 1px solid var(--border);" 
                                                data-nombre="<?php echo strtolower(htmlspecialchars($doc['nombre'])); ?>">
                                                <input type="checkbox" class="docente-checkbox" value="<?php echo $doc['id']; ?>" 
                                                    style="margin-right: 10px; width: 20px; height: 20px;" 
                                                    onchange="actualizarDocentesSeleccionados()">
                                                <span>
                                                    <?php echo htmlspecialchars($doc['nombre']); ?>
                                                    <small style="color: #666; display: block; font-size: 11px;">
                                                        <?php echo htmlspecialchars($doc['correo']); ?>
                                                    </small>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p style="text-align: center; padding: 20px; color: var(--text-meta);">
                                            No hay docentes asignados a tu sección
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Contador de seleccionados -->
                            <div class="seleccionados-info" style="display: flex; justify-content: space-between; align-items: center; margin-top: 5px;">
                                <small style="color: #666;">
                                    <span id="contador-docentes">0</span> docente(s) seleccionado(s)
                                </small>
                                <button type="button" onclick="limpiarSeleccionDocentes()" style="background: none; border: none; color: var(--primary); cursor: pointer; font-size: 12px;">
                                    Limpiar
                                </button>
                            </div>
                        </div>

                        <style>
                        /* Estilos para móvil */
                        @media (max-width: 768px) {
                            .companero-item {
                                padding: 12px !important;
                                font-size: 16px;
                            }
                            
                            .companero-item input[type="checkbox"] {
                                width: 24px;
                                height: 24px;
                            }
                            
                            .companeros-list {
                                max-height: 250px !important;
                            }
                        }
                        </style>

                        <script>
                        // Variables globales
                        let companerosSeleccionados = new Set();

                        // Filtrar compañeros por búsqueda
                        function filtrarCompaneros() {
                            const busqueda = document.getElementById('companero-search').value.toLowerCase();
                            const items = document.querySelectorAll('.companero-item');
                            
                            items.forEach(item => {
                                const nombre = item.getAttribute('data-nombre');
                                if (nombre.includes(busqueda)) {
                                    item.style.display = 'flex';
                                } else {
                                    item.style.display = 'none';
                                }
                            });
                        }

                        // Actualizar contador y campo oculto
                        function actualizarSeleccionados() {
                            const checkboxes = document.querySelectorAll('.companero-checkbox:checked');
                            companerosSeleccionados.clear();
                            
                            checkboxes.forEach(cb => {
                                companerosSeleccionados.add(cb.value);
                            });
                            
                            // Actualizar contador
                            document.getElementById('contador-seleccionados').textContent = companerosSeleccionados.size;
                            
                            // Actualizar campo oculto con los IDs seleccionados
                            const idsArray = Array.from(companerosSeleccionados);
                            document.getElementById('companero_ids_input').value = idsArray.join(',');
                            
                            // Debug: mostrar en consola
                            console.log('Compañeros seleccionados:', idsArray);
                        }

                        // Limpiar selección
                        function limpiarSeleccion() {
                            document.querySelectorAll('.companero-checkbox').forEach(cb => {
                                cb.checked = false;
                            });
                            actualizarSeleccionados();
                        }
                        // Variables globales para docentes
                        let docentesSeleccionados = new Set();

                        // Filtrar docentes por búsqueda
                        function filtrarDocentes() {
                            const busqueda = document.getElementById('docente-search').value.toLowerCase();
                            const items = document.querySelectorAll('.docente-item');
                            
                            items.forEach(item => {
                                const nombre = item.getAttribute('data-nombre');
                                if (nombre.includes(busqueda)) {
                                    item.style.display = 'flex';
                                } else {
                                    item.style.display = 'none';
                                }
                            });
                        }

                        // Actualizar contador y campo oculto de docentes
                        function actualizarDocentesSeleccionados() {
                            const checkboxes = document.querySelectorAll('.docente-checkbox:checked');
                            docentesSeleccionados.clear();
                            
                            checkboxes.forEach(cb => {
                                docentesSeleccionados.add(cb.value);
                            });
                            
                            // Actualizar contador
                            document.getElementById('contador-docentes').textContent = docentesSeleccionados.size;
                            
                            // Actualizar campo oculto con los IDs seleccionados
                            const idsArray = Array.from(docentesSeleccionados);
                            document.getElementById('docente_ids_input').value = idsArray.join(',');
                            
                            console.log('Docentes seleccionados:', idsArray);
                        }

                        // Limpiar selección de docentes
                        function limpiarSeleccionDocentes() {
                            document.querySelectorAll('.docente-checkbox').forEach(cb => {
                                cb.checked = false;
                            });
                            actualizarDocentesSeleccionados();
                        }

                        // Modificar el envío del formulario para usar los campos ocultos
                        document.querySelector('form').addEventListener('submit', function(e) {
                            // Eliminar inputs anteriores
                            document.querySelectorAll('input[name="companero_ids[]"]').forEach(el => el.remove());
                            document.querySelectorAll('input[name="docente_ids[]"]').forEach(el => el.remove());
                            
                            // Procesar compañeros
                            const compIdsString = document.getElementById('companero_ids_input').value;
                            if (compIdsString) {
                                const ids = compIdsString.split(',').filter(id => id.trim() !== '');
                                ids.forEach(id => {
                                    if (id) {
                                        const input = document.createElement('input');
                                        input.type = 'hidden';
                                        input.name = 'companero_ids[]';
                                        input.value = id;
                                        this.appendChild(input);
                                    }
                                });
                            }
                            
                            // Procesar docentes
                            const docIdsString = document.getElementById('docente_ids_input').value;
                            if (docIdsString) {
                                const ids = docIdsString.split(',').filter(id => id.trim() !== '');
                                ids.forEach(id => {
                                    if (id) {
                                        const input = document.createElement('input');
                                        input.type = 'hidden';
                                        input.name = 'docente_ids[]';
                                        input.value = id;
                                        this.appendChild(input);
                                    }
                                });
                            }
                        });
                        </script>
                        
                        <!-- Info adicional para "ambos" -->
                        <div class="form-group" id="info-ambos" style="display: none;">
                            <small style="color: #666;">
                                 Esta respuesta será visible para todos los docentes y el compañero seleccionado
                            </small>
                        </div>
                                                
                        <button type="submit" class="btn-enviar">📤 Enviar Respuesta</button>
                    </form>
                </div>
                
            <?php else: ?>
                <!-- Mensaje cuando no hay tema seleccionado -->
                <div class="no-seleccion">
                    <div class="no-seleccion-icon"></div>
                    <h2 style="margin-bottom: 8px;">Selecciona un tema</h2>
                    <p>Haz clic en un tema de la lista para ver las respuestas y participar</p>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // Menú hamburguesa
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


        // Mostrar/Ocultar selector de compañero y docente
        document.querySelectorAll('input[name="privacidad"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const selectCompanero = document.getElementById('select-companero');
                const selectDocente = document.getElementById('select-docente');
                const infoAmbos = document.getElementById('info-ambos');
                const companeroLabel = document.getElementById('companero-label');
                
                if (this.value === 'companero') {
                    selectCompanero.style.display = 'block';
                    selectDocente.style.display = 'none';
                    infoAmbos.style.display = 'none';
                    companeroLabel.textContent = 'Seleccionar Compañero';
                } else if (this.value === 'docente') {
                    selectCompanero.style.display = 'none';
                    selectDocente.style.display = 'block';
                    infoAmbos.style.display = 'none';
                } else if (this.value === 'ambos') {
                    selectCompanero.style.display = 'block';
                    selectDocente.style.display = 'block';
                    infoAmbos.style.display = 'block';
                    companeroLabel.textContent = 'Seleccionar Compañero (visible para él)';
                } else {
                    selectCompanero.style.display = 'none';
                    selectDocente.style.display = 'none';
                    infoAmbos.style.display = 'none';
                }
            });
        });
        function mostrarTodosDestinatarios(element, nombres) {
            // Buscar o crear tooltip
            let tooltip = element.nextElementSibling;
            if (!tooltip || !tooltip.classList.contains('destinatarios-tooltip')) {
                // Crear tooltip si no existe
                tooltip = document.createElement('div');
                tooltip.className = 'destinatarios-tooltip';
                tooltip.style.cssText = 'display: none; position: absolute; background: white; border: 1px solid #ccc; border-radius: 8px; padding: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); z-index: 1000; max-width: 250px;';
                
                // Crear lista de nombres
                let lista = '<strong>Todos los destinatarios:</strong><ul style="margin:5px 0 0 0; padding-left:20px;">';
                if (typeof nombres === 'string') {
                    // Si viene como string JSON, parsearlo
                    try {
                        const nombresArray = JSON.parse(nombres);
                        nombresArray.forEach(n => {
                            lista += `<li>${n}</li>`;
                        });
                    } catch(e) {
                        lista += `<li>${nombres}</li>`;
                    }
                } else if (Array.isArray(nombres)) {
                    nombres.forEach(n => {
                        lista += `<li>${n}</li>`;
                    });
                }
                lista += '</ul>';
                
                tooltip.innerHTML = lista;
                element.parentNode.appendChild(tooltip);
            }
            
            // Alternar visibilidad
            if (tooltip.style.display === 'none' || tooltip.style.display === '') {
                tooltip.style.display = 'block';
                
                // Posicionar el tooltip
                const rect = element.getBoundingClientRect();
                tooltip.style.top = (rect.bottom + window.scrollY + 5) + 'px';
                tooltip.style.left = (rect.left + window.scrollX) + 'px';
            } else {
                tooltip.style.display = 'none';
            }
        }

        // Cerrar tooltips al hacer clic fuera
        document.addEventListener('click', function(e) {
            if (!e.target.classList.contains('destinatarios-mas')) {
                document.querySelectorAll('.destinatarios-tooltip').forEach(t => {
                    t.style.display = 'none';
                });
            }
        });

        // Prevenir comportamiento por defecto en los items del tema
        document.querySelectorAll('.tema-item').forEach(item => {
            item.addEventListener('click', function(e) {
            });
        });
    </script>
</body>
</html>
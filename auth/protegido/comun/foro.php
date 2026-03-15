<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/onesignal_config.php';

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
$usuario_nombre = $_SESSION['usuario_nombre'];

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
        $stmt_grado = $conexion->prepare("
            SELECT grado, seccion FROM docentes WHERE usuario_id = ?
        ");
        $stmt_grado->execute([$usuario_id]);
        $datos_usuario = $stmt_grado->fetch(PDO::FETCH_ASSOC);
        if ($datos_usuario) {
            $grado_usuario = $datos_usuario['grado'];
            $seccion_usuario = $datos_usuario['seccion'];
        }
    }
} catch (Exception $e) {
    error_log("Error obteniendo grado/sección: " . $e->getMessage());
}

$tema_id = isset($_GET['tema']) ? (int)$_GET['tema'] : null;
$temas = [];
$tema_detalle = null;
$respuestas = [];
$usuarios_disponibles = [];

try {
    $conexion = getConexion();
    
    if (!$conexion) {
        throw new Exception("Error de conexión");
    }
    
    // OBTENER TEMAS - FILTRADOS POR GRADO/SECCIÓN
    if (!empty($grado_usuario)) {
        $query_temas = "
            SELECT t.id, t.titulo, u.nombre as autor_nombre, 
                   COUNT(DISTINCT r.id) as total_respuestas,
                   MAX(r.fecha_creacion) as ultima_respuesta
            FROM foros_temas t
            INNER JOIN usuarios u ON t.autor_id = u.id
            LEFT JOIN foros_respuestas r ON t.id = r.tema_id
            WHERE t.grado = ? AND t.seccion = ?
            GROUP BY t.id, u.nombre, t.fecha_creacion
            ORDER BY COALESCE(MAX(r.fecha_creacion), t.fecha_creacion) DESC
        ";
        $stmt_temas = $conexion->prepare($query_temas);
        $stmt_temas->execute([$grado_usuario, $seccion_usuario]);
    } else {
        $query_temas = "
            SELECT t.id, t.titulo, u.nombre as autor_nombre, 
                   COUNT(DISTINCT r.id) as total_respuestas,
                   MAX(r.fecha_creacion) as ultima_respuesta
            FROM foros_temas t
            INNER JOIN usuarios u ON t.autor_id = u.id
            LEFT JOIN foros_respuestas r ON t.id = r.tema_id
            GROUP BY t.id, u.nombre, t.fecha_creacion
            ORDER BY COALESCE(MAX(r.fecha_creacion), t.fecha_creacion) DESC
        ";
        $stmt_temas = $conexion->prepare($query_temas);
        $stmt_temas->execute();
    }
    
    $temas = $stmt_temas->fetchAll(PDO::FETCH_ASSOC);
    
    // OBTENER DETALLE DEL TEMA
    if ($tema_id) {
        // Validar acceso al tema
        if (!empty($grado_usuario)) {
            $stmt = $conexion->prepare("
                SELECT t.*, u.nombre as autor_nombre, u.id as autor_id
                FROM foros_temas t
                INNER JOIN usuarios u ON t.autor_id = u.id
                WHERE t.id = ? AND t.grado = ? AND t.seccion = ?
            ");
            $stmt->execute([$tema_id, $grado_usuario, $seccion_usuario]);
        } else {
            $stmt = $conexion->prepare("
                SELECT t.*, u.nombre as autor_nombre, u.id as autor_id
                FROM foros_temas t
                INNER JOIN usuarios u ON t.autor_id = u.id
                WHERE t.id = ?
            ");
            $stmt->execute([$tema_id]);
        }
        
        $tema_detalle = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($tema_detalle) {
            // OBTENER RESPUESTAS CON REGLAS DE VISIBILIDAD
            $query_respuestas = "
                SELECT r.*, 
                       u.nombre as autor_nombre,
                       CASE 
                           WHEN r.fecha_creacion + INTERVAL '15 minutes' > NOW() 
                           THEN true ELSE false 
                       END as puede_editar_tiempo,
                       EXTRACT(EPOCH FROM (r.fecha_creacion + INTERVAL '15 minutes' - NOW())) as segundos_restantes
                FROM foros_respuestas r
                INNER JOIN usuarios u ON r.autor_id = u.id
                WHERE r.tema_id = ?
                ORDER BY r.fecha_creacion ASC
            ";
            
            $stmt_resp = $conexion->prepare($query_respuestas);
            $stmt_resp->execute([$tema_id]);
            $todas_respuestas = $stmt_resp->fetchAll(PDO::FETCH_ASSOC);
            
            // FILTRAR RESPUESTAS SEGÚN PRIVACIDAD Y ROL - VERSIÓN CORREGIDA
            // FILTRAR RESPUESTAS SEGÚN PRIVACIDAD Y ROL - VERSIÓN CON SUPERVISIÓN DOCENTE
            foreach ($todas_respuestas as $respuesta) {
                $mostrar = false;
                
                // 🔥 REGLAS DE VISIBILIDAD CORREGIDAS
                
                // Regla 1: ADMINISTRADOR ve todo
                if ($usuario_rol === 'Administrador') {
                    $mostrar = true;
                }
                // Regla 2: AUTOR siempre ve su propia respuesta
                elseif ($respuesta['autor_id'] == $usuario_id) {
                    $mostrar = true;
                }
                // Regla 3: RESPUESTA PÚBLICA - todos ven
                elseif (!$respuesta['es_privado']) {
                    $mostrar = true;
                }
                // Regla 4: RESPUESTA PRIVADA
                elseif ($respuesta['es_privado'] && !empty($respuesta['destinatario_id'])) {
                    $destinatarios = explode(',', $respuesta['destinatario_id']);
                    
                    // 🔥 Caso 1: DOCENTE (SUPERVISOR) - VE TODO
                    if ($usuario_rol === 'Docente') {
                        // El docente ve TODAS las respuestas privadas sin excepción
                        // Esto permite supervisar conversaciones entre estudiantes
                        $mostrar = true;
                        
                        // NOTA: Aunque el docente ve todo, los mensajes se muestran con la etiqueta "Privado"
                        // para que sepa que es una conversación que debería ser privada entre estudiantes
                    }
                    // 🔥 Caso 2: ESTUDIANTE
                    elseif ($usuario_rol === 'Estudiante') {
                        // Estudiante ve SOLO los mensajes donde es destinatario
                        if (in_array($usuario_id, $destinatarios)) {
                            $mostrar = true;
                        }
                        // NO ve mensajes privados entre otros estudiantes
                        // NO ve mensajes privados entre docentes
                    }
                }
                
                if ($mostrar) {
                    // Procesar nombres de destinatarios
                    if (!empty($respuesta['destinatario_id'])) {
                        $ids = explode(',', $respuesta['destinatario_id']);
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));
                        $stmt_nombres = $conexion->prepare("
                            SELECT id, nombre, rol FROM usuarios WHERE id IN ($placeholders)
                        ");
                        $stmt_nombres->execute($ids);
                        $respuesta['destinatarios'] = $stmt_nombres->fetchAll(PDO::FETCH_ASSOC);
                    } else {
                        $respuesta['destinatarios'] = [];
                    }
                    
                    $respuestas[] = $respuesta;
                }
            }
            
            // OBTENER USUARIOS DISPONIBLES PARA MENSAJES
            if (!empty($grado_usuario)) {
                // Estudiantes del mismo grado/sección (excluyendo al actual)
                $stmt_est = $conexion->prepare("
                    SELECT u.id, u.nombre, 'Estudiante' as rol
                    FROM usuarios u
                    INNER JOIN estudiantes e ON u.id = e.usuario_id
                    WHERE u.rol = 'Estudiante'
                    AND u.id != ?
                    AND e.grado = ? AND e.seccion = ?
                    ORDER BY u.nombre
                ");
                $stmt_est->execute([$usuario_id, $grado_usuario, $seccion_usuario]);
                $estudiantes = $stmt_est->fetchAll(PDO::FETCH_ASSOC);
                
                // Docentes del mismo grado/sección
                $stmt_doc = $conexion->prepare("
                    SELECT u.id, u.nombre, 'Docente' as rol
                    FROM usuarios u
                    INNER JOIN docentes d ON u.id = d.usuario_id
                    WHERE u.rol = 'Docente'
                    AND d.grado = ? AND d.seccion = ?
                    ORDER BY u.nombre
                ");
                $stmt_doc->execute([$grado_usuario, $seccion_usuario]);
                $docentes = $stmt_doc->fetchAll(PDO::FETCH_ASSOC);
                
                // Combinar y organizar
                $usuarios_disponibles = [
                    'docentes' => $docentes,
                    'estudiantes' => $estudiantes
                ];
            }
        }
    }
    
} catch (Exception $e) {
    error_log("❌ ERROR FORO: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Foro - SIEDUCRES</title>
    <?php require_once '../includes/favicon.php'; ?>
    <?php require_once '../includes/header_onesignal.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Tus estilos existentes + nuevos estilos */
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
            --danger: #dc3545;
            --docente-badge: #FF6B6B;
            --estudiante-badge: #4ECDC4;
        }
        
        body { font-family: 'Inter', sans-serif; background-color: var(--canvas); color: var(--text-body); min-height: 100vh; display: flex; flex-direction: column; padding-top: 60px;}
        
        /* Header */
        .header { display: flex; justify-content: space-between; align-items: center; padding: 0 24px; height: 60px; background-color: var(--canvas); border-bottom: 1px solid var(--border); }
        .logo { height: 40px; }
        .header-right { display: flex; align-items: center; gap: 16px; }
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; background-color: #F0F0F0; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .icon-btn img { width: 20px; height: 20px; }
        .menu-dropdown { position: absolute; top: 60px; right: 24px; background: white; border: 1px solid var(--border); border-radius: 8px; display: none; min-width: 180px; z-index: 1000; }
        .menu-item { padding: 10px 16px; font-size: 14px; color: var(--text-body); text-decoration: none; display: block; }
        .menu-item:hover { background-color: #F8F8F8; }
        
        /* Layout Split-Pane */
        .foro-container { display: flex; flex: 1; overflow: hidden; height: calc(100vh - 140px); }
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
        .tema-meta { font-size: 12px; color: var(--text-meta); display: flex; justify-content: space-between; }
        
        /* Content Area - Detalle del Tema */
        .tema-header { margin-bottom: 32px; }
        .tema-titulo-principal { font-size: 32px; font-weight: 700; color: var(--text-heading); margin-bottom: 12px; }
        .tema-info { display: flex; gap: 20px; font-size: 14px; color: var(--text-meta); margin-bottom: 16px; flex-wrap: wrap; }
        .tema-descripcion { font-size: 16px; color: var(--text-body); line-height: 1.6; padding: 20px; background: var(--sidebar); border-radius: 12px; }
        
        /* Respuestas - NUEVOS ESTILOS MEJORADOS */
        .respuestas-section { margin-top: 40px; }
        .respuestas-title { font-size: 24px; font-weight: 700; color: var(--text-heading); margin-bottom: 24px; padding-bottom: 12px; border-bottom: 1px solid var(--border); }
        
        .respuesta-card { 
            padding: 20px; 
            background: var(--canvas); 
            border: 1px solid var(--border); 
            border-radius: 12px; 
            margin-bottom: 16px;
            transition: box-shadow 0.2s;
        }
        .respuesta-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .respuesta-card.privada { border-left: 4px solid var(--docente-badge); }
        
        .respuesta-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: flex-start; 
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .autor-info {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .respuesta-autor { 
            font-weight: 600; 
            color: var(--text-heading);
            font-size: 16px;
        }
        
        .rol-badge {
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 20px;
            background: #f0f0f0;
            color: #666;
        }
        
        .rol-badge.docente { background: var(--docente-badge); color: white; }
        .rol-badge.estudiante { background: var(--estudiante-badge); color: white; }
        
        .respuesta-fecha { 
            font-size: 12px; 
            color: var(--text-meta);
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .badge-privado { 
            font-size: 11px;
            padding: 4px 8px; 
            border-radius: 20px;
            font-weight: 500;
        }
        .badge-privado.docentes { background: var(--docente-badge); color: white; }
        .badge-privado.companero { background: var(--estudiante-badge); color: white; }
        .badge-privado.ambos { background: #9B8AFB; color: white; }
        
        .respuesta-contenido { 
            font-size: 14px; 
            color: var(--text-body); 
            line-height: 1.6;
            margin: 12px 0;
            padding: 8px 0;
        }
        
        /* Sección de destinatarios */
        .destinatarios-section {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px dashed var(--border);
            font-size: 12px;
        }
        
        .destinatarios-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 5px;
        }
        
        .destinatario-tag {
            background: #f0f0f0;
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .destinatario-tag .rol-tag {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 12px;
            background: rgba(0,0,0,0.1);
        }
        
        /* Acciones de respuesta */
        .respuesta-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            justify-content: flex-end;
        }
        
        .btn-action {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: background 0.2s;
        }
        
        .btn-action.delete {
            background: #fee;
            color: var(--danger);
        }
        .btn-action.delete:hover { background: #fdd; }
        
        .btn-action.edit {
            background: #e6f3ff;
            color: #0066cc;
        }
        .btn-action.edit:hover { background: #d4e8ff; }
        
        .tiempo-restante {
            font-size: 11px;
            color: var(--warning);
            margin-left: 8px;
        }
        
        /* Banner */
        .banner { 
            position: relative; 
            height: 80px; 
            overflow: hidden; 
            background: linear-gradient(135deg, var(--primary), var(--secondary)); 
        }
        .banner-content { text-align: center; position: relative; z-index: 2; padding: 20px; }
        .banner-title { font-size: 28px; font-weight: 700; color: white; }
        
        /* Mensajes */
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
        
        /* Formulario de respuesta mejorado */
        .formulario-respuesta { 
            margin-top: 40px; 
            padding: 24px; 
            background: var(--sidebar); 
            border-radius: 12px; 
        }
        .formulario-title { font-size: 20px; font-weight: 700; margin-bottom: 20px; }
        
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-weight: 600; margin-bottom: 8px; color: var(--text-heading); }
        .form-textarea { 
            width: 100%; 
            padding: 12px; 
            border: 1px solid var(--border); 
            border-radius: 8px; 
            font-family: 'Inter', sans-serif; 
            font-size: 14px; 
            resize: vertical; 
            min-height: 120px; 
        }
        .form-textarea:focus { outline: none; border-color: var(--primary); }
        
        .privacidad-options { 
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }
        
        .privacidad-option { 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            padding: 12px; 
            background: var(--canvas); 
            border: 1px solid var(--border); 
            border-radius: 8px; 
            cursor: pointer;
            transition: all 0.2s;
        }
        .privacidad-option:hover { border-color: var(--primary); background: white; }
        .privacidad-option input[type="radio"] { accent-color: var(--primary); width: 18px; height: 18px; }
        
        .usuarios-selector {
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            background: var(--canvas);
        }
        
        .selector-search {
            width: 100%;
            padding: 12px;
            border: none;
            border-bottom: 1px solid var(--border);
            font-family: 'Inter', sans-serif;
        }
        
        .selector-list {
            max-height: 250px;
            overflow-y: auto;
        }
        
        .selector-item {
            display: flex;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: background 0.2s;
        }
        .selector-item:hover { background: rgba(0,206,209,0.05); }
        .selector-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-right: 12px;
        }
        
        .selector-item .rol-mini {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 12px;
            background: #f0f0f0;
            margin-left: 8px;
        }
        
        .seleccionados-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 8px;
            padding: 8px 12px;
            background: #f5f5f5;
            border-radius: 6px;
        }
        
        .btn-enviar { 
            background: var(--primary); 
            color: #000; 
            padding: 14px 24px; 
            border: none; 
            border-radius: 8px; 
            font-weight: 600; 
            cursor: pointer; 
            width: 100%; 
            font-size: 16px;
        }
        .btn-enviar:hover { opacity: 0.9; }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media (max-width: 768px) {
            .foro-container { flex-direction: column; height: auto; }
            .sidebar { width: 100%; height: 300px; }
            .content-area { width: 100%; padding: 20px; }
            .privacidad-options { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            body {
                padding-top: 60px; /* Mantén el mismo padding */
            }
            .foro-container { 
                flex-direction: column; 
                height: auto; 
            }
            .sidebar { 
                width: 100%; 
                height: 300px; 
            }
            .content-area { 
                width: 100%; 
                padding: 20px; 
            }
            .privacidad-options { 
                grid-template-columns: 1fr; 
            }
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header_comun.php'; ?>

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
                            <span>Por <?php echo htmlspecialchars($tema['autor_nombre']); ?></span>
                            <span><?php echo $tema['total_respuestas']; ?> respuestas</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="padding: 20px; text-align: center; color: var(--text-meta);">
                    No hay temas en tu grado/sección aún
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
            
            <?php if ($tema_detalle): ?>
                <!-- Detalle del Tema -->
                <div class="tema-header">
                    <h1 class="tema-titulo-principal"><?php echo htmlspecialchars($tema_detalle['titulo']); ?></h1>
                    <div class="tema-info">
                        <span>👤 <?php echo htmlspecialchars($tema_detalle['autor_nombre']); ?></span>
                        <span>📅 <?php echo date('d/m/Y H:i', strtotime($tema_detalle['fecha_creacion'])); ?></span>
                        <span>📚 Grado <?php echo $tema_detalle['grado']; ?> - Sección <?php echo $tema_detalle['seccion']; ?></span>
                    </div>
                    <div class="tema-descripcion">
                        <?php echo nl2br(htmlspecialchars($tema_detalle['descripcion'])); ?>
                    </div>
                </div>

                <!-- Sección de Respuestas -->
                <div class="respuestas-section">
                    <h2 class="respuestas-title">💬 Respuestas (<?php echo count($respuestas); ?>)</h2>
                    
                    <?php if (count($respuestas) > 0): ?>
                        <?php foreach ($respuestas as $respuesta): ?>
                            <?php
                            // Determinar si el usuario actual puede borrar esta respuesta
                            $puede_borrar = false;
                            
                            if ($usuario_rol === 'Administrador' || $usuario_rol === 'Docente') {
                                $puede_borrar = true; // Admin y Docentes pueden borrar cualquier cosa
                            } elseif ($usuario_rol === 'Estudiante' && $respuesta['autor_id'] == $usuario_id) {
                                // Estudiante solo puede borrar en los primeros 15 minutos
                                if ($respuesta['puede_editar_tiempo']) {
                                    $puede_borrar = true;
                                }
                            }
                            
                            
                            // Calcular tiempo restante para estudiantes - VERSIÓN ULTRA SEGURA
                            $tiempo_restante = '';
                            if ($usuario_rol === 'Estudiante' && $respuesta['autor_id'] == $usuario_id) {
                                
                                // Verificar que puede editar
                                $puede_editar = isset($respuesta['puede_editar_tiempo']) ? $respuesta['puede_editar_tiempo'] : false;
                                
                                if ($puede_editar && isset($respuesta['segundos_restantes'])) {
                                    
                                    // Convertir a entero de manera segura
                                    $segundos_totales = 0;
                                    if (is_numeric($respuesta['segundos_restantes'])) {
                                        $segundos_totales = (int)$respuesta['segundos_restantes'];
                                    }
                                    
                                    // Asegurar que no sea negativo
                                    $segundos_totales = max(0, $segundos_totales);
                                    
                                    if ($segundos_totales > 0) {
                                        // Calcular tiempo
                                        $minutos = floor($segundos_totales / 60);
                                        $segundos_rest = $segundos_totales % 60;
                                        
                                        // Formatear
                                        $tiempo_restante = sprintf("⏱️ %d:%02d min restantes", $minutos, $segundos_rest);
                                    }
                                }
                            }
                            ?>
                            
                            <div class="respuesta-card <?php echo $respuesta['es_privado'] ? 'privada' : ''; ?>" id="respuesta-<?php echo $respuesta['id']; ?>">
                                <div class="respuesta-header">
                                    <div class="autor-info">
                                        <span class="respuesta-autor"><?php echo htmlspecialchars($respuesta['autor_nombre']); ?></span>
                                        
                                        <?php
                                        // Determinar rol del autor
                                        $stmt_rol = $conexion->prepare("SELECT rol FROM usuarios WHERE id = ?");
                                        $stmt_rol->execute([$respuesta['autor_id']]);
                                        $autor_rol = $stmt_rol->fetchColumn();
                                        ?>
                                        <span class="rol-badge <?php echo strtolower($autor_rol); ?>">
                                            <?php echo $autor_rol; ?>
                                        </span>
                                        
                                        <!-- BADGES DE PRIVACIDAD -->
                                        <?php if ($respuesta['es_privado']): ?>
                                            <span class="badge-privado <?php echo $respuesta['destinatario_tipo']; ?>">
                                                <?php 
                                                if ($respuesta['destinatario_tipo'] == 'docente') echo '👥 Solo Docentes';
                                                elseif ($respuesta['destinatario_tipo'] == 'companero') echo '🔒 Mensaje Privado';
                                                elseif ($respuesta['destinatario_tipo'] == 'ambos') echo '👥 Docentes + Compañero';
                                                ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="respuesta-fecha">
                                        <span>📅 <?php echo date('d/m/Y H:i:s', strtotime($respuesta['fecha_creacion'])); ?></span>
                                        <?php if ($tiempo_restante): ?>
                                            <span class="tiempo-restante"><?php echo $tiempo_restante; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="respuesta-contenido">
                                    <?php echo nl2br(htmlspecialchars($respuesta['contenido'])); ?>
                                </div>
                                
                                <!-- MOSTRAR DESTINATARIOS (si es privado) -->
                                <?php if ($respuesta['es_privado'] && !empty($respuesta['destinatarios'])): ?>
                                    <div class="destinatarios-section">
                                        <small style="color: var(--text-meta);">📨 Enviado a:</small>
                                        <div class="destinatarios-list">
                                            <?php foreach ($respuesta['destinatarios'] as $dest): ?>
                                                <span class="destinatario-tag">
                                                    <?php echo htmlspecialchars($dest['nombre']); ?>
                                                    <span class="rol-tag <?php echo strtolower($dest['rol']); ?>">
                                                        <?php echo $dest['rol'] == 'Docente' ? '👨‍🏫' : '👨‍🎓'; ?>
                                                    </span>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- ACCIONES DE LA RESPUESTA -->
                                <?php if ($puede_borrar): ?>
                                    <div class="respuesta-actions">
                                        <form action="borrar_respuesta.php" method="POST" style="display: inline;" 
                                              onsubmit="return confirm('¿Estás seguro de eliminar esta respuesta? Esta acción no se puede deshacer.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="respuesta_id" value="<?php echo $respuesta['id']; ?>">
                                            <input type="hidden" name="tema_id" value="<?php echo $tema_id; ?>">
                                            <button type="submit" class="btn-action delete">
                                                🗑️ Eliminar
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: var(--text-meta);">
                            💭 No hay respuestas aún. ¡Sé el primero en comentar!
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Formulario de Respuesta (solo para estudiantes y docentes del mismo grado/sección) -->
                <?php if (in_array($usuario_rol, ['Estudiante', 'Docente'])): ?>
                <div class="formulario-respuesta">
                    <h3 class="formulario-title">📝 Escribir Respuesta</h3>
                    <form action="procesar_respuesta.php" method="POST" id="form-respuesta">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="tema_id" value="<?php echo $tema_id; ?>">
                        
                        <!-- 🔥 CAMPOS OCULTOS -->
                        <input type="hidden" name="companero_ids" id="companero_ids" value="">
                        <input type="hidden" name="docente_ids" id="docente_ids" value="">
                        <input type="hidden" name="privacidad" id="privacidad_seleccionada" value="publico">
                        
                        <div class="form-group">
                            <label class="form-label">Mensaje</label>
                            <textarea name="contenido" class="form-textarea" placeholder="Escribe tu respuesta aquí..." required></textarea>
                        </div>
                        
                        <!-- 🔥 NUEVO: SOLO 2 OPCIONES DE PRIVACIDAD -->
                        <div class="form-group">
                            <label class="form-label">Tipo de mensaje</label>
                            <div class="privacidad-options" style="grid-template-columns: 1fr 1fr;">
                                <label class="privacidad-option <?php echo (!isset($_GET['privado']) ? 'active' : ''); ?>" id="opcion-publico">
                                    <input type="radio" name="tipo_mensaje" value="publico" checked onchange="cambiarTipoMensaje('publico')">
                                    <span>🌍 <strong>Público</strong><br><small>Todos los participantes ven este mensaje</small></span>
                                </label>
                                
                                <label class="privacidad-option" id="opcion-privado">
                                    <input type="radio" name="tipo_mensaje" value="privado" onchange="cambiarTipoMensaje('privado')">
                                    <span>🔒 <strong>Privado</strong><br><small>Solo las personas que elijas</small></span>
                                </label>
                            </div>
                        </div>
                        
                        <!-- 🔥 SELECTOR DE DESTINATARIOS (se muestra solo cuando es privado) -->
                        <div id="selector-destinatarios" style="display: none;">
                            <div class="form-group">
                                <label class="form-label">📨 Enviar a:</label>
                                
                                <!-- Pestañas para cambiar entre estudiantes y docentes -->
                                <div style="display: flex; gap: 10px; margin-bottom: 15px; border-bottom: 1px solid var(--border);">
                                    <button type="button" class="tab-btn active" id="tab-estudiantes" onclick="cambiarTab('estudiantes')" style="flex:1; padding: 10px; background: none; border: none; border-bottom: 3px solid var(--primary); font-weight: 600; cursor: pointer;">
                                        👨‍🎓 Estudiantes
                                    </button>
                                    <?php if (!empty($usuarios_disponibles['docentes'])): ?>
                                    <button type="button" class="tab-btn" id="tab-docentes" onclick="cambiarTab('docentes')" style="flex:1; padding: 10px; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer;">
                                        👨‍🏫 Docentes
                                    </button>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Lista de Estudiantes -->
                                <div id="lista-estudiantes" class="usuarios-lista" style="display: block;">
                                    <?php if (!empty($usuarios_disponibles['estudiantes'])): ?>
                                        <input type="text" id="buscar-estudiantes" class="selector-search" 
                                            placeholder="Buscar estudiante..." onkeyup="filtrarLista('estudiantes')" style="margin-bottom: 10px;">
                                        
                                        <div style="max-height: 250px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px;">
                                            <?php foreach ($usuarios_disponibles['estudiantes'] as $est): ?>
                                                <label class="selector-item" data-nombre="<?php echo strtolower(htmlspecialchars($est['nombre'])); ?>">
                                                    <input type="checkbox" class="destinatario-checkbox" data-tipo="estudiante" value="<?php echo $est['id']; ?>" onchange="actualizarSeleccionados()">
                                                    <span><?php echo htmlspecialchars($est['nombre']); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p style="text-align: center; padding: 20px; color: #999;">No hay otros estudiantes disponibles</p>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Lista de Docentes -->
                                <div id="lista-docentes" class="usuarios-lista" style="display: none;">
                                    <?php if (!empty($usuarios_disponibles['docentes'])): ?>
                                        <input type="text" id="buscar-docentes" class="selector-search" 
                                            placeholder="Buscar docente..." onkeyup="filtrarLista('docentes')" style="margin-bottom: 10px;">
                                        
                                        <div style="max-height: 250px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px;">
                                            <?php foreach ($usuarios_disponibles['docentes'] as $doc): ?>
                                                <?php if ($doc['id'] != $usuario_id): ?>
                                                    <label class="selector-item" data-nombre="<?php echo strtolower(htmlspecialchars($doc['nombre'])); ?>">
                                                        <input type="checkbox" class="destinatario-checkbox" data-tipo="docente" value="<?php echo $doc['id']; ?>" onchange="actualizarSeleccionados()">
                                                        <span><?php echo htmlspecialchars($doc['nombre']); ?></span>
                                                        <span class="rol-mini">Docente</span>
                                                    </label>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p style="text-align: center; padding: 20px; color: #999;">No hay docentes disponibles</p>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Resumen de seleccionados -->
                                <div class="seleccionados-info" style="margin-top: 15px; background: #e8f4fd;">
                                    <div>
                                        <span id="total-seleccionados">0</span> persona(s) seleccionada(s)
                                        <span id="detalle-seleccionados" style="font-size: 11px; color: #666; margin-left: 10px;"></span>
                                    </div>
                                    <button type="button" onclick="limpiarTodo()" style="background: none; border: none; color: var(--primary); cursor: pointer;">
                                        Limpiar todo
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Info adicional -->
                            <div class="form-group" style="background: #fff3cd; padding: 10px; border-radius: 8px; font-size: 13px;">
                                <small>
                                    📌 <strong>Nota:</strong> 
                                    <?php if ($usuario_rol === 'Docente'): ?>
                                        Como docente, podrás ver todos los mensajes privados entre docentes.
                                        Los estudiantes solo verán mensajes donde sean destinatarios.
                                    <?php else: ?>
                                        Los docentes pueden ver todos los mensajes privados.
                                        Otros estudiantes solo verán este mensaje si son destinatarios.
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn-enviar" style="margin-top: 20px;">📤 Enviar Respuesta</button>
                    </form>
                </div>

                <style>
                    .privacidad-option {
                        text-align: center;
                        padding: 15px;
                    }
                    .privacidad-option.active {
                        border-color: var(--primary);
                        background: rgba(0,206,209,0.1);
                    }
                    .privacidad-option input[type="radio"] {
                        display: none;
                    }
                    .tab-btn.active {
                        color: var(--primary);
                        font-weight: 600;
                    }
                    .selector-item {
                        display: flex;
                        align-items: center;
                        padding: 10px;
                        border-bottom: 1px solid var(--border);
                        cursor: pointer;
                        transition: background 0.2s;
                    }
                    .selector-item:hover {
                        background: rgba(0,206,209,0.05);
                    }
                    .selector-item input[type="checkbox"] {
                        width: 18px;
                        height: 18px;
                        margin-right: 12px;
                    }
                    .seleccionados-info {
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        padding: 10px 15px;
                        background: #f0f7ff;
                        border-radius: 8px;
                        font-size: 14px;
                    }
                </style>

                <script>
                    // Variables globales
                    let seleccionados = {
                        estudiantes: new Set(),
                        docentes: new Set()
                    };
                    
                    // Cambiar entre público/privado
                    function cambiarTipoMensaje(tipo) {
                        const selector = document.getElementById('selector-destinatarios');
                        const privacidadInput = document.getElementById('privacidad_seleccionada');
                        
                        // Actualizar estilos de las opciones
                        document.getElementById('opcion-publico').classList.toggle('active', tipo === 'publico');
                        document.getElementById('opcion-privado').classList.toggle('active', tipo === 'privado');
                        
                        if (tipo === 'privado') {
                            selector.style.display = 'block';
                            privacidadInput.value = 'privado';
                        } else {
                            selector.style.display = 'none';
                            privacidadInput.value = 'publico';
                            // Limpiar selecciones si cambia a público
                            limpiarTodo();
                        }
                    }
                    
                    // Cambiar entre pestañas de estudiantes/docentes
                    function cambiarTab(tab) {
                        // Actualizar pestañas
                        document.getElementById('tab-estudiantes').classList.toggle('active', tab === 'estudiantes');
                        document.getElementById('tab-estudiantes').style.borderBottomColor = tab === 'estudiantes' ? 'var(--primary)' : 'transparent';
                        
                        const tabDocentes = document.getElementById('tab-docentes');
                        if (tabDocentes) {
                            tabDocentes.classList.toggle('active', tab === 'docentes');
                            tabDocentes.style.borderBottomColor = tab === 'docentes' ? 'var(--primary)' : 'transparent';
                        }
                        
                        // Mostrar lista correspondiente
                        document.getElementById('lista-estudiantes').style.display = tab === 'estudiantes' ? 'block' : 'none';
                        if (document.getElementById('lista-docentes')) {
                            document.getElementById('lista-docentes').style.display = tab === 'docentes' ? 'block' : 'none';
                        }
                    }
                    
                    // Filtrar listas
                    function filtrarLista(tipo) {
                        const busqueda = document.getElementById(`buscar-${tipo}`).value.toLowerCase();
                        const items = document.querySelectorAll(`#lista-${tipo} .selector-item`);
                        
                        items.forEach(item => {
                            const nombre = item.getAttribute('data-nombre');
                            item.style.display = nombre.includes(busqueda) ? 'flex' : 'none';
                        });
                    }
                    
                    // Actualizar seleccionados
                    function actualizarSeleccionados() {
                        // Limpiar sets
                        seleccionados.estudiantes.clear();
                        seleccionados.docentes.clear();
                        
                        // Recoger checkboxes marcados
                        document.querySelectorAll('.destinatario-checkbox:checked').forEach(cb => {
                            const tipo = cb.getAttribute('data-tipo');
                            if (tipo === 'estudiante') {
                                seleccionados.estudiantes.add(cb.value);
                            } else {
                                seleccionados.docentes.add(cb.value);
                            }
                        });
                        
                        // Actualizar campos ocultos
                        document.getElementById('companero_ids').value = Array.from(seleccionados.estudiantes).join(',');
                        document.getElementById('docente_ids').value = Array.from(seleccionados.docentes).join(',');
                        
                        // Actualizar contador y detalle
                        const total = seleccionados.estudiantes.size + seleccionados.docentes.size;
                        document.getElementById('total-seleccionados').textContent = total;
                        
                        let detalle = [];
                        if (seleccionados.estudiantes.size > 0) detalle.push(`${seleccionados.estudiantes.size} estudiante(s)`);
                        if (seleccionados.docentes.size > 0) detalle.push(`${seleccionados.docentes.size} docente(s)`);
                        
                        document.getElementById('detalle-seleccionados').textContent = detalle.length > 0 ? `(${detalle.join(', ')})` : '';
                    }
                    
                    // Limpiar todo
                    function limpiarTodo() {
                        document.querySelectorAll('.destinatario-checkbox').forEach(cb => {
                            cb.checked = false;
                        });
                        actualizarSeleccionados();
                    }
                    
                    // Validar formulario antes de enviar
                    document.getElementById('form-respuesta').addEventListener('submit', function(e) {
                        const tipo = document.querySelector('input[name="tipo_mensaje"]:checked').value;
                        
                        if (tipo === 'privado') {
                            const totalSeleccionados = seleccionados.estudiantes.size + seleccionados.docentes.size;
                            
                            if (totalSeleccionados === 0) {
                                e.preventDefault();
                                alert('❌ Debes seleccionar al menos una persona para el mensaje privado');
                                return false;
                            }
                            
                            // Confirmar envío
                            let mensaje = `📨 Enviar mensaje privado a:\n`;
                            if (seleccionados.estudiantes.size > 0) mensaje += `- ${seleccionados.estudiantes.size} estudiante(s)\n`;
                            if (seleccionados.docentes.size > 0) mensaje += `- ${seleccionados.docentes.size} docente(s)\n`;
                            mensaje += `\n¿Continuar?`;
                            
                            if (!confirm(mensaje)) {
                                e.preventDefault();
                                return false;
                            }
                        }
                        
                        return true;
                    });
                </script>
                <?php endif; ?>
                
            <?php else: ?>
                <!-- Mensaje cuando no hay tema seleccionado -->
                <div class="no-seleccion">
                    <div style="font-size: 64px; margin-bottom: 20px;">💬</div>
                    <h2 style="margin-bottom: 8px;">Selecciona un tema</h2>
                    <p style="color: var(--text-meta);">Haz clic en un tema de la lista para ver las respuestas y participar</p>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // Variables globales para selecciones
        let seleccionados = {
            companeros: new Set(),
            docentes: new Set()
        };
        
        // Función para filtrar usuarios
        function filtrarUsuarios(tipo) {
            const busqueda = document.getElementById(`buscar-${tipo}`).value.toLowerCase();
            const items = document.querySelectorAll(`#lista-${tipo} .selector-item`);
            
            items.forEach(item => {
                const nombre = item.getAttribute('data-nombre');
                item.style.display = nombre.includes(busqueda) ? 'flex' : 'none';
            });
        }
        
        // Función para actualizar seleccionados
        function actualizarSeleccionados(tipo) {
            const checkboxes = document.querySelectorAll(`.${tipo}-checkbox:checked`);
            seleccionados[tipo].clear();
            
            checkboxes.forEach(cb => {
                seleccionados[tipo].add(cb.value);
            });
            
            // Actualizar contador
            const contador = document.getElementById(`contador-${tipo}`);
            if (contador) contador.textContent = seleccionados[tipo].size;
            
            // 🔥 Actualizar campo oculto (ahora siempre visible)
            const idsArray = Array.from(seleccionados[tipo]);
            const hiddenField = document.getElementById(`${tipo}_ids`);
            if (hiddenField) {
                hiddenField.value = idsArray.join(',');
                console.log(`${tipo} IDs actualizados:`, hiddenField.value);
            }
        }
        
        // Función para limpiar selección
        function limpiarSeleccion(tipo) {
            document.querySelectorAll(`.${tipo}-checkbox`).forEach(cb => {
                cb.checked = false;
            });
            actualizarSeleccionados(tipo);
        }
        
        // Mostrar/ocultar selectores según privacidad
        document.querySelectorAll('input[name="privacidad"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const selectorCompaneros = document.getElementById('selector-companeros');
                const selectorDocentes = document.getElementById('selector-docentes');
                const companerosLabel = document.getElementById('companeros-label');
                
                // Ocultar todo primero
                if (selectorCompaneros) selectorCompaneros.style.display = 'none';
                if (selectorDocentes) selectorDocentes.style.display = 'none';
                
                if (this.value === 'companero') {
                    if (selectorCompaneros) {
                        selectorCompaneros.style.display = 'block';
                        if (companerosLabel) companerosLabel.textContent = 'Seleccionar Estudiante(s)';
                    }
                } else if (this.value === 'docente') {
                    if (selectorDocentes) selectorDocentes.style.display = 'block';
                } else if (this.value === 'ambos') {
                    if (selectorCompaneros) selectorCompaneros.style.display = 'block';
                    if (selectorDocentes) selectorDocentes.style.display = 'block';
                    if (companerosLabel) companerosLabel.textContent = 'Seleccionar Estudiante (visible para él/ella)';
                }
                
                console.log('Privacidad cambiada a:', this.value);
            });
        });
        
        // Validar formulario antes de enviar
        document.getElementById('form-respuesta').addEventListener('submit', function(e) {
            const privacidad = document.querySelector('input[name="privacidad"]:checked');
            if (!privacidad) {
                e.preventDefault();
                alert('Debes seleccionar un tipo de privacidad');
                return false;
            }
            
            const privacidadValue = privacidad.value;
            const companeroIds = document.getElementById('companero_ids') ? document.getElementById('companero_ids').value : '';
            const docenteIds = document.getElementById('docente_ids') ? document.getElementById('docente_ids').value : '';
            
            console.log('=== VALIDACIÓN FINAL ===');
            console.log('Privacidad:', privacidadValue);
            console.log('Compañeros IDs:', companeroIds);
            console.log('Docentes IDs:', docenteIds);
            
            if (privacidadValue === 'companero' && (!companeroIds || companeroIds.trim() === '')) {
                e.preventDefault();
                alert('❌ Debes seleccionar al menos un estudiante');
                return false;
            }
            
            if (privacidadValue === 'docente' && (!docenteIds || docenteIds.trim() === '')) {
                e.preventDefault();
                alert('❌ Debes seleccionar al menos un docente');
                return false;
            }
            
            if (privacidadValue === 'ambos') {
                if (!companeroIds || companeroIds.trim() === '') {
                    e.preventDefault();
                    alert('❌ Debes seleccionar un estudiante');
                    return false;
                }
                if (!docenteIds || docenteIds.trim() === '') {
                    e.preventDefault();
                    alert('❌ Debes seleccionar al menos un docente');
                    return false;
                }
            }
            
            console.log('✅ Validación exitosa, enviando formulario...');
            return true;
        });
        
        // Menú hamburguesa
        document.addEventListener('DOMContentLoaded', function() {
            const menuToggle = document.getElementById('menu-toggle');
            if (menuToggle) {
                menuToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const dropdown = document.getElementById('dropdown');
                    if (dropdown) {
                        dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
                    }
                });
                
                document.addEventListener('click', function(e) {
                    const dropdown = document.getElementById('dropdown');
                    const toggle = document.getElementById('menu-toggle');
                    if (dropdown && toggle && !toggle.contains(e.target) && !dropdown.contains(e.target)) {
                        dropdown.style.display = 'none';
                    }
                });
            }
        });
    </script>
    
</body>
</html>
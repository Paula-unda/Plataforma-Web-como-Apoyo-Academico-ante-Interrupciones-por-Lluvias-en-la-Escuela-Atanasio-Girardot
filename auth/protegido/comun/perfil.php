<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/onesignal_config.php';

header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!sesionActiva() || !in_array($_SESSION['usuario_rol'], ['Estudiante', 'Docente', 'Administrador', 'Representante'])) {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$usuario_rol = $_SESSION['usuario_rol'];
$usuario_nombre = $_SESSION['usuario_nombre'];

$datos_usuario = [];
$datos_especificos = [];

try {
    $conexion = getConexion();
    
    // Obtener datos básicos del usuario
    $stmt = $conexion->prepare("
        SELECT id, nombre, correo, rol, 
               TO_CHAR(creado_en, 'DD/MM/YYYY HH24:MI') as fecha_registro,
               external_id_onesignal
        FROM usuarios 
        WHERE id = ?
    ");
    $stmt->execute([$usuario_id]);
    $datos_usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Obtener datos específicos según el rol
    if ($usuario_rol === 'Estudiante') {
        $stmt_esp = $conexion->prepare("
            SELECT e.grado, e.seccion,
                   (SELECT COUNT(*) FROM progreso_contenido WHERE estudiante_id = e.usuario_id AND completado = true) as contenidos_completados,
                   (SELECT COUNT(*) FROM entregas_estudiantes WHERE estudiante_id = e.usuario_id) as total_entregas,
                   (SELECT ROUND(AVG(calificacion), 2) FROM entregas_estudiantes WHERE estudiante_id = e.usuario_id AND calificacion IS NOT NULL) as promedio_calificaciones
            FROM estudiantes e
            WHERE e.usuario_id = ?
        ");
        $stmt_esp->execute([$usuario_id]);
        $datos_especificos = $stmt_esp->fetch(PDO::FETCH_ASSOC);
        
    } elseif ($usuario_rol === 'Docente') {
        $stmt_esp = $conexion->prepare("
            SELECT d.grado, d.seccion,
                   (SELECT COUNT(*) FROM actividades WHERE docente_id = d.usuario_id) as total_actividades,
                   (SELECT COUNT(*) FROM contenidos WHERE docente_id = d.usuario_id) as total_contenidos
            FROM docentes d
            WHERE d.usuario_id = ?
        ");
        $stmt_esp->execute([$usuario_id]);
        $datos_especificos = $stmt_esp->fetch(PDO::FETCH_ASSOC);
        
    } elseif ($usuario_rol === 'Representante') {
        $stmt_esp = $conexion->prepare("
            SELECT COUNT(*) as total_estudiantes
            FROM representantes_estudiantes
            WHERE representante_id = ?
        ");
        $stmt_esp->execute([$usuario_id]);
        $total_estudiantes = $stmt_esp->fetchColumn();
        $datos_especificos = ['total_estudiantes' => $total_estudiantes];
        
        // Obtener lista de estudiantes asociados
        $stmt_estudiantes = $conexion->prepare("
            SELECT u.nombre, u.correo, e.grado, e.seccion
            FROM representantes_estudiantes re
            INNER JOIN usuarios u ON re.estudiante_id = u.id
            INNER JOIN estudiantes e ON u.id = e.usuario_id
            WHERE re.representante_id = ?
            ORDER BY u.nombre
        ");
        $stmt_estudiantes->execute([$usuario_id]);
        $datos_especificos['estudiantes'] = $stmt_estudiantes->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (Exception $e) {
    error_log("Error en perfil: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Mi Perfil - SIEDUCRES</title>
    <?php require_once '../includes/favicon.php'; ?>
    <?php require_once '../includes/header_onesignal.php'; ?>
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
            --primary-lime: #c3d54dff;
            --primary-purple: #9B8AFB;
            --white: #FFFFFF;
            --canvas-bg: #F5F5F5;
            --text-main: #000000;
            --text-muted: #666666;
            --border-light: #E0E0E0;
        }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--canvas-bg); 
            color: var(--text-main); 
            min-height: 100vh; 
            padding-top: 60px;
            margin: 0;
        }
        
        /* Banner con color sólido */
        .banner { 
            height: 100px; 
            background-color: var(--primary-cyan); /* Color sólido en lugar de degradado */
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .banner-content { 
            text-align: center; 
            padding: 0 20px; 
        }
        
        .banner-title { 
            font-size: clamp(24px, 5vw, 32px); 
            font-weight: 700; 
            color: var(--white); 
            margin: 0;
        }
        
        /* Contenedor principal */
        .perfil-container {
            max-width: 1000px;
            margin: 0 auto 40px;
            padding: 0 20px;
        }
        
        /* Tarjeta de perfil - más redondeada */
        .perfil-card {
            background: var(--white);
            border-radius: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        /* Header del perfil - color sólido */
        .perfil-header {
            background-color: var(--primary-pink); /* Rosa sólido */
            padding: 30px;
            color: var(--white);
            position: relative;
            display: flex;
            align-items: center;
            gap: 25px;
            flex-wrap: wrap;
        }
        
        /* Avatar con borde redondeado */
        .perfil-avatar {
            width: 100px;
            height: 100px;
            background-color: var(--white);
            border-radius: 50%; /* Completamente redondo */
            display: flex;
            align-items: center;
            justify-content: center;
            border: 3px solid var(--white);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .perfil-avatar img {
            width: 60px;
            height: 60px;
            object-fit: contain;
        }
        
        .perfil-info {
            flex: 1;
        }
        
        .perfil-nombre {
            font-size: clamp(24px, 4vw, 32px);
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--white);
        }
        
        .perfil-rol {
            display: inline-block;
            background-color: rgba(255,255,255,0.2);
            padding: 6px 20px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 500;
            color: var(--white);
            backdrop-filter: blur(5px);
        }
        
        .perfil-body {
            padding: 30px;
        }
        
        /* Grid de información */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .info-section {
            background-color: var(--canvas-bg);
            border-radius: 16px;
            padding: 20px;
        }
        
        .info-section-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-cyan);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .info-item {
            display: flex;
            margin-bottom: 15px;
            padding: 8px 0;
            border-bottom: 1px dashed var(--border-light);
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            width: 120px;
            font-weight: 500;
            color: var(--text-muted);
        }
        
        .info-value {
            flex: 1;
            color: var(--text-main);
            font-weight: 500;
        }
        
        /* Estadísticas */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .stat-card {
            background-color: var(--white);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid var(--border-light);
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-pink);
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 13px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Tabla de estudiantes */
        .estudiantes-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .estudiantes-table th {
            text-align: left;
            padding: 12px;
            background-color: var(--primary-cyan);
            color: var(--white);
            font-weight: 600;
            font-size: 14px;
        }
        
        .estudiantes-table td {
            padding: 12px;
            border-bottom: 1px solid var(--border-light);
            background-color: var(--white);
        }
        
        .estudiantes-table tr:hover td {
            background-color: rgba(75, 196, 231, 0.05);
        }
        
        /* Mensajes */
        .mensaje-info {
            background-color: var(--primary-lime);
            color: var(--text-main);
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            opacity: 0.9;
        }
        
        /* Botones de acción - redondeados y sin borde negro */
        .acciones {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 50px; /* Muy redondeados */
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .btn-primary {
            background-color: var(--primary-cyan);
            color: var(--white);
        }
        
        .btn-primary:hover {
            background-color: #3aa9cc; /* Versión más oscura */
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(75, 196, 231, 0.3);
        }
        
        .btn-secondary {
            background-color: var(--white);
            color: var(--text-main);
            border: 1px solid var(--border-light);
        }
        
        .btn-secondary:hover {
            background-color: var(--canvas-bg);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .perfil-header {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }
            
            .perfil-body {
                padding: 20px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .info-item {
                flex-direction: column;
            }
            
            .info-label {
                width: 100%;
                margin-bottom: 5px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .acciones {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .estudiantes-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .perfil-avatar {
                width: 80px;
                height: 80px;
            }
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header_comun.php'; ?>

    <!-- Banner con color sólido -->
    <div class="banner">
        <div class="banner-content">
            <h1 class="banner-title">Mi Perfil</h1>
        </div>
    </div>

    <!-- Contenido principal -->
    <div class="perfil-container">
        
        <?php if (!$datos_usuario): ?>
            <div class="mensaje-info">
                No se pudieron cargar los datos del perfil. Por favor, intenta más tarde.
            </div>
        <?php else: ?>
        
        <!-- Tarjeta de perfil -->
        <div class="perfil-card">
            <div class="perfil-header">
                <div class="perfil-avatar">
                    <img src="../../../assets/icon-user.svg" alt="Avatar" onerror="this.src='https://via.placeholder.com/60x60?text=👤'">
                </div>
                <div class="perfil-info">
                    <h1 class="perfil-nombre"><?php echo htmlspecialchars($datos_usuario['nombre']); ?></h1>
                    <span class="perfil-rol"><?php echo htmlspecialchars($datos_usuario['rol']); ?></span>
                </div>
            </div>
            
            <div class="perfil-body">
                <!-- Grid de información -->
                <div class="info-grid">
                    <!-- Información básica -->
                    <div class="info-section">
                        <h3 class="info-section-title">
                            <span>📋</span> Información Básica
                        </h3>
                        
                        <div class="info-item">
                            <span class="info-label">Correo:</span>
                            <span class="info-value"><?php echo htmlspecialchars($datos_usuario['correo']); ?></span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Rol:</span>
                            <span class="info-value"><?php echo htmlspecialchars($datos_usuario['rol']); ?></span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Registro:</span>
                            <span class="info-value"><?php echo htmlspecialchars($datos_usuario['fecha_registro']); ?></span>
                        </div>
                    </div>
                    
                    <!-- Información específica según rol -->
                    <div class="info-section">
                        <h3 class="info-section-title">
                            <?php if ($usuario_rol === 'Estudiante'): ?>
                                <span>🎓</span> Información Académica
                            <?php elseif ($usuario_rol === 'Docente'): ?>
                                <span>👨‍🏫</span> Información Docente
                            <?php elseif ($usuario_rol === 'Representante'): ?>
                                <span>👪</span> Información de Representante
                            <?php else: ?>
                                <span>⚙️</span> Información del Sistema
                            <?php endif; ?>
                        </h3>
                        
                        <?php if ($usuario_rol === 'Estudiante' && $datos_especificos): ?>
                            <div class="info-item">
                                <span class="info-label">Grado:</span>
                                <span class="info-value"><?php echo htmlspecialchars($datos_especificos['grado'] ?? 'No asignado'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Sección:</span>
                                <span class="info-value"><?php echo htmlspecialchars($datos_especificos['seccion'] ?? 'No asignada'); ?></span>
                            </div>
                            
                        <?php elseif ($usuario_rol === 'Docente' && $datos_especificos): ?>
                            <div class="info-item">
                                <span class="info-label">Grado:</span>
                                <span class="info-value"><?php echo htmlspecialchars($datos_especificos['grado'] ?? 'No asignado'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Sección:</span>
                                <span class="info-value"><?php echo htmlspecialchars($datos_especificos['seccion'] ?? 'No asignada'); ?></span>
                            </div>
                            
                        <?php elseif ($usuario_rol === 'Representante' && isset($datos_especificos['total_estudiantes'])): ?>
                            <div class="info-item">
                                <span class="info-label">Estudiantes a cargo:</span>
                                <span class="info-value"><?php echo $datos_especificos['total_estudiantes']; ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Estadísticas -->
                <?php if ($usuario_rol === 'Estudiante' && $datos_especificos): ?>
                <div class="info-section">
                    <h3 class="info-section-title">
                        <span>📊</span> Estadísticas de Aprendizaje
                    </h3>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $datos_especificos['contenidos_completados'] ?? 0; ?></div>
                            <div class="stat-label">Contenidos Completados</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $datos_especificos['total_entregas'] ?? 0; ?></div>
                            <div class="stat-label">Actividades Entregadas</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $datos_especificos['promedio_calificaciones'] ?? 'N/A'; ?></div>
                            <div class="stat-label">Promedio General</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($usuario_rol === 'Docente' && $datos_especificos): ?>
                <div class="info-section">
                    <h3 class="info-section-title">
                        <span>📚</span> Contenido Creado
                    </h3>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $datos_especificos['total_actividades'] ?? 0; ?></div>
                            <div class="stat-label">Actividades</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $datos_especificos['total_contenidos'] ?? 0; ?></div>
                            <div class="stat-label">Contenidos</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Lista de estudiantes para representantes -->
                <?php if ($usuario_rol === 'Representante' && !empty($datos_especificos['estudiantes'])): ?>
                <div class="info-section">
                    <h3 class="info-section-title">
                        <span>👨‍🎓</span> Estudiantes a mi cargo
                    </h3>
                    
                    <table class="estudiantes-table">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Correo</th>
                                <th>Grado</th>
                                <th>Sección</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($datos_especificos['estudiantes'] as $estudiante): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($estudiante['nombre']); ?></td>
                                <td><?php echo htmlspecialchars($estudiante['correo']); ?></td>
                                <td><?php echo htmlspecialchars($estudiante['grado']); ?></td>
                                <td><?php echo htmlspecialchars($estudiante['seccion']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                
                <!-- Acciones (sin cambiar contraseña) -->
                <div class="acciones">
                    <a href="javascript:history.back()" class="btn btn-primary">
                        Volver
                    </a>
                </div>
            </div>
        </div>
        
        <?php endif; ?>
    </div>
</body>
</html>
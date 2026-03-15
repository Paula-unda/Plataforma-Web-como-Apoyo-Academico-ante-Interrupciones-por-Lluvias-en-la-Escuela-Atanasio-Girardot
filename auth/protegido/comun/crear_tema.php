<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/onesignal_config.php';

// ✅ Ambos roles pueden crear temas
if (!sesionActiva() || !in_array($_SESSION['usuario_rol'], ['Estudiante', 'Docente', 'Administrador'])) {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$usuario_rol = $_SESSION['usuario_rol'];

// 🔥 Obtener grado y sección del usuario actual
$grado_usuario = '';
$seccion_usuario = '';

try {
    $conexion = getConexion();
    
    if ($usuario_rol === 'Estudiante') {
        $stmt = $conexion->prepare("SELECT grado, seccion FROM estudiantes WHERE usuario_id = ?");
        $stmt->execute([$usuario_id]);
        $datos = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($datos) {
            $grado_usuario = $datos['grado'];
            $seccion_usuario = $datos['seccion'];
        }
    } elseif ($usuario_rol === 'Docente') {
        // 🔥 AHORA los docentes también obtienen su grado/sección de la BD
        $stmt = $conexion->prepare("SELECT grado, seccion FROM docentes WHERE usuario_id = ?");
        $stmt->execute([$usuario_id]);
        $datos = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($datos) {
            $grado_usuario = $datos['grado'];
            $seccion_usuario = $datos['seccion'];
        }
    }
} catch (Exception $e) {
    error_log("Error obteniendo grado/sección: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo = trim($_POST['titulo'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    
    if (empty($titulo) || empty($descripcion)) {
        header('Location: crear_tema.php?error=Todos+los+campos+son+obligatorios');
        exit();
    }
    
    try {
        $conexion = getConexion();
        
        // 🔥 Para estudiantes y docentes, usar el grado/sección de la sesión
        $grado_tema = $grado_usuario;
        $seccion_tema = $seccion_usuario;
        
        // Si por alguna razón no tienen grado/sección, redirigir
        if (empty($grado_tema) || empty($seccion_tema)) {
            header('Location: crear_tema.php?error=No+tienes+un+grado+o+sección+asignados');
            exit();
        }
        
        // Insertar tema CON grado y sección
        $query = "INSERT INTO foros_temas (titulo, descripcion, autor_id, grado, seccion) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conexion->prepare($query);
        $stmt->execute([$titulo, $descripcion, $usuario_id, $grado_tema, $seccion_tema]);
        
        header('Location: foro.php?exito=Tema+creado+correctamente');
        exit();
        
    } catch (Exception $e) {
        error_log("Error crear tema: " . $e->getMessage());
        header('Location: crear_tema.php?error=Error+al+crear+el+tema');
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Nuevo Tema - Foro</title>
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
            --primary: #00CED1;
            --secondary: #B19BFF;
            --text-dark: #333;
            --text-light: #666;
            --border: #E0E0E0;
            --error-bg: #f8d7da;
            --error-text: #dc3545;
        }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background: #F8F9FA; 
            min-height: 100vh; 
            display: flex; 
            align-items: center; 
            justify-content: center;
            padding: 16px;
            margin: 0;
        }
        
        .card { 
            background: white; 
            padding: 32px 24px; 
            border-radius: 20px; 
            box-shadow: 0 8px 24px rgba(0,0,0,0.12); 
            width: 100%; 
            max-width: 600px;
            transition: all 0.3s ease;
        }
        
        h1 { 
            color: #000; 
            margin-bottom: 28px; 
            font-size: clamp(24px, 5vw, 32px);
            font-weight: 700;
            line-height: 1.2;
        }
        
        .form-group { 
            margin-bottom: 24px; 
        }
        
        label { 
            display: block; 
            font-weight: 600; 
            margin-bottom: 8px; 
            color: var(--text-dark);
            font-size: 15px;
        }
        
        input, textarea, select { 
            width: 100%; 
            padding: 14px 16px; 
            border: 1.5px solid var(--border); 
            border-radius: 12px; 
            font-family: 'Inter', sans-serif; 
            font-size: 16px;
            transition: border-color 0.2s;
            background: white;
        }
        
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,206,209,0.1);
        }
        
        textarea { 
            min-height: 150px; 
            resize: vertical;
            line-height: 1.5;
        }
        
        .btn { 
            background: var(--primary); 
            color: #000; 
            padding: 16px 24px; 
            border: none; 
            border-radius: 12px; 
            font-weight: 700; 
            font-size: 16px;
            cursor: pointer; 
            width: 100%; 
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,206,209,0.2);
        }
        
        .btn:hover { 
            opacity: 0.9;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(0,206,209,0.3);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .btn-cancel { 
            background: #f0f0f0; 
            color: var(--text-dark);
            box-shadow: none;
            margin-top: 12px;
            border: 1px solid var(--border);
        }
        
        .btn-cancel:hover {
            background: #e4e4e4;
            box-shadow: none;
        }
        
        .error-message { 
            color: var(--error-text); 
            margin-bottom: 24px; 
            padding: 14px 16px; 
            background: var(--error-bg); 
            border-radius: 12px; 
            font-size: 15px;
            border-left: 4px solid var(--error-text);
        }
        
        /* Estilo para la información del grado/sección */
        .info-asignacion {
            background: #e8f4fd;
            border-left: 4px solid var(--primary);
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 28px;
            font-size: 15px;
            line-height: 1.5;
        }
        
        .info-asignacion strong {
            color: var(--primary);
            font-size: 16px;
            display: block;
            margin-bottom: 6px;
        }
        
        .info-asignacion small {
            color: var(--text-light);
            font-size: 14px;
            display: block;
            margin-top: 6px;
        }
        
        /* Grupo de botones para móvil */
        .button-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 16px;
        }
        
        /* Mejoras para móvil */
        @media (max-width: 480px) {
            body {
                padding: 12px;
                align-items: flex-start;
            }
            
            .card {
                padding: 24px 20px;
                border-radius: 16px;
            }
            
            h1 {
                margin-bottom: 24px;
            }
            
            input, textarea, select {
                padding: 16px;
                font-size: 16px; /* Evita zoom en iOS */
                -webkit-appearance: none;
                border-radius: 12px;
            }
            
            .btn {
                padding: 18px 20px;
                font-size: 17px;
            }
            
            .info-asignacion {
                padding: 14px;
                font-size: 14px;
            }
        }
        
        /* Para pantallas muy pequeñas */
        @media (max-width: 360px) {
            .card {
                padding: 20px 16px;
            }
            
            h1 {
                font-size: 22px;
            }
        }
        
        /* Para tablets */
        @media (min-width: 768px) and (max-width: 1024px) {
            .card {
                padding: 40px 32px;
            }
            
            input, textarea, select {
                padding: 16px 18px;
            }
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>📝 Crear Nuevo Tema</h1>
        
        <?php if (isset($_GET['error'])): ?>
            <div class="error-message">
                <strong>❌ Error:</strong> <?php echo htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>
        
        <!-- 🔥 Mostrar información del grado/sección -->
        <?php if (!empty($grado_usuario) && !empty($seccion_usuario)): ?>
            <div class="info-asignacion">
                <strong>📚 Creando tema para:</strong>
                <span style="font-size: 16px; font-weight: 500;">Grado <?php echo htmlspecialchars($grado_usuario); ?> - Sección <?php echo htmlspecialchars($seccion_usuario); ?></span>
                <small>El tema se publicará automáticamente en tu grado y sección. Todos los participantes de este grado/sección podrán verlo.</small>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Título del tema</label>
                <input type="text" name="titulo" required placeholder="Ej: Dudas sobre la Tarea 1" autocomplete="off">
            </div>
            
            <div class="form-group">
                <label>Descripción</label>
                <textarea name="descripcion" required placeholder="Describe tu tema o pregunta en detalle..."></textarea>
            </div>
            
            <!-- Grupo de botones mejorado para móvil -->
            <div class="button-group">
                <button type="submit" class="btn">📤 Publicar Tema</button>
                <a href="foro.php" class="btn btn-cancel">Cancelar</a>
            </div>
        </form>
    </div>
</body>
</html>
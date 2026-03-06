<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/encuestas_funciones.php';

if (!sesionActiva()) {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$conexion = getConexion();
$usuario_id = $_SESSION['usuario_id'];
$usuario_rol = $_SESSION['usuario_rol'];

// Obtener grado y sección si es estudiante
$grado = '';
$seccion = '';
if ($usuario_rol === 'Estudiante') {
    $stmt = $conexion->prepare("SELECT grado, seccion FROM estudiantes WHERE usuario_id = ?");
    $stmt->execute([$usuario_id]);
    $est = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($est) {
        $grado = $est['grado'];
        $seccion = $est['seccion'];
    }
}

// Obtener encuestas disponibles
$sql = "
    SELECT e.*,
           (SELECT COUNT(*) FROM respuestas_encuesta WHERE encuesta_id = e.id AND usuario_id = ?) as ya_respondio
    FROM encuestas e
    WHERE e.activo = true 
    AND e.fecha_cierre >= CURRENT_DATE
    AND (
        e.dirigido_a = 'todos' 
        OR e.dirigido_a = ?
";

$params = [$usuario_id, $usuario_rol];

if ($usuario_rol === 'Estudiante' && $grado) {
    $sql .= " OR (e.dirigido_a = 'estudiantes' AND (e.grado IS NULL OR e.grado = ?) AND (e.seccion IS NULL OR e.seccion = ?))";
    $params[] = $grado;
    $params[] = $seccion;
} else {
    $sql .= " OR e.dirigido_a = ?";
    $params[] = $usuario_rol;
}

$sql .= ") ORDER BY e.fecha_publicacion DESC";

$stmt = $conexion->prepare($sql);
$stmt->execute($params);
$encuestas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mensajes de sesión
$exito = $_GET['exito'] ?? '';
$error = $_GET['error'] ?? '';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Encuestas Disponibles - SIEDUCRES</title>
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
            flex: 1; padding: 40px 20px; max-width: 1200px; margin: 0 auto; width: 100%;
        }
        .mensaje-exito {
            background: #d4edda; color: #155724; padding: 15px; border-radius: 8px;
            margin-bottom: 20px; border: 1px solid #c3e6cb;
        }
        .mensaje-error {
            background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px;
            margin-bottom: 20px; border: 1px solid #f5c6cb;
        }
        .encuesta-card {
            background: white; border-radius: 16px; padding: 25px;
            margin-bottom: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            border-left: 4px solid var(--primary-cyan);
            transition: transform 0.2s;
        }
        .encuesta-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.15);
        }
        .encuesta-titulo {
            font-size: 20px; font-weight: 600; color: var(--primary-purple);
            margin-bottom: 10px;
        }
        .encuesta-descripcion {
            color: #666; margin-bottom: 15px; line-height: 1.6;
        }
        .encuesta-meta {
            display: flex; gap: 20px; margin-bottom: 15px;
            font-size: 14px; color: #999;
        }
        .btn-responder {
            background: var(--primary-lime); color: #333;
            padding: 10px 25px; border: none; border-radius: 8px;
            font-weight: 600; cursor: pointer; text-decoration: none;
            display: inline-block;
        }
        .btn-responder:hover {
            opacity: 0.9;
        }
        .badge-respondida {
            background: #28a745; color: white; padding: 5px 15px;
            border-radius: 20px; font-size: 14px; display: inline-block;
        }
        .badge-vencida {
            background: #dc3545; color: white; padding: 5px 15px;
            border-radius: 20px; font-size: 14px; display: inline-block;
        }
        .volver {
            margin-top: 30px; text-align: center;
        }
        .volver a {
            color: var(--primary-pink); text-decoration: none;
        }
        .no-encuestas {
            text-align: center; padding: 50px; background: white; border-radius: 16px;
        }
        .footer {
            height: 50px; background-color: var(--surface); border-top: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
            padding: 0 24px; font-size: 13px; color: var(--text-muted);
            position: sticky; bottom: 0;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-left">
            <img src="../../../assets/logo.svg" alt="SIEDUCRES" class="logo">
        </div>
        <div class="header-right">
            <div class="icon-btn" onclick="window.location.href='notificaciones.php'">
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

    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image">
    </div>

    <div class="banner-content">
        <h1 class="banner-title">📋 Encuestas Disponibles</h1>
    </div>

    <main class="main-content">
        <?php if ($exito): ?>
            <div class="mensaje-exito"><?php echo htmlspecialchars($exito); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mensaje-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (empty($encuestas)): ?>
            <div class="no-encuestas">
                <p style="color: #999; font-size: 18px;">No hay encuestas disponibles en este momento</p>
            </div>
        <?php else: ?>
            <?php foreach ($encuestas as $e): ?>
            <div class="encuesta-card">
                <div class="encuesta-titulo">
                    <?php echo htmlspecialchars($e['titulo']); ?>
                </div>
                <div class="encuesta-descripcion">
                    <?php echo nl2br(htmlspecialchars($e['descripcion'])); ?>
                </div>
                <?php if (!empty($e['instrucciones'])): ?>
                    <div style="background: #f9f9f9; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
                        <strong>Instrucciones:</strong> <?php echo nl2br(htmlspecialchars($e['instrucciones'])); ?>
                    </div>
                <?php endif; ?>
                <div class="encuesta-meta">
                    <span>📅 Publicada: <?php echo date('d/m/Y', strtotime($e['fecha_publicacion'])); ?></span>
                    <span>⏰ Cierra: <?php echo date('d/m/Y', strtotime($e['fecha_cierre'])); ?></span>
                </div>
                
                <?php if ($e['ya_respondio'] > 0): ?>
                    <span class="badge-respondida">✅ Ya respondida</span>
                <?php elseif (strtotime($e['fecha_cierre']) < time()): ?>
                    <span class="badge-vencida">⏰ Vencida</span>
                <?php else: ?>
                    <a href="responder_encuesta.php?id=<?php echo $e['id']; ?>" class="btn-responder">📝 Responder Encuesta</a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <div class="volver">
            <a href="<?php 
                if ($usuario_rol === 'Administrador') echo '../admin/index.php';
                elseif ($usuario_rol === 'Docente') echo '../docente/index.php';
                elseif ($usuario_rol === 'Estudiante') echo '../estudiante/index.php';
                elseif ($usuario_rol === 'Representante') echo '../representante/index.php';
            ?>">← Volver al Panel</a>
        </div>
    </main>

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
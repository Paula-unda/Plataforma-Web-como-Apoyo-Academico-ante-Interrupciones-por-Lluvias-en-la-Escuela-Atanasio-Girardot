<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/encuestas_funciones.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Administrador') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$conexion = getConexion();
$mensaje = $_SESSION['mensaje'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['mensaje'], $_SESSION['error']);

// Obtener todas las encuestas
$encuestas = $conexion->query("
    SELECT 
        e.*,
        u.nombre as creador_nombre,
        (SELECT COUNT(DISTINCT usuario_id) FROM respuestas_encuesta WHERE encuesta_id = e.id) as total_respondieron
    FROM encuestas e
    LEFT JOIN usuarios u ON e.creado_por = u.id
    ORDER BY e.fecha_publicacion DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Encuestas - SIEDUCRES</title>
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
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-header h2 { color: white; font-weight: 600; }
        .btn-crear {
            background: var(--primary-lime);
            color: #333;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
        }
        table {
            width: 100%; border-collapse: collapse;
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
        .badge-activo { background-color: var(--primary-lime); color: #333; }
        .badge-inactivo { background-color: #f8d7da; color: #721c24; }
        .btn-accion {
            padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer;
            font-size: 12px; font-weight: 600; text-decoration: none; display: inline-block;
            margin-right: 5px;
        }
        .btn-editar { background: var(--primary-cyan); color: white; }
        .btn-ver { background: var(--primary-purple); color: white; }
        .btn-eliminar { background: var(--primary-pink); color: white; }
        .btn-exportar { background: #28a745; color: white; }
        .mensaje-exito {
            background: #d4edda; color: #155724; padding: 15px; border-radius: 8px;
            margin-bottom: 20px; border: 1px solid #c3e6cb;
        }
        .mensaje-error {
            background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px;
            margin-bottom: 20px; border: 1px solid #f5c6cb;
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
                <a href="index.php" class="menu-item">Panel Principal</a>
                <a href="../../logout.php" class="menu-item">Cerrar sesión</a>
            </div>
        </div>
    </header>

    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image">
    </div>

    <div class="banner-content">
        <h1 class="banner-title">Gestión de Encuestas</h1>
    </div>

    <main class="main-content">
        <?php if ($mensaje): ?>
            <div class="mensaje-exito"><?php echo $mensaje; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mensaje-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2>Encuestas de Satisfacción</h2>
                <a href="crear_encuesta.php" class="btn-crear">Nueva Encuesta</a>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>Público</th>
                        <th>Fecha Publicación</th>
                        <th>Fecha Cierre</th>
                        <th>Estado</th>
                        <th>Respuestas</th>
                        <th>Creado por</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($encuestas)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 30px;">
                                No hay encuestas creadas. ¡Crea la primera!
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($encuestas as $e): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($e['titulo']); ?></strong></td>
                            <td>
                                <?php 
                                echo ucfirst($e['dirigido_a']);
                                if ($e['grado']) echo " - {$e['grado']} {$e['seccion']}";
                                ?>
                            </td>
                            <td><?php echo date('d/m/Y', strtotime($e['fecha_publicacion'])); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($e['fecha_cierre'])); ?></td>
                            <td>
                                <span class="badge <?php echo $e['activo'] ? 'badge-activo' : 'badge-inactivo'; ?>">
                                    <?php echo $e['activo'] ? 'Activa' : 'Inactiva'; ?>
                                </span>
                            </td>
                            <td><?php echo $e['total_respondieron']; ?> / <?php echo $e['total_preguntas']; ?> preg</td>
                            <td><?php echo $e['creador_nombre'] ?? 'Sistema'; ?></td>
                            <td>
                                <a href="editar_encuesta.php?id=<?php echo $e['id']; ?>" class="btn-accion btn-editar">Editar</a>
                                <a href="ver_resultados_encuesta.php?id=<?php echo $e['id']; ?>" class="btn-accion btn-ver">Ver</a>
                                <a href="exportar_resultados.php?id=<?php echo $e['id']; ?>" class="btn-accion btn-exportar">PDF</a>
                                <a href="eliminar_encuesta.php?id=<?php echo $e['id']; ?>" class="btn-accion btn-eliminar" onclick="return confirm('¿Eliminar encuesta? Esta acción no se puede deshacer.')">Eliminar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
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
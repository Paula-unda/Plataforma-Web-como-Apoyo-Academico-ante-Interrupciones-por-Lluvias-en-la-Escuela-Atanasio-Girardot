<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/encuestas_funciones.php';
require_once '../includes/onesignal_config.php';

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Gestión de Encuestas - SIEDUCRES</title>
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
            --text-dark: #333333;
            --text-muted: #666666;
            --border: #E0E0E0;
            --surface: #FFFFFF;
            --background: #F5F5F5;
            --primary-cyan: #4BC4E7;
            --primary-pink: #EF5E8E;
            --primary-lime: #C2D54E;
            --primary-purple: #9b8afb;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--background);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header responsive */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 16px;
            height: 60px;
            background-color: var(--surface);
            border-bottom: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            position: relative;
            z-index: 100;
        }

        @media (min-width: 768px) {
            .header {
                padding: 0 24px;
            }
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .logo {
            height: 32px;
        }

        @media (min-width: 768px) {
            .logo {
                height: 40px;
            }
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
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
            flex-shrink: 0;
        }

        .icon-btn img {
            width: 20px;
            height: 20px;
            object-fit: contain;
        }

        .menu-dropdown {
            position: absolute;
            top: 60px;
            right: 16px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            display: none;
            min-width: 180px;
            z-index: 1000;
        }

        @media (min-width: 768px) {
            .menu-dropdown {
                right: 24px;
            }
        }

        .menu-item {
            padding: 12px 16px;
            font-size: 14px;
            color: var(--text-dark);
            text-decoration: none;
            display: block;
            transition: background 0.2s;
        }

        .menu-item:hover {
            background-color: #F8F8F8;
        }

        /* Banner responsive */
        .banner {
            position: relative;
            height: 80px;
            overflow: hidden;
        }

        @media (min-width: 768px) {
            .banner {
                height: 100px;
            }
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
            padding: 16px;
            margin: 0 auto;
        }

        .banner-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-dark);
        }

        @media (min-width: 768px) {
            .banner-title {
                font-size: 36px;
            }
        }

        /* Contenido principal */
        .main-content {
            flex: 1;
            padding: 20px 16px;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        @media (min-width: 768px) {
            .main-content {
                padding: 40px 20px;
            }
        }

        /* Mensajes */
        .mensaje-exito,
        .mensaje-error {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .mensaje-exito {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .mensaje-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Tarjeta */
        .card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
            border: 1px solid var(--border);
        }

        @media (min-width: 768px) {
            .card {
                padding: 24px;
                margin-bottom: 30px;
            }
        }

        .card-header {
            background-color: var(--primary-cyan);
            margin: -20px -20px 16px -20px;
            padding: 16px 20px;
            border-radius: 16px 16px 0 0;
            color: white;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        @media (min-width: 768px) {
            .card-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                margin: -24px -24px 20px -24px;
                padding: 20px 24px;
            }
        }

        .card-header h2 {
            color: white;
            font-weight: 600;
            font-size: 1.2rem;
        }

        .btn-crear {
            background: var(--primary-lime);
            color: #333;
            padding: 12px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            text-align: center;
            width: 100%;
        }

        @media (min-width: 768px) {
            .btn-crear {
                width: auto;
                padding: 10px 20px;
            }
        }

        /* Tabla responsive */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -20px;
            padding: 0 20px;
        }

        table {
            width: 100%;
            min-width: 800px;
            border-collapse: collapse;
        }

        th {
            background-color: var(--primary-cyan);
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
        }

        tr:hover {
            background-color: #f9f9f9;
        }

        /* Badges */
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge-activo {
            background-color: var(--primary-lime);
            color: #333;
        }

        .badge-inactivo {
            background-color: #f8d7da;
            color: #721c24;
        }

        /* Botones de acción */
        .acciones-wrapper {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .btn-accion {
            padding: 6px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            white-space: nowrap;
        }

        .btn-editar { background: var(--primary-cyan); color: white; }
        .btn-ver { background: var(--primary-purple); color: white; }
        .btn-eliminar { background: var(--primary-pink); color: white; }
        .btn-exportar { background: #28a745; color: white; }

        /* Vista de cards para móvil extremo */
        .mobile-cards-view {
            display: none;
        }

        /* Footer */
        .footer {
            height: 50px;
            background-color: var(--surface);
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 16px;
            font-size: 12px;
            color: var(--text-muted);
            position: sticky;
            bottom: 0;
        }
        /* Enlace volver */
        .back-link {
            display: block;
            color: var(--primary-pink);
            text-decoration: none;
        }
        

        @media (min-width: 768px) {
            .footer {
                padding: 0 24px;
                font-size: 13px;
            }
        }

        /* Media queries para móvil */
        @media (max-width: 600px) {
            .table-responsive {
                margin: 0;
                padding: 0;
            }
            
            table {
                min-width: 100%;
            }
            
            .acciones-wrapper {
                flex-direction: column;
                gap: 3px;
            }
            
            .btn-accion {
                text-align: center;
                width: 100%;
            }
            
            .card-header {
                padding: 16px;
            }
            
            .btn-crear {
                padding: 10px;
            }
        }

        /* Para pantallas muy pequeñas, mostrar cards en lugar de tabla */
        @media (max-width: 480px) {
            .table-responsive {
                display: none;
            }
            
            .mobile-cards-view {
                display: block;
            }
            
            .encuesta-card {
                background: white;
                border: 1px solid var(--border);
                border-radius: 12px;
                padding: 16px;
                margin-bottom: 12px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            }
            
            .encuesta-card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 12px;
                padding-bottom: 8px;
                border-bottom: 2px solid var(--primary-cyan);
            }
            
            .encuesta-titulo {
                font-weight: 700;
                color: var(--text-dark);
                font-size: 16px;
            }
            
            .encuesta-badge {
                font-size: 11px;
                padding: 4px 8px;
                border-radius: 20px;
                font-weight: 600;
            }
            
            .encuesta-detalle {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px;
                margin-bottom: 12px;
            }
            
            .detalle-item {
                font-size: 12px;
            }
            
            .detalle-label {
                color: var(--text-muted);
                font-size: 10px;
                display: block;
            }
            
            .detalle-valor {
                font-weight: 600;
                color: var(--text-dark);
            }
            
            .encuesta-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 12px;
                padding-top: 12px;
                border-top: 1px dashed var(--border);
            }
            
            .creador-info {
                font-size: 11px;
                color: var(--text-muted);
            }
            
            .acciones-mobile {
                display: flex;
                gap: 5px;
            }
            
            .btn-mobile {
                width: 32px;
                height: 32px;
                border-radius: 6px;
                display: flex;
                align-items: center;
                justify-content: center;
                text-decoration: none;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header_comun.php'; ?>

    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image" onerror="this.style.display='none'">
    </div>

    <div class="banner-content">
        <h1 class="banner-title">Gestión de Encuestas</h1>
    </div>
    <!-- 🔴 FLECHA DE VOLVER A LA IZQUIERDA -->
    <?php if (basename($_SERVER['PHP_SELF']) != 'index.php'): ?>
        <div style="max-width: 1200px; margin: 10px 0 10px 40px; padding: 0; width: 100%;">
            <a href="index.php" class="back-link">← Volver al Panel</a>
        </div>
    <?php endif; ?>

    <main class="main-content">
        <?php if ($mensaje): ?>
            <div class="mensaje-exito">✅ <?php echo $mensaje; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mensaje-error">❌ <?php echo $error; ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2>Encuestas de Satisfacción</h2>
                <a href="crear_encuesta.php" class="btn-crear">+ Nueva Encuesta</a>
            </div>
            
            <!-- VISTA DESKTOP (tabla) -->
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Título</th>
                            <th>Público</th>
                            <th>Publicación</th>
                            <th>Cierre</th>
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
                                <td><?php echo $e['total_respondieron']; ?> / <?php echo $e['total_preguntas']; ?></td>
                                <td><?php echo $e['creador_nombre'] ?? 'Sistema'; ?></td>
                                <td>
                                    <div class="acciones-wrapper">
                                        <a href="editar_encuesta.php?id=<?php echo $e['id']; ?>" class="btn-accion btn-editar" title="Editar">✏️</a>
                                        <a href="ver_resultados_encuesta.php?id=<?php echo $e['id']; ?>" class="btn-accion btn-ver" title="Ver resultados">📊</a>
                                        <a href="exportar_resultados.php?id=<?php echo $e['id']; ?>" class="btn-accion btn-exportar" title="Exportar PDF">📄</a>
                                        <a href="eliminar_encuesta.php?id=<?php echo $e['id']; ?>" class="btn-accion btn-eliminar" title="Eliminar" onclick="return confirm('¿Eliminar encuesta? Esta acción no se puede deshacer.')">🗑️</a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- VISTA MÓVIL (cards) - Solo visible en pantallas muy pequeñas -->
            <div class="mobile-cards-view">
                <?php if (empty($encuestas)): ?>
                    <p style="text-align: center; padding: 30px; color: #999;">
                        No hay encuestas creadas. ¡Crea la primera!
                    </p>
                <?php else: ?>
                    <?php foreach ($encuestas as $e): ?>
                    <div class="encuesta-card">
                        <div class="encuesta-card-header">
                            <span class="encuesta-titulo"><?php echo htmlspecialchars($e['titulo']); ?></span>
                            <span class="encuesta-badge <?php echo $e['activo'] ? 'badge-activo' : 'badge-inactivo'; ?>">
                                <?php echo $e['activo'] ? 'Activa' : 'Inactiva'; ?>
                            </span>
                        </div>
                        
                        <div class="encuesta-detalle">
                            <div class="detalle-item">
                                <span class="detalle-label">Público</span>
                                <span class="detalle-valor"><?php echo ucfirst($e['dirigido_a']); ?></span>
                            </div>
                            <div class="detalle-item">
                                <span class="detalle-label">Grado</span>
                                <span class="detalle-valor"><?php echo $e['grado'] ?: 'Todos'; ?></span>
                            </div>
                            <div class="detalle-item">
                                <span class="detalle-label">Publicación</span>
                                <span class="detalle-valor"><?php echo date('d/m/Y', strtotime($e['fecha_publicacion'])); ?></span>
                            </div>
                            <div class="detalle-item">
                                <span class="detalle-label">Cierre</span>
                                <span class="detalle-valor"><?php echo date('d/m/Y', strtotime($e['fecha_cierre'])); ?></span>
                            </div>
                            <div class="detalle-item">
                                <span class="detalle-label">Respuestas</span>
                                <span class="detalle-valor"><?php echo $e['total_respondieron']; ?> / <?php echo $e['total_preguntas']; ?></span>
                            </div>
                        </div>
                        
                        <div class="encuesta-footer">
                            <span class="creador-info">Por: <?php echo $e['creador_nombre'] ?? 'Sistema'; ?></span>
                            <div class="acciones-mobile">
                                <a href="editar_encuesta.php?id=<?php echo $e['id']; ?>" class="btn-mobile btn-editar" title="Editar">✏️</a>
                                <a href="ver_resultados_encuesta.php?id=<?php echo $e['id']; ?>" class="btn-mobile btn-ver" title="Ver">📊</a>
                                <a href="exportar_resultados.php?id=<?php echo $e['id']; ?>" class="btn-mobile btn-exportar" title="PDF">📄</a>
                                <a href="eliminar_encuesta.php?id=<?php echo $e['id']; ?>" class="btn-mobile btn-eliminar" title="Eliminar" onclick="return confirm('¿Eliminar encuesta?')">🗑️</a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
    </main>

    <footer class="footer">
        <span>v2.0.0</span>
        <span>Soporte Técnico</span>
    </footer>

    <script>
        // Toggle menú hamburguesa
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
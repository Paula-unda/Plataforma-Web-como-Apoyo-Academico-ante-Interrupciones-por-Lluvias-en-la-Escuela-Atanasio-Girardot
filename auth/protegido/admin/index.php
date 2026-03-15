<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/onesignal_config.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Administrador') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

// Obtener estadísticas para mostrar
$conexion = getConexion();

$total_usuarios = $conexion->query("SELECT COUNT(*) FROM usuarios WHERE activo = true")->fetchColumn();
$total_estudiantes = $conexion->query("SELECT COUNT(*) FROM estudiantes")->fetchColumn();
$total_docentes = $conexion->query("SELECT COUNT(*) FROM docentes")->fetchColumn();
$total_actividades = $conexion->query("SELECT COUNT(*) FROM actividades WHERE activo = true")->fetchColumn();

// Obtener estadísticas adicionales
$total_representantes = $conexion->query("SELECT COUNT(DISTINCT representante_id) FROM representantes_estudiantes")->fetchColumn();
$total_foros = $conexion->query("SELECT COUNT(*) FROM foros_temas")->fetchColumn();
$total_contenidos = $conexion->query("SELECT COUNT(*) FROM contenidos WHERE activo = true")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Panel de Administrador - SIEDUCRES</title>
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

        .icon-btn:hover {
            background-color: #E0E0E0;
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
            margin-bottom: 8px;
        }

        @media (min-width: 768px) {
            .banner-title {
                font-size: 36px;
            }
        }

        .banner-subtitle {
            font-size: 16px;
            color: var(--text-muted);
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

        /* ===== NUEVO DISEÑO DE ESTADÍSTICAS (IGUAL QUE EN REPORTES) ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 40px;
        }

        @media (min-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 20px;
            }
        }

        @media (min-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .stat-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border-left: 4px solid var(--primary-cyan);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        }

        .stat-card.cyan { border-left-color: var(--primary-cyan); }
        .stat-card.pink { border-left-color: var(--primary-pink); }
        .stat-card.lime { border-left-color: var(--primary-lime); }
        .stat-card.purple { border-left-color: var(--primary-purple); }

        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-cyan);
            margin-bottom: 5px;
        }

        @media (min-width: 768px) {
            .stat-number {
                font-size: 32px;
            }
        }

        .stat-label {
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* Tarjetas de navegación */
        .card-grid {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
            max-width: 1600px;
            width: 100%;
            margin-top: 20px;
        }

        .card {
            background: linear-gradient(135deg, #4a90e2, #6fb1fc);
            border-radius: 16px;
            padding: 32px 24px;
            text-align: center;
            color: #000000;
            text-decoration: none;
            display: block;
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            min-width: 240px;
            max-width: 280px;
            flex: 1;
        }

        .card:hover {
            transform: translateY(-6px);
            box-shadow: 0 10px 24px rgba(0,0,0,0.15);
        }

        /* Gradientes para cada tarjeta */
        .card-1 { background: linear-gradient(135deg, #f36c7b, #f89ca6); }
        .card-2 { background: linear-gradient(135deg, #24d4dc, #5ce0e6); }
        .card-3 { background: linear-gradient(135deg, #cbd74f, #d7e07a); }
        .card-4 { background: linear-gradient(135deg, #a78bfe, #c0a9ff); }
        .card-5 { background: linear-gradient(135deg, #f36c7b, #f89ca6); }

        .card-icon {
            width: 120px;
            height: 120px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .card-icon img {
            width: 80px;
            height: 80px;
            object-fit: contain;
        }

        .card-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #000000;
        }

        .card-desc {
            font-size: 14px;
            opacity: 0.9;
            line-height: 1.5;
            color: #000000;
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

        @media (min-width: 768px) {
            .footer {
                padding: 0 24px;
                font-size: 13px;
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .card-grid {
                flex-direction: column;
                align-items: center;
            }
            .card {
                width: 100%;
                max-width: 320px;
            }
        }
    </style>
</head>
<body>
    <div style="height: 60px; width: 100%; background: transparent;"></div>

    <?php require_once '../includes/header_comun.php'; ?>

    <!-- Banner -->
    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image" onerror="this.style.display='none'">
    </div>
    
    <!-- Contenido -->
    <div class="banner-content">
        <h1 class="banner-title">¡Bienvenido, <?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Administrador'); ?>!</h1>
        <p class="banner-subtitle">Panel de control y administración general del sistema</p>
    </div>

    <!-- Estadísticas rápidas con nuevo diseño -->
    <main class="main-content">
        <div class="stats-grid">
            <div class="stat-card cyan">
                <div class="stat-number"><?php echo $total_usuarios; ?></div>
                <div class="stat-label">Usuarios Activos</div>
            </div>
            <div class="stat-card pink">
                <div class="stat-number"><?php echo $total_estudiantes; ?></div>
                <div class="stat-label">Estudiantes</div>
            </div>
            <div class="stat-card lime">
                <div class="stat-number"><?php echo $total_docentes; ?></div>
                <div class="stat-label">Docentes</div>
            </div>
            <div class="stat-card purple">
                <div class="stat-number"><?php echo $total_representantes; ?></div>
                <div class="stat-label">Representantes</div>
            </div>
            <div class="stat-card cyan">
                <div class="stat-number"><?php echo $total_actividades; ?></div>
                <div class="stat-label">Actividades</div>
            </div>
            <div class="stat-card pink">
                <div class="stat-number"><?php echo $total_contenidos; ?></div>
                <div class="stat-label">Contenidos</div>
            </div>
            <div class="stat-card lime">
                <div class="stat-number"><?php echo $total_foros; ?></div>
                <div class="stat-label">Foros</div>
            </div>
            <div class="stat-card purple">
                <div class="stat-number"><?php echo $total_estudiantes + $total_docentes; ?></div>
                <div class="stat-label">Total Comunidad</div>
            </div>
        </div>

        <!-- Tarjetas de navegación -->
        <div class="card-grid">
            <!-- Tarjeta 1: GESTIÓN DE USUARIOS -->
            <a href="gestion_usuarios.php" class="card card-1">
                <div class="card-icon">
                    <img src="../../../assets/icon-user.svg" alt="Usuarios">
                </div>
                <h2 class="card-title">Gestión de Usuarios</h2>
                <p class="card-desc">Administrar estudiantes, docentes y representantes</p>
            </a>

            <!-- Tarjeta 2: PERÍODOS ESCOLARES -->
            <a href="periodos.php" class="card card-2">
                <div class="card-icon">
                    <img src="../../../assets/calendario.svg" alt="Períodos" onerror="this.src='https://via.placeholder.com/80x80?text=📅'">
                </div>
                <h2 class="card-title">Períodos Escolares</h2>
                <p class="card-desc">Gestionar lapsos y períodos académicos</p>
            </a>

            <!-- Tarjeta 3: ENCUESTAS -->
            <a href="encuestas.php" class="card card-3">
                <div class="card-icon">
                    <img src="../../../assets/encuestas_negro.svg" alt="Encuestas">
                </div>
                <h2 class="card-title">Encuestas</h2>
                <p class="card-desc">Crear y gestionar encuestas de satisfacción</p>
            </a>

            <!-- Tarjeta 4: REPORTES -->
            <a href="../comun/reportes.php" class="card card-4">
                <div class="card-icon">
                    <img src="../../../assets/reportes.svg" alt="Reportes" onerror="this.src='https://via.placeholder.com/80x80?text=📈'">
                </div>
                <h2 class="card-title">Reportes</h2>
                <p class-card-desc">Generar reportes de participación y académicos</p>
            </a>

            <!-- Tarjeta 5: PAPELERA -->
            <a href="papelera_usuarios.php" class="card card-5">
                <div class="card-icon">
                    <img src="../../../assets/basurero_borrar.svg" alt="Papelera">
                </div>
                <h2 class="card-title">Papelera</h2>
                <p class="card-desc">Usuarios eliminados y restauración</p>
            </a>
        </div>
    </main>

    <!-- Pie de página -->
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
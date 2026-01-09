<?php
session_start();
require_once '../../funciones.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Administrador') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

try {
    $pdo = getConexion();
    
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.nombre,
            u.correo,
            u.rol,
            u.activo,
            u.contrasena_temporal,
            e.grado,
            e.seccion,
            STRING_AGG(
                CONCAT(es.nombre, ' (', COALESCE(esd.grado, 'N/A'), '-', COALESCE(esd.seccion, 'N/A'), ')'),
                ', '
                ORDER BY es.nombre
            ) AS estudiantes_asignados
        FROM usuarios u
        LEFT JOIN estudiantes e ON u.id = e.usuario_id
        LEFT JOIN representantes_estudiantes re ON u.id = re.representante_id
        LEFT JOIN usuarios es ON re.estudiante_id = es.id
        LEFT JOIN estudiantes esd ON es.id = esd.usuario_id
        GROUP BY u.id, u.nombre, u.correo, u.rol, u.activo, u.contrasena_temporal, e.grado, e.seccion
        ORDER BY u.nombre
    ");
    $stmt->execute();
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $usuarios = [];
    error_log("Error al cargar usuarios: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios - SIEDUCRES</title>
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
            --border-dark: #000000;
            --border-light: #CCCCCC;
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

        .icon-btn img {
            width: 20px;
            height: 20px;
            object-fit: contain;
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

        /* Banner superior */
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

        .banner-subtitle {
            font-size: 18px;
            color: var(--text-muted);
        }

        /* Contenido principal */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 40px 20px;
        }

        /* Nueva sección de título + acciones */
        .page-header-section {
            width: 100%;
            max-width: 1200px;
            margin-bottom: 28px;
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-main);
            text-align: center;
            margin-bottom: 20px;
        }

        .header-actions {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .search-wrapper {
            flex: 1;
            max-width: 800px;
        }

        .search-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-light);
            border-radius: 12px; 
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .search-input:focus {
            border-color: var(--primary-cyan);
            box-shadow: 0 0 0 3px rgba(75, 196, 231, 0.2);
        }

        .btn-primary {
            background-color: var(--primary-cyan);
            color: var(--text-main);
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            transition: background 0.2s;
        }

        .btn-primary:hover {
            background-color: #3ab3d6;
        }

        /* Tabla de usuarios */
        .table-container {
            width: 100%;
            max-width: 1200px;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background-color: var(--white);
            border: 1px solid var(--border-dark);
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
        }

        th, td {
            padding: 12px;
            text-align: center;
            border: 1px solid var(--border-dark);
        }

        th {
            background-color: var(--primary-lime);
            font-weight: 700;
            font-size: 14px;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
        }

        .btn-action {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .btn-edit {
            background-color: var(--primary-purple);
            color: var(--white);
        }

        .btn-delete {
            background-color: var(--primary-pink);
            color: var(--white);
        }

        .btn-access {
            background-color: var(--primary-lime);
            color: var(--text-main);
        }

        .btn-action:hover {
            transform: scale(1.1);
            filter: brightness(1.1); 
        }

        /* Pie de página fijo */
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

        /* Responsive */
        @media (max-width: 768px) {
            .header-actions {
                flex-direction: column;
                align-items: stretch;
                gap: 16px;
            }

            .search-input {
                font-size: 16px; 
            }

            .btn-primary {
                text-align: center;
                padding: 12px;
            }
        }
        /* Botón Ver Contraseña */
        .btn-view-pass {
            background-color: #6c757d;
            color: white;
        }

        .btn-view-pass:hover {
            background-color: #5a6268;
        }

        /* Popup de contraseña mejorado */
        .password-popup {
            position: absolute;
            background: white;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            padding: 12px;
            z-index: 1000;
            font-family: 'Inter', sans-serif; 
            font-size: 14px;
            color: var(--text-main);
            min-width: 180px;
            max-width: 220px;
            text-align: center;
            word-break: break-word;
        }

        /* Eliminar trazo en botones reales */
        .btn-action.btn-access {
            outline: none !important;
            border: none !important;
        }

        .btn-action.btn-access:focus,
        .btn-action.btn-access:active {
            outline: none !important;
            box-shadow: none !important;
            border: none !important;
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
            <div class="icon-btn">
                <img src="../../../assets/icon-bell.svg" alt="Notificaciones">
            </div>
            <div class="icon-btn">
                <img src="../../../assets/icon-user.svg" alt="Perfil">
            </div>
            <div class="icon-btn" id="menu-toggle">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="#333333">
                    <path d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/>
                </svg>
            </div>

            <div class="menu-dropdown" id="dropdown">
                <a href="../../logout.php" class="menu-item">Cerrar sesión</a>
            </div>
        </div>
    </header>

    <!-- Banner -->
    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image">
        <div class="banner-content">
            <h1 class="banner-title">¡Bienvenidos a SIEDUCRES!</h1>
            <p class="banner-subtitle">Plataforma para la recuperación de clases interrumpidas por condiciones climáticas</p>
        </div>
    </div>

    <!-- Contenido principal -->
    <main class="main-content">
        <div class="page-header-section">
            <h1 class="page-title">Gestión de usuarios</h1>
            <div class="header-actions">
                <div class="search-wrapper">
                    <input type="text" id="searchInput" placeholder="Buscar por nombre, correo, rol, grado o sección..." 
                        class="search-input">
                </div>
                <a href="registrar_usuario.php" class="btn-primary">+ Registrar usuario</a>
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Nombre completo</th>
                        <th>Correo electrónico</th>
                        <th>Rol</th>
                        <th>Detalles</th> 
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody id="usuariosTableBody">
                    <?php if (empty($usuarios)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 20px;">
                                No hay usuarios registrados.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($usuarios as $usuario): ?>
                            <tr data-nombre="<?= htmlspecialchars($usuario['nombre']) ?>"
                                data-correo="<?= htmlspecialchars($usuario['correo']) ?>"
                                data-rol="<?= htmlspecialchars($usuario['rol']) ?>"
                                data-detalles="<?= htmlspecialchars(($usuario['rol'] === 'Estudiante') ? ($usuario['grado'] . '-' . $usuario['seccion']) : ($usuario['estudiantes_asignados'] ?? '')) ?>">
                                <td><?= htmlspecialchars($usuario['nombre']) ?></td>
                                <td><?= htmlspecialchars($usuario['correo']) ?></td>
                                <td><?= htmlspecialchars($usuario['rol']) ?></td>
                                <td style="text-align: left; max-width: 250px;">
                                    <?php if ($usuario['rol'] === 'Estudiante'): ?>
                                        <strong>Grado:</strong> <?= htmlspecialchars($usuario['grado'] ?? '—') ?><br>
                                        <strong>Sección:</strong> <?= htmlspecialchars($usuario['seccion'] ?? '—') ?>
                                    <?php elseif ($usuario['rol'] === 'Representante'): ?>
                                        <?php if (!empty($usuario['estudiantes_asignados'])): ?>
                                            <strong>Estudiantes:</strong><br>
                                            <small><?= nl2br(htmlspecialchars($usuario['estudiantes_asignados'])) ?></small>
                                        <?php else: ?>
                                            <em>Sin estudiantes asignados</em>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($usuario['activo']): ?>
                                        <span style="color: #c3d54dff; font-weight: bold;">Activo</span>
                                    <?php else: ?>
                                        <span style="color: #EF5E8E; font-weight: bold;">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="editar_usuario.php?id=<?= $usuario['id'] ?>" class="btn-action btn-edit" title="Editar">
                                            <img src="../../../assets/lapiz_editar.svg" alt="Editar" style="width:16px; height:16px;">
                                        </a>
                                        <a href="eliminar_usuario.php?id=<?= $usuario['id'] ?>" class="btn-action btn-delete" title="Eliminar" onclick="return confirm('¿Estás seguro de eliminar este usuario?');">
                                            <img src="../../../assets/basurero_borrar.svg" alt="Eliminar" style="width:16px; height:16px;">
                                        </a>
                                        <!--Botón Ver Contraseña-->
                                        <button class="btn-action btn-access" 
                                                data-password="<?= htmlspecialchars($usuario['contrasena_temporal'] ?? '—') ?>"
                                                data-user="<?= htmlspecialchars($usuario['nombre']) ?>"
                                                title="Ver contraseña temporal">
                                            <!-- Ícono de LLAVE -->
                                            <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                                <path d="M8 1a2 2 0 0 1 2 2v2H6V3a2 2 0 0 1 2-2zm3 6V5a3 3 0 0 0-6 0v2a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- Pie de página -->
    <footer class="footer">
        <span>v2.0.0</span>
        <span>Soporte Técnico</span>
    </footer>

    <script>

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

        // Buscador en tiempo real
        document.getElementById('searchInput').addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('#usuariosTableBody tr');
            
            rows.forEach(row => {
                const nombre = row.getAttribute('data-nombre') || '';
                const correo = row.getAttribute('data-correo') || '';
                const rol = row.getAttribute('data-rol') || '';
                const detalles = row.getAttribute('data-detalles') || '';
                
                const matches = 
                    nombre.toLowerCase().includes(filter) ||
                    correo.toLowerCase().includes(filter) ||
                    rol.toLowerCase().includes(filter) ||
                    detalles.toLowerCase().includes(filter);
                
                row.style.display = matches ? '' : 'none';
            });
        });

        // Popup de contraseña 
        let currentPassPopup = null;

        // Cerrar popup si se hace clic fuera
        document.addEventListener('click', function(e) {
            if (currentPassPopup && !e.target.closest('.btn-access') && !e.target.closest('.password-popup')) {
                currentPassPopup.remove();
                currentPassPopup = null;
            }
        });

        // Configurar todos los botones .btn-access
        document.querySelectorAll('.btn-access').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // Si ya hay un popup abierto, cerrarlo y salir
                if (currentPassPopup) {
                    currentPassPopup.remove();
                    currentPassPopup = null;
                    return;
                }

                const password = this.getAttribute('data-password') || '—';
                const user = this.getAttribute('data-user') || 'Usuario';
                const rect = this.getBoundingClientRect();
                
                // Crear popup
                const popup = document.createElement('div');
                popup.className = 'password-popup';
                popup.innerHTML = `
                    <div style="font-size:12px; color:var(--text-muted); margin-bottom:6px;">
                        Contraseña de <strong>${user}</strong>
                    </div>
                    <div style="
                        font-family: 'Inter', sans-serif;
                        font-weight: 600;
                        font-size: 15px;
                        background: var(--canvas-bg);
                        padding: 6px 10px;
                        border-radius: 4px;
                        margin: 4px 0;
                        letter-spacing: 0.5px;
                        color: var(--text-main);
                    ">${password}</div>
                `;

                // Calcular posición para que no se salga de la pantalla
                const viewportWidth = window.innerWidth;
                const popupWidth = 220;
                let leftPos = rect.left;
                
                if (rect.left + popupWidth > viewportWidth) {
                    leftPos = viewportWidth - popupWidth - 10;
                }
                
                popup.style.top = (rect.top - popup.offsetHeight - 8) + 'px'; 
                popup.style.left = Math.max(10, leftPos) + 'px';
                popup.style.zIndex = '10000';

                document.body.appendChild(popup);
                currentPassPopup = popup;

                // Añadir evento para cerrar al hacer clic fuera
                const closePopup = (e) => {
                    if (!popup.contains(e.target) && !button.contains(e.target)) {
                        popup.remove();
                        currentPassPopup = null;
                        document.removeEventListener('click', closePopup);
                    }
                };
                setTimeout(() => {
                    document.addEventListener('click', closePopup);
                }, 100);
            });

            // Eliminar el outline al hacer clic (y al enfocar)
            button.addEventListener('focus', function() {
                this.style.outline = 'none';
            });
        });


    </script>

</body>
</html>
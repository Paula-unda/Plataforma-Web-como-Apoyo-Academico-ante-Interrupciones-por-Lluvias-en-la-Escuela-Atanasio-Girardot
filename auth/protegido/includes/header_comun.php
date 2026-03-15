<?php
// /auth/protegido/includes/header_comun.php

// Asegurar que la sesión está iniciada
if (!isset($_SESSION)) {
    session_start();
}

// Función para contar notificaciones no leídas
function contarNotificacionesNoLeidas($conexion, $usuario_id) {
    try {
        $stmt = $conexion->prepare("SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND leido = false");
        $stmt->execute([$usuario_id]);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// Obtener contador si hay sesión activa
$notificaciones_no_leidas = 0;
if (isset($_SESSION['usuario_id']) && isset($conexion)) {
    $notificaciones_no_leidas = contarNotificacionesNoLeidas($conexion, $_SESSION['usuario_id']);
}

// Determinar la página de inicio según el rol
$pagina_inicio = '#';
if (isset($_SESSION['usuario_rol'])) {
    switch ($_SESSION['usuario_rol']) {
        case 'Administrador':
            $pagina_inicio = '../admin/index.php';
            break;
        case 'Docente':
            $pagina_inicio = '../docente/index.php';
            break;
        case 'Estudiante':
            $pagina_inicio = '../estudiante/index.php';
            break;
        case 'Representante':
            $pagina_inicio = '../representante/index.php';
            break;
    }
}
?>

<!-- ==================== HEADER COMÚN ==================== -->
<style>
    /* Header fijo en la parte superior */
    .header-global {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0 24px;
        height: 60px;
        background-color: var(--surface, #FFFFFF);
        border-bottom: 1px solid var(--border, #E0E0E0);
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        position: fixed;  /* ← CAMBIA DE 'sticky' a 'fixed' */
        top: 0;
        left: 0;
        right: 0;
        z-index: 1000;
        width: 100%;
    }

    .header-left {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .header-logo {
        height: 40px;
        cursor: pointer;
        transition: opacity 0.2s;
    }

    .header-logo:hover {
        opacity: 0.8;
    }

    .header-right {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    /* Botones circulares */
    .header-icon-btn {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background-color: #F0F0F0;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
        position: relative;
        border: none;
        padding: 0;
    }

    .header-icon-btn:hover {
        background-color: #E0E0E0;
        transform: scale(1.05);
    }

    .header-icon-btn img {
        width: 22px;
        height: 22px;
        object-fit: contain;
    }

    /* Contador de notificaciones (punto rojo) */
    .notificacion-badge {
        position: absolute;
        top: -2px;
        right: -2px;
        background-color: var(--primary-pink, #EF5E8E);
        color: white;
        font-size: 11px;
        font-weight: 600;
        min-width: 18px;
        height: 18px;
        border-radius: 9px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 4px;
        box-shadow: 0 2px 4px rgba(239, 94, 142, 0.3);
        border: 2px solid white;
    }

    /* Menú desplegable */
    .header-dropdown {
        position: absolute;
        top: 50px;
        right: 0;
        background: white;
        border: 1px solid var(--border, #E0E0E0);
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        display: none;
        min-width: 200px;
        z-index: 1001;
        overflow: hidden;
    }

    .header-dropdown-item {
        padding: 12px 16px;
        font-size: 14px;
        color: var(--text-dark, #333333);
        text-decoration: none;
        display: block;
        transition: background 0.2s;
        border-bottom: 1px solid #f0f0f0;
    }

    .header-dropdown-item:last-child {
        border-bottom: none;
    }

    .header-dropdown-item:hover {
        background-color: #F8F8F8;
    }

    .header-dropdown-item i {
        margin-right: 8px;
        opacity: 0.7;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .header-global {
            padding: 0 16px;
        }
        
        .header-logo {
            height: 32px;
        }
        
        .header-icon-btn {
            width: 36px;
            height: 36px;
        }
        
        .header-icon-btn img {
            width: 20px;
            height: 20px;
        }
    }
</style>

<header class="header-global">
    <!-- Logo (click para ir al panel principal) -->
    <div class="header-left">
        <a href="<?php echo $pagina_inicio; ?>">
            <img src="../../../assets/logo.svg" alt="SIEDUCRES" class="header-logo" 
                 onerror="this.src='https://via.placeholder.com/120x40?text=SIEDUCRES'">
        </a>
    </div>

    <!-- Iconos de acción -->
    <div class="header-right">
        <!-- Notificaciones con contador -->
        <button class="header-icon-btn" onclick="window.location.href='../comun/notificaciones.php'" 
                title="Notificaciones">
            <img src="../../../assets/icon-bell.svg" alt="Notificaciones"
                 onerror="this.src='https://via.placeholder.com/20x20?text=🔔'">
            <?php if ($notificaciones_no_leidas > 0): ?>
                <span class="notificacion-badge"><?php echo $notificaciones_no_leidas; ?></span>
            <?php endif; ?>
        </button>

        <!-- Perfil -->
        <button class="header-icon-btn" onclick="window.location.href='../comun/perfil.php'" title="Mi Perfil">
            <img src="../../../assets/icon-user.svg" alt="Perfil"
                onerror="this.src='https://via.placeholder.com/20x20?text=👤'">
        </button>

        <!-- Menú hamburguesa -->
        <div style="position: relative;">
            <button class="header-icon-btn" id="menu-toggle-global" title="Menú">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="#333333">
                    <path d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/>
                </svg>
            </button>

            <!-- Dropdown del menú -->
            <div class="header-dropdown" id="dropdown-global">
                <a href="<?php echo $pagina_inicio; ?>" class="header-dropdown-item">
                    📋 Panel Principal
                </a>
                <a href="../comun/notificaciones.php" class="header-dropdown-item">
                    🔔 Notificaciones
                </a>
                <a href="../comun/perfil.php" class="header-dropdown-item">
                    👤 Mi Perfil
                </a>
                <a href="../../logout.php" class="header-dropdown-item" style="color: #EF5E8E;">
                    🚪 Cerrar sesión
                </a>
            </div>
        </div>
    </div>
</header>

<script>
// Script para el menú hamburguesa
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.getElementById('menu-toggle-global');
    const dropdown = document.getElementById('dropdown-global');
    
    if (menuToggle && dropdown) {
        menuToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        });
        
        document.addEventListener('click', function(e) {
            if (!menuToggle.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    }
});
</script>
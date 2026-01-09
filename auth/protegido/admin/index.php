<?php
session_start();
require_once '../../funciones.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Administrador') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administrador - SIEDUCRES</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #4BC4E7;      
            --text-dark: #333333;
            --text-muted: #666666;
            --border: #E0E0E0;
            --surface: #FFFFFF;
            --background: #F5F5F5;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--background);
            color: var(--text-dark);
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
            background-color: var(--surface);
            border-bottom: 1px solid var(--border);
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
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            display: none;
            min-width: 180px;
        }

        .menu-item {
            padding: 10px 16px;
            font-size: 14px;
            color: var(--text-dark);
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
            color: var(--text-dark);
            margin-bottom: 8px;
        }

        .banner-subtitle {
            font-size: 18px;
            color: var(--text-muted);
        }
        /* Tarjetas */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 320px));
            gap: 32px;
            justify-content: center;
        }

        .card {
            background: var(--primary);
            border-radius: 16px;
            padding: 32px 24px;
            text-align: center;
            color: white;
            text-decoration: none;
            display: block;
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            width: 100%;
            max-width: 320px;
        }

        .card:hover {
            transform: translateY(-6px);
            box-shadow: 0 10px 24px rgba(0,0,0,0.15);
        }

        .card-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: rgba(255,255,255,0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .card-icon img {
            width: 48px;
            height: 48px;
            object-fit: contain;
        }

        .card-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .card-desc {
            font-size: 14px;
            opacity: 0.9;
            line-height: 1.5;
        }

        /* Pie de página fijo */
        .footer {
            height: 50px;
            background-color: var(--surface);
            border-top: 1px solid var(--border);
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


        @media (max-width: 768px) {
            .banner-title { font-size: 28px; }
            .banner { height: 160px; }
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
       </div>   
       <!-- Contenido -->
       <div class="banner-content">
           <h1 class="banner-title">¡Bienvenidos a SIEDUCRES!</h1>
           <p class="banner-subtitle">Plataforma para la recuperación de clases interrumpidas por condiciones climáticas</p>
       </div>
    

    <!-- Tarjetas -->
    <main class="main-content">
        <div class="card-grid">
            <a href="gestion_usuarios.php" class="card">
                <div class="card-icon">
                    <img src="../../../assets/icon-card.svg" alt="Gestión de Usuarios">
                </div>
                <h2 class="card-title">Gestión de Usuarios</h2>
                <p class="card-desc">Registrar, editar y eliminar usuarios del sistema.</p>
            </a>
        </div>
    </main>

    <!--Pie de página -->
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
    </script>

</body>
</html>
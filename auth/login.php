<?php
session_start();
if (isset($_SESSION['usuario_id'])) {
    require_once 'funciones.php';
    redirigirPorRol($_SESSION['usuario_rol']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔐 Iniciar Sesión - SIEDUCRES</title>

    <!-- Google Fonts: Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 (solo CSS — no JS necesario para login simple) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            /* Paleta de colores */
            --primary: #4BC4E7;
            --secondary-pink: #EF5E8E;
            --secondary-purple: #754E9E;
            --secondary-green: #58BC67;
            --secondary-red: #E05555;
            --background-canvas: #F5F5F5;
            --background-surface: #FFFFFF;
            --text-dark: #333333;
            --text-placeholder: #AAAAAA;
            --text-link: #4BC4E7;
            --border: #CCCCCC;

            /* Tipografía */
            --font-family: "Inter", Arial, sans-serif;
            --base-font-size: 16px;

            /* Espaciado */
            --spacing-s: 8px;
            --spacing-m: 16px;
            --spacing-l: 24px;
            --spacing-xl: 32px;
            --container-padding: 40px;
            --element-spacing: 20px;

            /* Componentes */
            --card-bg: var(--background-surface);
            --card-border-radius: 4px;
            --card-padding: 32px 40px;

            --button-primary-bg: var(--primary);
            --button-primary-color: #FFFFFF;
            --button-primary-radius: 4px;
            --button-primary-padding: 10px 20px;
            --button-primary-shadow: 0px 4px 6px rgba(0, 0, 0, 0.1);

            --input-bg: var(--background-surface);
            --input-border: var(--border);
            --input-radius: 4px;
            --input-padding: 10px 12px;
        }

        body {
            background-color: var(--background-canvas);
            font-family: var(--font-family);
            font-size: var(--base-font-size);
            color: var(--text-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: var(--spacing-xl);
        }

        .login-card {
            background-color: var(--card-bg);
            border-radius: var(--card-border-radius);
            padding: var(--card-padding);
            width: 100%;
            max-width: 400px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .logo-container {
            text-align: center;
            margin-bottom: var(--spacing-l);
        }

        .logo {
            height: 50px;
            margin-bottom: 20px;
        }

        .brand-title {
            font-size: 24px;
            font-weight: 600;
            line-height: 1.2;
            color: var(--text-dark);
            margin-bottom: var(--element-spacing);
            text-align: center;
        }

        .form-label {
            font-size: 14px;
            font-weight: normal;
            color: var(--text-dark);
            margin-bottom: var(--spacing-s);
        }

        .form-control {
            background-color: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: var(--input-radius);
            padding: var(--input-padding);
            color: var(--text-dark);
            width: 100%;
            font-family: var(--font-family);
        }

        .form-control::placeholder {
            color: var(--text-placeholder);
        }

        .btn-primary-custom {
            background-color: var(--button-primary-bg);
            color: var(--button-primary-color);
            border: none;
            border-radius: var(--button-primary-radius);
            padding: var(--button-primary-padding);
            font-weight: 600;
            width: 100%;
            box-shadow: var(--button-primary-shadow);
            font-family: var(--font-family);
            margin-top: var(--element-spacing);
        }

        .btn-primary-custom:hover {
            background-color: #3ab3d6; 
            box-shadow: 0px 2px 4px rgba(0,0,0,0.1);
        }

        .forgot-link {
            display: block;
            text-align: center;
            font-size: 14px;
            color: var(--text-link);
            text-decoration: none;
            margin-top: var(--spacing-l);
        }

        .forgot-link:hover {
            text-decoration: underline;
        }

        .alert {
            font-size: 14px;
            text-align: center;
            margin-bottom: var(--spacing-m);
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo-container">
            <h1 class="brand-title">SIEDUCRES</h1>
        </div>

        <?php if (!empty($_GET['error'])): ?>
            <div class="alert alert-danger" role="alert">
                <?= htmlspecialchars($_GET['error']) ?>
            </div>
        <?php elseif (!empty($_GET['mensaje'])): ?>
            <div class="alert alert-success" role="alert">
                <?= htmlspecialchars($_GET['mensaje']) ?>
            </div>
        <?php endif; ?>

        <form action="procesar_login.php" method="POST">
            <div class="mb-3">
                <label for="correo" class="form-label">Correo electrónico</label>
                <input type="email" na="comerreo" id="correo" class="form-control" required autofocus>
            </div>

            <div class="mb-3">
                <label for="contrasena" class="form-label">Contraseña</label>
                <input type="password" name="contrasena" id="contrasena" class="form-control" required>
            </div>

            <button type="submit" class="btn-primary-custom">
                Iniciar sesión
            </button>
        </form>

        <a href="notificar_olvido.php" class="forgot-link">
            ¿Olvidaste tu contraseña?
        </a>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
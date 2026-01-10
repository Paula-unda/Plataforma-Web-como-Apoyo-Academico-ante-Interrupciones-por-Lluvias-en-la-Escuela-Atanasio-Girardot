<?php
session_start();
require_once '../../funciones.php'; 
// Verificación de sesión
if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Administrador') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

// Obtener lista de estudiantes para representantes
$estudiantes = [];
try {
    $pdo = getConexion();  
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombre, e.grado, e.seccion
        FROM usuarios u
        INNER JOIN estudiantes e ON u.id = e.usuario_id
        WHERE u.rol = 'Estudiante' AND u.activo = true
        ORDER BY u.nombre
    ");
    $stmt->execute();
    $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error al cargar estudiantes: " . $e->getMessage());
}

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Administrador') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

// Mensajes
$mensaje = '';
$error = '';

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $nombre = trim($_POST['nombre'] ?? '');
        $correo = trim($_POST['correo'] ?? '');
        $rol = $_POST['rol'] ?? '';
        $telefono = trim($_POST['telefono'] ?? '');
        $grado = trim($_POST['grado'] ?? '');
        $seccion = trim($_POST['seccion'] ?? '');
        $post_estudiantes_raw = trim($_POST['estudiantes'] ?? '');
        


        // Validaciones básicas
        if (empty($nombre)) throw new Exception('El nombre es obligatorio.');
        if (empty($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) throw new Exception('El correo es inválido.');
        if (empty($telefono)) throw new Exception('El número de teléfono es obligatorio.'); 
        if (!preg_match('/^\+58\s\d{9,10}$/', $telefono)) {
            throw new Exception('El teléfono debe tener formato: +58 4121234567 (con espacio después de +58 y 9-10 dígitos).');
        }
        if (empty($rol)) throw new Exception('El rol es obligatorio.');
        if (!in_array($rol, ['Administrador', 'Docente', 'Estudiante', 'Representante'])) throw new Exception('Rol no válido.');
        

        // Validaciones por rol
        if ($rol === 'Estudiante') {
            if (empty($grado)) throw new Exception('El grado es obligatorio para estudiantes.');
            if (empty($seccion)) throw new Exception('La sección es obligatoria para estudiantes.');
        }

        // Obtener contraseña (puede ser la generada o editada)
        $contrasena_plana = trim($_POST['contrasena'] ?? '');
        if (empty($contrasena_plana)) {
            // Si no se proporcionó, generar con el nombre
            $primera_letra = strtoupper(substr($nombre, 0, 1));
            $anio = date('Y');
            $contrasena_plana = $primera_letra . $anio . 'siudecres+';
        }
        $contrasena_hash = password_hash($contrasena_plana, PASSWORD_BCRYPT);

        // Insertar en BD
        $pdo = getConexion();
        
        // Tabla principal
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (nombre, correo, contrasena, contrasena_temporal, rol, activo, telefono)
            VALUES (:nombre, :correo, :contrasena, :contrasena_temporal, :rol, :activo, :telefono)
            RETURNING id
        ");
        $stmt->execute([
            ':nombre' => $nombre,
            ':correo' => $correo,
            ':contrasena' => $contrasena_hash,
            ':contrasena_temporal' => $contrasena_plana, 
            ':rol' => $rol,
            ':activo' => !empty($_POST['activo']),
            ':telefono' => $telefono
        ]);
        $usuario_id = $stmt->fetchColumn();

        // Datos adicionales según rol
        if ($rol === 'Estudiante') {
            $stmt = $pdo->prepare("
                INSERT INTO estudiantes (usuario_id, grado, seccion) 
                VALUES (:usuario_id, :grado, :seccion)
            ");
            $stmt->execute([
                ':usuario_id' => $usuario_id,
                ':grado' => $grado,
                ':seccion' => $seccion
            ]);
        } elseif ($rol === 'Representante') {
            $estudiantes_seleccion_str = $_POST['estudiantes_seleccion'] ?? '';
            $estudiantes_seleccion = !empty($estudiantes_seleccion_str) ? explode(',', $estudiantes_seleccion_str) : [];

            if (empty($estudiantes_seleccion)) {
                throw new Exception('Debe seleccionar al menos un estudiante.');
            }

            foreach ($estudiantes_seleccion as $est_id) {
                // Validar que el ID sea numérico y exista
                if (!is_numeric($est_id) || !in_array($est_id, array_column($estudiantes, 'id'))) {
                    throw new Exception('Estudiante seleccionado no válido.');
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO representantes_estudiantes (representante_id, estudiante_id)
                    VALUES (:representante_id, :estudiante_id)
                ");
                $stmt->execute([
                    ':representante_id' => $usuario_id,
                    ':estudiante_id' => (int)$est_id
                ]);
            }
        }
        

        $mensaje = "Usuario registrado con éxito.<br>👤 <strong>Contraseña:</strong> <code>$contrasena_plana</code>";

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Usuario - SIEDUCRES</title>
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
            --primary-lime: #C2D54E;
            --primary-purple: #9B8AFB;
            --white: #FFFFFF;
            --canvas-bg: #F5F5F5;
            --text-main: #000000;
            --text-muted: #666666;
            --border-dark: #000000;
            --border-light: #CCCCCC;
        }
        /* Botón de retroceso */
        .back-button {
            position: absolute;
            top: 168px; 
            left: 24px;
            z-index: 10;
        }

        .btn-back {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #666666;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            background: white;
            padding: 6px 12px;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            transition: all 0.2s;
        }

        .btn-back:hover {
            color: #4BC4E7;
            background: #f0f9fc;
            transform: translateX(-4px);
        }

        .btn-back svg {
            width: 16px;
            height: 16px;
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

        .icon-btn svg {
            width: 20px;
            height: 20px;
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

        /* Banner */
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

        /* Contenido principal */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 40px 20px;
        }

        .page-header {
            width: 100%;
            max-width: 800px;
            margin-bottom: 32px;
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 16px;
        }

        /* Formulario */
        .form-container {
            width: 100%;
            max-width: 800px;
            background: var(--white);
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 16px;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            margin-bottom: 16px;
        }

        label {
            display: block;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 8px;
            color: var(--text-main);
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
        }

        .form-select {
            appearance: none;
            background-image: url("image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23333333' viewBox='0 0 16 16'%3E%3Cpath d='M8 11l-5-5h10l-5 5z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
        }

        .conditional-fields {
            background-color: #f8f9fa;
            border-radius: 16px;
            padding: 20px;
            margin-top: 20px;
            border-left: 4px solid var(--primary-cyan);
        }

        .conditional-title {
            font-weight: 700;
            color: var(--primary-cyan);
            margin-bottom: 12px;
            font-size: 16px;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-success {
            background-color: #e8f7fc;
            border: 1px solid var(--primary-cyan);
            color: var(--text-main);
        }

        .alert-error {
            background-color: #fde8ec;
            border: 1px solid var(--primary-pink);
            color: var(--text-main);
        }

        .btn-primary {
            background-color: var(--primary-cyan);
            color: var(--text-main);
            border: none;
            border-radius: 4px;
            padding: 12px 24px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.2s;
            width: 100%;
        }

        .btn-primary:hover {
            background-color: #3ab3d6;
        }

        /* Pie de página */
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

        .select-chip-wrapper {
            position: relative;
        }

        .select-chip-wrapper .form-control {
            padding-right: 40px;
        }

        .dropdown-list {
            display: none;
            position: absolute;
            width: 100%;
            background: white;
            border: 1px solid var(--border-light);
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .dropdown-item {
            padding: 8px 16px;
            cursor: pointer;
            transition: background 0.2s;
        }

        .dropdown-item:hover {
            background-color: #f0f0f0;
        }

        .chips-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
            min-height: 40px;
            padding: 8px 0;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            background: #f9f9f9;
        }

        .chip {
            display: flex;
            align-items: center;
            background: var(--primary-cyan);
            color: white;
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 14px;
            gap: 8px;
            cursor: pointer;
        }

        .chip .remove {
            font-size: 12px;
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .chip .remove:hover {
            opacity: 1;
        }
        
    </style>
</head>
<body>

    <!--Encabezado -->
    <header class="header">
        <div class="header-left">
            <img src="../../../assets/logo.svg" alt="SIEDUCRES" class="logo">
        </div>
        <div class="header-right">
            <div class="icon-btn">
                <!-- Campana-->
                <svg width="20" height="20" viewBox="0 0 24 24" fill="#333333">
                    <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/>
                </svg>
            </div>
            <div class="icon-btn">
                <!--Perfil -->
                <svg width="20" height="20" viewBox="0 0 24 24" fill="#333333">
                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                </svg>
            </div>
            <div class="icon-btn" id="menu-toggle">
                <!--Menú-->
                <svg width="20" height="20" viewBox="0 0 24 24" fill="#333333">
                    <path d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/>
                </svg>
            </div>
    </header>

    <!--Banner -->
    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image">
        <div class="banner-content">
            <h1 class="banner-title">¡Bienvenidos a SIEDUCRES!</h1>
        </div>
    </div>
    <!-- Botón de retroceso -->
    <div class="back-button">
        <a href="gestion_usuarios.php" class="btn-back">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                <path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/>
            </svg>
            <span>Volver a Gestión de Usuarios</span>
        </a>
    </div>
    <!--Contenido principal -->
    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">Registrar nuevo usuario</h1>
        </div>

        <div class="form-container">
            <?php if ($mensaje): ?>
                <div class="alert alert-success"><?= $mensaje ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" id="registroForm">
                <div class="form-row">
                    <div class="form-group">
                        <label for="nombre">Nombre completo *</label>
                        <input type="text" id="nombre" name="nombre" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="correo">Correo electrónico *</label>
                        <input type="email" id="correo" name="correo" class="form-control" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="rol">Rol *</label>
                        <select id="rol" name="rol" class="form-control form-select" required onchange="mostrarCamposCondicionales()">
                            <option value="">Seleccionar rol</option>
                            <option value="Administrador">Administrador</option>
                            <option value="Docente">Docente</option>
                            <option value="Estudiante">Estudiante</option>
                            <option value="Representante">Representante</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="activo" value="1" checked>
                            Cuenta activa
                        </label>
                        <small class="text-muted">Desmarca para desactivar temporalmente</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="telefono">Teléfono *</label>
                    <input type="tel" id="telefono" name="telefono" class="form-control" placeholder="+58..." required>
                </div>
                <div class="form-group">
                    <label for="contrasena">Contraseña</label>
                    <input type="text" id="contrasena" name="contrasena" class="form-control"
                        placeholder="Se generará al escribir el nombre">
                    <small class="text-muted">
                        Ej: <code>A2026siudecres+</code> (primera letra + año + "siudecres+")
                    </small>
                </div>
                <!-- Campos condicionales -->
                <div id="camposEstudiante" class="conditional-fields" style="display:none;">
                    <div class="conditional-title">Datos del estudiante</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="grado">Grado *</label>
                            <select id="grado" name="grado" class="form-control form-select" required>
                                <option value="">Seleccionar</option>
                                <option value="1ro">1ro</option>
                                <option value="2do">2do</option>
                                <option value="3ero">3ero</option>
                                <option value="4to">4to</option>
                                <option value="5to">5to</option>
                                <option value="6to">6to</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="seccion">Sección *</label>
                            <select id="seccion" name="seccion" class="form-control form-select" required>
                                <option value="">Seleccionar</option> 
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="C">C</option>
                                <option value="D">D</option>
                                <option value="U">Única</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="camposRepresentante" class="conditional-fields" style="display:none;">
                    <div class="conditional-title">Estudiantes a cargo</div>
                    <div class="form-group">
                        <label for="estudiantes_seleccion">Seleccionar estudiantes *</label>

                        <!-- Campo de entrada para buscar -->
                        <div class="select-chip-wrapper">
                            <input type="text" id="searchEstudiantes" class="form-control" placeholder="Buscar estudiante..." autocomplete="off">

                            <!-- Lista desplegable -->
                            <div id="estudiantesList" class="dropdown-list">
                                <?php foreach ($estudiantes as $est): ?>
                                    <div class="dropdown-item" data-id="<?= $est['id'] ?>">
                                        <?= htmlspecialchars($est['nombre']) ?> 
                                        (<?= htmlspecialchars($est['grado']) ?>-<?= htmlspecialchars($est['seccion']) ?>)
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div id="selectedChips" class="chips-container">
                            </div>
                        </div>

                        <small class="text-muted">Haz clic en un estudiante para agregarlo. Haz clic en la "x" para quitarlo.</small>
                    </div>
                </div>

                <button type="submit" class="btn-primary">+ Registrar usuario</button>
            </form>
        </div>
    </main>

    <!-- Pie de página -->
    <footer class="footer">
        <span>v2.0.0</span>
        <span>Soporte Técnico</span>
    </footer>

    <script>
        function mostrarCamposCondicionales() {
            const rol = document.getElementById('rol').value;
            const gradoSelect = document.getElementById('grado');
            const seccionSelect = document.getElementById('seccion');
            

            if (rol === 'Estudiante') {
                document.getElementById('camposEstudiante').style.display = 'block';
                gradoSelect.setAttribute('required', 'required');
                seccionSelect.setAttribute('required', 'required');
            } else {
                
                document.getElementById('camposEstudiante').style.display = 'none';
                gradoSelect.removeAttribute('required');
                seccionSelect.removeAttribute('required');
                

                gradoSelect.value = '';
                seccionSelect.value = '';
            }
            
            // Mostrar/Ocultar campos de representante
            document.getElementById('camposRepresentante').style.display = 
                rol === 'Representante' ? 'block' : 'none';
        }

        // Ejecutar al cargar y al cambiar
        document.addEventListener('DOMContentLoaded', function() {
            const rolSelect = document.getElementById('rol');
            rolSelect.addEventListener('change', mostrarCamposCondicionales);
            
            // Inicializar estado
            mostrarCamposCondicionales();

            // --- Resto del código para chips ---
            const searchInput = document.getElementById('searchEstudiantes');
            const dropdownList = document.getElementById('estudiantesList');
            const selectedChips = document.getElementById('selectedChips');
            const form = document.getElementById('registroForm');

            searchInput?.addEventListener('focus', () => dropdownList.style.display = 'block');
            
            searchInput?.addEventListener('blur', () => {
                setTimeout(() => {
                    if (!dropdownList.matches(':hover') && !searchInput.matches(':focus')) {
                        dropdownList.style.display = 'none';
                    }
                }, 200);
            });

            searchInput?.addEventListener('input', function() {
                const filter = this.value.toLowerCase();
                dropdownList.querySelectorAll('.dropdown-item').forEach(item => {
                    item.style.display = item.textContent.toLowerCase().includes(filter) ? 'block' : 'none';
                });
            });

            dropdownList?.addEventListener('click', function(e) {
                if (e.target.classList.contains('dropdown-item')) {
                    const id = e.target.getAttribute('data-id');
                    const name = e.target.textContent;
                    if (selectedChips.querySelector(`[data-id="${id}"]`)) return;

                    const chip = document.createElement('div');
                    chip.className = 'chip';
                    chip.dataset.id = id;
                    chip.innerHTML = `${name} <span class="remove">×</span>`;
                    chip.querySelector('.remove').addEventListener('click', e => {
                        e.stopPropagation(); chip.remove(); updateHiddenField();
                    });
                    selectedChips.appendChild(chip);
                    updateHiddenField();
                    searchInput.value = '';
                    dropdownList.style.display = 'none';
                }
            });
            // Dentro de DOMContentLoaded:
            function updateHiddenField() {
                const ids = Array.from(selectedChips.children).map(chip => chip.dataset.id);
                let hidden = document.querySelector('input[name="estudiantes_seleccion"]');
                
                // Si no existe el campo oculto, créalo
                if (!hidden) {
                    hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'estudiantes_seleccion';
                    form.appendChild(hidden);
                }
                
                // Siempre asigna un valor 
                hidden.value = ids.join(',');
            }

            // Ejecutar al cargar para crear el campo oculto desde el inicio
            updateHiddenField();


            // Generar contraseña
            document.getElementById('nombre')?.addEventListener('input', function() {
                const nombre = this.value.trim();
                const campo = document.getElementById('contrasena');
                if (nombre && !campo.value) {
                    const letra = nombre.charAt(0).toUpperCase();
                    const anio = new Date().getFullYear();
                    campo.value = letra + anio + 'siudecres+';
                }
            });
        });

        // Menú hamburguesa
        document.getElementById('menu-toggle')?.addEventListener('click', function() {
            const dropdown = document.getElementById('dropdown');
            if (dropdown) dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        });

        document.addEventListener('click', function(e) {
            const dropdown = document.getElementById('dropdown');
            const toggle = document.getElementById('menu-toggle');
            if (dropdown && toggle && !toggle.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    </script>

</body>
</html>
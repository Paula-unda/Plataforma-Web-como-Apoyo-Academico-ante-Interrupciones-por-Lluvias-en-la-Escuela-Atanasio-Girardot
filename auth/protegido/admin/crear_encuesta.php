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
$error = '';
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo = $_POST['titulo'] ?? '';
    $descripcion = $_POST['descripcion'] ?? '';
    $instrucciones = $_POST['instrucciones'] ?? '';
    $fecha_cierre = $_POST['fecha_cierre'] ?? '';
    $dirigido_a = $_POST['dirigido_a'] ?? 'todos';
    $grado = $_POST['grado'] ?? null;
    $seccion = $_POST['seccion'] ?? null;
    
    try {
        $conexion->beginTransaction();
        
        // Insertar encuesta
        $stmt = $conexion->prepare("
            INSERT INTO encuestas 
            (titulo, descripcion, instrucciones, fecha_cierre, dirigido_a, grado, seccion, creado_por)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$titulo, $descripcion, $instrucciones, $fecha_cierre, $dirigido_a, $grado, $seccion, $_SESSION['usuario_id']]);
        $encuesta_id = $conexion->lastInsertId();
        
        // Insertar preguntas
        $preguntas = $_POST['preguntas'] ?? [];
        $tipos = $_POST['tipos'] ?? [];
        $opciones = $_POST['opciones'] ?? [];
        
        $orden = 0;
        foreach ($preguntas as $index => $pregunta) {
            if (empty($pregunta)) continue;
            
            $tipo = $tipos[$index] ?? 'texto';
            $opciones_json = null;
            
            if (in_array($tipo, ['opcion_multiple', 'casilla_verificacion']) && !empty($opciones[$index])) {
                $opciones_array = array_filter(array_map('trim', explode("\n", $opciones[$index])));
                $opciones_json = json_encode($opciones_array);
            }
            
            $stmt_p = $conexion->prepare("
                INSERT INTO preguntas_encuesta (encuesta_id, pregunta, tipo, opciones, orden)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt_p->execute([$encuesta_id, $pregunta, $tipo, $opciones_json, $orden]);
            $orden++;
        }
        
        // Actualizar total de preguntas
        $stmt_up = $conexion->prepare("UPDATE encuestas SET total_preguntas = ? WHERE id = ?");
        $stmt_up->execute([$orden, $encuesta_id]);
        
        // Log
        logEncuesta($conexion, $encuesta_id, $_SESSION['usuario_id'], 'crear', "Encuesta creada con $orden preguntas");
        
        $conexion->commit();
        
        $_SESSION['mensaje'] = "Encuesta creada exitosamente";
        header('Location: encuestas.php');
        exit();
        
    } catch (Exception $e) {
        $conexion->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Obtener grados y secciones para filtros
$grados = $conexion->query("SELECT DISTINCT grado FROM estudiantes WHERE grado IS NOT NULL ORDER BY grado")->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Crear Encuesta - SIEDUCRES</title>
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
            --primary-cyan: #4BC4E7;
            --primary-pink: #EF5E8E;
            --primary-lime: #C2D54E;
            --primary-purple: #9b8afb;
            --text-dark: #333333;
            --text-muted: #666666;
            --border: #E0E0E0;
            --surface: #FFFFFF;
            --background: #F5F5F5;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--background);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header */
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
            color: var(--text-dark);
            margin-bottom: 8px;
        }

        /* Contenedor principal - MISMO TAMAÑO EN DESKTOP */
        .container {
            max-width: 800px;
            margin: 40px auto;
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            width: 90%;
        }

        /* Títulos - MISMOS TAMAÑOS EN DESKTOP */
        h1 {
            color: var(--primary-cyan);
            margin-bottom: 30px;
            font-size: 28px;
        }

        h2 {
            color: var(--primary-purple);
            font-size: 20px;
            margin: 30px 0 20px;
        }

        /* Formulario - MISMOS TAMAÑOS */
        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-dark);
            font-size: 14px;
        }

        input, textarea, select {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            transition: border-color 0.2s;
        }

        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: var(--primary-cyan);
            box-shadow: 0 0 0 3px rgba(75, 196, 231, 0.1);
        }

        /* Grid de 2 columnas */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        /* Preguntas */
        .pregunta-item {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid var(--primary-cyan);
            position: relative;
        }

        .btn-eliminar {
            background: var(--primary-pink);
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            float: right;
            margin-bottom: 10px;
        }

        .btn-eliminar:hover {
            opacity: 0.9;
        }

        /* Botones */
        .btn-agregar {
            background: var(--primary-lime);
            color: var(--text-dark);
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            margin: 10px 0 20px;
            font-size: 14px;
            width: 100%;
        }

        .btn-agregar:hover {
            opacity: 0.9;
        }

        .btn-guardar {
            background: var(--primary-cyan);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            font-size: 16px;
            margin-top: 20px;
            transition: transform 0.2s;
        }

        .btn-guardar:hover {
            transform: translateY(-2px);
            opacity: 0.9;
        }

        /* Opciones */
        .opciones-textarea {
            margin-top: 15px;
            font-size: 13px;
            color: #666;
        }

        .opciones-textarea textarea {
            margin-top: 5px;
        }

        /* Mensaje de error */
        .error-mensaje {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }

        /* Footer */
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
            margin-top: auto;
        }

        /* ===== MEDIA QUERIES SÓLO PARA MÓVIL ===== */
        @media (max-width: 768px) {
            .header {
                padding: 0 16px;
            }

            .logo {
                height: 32px;
            }

            .banner {
                height: 80px;
            }

            .banner-title {
                font-size: 28px;
            }

            .container {
                width: 95%;
                padding: 20px;
                margin: 20px auto;
            }

            h1 {
                font-size: 24px;
                margin-bottom: 20px;
            }

            h2 {
                font-size: 18px;
                margin: 20px 0 15px;
            }

            .grid-2 {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .pregunta-item {
                padding: 15px;
            }

            .btn-eliminar {
                padding: 6px 10px;
                font-size: 12px;
            }

            .btn-agregar {
                padding: 10px 20px;
                font-size: 14px;
            }

            .btn-guardar {
                padding: 12px 24px;
                font-size: 15px;
            }

            .footer {
                padding: 0 16px;
                font-size: 12px;
            }
        }

        /* Para pantallas muy pequeñas */
        @media (max-width: 480px) {
            .container {
                padding: 15px;
            }

            h1 {
                font-size: 22px;
            }

            .pregunta-item {
                padding: 12px;
            }

            .btn-eliminar {
                float: none;
                display: block;
                width: 100%;
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header_comun.php'; ?>
    <!-- Banner -->
    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner SIEDUCRES" class="banner-image" onerror="this.style.display='none'">
    </div>

    <div class="banner-content">
        <h1 class="banner-title">Crear Nueva Encuesta</h1>
    </div>

    <!-- Contenido principal -->
    <div class="container">
        <?php if ($error): ?>
            <div class="error-mensaje">❌ <?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST" id="formEncuesta">
            <!-- Datos básicos -->
            <div class="form-group">
                <label>Título de la encuesta:</label>
                <input type="text" name="titulo" required placeholder="Ej: Evaluación del Primer Lapso">
            </div>
            
            <div class="form-group">
                <label>Descripción:</label>
                <textarea name="descripcion" rows="3" required placeholder="Explica el propósito de la encuesta"></textarea>
            </div>
            
            <div class="form-group">
                <label>Instrucciones (opcional):</label>
                <textarea name="instrucciones" rows="2" placeholder="Cómo deben responder los usuarios"></textarea>
            </div>
            
            <div class="grid-2">
                <div class="form-group">
                    <label>Fecha de cierre:</label>
                    <input type="date" name="fecha_cierre" required value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                </div>
                
                <div class="form-group">
                    <label>Dirigido a:</label>
                    <select name="dirigido_a" id="dirigido_a" required>
                        <option value="todos">Todos los usuarios</option>
                        <option value="estudiantes">Solo estudiantes</option>
                        <option value="docentes">Solo docentes</option>
                        <option value="representantes">Solo representantes</option>
                    </select>
                </div>
            </div>
            
            <div id="filtros_grado" style="display: none;" class="grid-2">
                <div class="form-group">
                    <label>Grado (opcional):</label>
                    <select name="grado">
                        <option value="">Todos los grados</option>
                        <?php foreach ($grados as $g): ?>
                            <option value="<?php echo $g; ?>"><?php echo $g; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sección (opcional):</label>
                    <input type="text" name="seccion" placeholder="Ej: A">
                </div>
            </div>
            
            <h2>Preguntas</h2>
            
            <div id="preguntas-container">
                <!-- Las preguntas se agregarán aquí dinámicamente -->
            </div>
            
            <button type="button" class="btn-agregar" onclick="agregarPregunta()">➕ Agregar Pregunta</button>
            
            <button type="submit" class="btn-guardar">📋 Guardar Encuesta</button>
        </form>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <span>v2.0.0</span>
        <span>Soporte Técnico</span>
    </footer>

    <script>
        let contadorPreguntas = 0;
        
        function agregarPregunta() {
            const container = document.getElementById('preguntas-container');
            const div = document.createElement('div');
            div.className = 'pregunta-item';
            div.id = `pregunta_${contadorPreguntas}`;
            
            div.innerHTML = `
                <button type="button" class="btn-eliminar" onclick="eliminarPregunta(${contadorPreguntas})">🗑️ Eliminar</button>
                <div style="margin-bottom: 10px; clear: both;">
                    <label>Pregunta:</label>
                    <input type="text" name="preguntas[${contadorPreguntas}]" required placeholder="Escribe la pregunta...">
                </div>
                <div style="margin-bottom: 10px;">
                    <label>Tipo de respuesta:</label>
                    <select name="tipos[${contadorPreguntas}]" onchange="mostrarOpciones(${contadorPreguntas}, this.value)">
                        <option value="texto">Texto libre</option>
                        <option value="opcion_multiple">Opción múltiple (una respuesta)</option>
                        <option value="casilla_verificacion">Casillas de verificación (varias)</option>
                        <option value="escala_1_5">Escala 1-5</option>
                        <option value="escala_1_10">Escala 1-10</option>
                        <option value="si_no">Sí/No</option>
                    </select>
                </div>
                <div id="opciones_${contadorPreguntas}" style="display: none;" class="opciones-textarea">
                    <label>Opciones (una por línea):</label>
                    <textarea name="opciones[${contadorPreguntas}]" rows="3" placeholder="Opción 1&#10;Opción 2&#10;Opción 3"></textarea>
                </div>
            `;
            
            container.appendChild(div);
            contadorPreguntas++;
        }
        
        function eliminarPregunta(id) {
            document.getElementById(`pregunta_${id}`).remove();
        }
        
        function mostrarOpciones(id, tipo) {
            const divOpciones = document.getElementById(`opciones_${id}`);
            if (tipo === 'opcion_multiple' || tipo === 'casilla_verificacion') {
                divOpciones.style.display = 'block';
            } else {
                divOpciones.style.display = 'none';
            }
        }
        
        // Mostrar filtros solo para estudiantes
        document.getElementById('dirigido_a').addEventListener('change', function() {
            const filtros = document.getElementById('filtros_grado');
            filtros.style.display = this.value === 'estudiantes' ? 'grid' : 'none';
        });
        
        // Agregar primera pregunta por defecto
        window.onload = function() {
            agregarPregunta();
        };

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
<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/encuestas_funciones.php';
require_once '../includes/onesignal_config.php';

if (!sesionActiva()) {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$conexion = getConexion();
$usuario_id = $_SESSION['usuario_id'];
$encuesta_id = $_GET['id'] ?? 0;

// Verificar que la encuesta existe y está activa
$stmt = $conexion->prepare("
    SELECT * FROM encuestas 
    WHERE id = ? AND activo = true AND fecha_cierre >= CURRENT_DATE
");
$stmt->execute([$encuesta_id]);
$encuesta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$encuesta) {
    header('Location: encuestas_disponibles.php?error=Encuesta+no+disponible');
    exit();
}

// Verificar que el usuario puede responder
if (!puedeResponderEncuesta($conexion, $usuario_id, $encuesta_id)) {
    header('Location: encuestas_disponibles.php?error=Ya+has+respondido+esta+encuesta');
    exit();
}

// Obtener preguntas
$preguntas = obtenerPreguntasEncuesta($conexion, $encuesta_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conexion->beginTransaction();
        
        $ip = $_SERVER['REMOTE_ADDR'];
        $user_agent = $_SERVER['HTTP_USER_AGENT'];
        
        foreach ($preguntas as $p) {
            $respuesta = $_POST['pregunta_' . $p['id']] ?? '';
            $respuesta_json = null;
            
            // Procesar según tipo
            if ($p['tipo'] === 'casilla_verificacion' && is_array($respuesta)) {
                $respuesta_json = json_encode($respuesta);
                $respuesta = implode(', ', $respuesta);
            }
            
            if (!empty($respuesta) || !$p['obligatoria']) {
                $stmt_r = $conexion->prepare("
                    INSERT INTO respuestas_encuesta 
                    (encuesta_id, pregunta_id, usuario_id, respuesta, respuesta_json, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_r->execute([
                    $encuesta_id, $p['id'], $usuario_id, 
                    $respuesta, $respuesta_json, $ip, $user_agent
                ]);
            }
        }
        
        // Actualizar contador en encuesta
        $stmt_up = $conexion->prepare("
            UPDATE encuestas SET total_respuestas = total_respuestas + 1 WHERE id = ?
        ");
        $stmt_up->execute([$encuesta_id]);
        
        // Log
        logEncuesta($conexion, $encuesta_id, $usuario_id, 'responder', "Usuario respondió encuesta");
        
        $conexion->commit();
        
        header('Location: encuestas_disponibles.php?exito=Encuesta+respondida+con+éxito');
        exit();
        
    } catch (Exception $e) {
        $conexion->rollBack();
        $error = "Error al guardar respuestas: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Responder Encuesta - SIEDUCRES</title>
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

        /* Header móvil */
        .mobile-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .back-button {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--primary-pink);
            padding: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s;
        }

        .back-button:hover {
            background: rgba(239, 94, 142, 0.1);
        }

        .logo {
            height: 32px;
        }

        .header-right {
            display: flex;
            gap: 8px;
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

        /* Contenedor principal */
        .container {
            flex: 1;
            padding: 16px;
            max-width: 800px;
            width: 100%;
            margin: 0 auto;
        }

        /* Encabezado de encuesta */
        .encuesta-header {
            background: var(--surface);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border-left: 4px solid var(--primary-cyan);
        }

        .encuesta-titulo {
            color: var(--primary-cyan);
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 12px;
            line-height: 1.3;
        }

        @media (min-width: 768px) {
            .encuesta-titulo {
                font-size: 28px;
            }
        }

        .encuesta-descripcion {
            color: var(--text-muted);
            margin: 15px 0;
            line-height: 1.6;
            font-size: 15px;
        }

        .instrucciones-box {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 12px;
            margin: 16px 0;
            border-left: 3px solid var(--primary-purple);
        }

        .fecha-cierre {
            color: #999;
            font-size: 14px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px dashed var(--border);
        }

        /* Preguntas */
        .pregunta-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: transform 0.2s;
        }

        .pregunta-card:active {
            transform: scale(0.99);
        }

        .pregunta-texto {
            font-weight: 600;
            margin-bottom: 16px;
            color: var(--text-dark);
            font-size: 16px;
            line-height: 1.5;
            padding-right: 24px;
        }

        .obligatoria {
            color: var(--primary-pink);
            font-size: 13px;
            margin-left: 8px;
            font-weight: 400;
        }

        /* Campos de texto */
        textarea {
            width: 100%;
            padding: 14px 16px;
            border: 1.5px solid var(--border);
            border-radius: 12px;
            font-family: 'Inter', sans-serif;
            font-size: 16px;
            background: white;
            transition: all 0.2s;
            resize: vertical;
            min-height: 100px;
        }

        textarea:focus {
            outline: none;
            border-color: var(--primary-cyan);
            box-shadow: 0 0 0 3px rgba(75, 196, 231, 0.1);
        }

        /* Opciones */
        .opciones-grid {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .opcion-item {
            display: flex;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .opcion-item:last-child {
            border-bottom: none;
        }

        .opcion-item input[type="radio"],
        .opcion-item input[type="checkbox"] {
            width: 24px;
            height: 24px;
            margin-right: 14px;
            accent-color: var(--primary-cyan);
            flex-shrink: 0;
        }

        .opcion-item label {
            font-size: 16px;
            color: var(--text-dark);
            flex: 1;
            line-height: 1.4;
        }

        /* Escalas */
        .escala-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(50px, 1fr));
            gap: 8px;
            margin-top: 12px;
        }

        .escala-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            background: #f8f9fa;
            padding: 12px 4px;
            border-radius: 12px;
            border: 2px solid transparent;
            transition: all 0.2s;
            cursor: pointer;
        }

        .escala-item.selected {
            background: white;
            border-color: var(--primary-cyan);
            box-shadow: 0 2px 8px rgba(75, 196, 231, 0.2);
        }

        .escala-item input[type="radio"] {
            width: 20px;
            height: 20px;
            margin-bottom: 6px;
            accent-color: var(--primary-cyan);
            cursor: pointer;
        }

        .escala-item span {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-dark);
        }

        /* Botones */
        .btn-enviar {
            background: var(--primary-lime);
            color: var(--text-dark);
            padding: 18px 24px;
            border: none;
            border-radius: 16px;
            font-weight: 700;
            font-size: 18px;
            cursor: pointer;
            width: 100%;
            margin: 24px 0 16px;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(194, 213, 78, 0.3);
            -webkit-appearance: none;
            appearance: none;
        }

        .btn-enviar:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(194, 213, 78, 0.4);
        }

        .btn-enviar:active {
            transform: translateY(0);
        }

        .btn-volver {
            background: #f0f0f0;
            color: var(--text-muted);
            padding: 16px 24px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            width: 100%;
            text-decoration: none;
            display: block;
            text-align: center;
            transition: all 0.2s;
        }

        .btn-volver:hover {
            background: #e0e0e0;
            color: var(--text-dark);
        }

        /* Error */
        .error-mensaje {
            background: #f8d7da;
            color: #721c24;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .error-mensaje::before {
            content: "⚠️";
            font-size: 20px;
        }

        /* Barra de progreso */
        .progreso-container {
            background: var(--surface);
            border-radius: 30px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
        }

        .progreso-info {
            font-size: 14px;
            color: var(--text-muted);
            white-space: nowrap;
        }

        .progreso-barra {
            flex: 1;
            height: 8px;
            background: #f0f0f0;
            border-radius: 4px;
            overflow: hidden;
        }

        .progreso-llenado {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-cyan), var(--primary-purple));
            border-radius: 4px;
            width: 0%;
            transition: width 0.3s;
        }

        /* Footer */
        .footer {
            text-align: center;
            padding: 20px 16px;
            color: var(--text-muted);
            font-size: 13px;
            border-top: 1px solid var(--border);
            margin-top: 20px;
            background: var(--surface);
        }
        /* Menú hamburguesa */
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

        /* Ajustar posición del header para que no tape la flecha */
        .mobile-header {
            position: relative;
            z-index: 100;
        }

        /* Animaciones */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .pregunta-card {
            animation: slideIn 0.3s ease-out;
        }

        /* Ajustes para pantallas muy pequeñas */
        @media (max-width: 360px) {
            .encuesta-titulo {
                font-size: 20px;
            }
            
            .escala-container {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .progreso-container {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        /* Modo oscuro opcional */
        @media (prefers-color-scheme: dark) {
            :root {
                --background: #1a1a1a;
                --surface: #2d2d2d;
                --text-dark: #ffffff;
                --text-muted: #b0b0b0;
                --border: #404040;
            }
            
            .pregunta-card,
            .encuesta-header,
            .mobile-header,
            .footer,
            .progreso-container {
                background: var(--surface);
            }
            
            textarea,
            .instrucciones-box {
                background: #3d3d3d;
                color: white;
                border-color: #555;
            }
            
            .escala-item {
                background: #3d3d3d;
            }
            
            .escala-item.selected {
                background: #4d4d4d;
            }
        }

        /* Mejoras táctiles */
        button, a, input[type="radio"], input[type="checkbox"], label {
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }

        input[type="radio"]:checked + label,
        input[type="checkbox"]:checked + label {
            font-weight: 600;
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header_comun.php'; ?>
    <!-- Flecha de retroceso DEBAJO del header -->
    <div style="padding: 8px 16px; background: white; border-bottom: 1px solid var(--border);">
        <button class="back-button" onclick="window.location.href='encuestas_disponibles.php'" 
                style="background: none; border: none; font-size: 16px; cursor: pointer; color: var(--primary-pink); display: flex; align-items: center; gap: 6px; padding: 8px 0;">
            <span style="font-size: 20px;">←</span>
            <span>Volver a encuestas</span>
        </button>
    </div>



    <div class="container">
        <!-- Barra de progreso -->
        <div class="progreso-container">
            <span class="progreso-info">Progreso</span>
            <div class="progreso-barra">
                <div class="progreso-llenado" id="barraProgreso"></div>
            </div>
            <span class="progreso-info" id="contadorProgreso">0/<?php echo count($preguntas); ?></span>
        </div>

        <!-- Encabezado de la encuesta -->
        <div class="encuesta-header">
            <div class="encuesta-titulo">
                <?php echo htmlspecialchars($encuesta['titulo']); ?>
            </div>
            <div class="encuesta-descripcion">
                <?php echo nl2br(htmlspecialchars($encuesta['descripcion'])); ?>
            </div>
            <?php if (!empty($encuesta['instrucciones'])): ?>
                <div class="instrucciones-box">
                    <strong>📋 Instrucciones:</strong><br>
                    <?php echo nl2br(htmlspecialchars($encuesta['instrucciones'])); ?>
                </div>
            <?php endif; ?>
            <div class="fecha-cierre">
                ⏰ Esta encuesta cierra el <?php echo date('d/m/Y', strtotime($encuesta['fecha_cierre'])); ?>
            </div>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="error-mensaje">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" id="formEncuesta">
            <?php foreach ($preguntas as $index => $p): ?>
            <div class="pregunta-card" data-pregunta-id="<?php echo $p['id']; ?>">
                <div class="pregunta-texto">
                    <?php echo ($index + 1) . '. ' . htmlspecialchars($p['pregunta']); ?>
                    <?php if ($p['obligatoria']): ?>
                        <span class="obligatoria">*Obligatoria</span>
                    <?php endif; ?>
                </div>
                
                <?php if ($p['tipo'] === 'texto'): ?>
                    <textarea name="pregunta_<?php echo $p['id']; ?>" 
                              <?php echo $p['obligatoria'] ? 'required' : ''; ?>
                              placeholder="Escribe tu respuesta aquí..."></textarea>
                
                <?php elseif ($p['tipo'] === 'si_no'): ?>
                    <div class="opciones-grid">
                        <label class="opcion-item">
                            <input type="radio" name="pregunta_<?php echo $p['id']; ?>" value="Sí" <?php echo $p['obligatoria'] ? 'required' : ''; ?>>
                            <span>Sí</span>
                        </label>
                        <label class="opcion-item">
                            <input type="radio" name="pregunta_<?php echo $p['id']; ?>" value="No">
                            <span>No</span>
                        </label>
                    </div>
                
                <?php elseif ($p['tipo'] === 'escala_1_5'): ?>
                    <div class="escala-container">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <label class="escala-item">
                            <input type="radio" name="pregunta_<?php echo $p['id']; ?>" value="<?php echo $i; ?>" <?php echo $p['obligatoria'] ? 'required' : ''; ?>>
                            <span><?php echo $i; ?></span>
                        </label>
                        <?php endfor; ?>
                    </div>
                
                <?php elseif ($p['tipo'] === 'escala_1_10'): ?>
                    <div class="escala-container">
                        <?php for ($i = 1; $i <= 10; $i++): ?>
                        <label class="escala-item">
                            <input type="radio" name="pregunta_<?php echo $p['id']; ?>" value="<?php echo $i; ?>" <?php echo $p['obligatoria'] ? 'required' : ''; ?>>
                            <span><?php echo $i; ?></span>
                        </label>
                        <?php endfor; ?>
                    </div>
                
                <?php elseif ($p['tipo'] === 'opcion_multiple' && $p['opciones']): 
                    $opciones = json_decode($p['opciones'], true);
                    if (is_array($opciones)):
                ?>
                    <div class="opciones-grid">
                        <?php foreach ($opciones as $opcion): ?>
                        <label class="opcion-item">
                            <input type="radio" name="pregunta_<?php echo $p['id']; ?>" value="<?php echo htmlspecialchars($opcion); ?>" <?php echo $p['obligatoria'] ? 'required' : ''; ?>>
                            <span><?php echo htmlspecialchars($opcion); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                <?php 
                    endif;
                
                elseif ($p['tipo'] === 'casilla_verificacion' && $p['opciones']):
                    $opciones = json_decode($p['opciones'], true);
                    if (is_array($opciones)):
                ?>
                    <div class="opciones-grid">
                        <?php foreach ($opciones as $opcion): ?>
                        <label class="opcion-item">
                            <input type="checkbox" name="pregunta_<?php echo $p['id']; ?>[]" value="<?php echo htmlspecialchars($opcion); ?>">
                            <span><?php echo htmlspecialchars($opcion); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                <?php 
                    endif;
                endif; ?>
            </div>
            <?php endforeach; ?>
            
            <button type="submit" class="btn-enviar">📤 Enviar Respuestas</button>
        </form>
        
        <a href="encuestas_disponibles.php" class="btn-volver">← Volver a encuestas</a>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>© <?php echo date('Y'); ?> SIEDUCRES - Plataforma Educativa</p>
        <p style="font-size: 11px; margin-top: 4px;">v2.0.0</p>
    </div>

    <script>
        // Calcular progreso
        function actualizarProgreso() {
            const preguntas = document.querySelectorAll('.pregunta-card');
            const total = preguntas.length;
            let respondidas = 0;
            
            preguntas.forEach(pregunta => {
                const radios = pregunta.querySelectorAll('input[type="radio"]:checked');
                const checkboxes = pregunta.querySelectorAll('input[type="checkbox"]:checked');
                const textarea = pregunta.querySelector('textarea');
                
                if (radios.length > 0 || checkboxes.length > 0) {
                    respondidas++;
                } else if (textarea && textarea.value.trim() !== '') {
                    respondidas++;
                }
            });
            
            const porcentaje = (respondidas / total) * 100;
            document.getElementById('barraProgreso').style.width = porcentaje + '%';
            document.getElementById('contadorProgreso').innerText = respondidas + '/' + total;
        }

        // Actualizar progreso al cambiar respuestas
        document.querySelectorAll('input, textarea').forEach(input => {
            input.addEventListener('change', actualizarProgreso);
            input.addEventListener('keyup', actualizarProgreso);
        });

        // Resaltar escala seleccionada
        document.querySelectorAll('.escala-item input').forEach(input => {
            input.addEventListener('change', function() {
                // Quitar selected de todos los hermanos
                this.closest('.escala-container').querySelectorAll('.escala-item').forEach(item => {
                    item.classList.remove('selected');
                });
                // Agregar selected al padre
                this.closest('.escala-item').classList.add('selected');
            });
        });

        // Validar formulario antes de enviar
        document.getElementById('formEncuesta').addEventListener('submit', function(e) {
            const obligatorias = document.querySelectorAll('[required]');
            let faltan = [];
            
            obligatorias.forEach(input => {
                if (input.type === 'radio') {
                    const name = input.name;
                    const checked = document.querySelector(`input[name="${name}"]:checked`);
                    if (!checked) {
                        const pregunta = input.closest('.pregunta-card').querySelector('.pregunta-texto').innerText.substring(0, 30);
                        faltan.push(pregunta + '...');
                    }
                } else if (!input.value) {
                    const pregunta = input.closest('.pregunta-card').querySelector('.pregunta-texto').innerText.substring(0, 30);
                    faltan.push(pregunta + '...');
                }
            });
            
            if (faltan.length > 0) {
                e.preventDefault();
                alert('❌ Faltan por responder:\n' + faltan.join('\n'));
            }
        });

        // Inicializar progreso
        actualizarProgreso();
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
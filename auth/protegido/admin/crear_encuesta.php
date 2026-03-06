<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/encuestas_funciones.php';

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
<html>
<head>
    <meta charset="UTF-8">
    <title>Crear Encuesta - SIEDUCRES</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary-cyan: #4BC4E7; --primary-pink: #EF5E8E;
            --primary-lime: #C2D54E; --primary-purple: #9b8afb;
            --border: #E0E0E0; --surface: #FFFFFF; --background: #F5F5F5;
        }
        body {
            font-family: 'Inter', sans-serif; background: var(--background);
            padding: 40px 20px;
        }
        .container {
            max-width: 800px; margin: 0 auto; background: white;
            border-radius: 16px; padding: 30px; box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        h1 { color: var(--primary-cyan); margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 5px; color: #333; }
        input, textarea, select {
            width: 100%; padding: 12px; border: 1px solid var(--border);
            border-radius: 8px; font-family: 'Inter', sans-serif;
        }
        .pregunta-item {
            background: #f9f9f9; padding: 20px; border-radius: 8px;
            margin-bottom: 15px; border-left: 4px solid var(--primary-cyan);
        }
        .btn-agregar {
            background: var(--primary-lime); color: #333; border: none;
            padding: 10px 20px; border-radius: 8px; font-weight: 600;
            cursor: pointer; margin: 10px 0;
        }
        .btn-eliminar {
            background: var(--primary-pink); color: white; border: none;
            padding: 5px 10px; border-radius: 4px; cursor: pointer;
            float: right; font-size: 12px;
        }
        .btn-guardar {
            background: var(--primary-cyan); color: white; border: none;
            padding: 15px 30px; border-radius: 8px; font-weight: 600;
            cursor: pointer; width: 100%; font-size: 16px; margin-top: 20px;
        }
        .opciones-textarea {
            margin-top: 10px; font-size: 13px; color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📝 Crear Nueva Encuesta</h1>
        
        <?php if ($error): ?>
            <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                <?php echo $error; ?>
            </div>
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
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
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
            
            <div id="filtros_grado" style="display: none; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
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
            
            <h2 style="margin: 30px 0 20px; color: var(--primary-purple);">Preguntas</h2>
            
            <div id="preguntas-container">
                <!-- Las preguntas se agregarán aquí dinámicamente -->
            </div>
            
            <button type="button" class="btn-agregar" onclick="agregarPregunta()">Agregar Pregunta</button>
            
            <button type="submit" class="btn-guardar">Guardar Encuesta</button>
        </form>
    </div>
    
    <script>
        let contadorPreguntas = 0;
        
        function agregarPregunta() {
            const container = document.getElementById('preguntas-container');
            const div = document.createElement('div');
            div.className = 'pregunta-item';
            div.id = `pregunta_${contadorPreguntas}`;
            
            div.innerHTML = `
                <button type="button" class="btn-eliminar" onclick="eliminarPregunta(${contadorPreguntas})">🗑️ Eliminar</button>
                <div style="margin-bottom: 10px;">
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
    </script>
</body>
</html>
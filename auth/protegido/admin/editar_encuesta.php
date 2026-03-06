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
$encuesta_id = $_GET['id'] ?? 0;

// Obtener datos de la encuesta
$stmt = $conexion->prepare("SELECT * FROM encuestas WHERE id = ?");
$stmt->execute([$encuesta_id]);
$encuesta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$encuesta) {
    header('Location: encuestas.php?error=Encuesta+no+encontrada');
    exit();
}

// Obtener preguntas
$preguntas = obtenerPreguntasEncuesta($conexion, $encuesta_id);

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
        
        // Actualizar encuesta
        $stmt = $conexion->prepare("
            UPDATE encuestas 
            SET titulo = ?, descripcion = ?, instrucciones = ?, fecha_cierre = ?, 
                dirigido_a = ?, grado = ?, seccion = ?
            WHERE id = ?
        ");
        $stmt->execute([$titulo, $descripcion, $instrucciones, $fecha_cierre, $dirigido_a, $grado, $seccion, $encuesta_id]);
        
        // Eliminar preguntas existentes
        $stmt_del = $conexion->prepare("DELETE FROM preguntas_encuesta WHERE encuesta_id = ?");
        $stmt_del->execute([$encuesta_id]);
        
        // Insertar nuevas preguntas
        $preguntas_nuevas = $_POST['preguntas'] ?? [];
        $tipos = $_POST['tipos'] ?? [];
        $opciones = $_POST['opciones'] ?? [];
        
        $orden = 0;
        foreach ($preguntas_nuevas as $index => $pregunta) {
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
        logEncuesta($conexion, $encuesta_id, $_SESSION['usuario_id'], 'editar', "Encuesta actualizada");
        
        $conexion->commit();
        
        $_SESSION['mensaje'] = "✅ Encuesta actualizada exitosamente";
        header('Location: encuestas.php');
        exit();
        
    } catch (Exception $e) {
        $conexion->rollBack();
        $error = "❌ Error: " . $e->getMessage();
    }
}

// Obtener grados para filtros
$grados = $conexion->query("SELECT DISTINCT grado FROM estudiantes WHERE grado IS NOT NULL ORDER BY grado")->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Editar Encuesta - SIEDUCRES</title>
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
        .btn-cancelar {
            background: #ccc; color: #333; text-decoration: none;
            padding: 15px 30px; border-radius: 8px; font-weight: 600;
            display: inline-block; text-align: center; margin-top: 10px;
        }
        .opciones-textarea {
            margin-top: 10px; font-size: 13px; color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Editar Encuesta: <?php echo htmlspecialchars($encuesta['titulo']); ?></h1>
        
        <?php if ($error): ?>
            <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" id="formEncuesta">
            <!-- Datos básicos -->
            <div class="form-group">
                <label>Título de la encuesta:</label>
                <input type="text" name="titulo" required value="<?php echo htmlspecialchars($encuesta['titulo']); ?>">
            </div>
            
            <div class="form-group">
                <label>Descripción:</label>
                <textarea name="descripcion" rows="3" required><?php echo htmlspecialchars($encuesta['descripcion']); ?></textarea>
            </div>
            
            <div class="form-group">
                <label>Instrucciones (opcional):</label>
                <textarea name="instrucciones" rows="2"><?php echo htmlspecialchars($encuesta['instrucciones']); ?></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label>Fecha de cierre:</label>
                    <input type="date" name="fecha_cierre" required value="<?php echo $encuesta['fecha_cierre']; ?>">
                </div>
                
                <div class="form-group">
                    <label>Dirigido a:</label>
                    <select name="dirigido_a" id="dirigido_a" required>
                        <option value="todos" <?php echo $encuesta['dirigido_a'] == 'todos' ? 'selected' : ''; ?>>Todos los usuarios</option>
                        <option value="estudiantes" <?php echo $encuesta['dirigido_a'] == 'estudiantes' ? 'selected' : ''; ?>>Solo estudiantes</option>
                        <option value="docentes" <?php echo $encuesta['dirigido_a'] == 'docentes' ? 'selected' : ''; ?>>Solo docentes</option>
                        <option value="representantes" <?php echo $encuesta['dirigido_a'] == 'representantes' ? 'selected' : ''; ?>>Solo representantes</option>
                    </select>
                </div>
            </div>
            
            <div id="filtros_grado" style="display: <?php echo $encuesta['dirigido_a'] == 'estudiantes' ? 'grid' : 'none'; ?>; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>Grado (opcional):</label>
                    <select name="grado">
                        <option value="">Todos los grados</option>
                        <?php foreach ($grados as $g): ?>
                            <option value="<?php echo $g; ?>" <?php echo $encuesta['grado'] == $g ? 'selected' : ''; ?>><?php echo $g; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sección (opcional):</label>
                    <input type="text" name="seccion" value="<?php echo $encuesta['seccion']; ?>" placeholder="Ej: A">
                </div>
            </div>
            
            <h2 style="margin: 30px 0 20px; color: var(--primary-purple);">Preguntas</h2>
            
            <div id="preguntas-container">
                <?php foreach ($preguntas as $index => $p): ?>
                <div class="pregunta-item" id="pregunta_<?php echo $index; ?>">
                    <button type="button" class="btn-eliminar" onclick="eliminarPregunta(this)">Eliminar</button>
                    <div style="margin-bottom: 10px;">
                        <label>Pregunta:</label>
                        <input type="text" name="preguntas[<?php echo $index; ?>]" required value="<?php echo htmlspecialchars($p['pregunta']); ?>">
                    </div>
                    <div style="margin-bottom: 10px;">
                        <label>Tipo de respuesta:</label>
                        <select name="tipos[<?php echo $index; ?>]" onchange="mostrarOpciones(this, <?php echo $index; ?>)" data-index="<?php echo $index; ?>">
                            <option value="texto" <?php echo $p['tipo'] == 'texto' ? 'selected' : ''; ?>>Texto libre</option>
                            <option value="opcion_multiple" <?php echo $p['tipo'] == 'opcion_multiple' ? 'selected' : ''; ?>>Opción múltiple (una respuesta)</option>
                            <option value="casilla_verificacion" <?php echo $p['tipo'] == 'casilla_verificacion' ? 'selected' : ''; ?>>Casillas de verificación (varias)</option>
                            <option value="escala_1_5" <?php echo $p['tipo'] == 'escala_1_5' ? 'selected' : ''; ?>>Escala 1-5</option>
                            <option value="escala_1_10" <?php echo $p['tipo'] == 'escala_1_10' ? 'selected' : ''; ?>>Escala 1-10</option>
                            <option value="si_no" <?php echo $p['tipo'] == 'si_no' ? 'selected' : ''; ?>>Sí/No</option>
                        </select>
                    </div>
                    <div id="opciones_<?php echo $index; ?>" style="display: <?php echo in_array($p['tipo'], ['opcion_multiple', 'casilla_verificacion']) ? 'block' : 'none'; ?>;" class="opciones-textarea">
                        <label>Opciones (una por línea):</label>
                        <textarea name="opciones[<?php echo $index; ?>]" rows="3"><?php 
                            if ($p['opciones']) {
                                $opts = json_decode($p['opciones'], true);
                                echo is_array($opts) ? implode("\n", $opts) : '';
                            }
                        ?></textarea>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <button type="button" class="btn-agregar" onclick="agregarPregunta()">Agregar Pregunta</button>
            
            <button type="submit" class="btn-guardar">Guardar Cambios</button>
            <a href="encuestas.php" class="btn-cancelar" style="width: 100%;">Cancelar</a>
        </form>
    </div>
    
    <script>
        let contadorPreguntas = <?php echo count($preguntas); ?>;
        
        function agregarPregunta() {
            const container = document.getElementById('preguntas-container');
            const div = document.createElement('div');
            div.className = 'pregunta-item';
            div.id = `pregunta_${contadorPreguntas}`;
            
            div.innerHTML = `
                <button type="button" class="btn-eliminar" onclick="eliminarPregunta(this)">🗑️ Eliminar</button>
                <div style="margin-bottom: 10px;">
                    <label>Pregunta:</label>
                    <input type="text" name="preguntas[${contadorPreguntas}]" required placeholder="Escribe la pregunta...">
                </div>
                <div style="margin-bottom: 10px;">
                    <label>Tipo de respuesta:</label>
                    <select name="tipos[${contadorPreguntas}]" onchange="mostrarOpciones(this, ${contadorPreguntas})">
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
        
        function eliminarPregunta(boton) {
            boton.closest('.pregunta-item').remove();
        }
        
        function mostrarOpciones(select, index) {
            const divOpciones = document.getElementById(`opciones_${index}`);
            if (select.value === 'opcion_multiple' || select.value === 'casilla_verificacion') {
                divOpciones.style.display = 'block';
            } else {
                divOpciones.style.display = 'none';
            }
        }
        
        // Inicializar selects existentes
        document.querySelectorAll('select[name^="tipos"]').forEach(select => {
            const index = select.getAttribute('data-index');
            if (index) {
                mostrarOpciones(select, index);
            }
        });
        
        // Mostrar filtros según selección
        document.getElementById('dirigido_a').addEventListener('change', function() {
            const filtros = document.getElementById('filtros_grado');
            filtros.style.display = this.value === 'estudiantes' ? 'grid' : 'none';
        });
    </script>
</body>
</html>
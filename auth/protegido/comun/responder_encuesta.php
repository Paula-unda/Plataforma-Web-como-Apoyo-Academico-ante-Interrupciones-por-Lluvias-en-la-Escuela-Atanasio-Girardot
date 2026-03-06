<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/encuestas_funciones.php';

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
        
        // Notificación al admin (opcional)
        // enviarNotificacion($conexion, 1, "Nueva respuesta", "Un usuario respondió la encuesta", 'encuesta', $encuesta_id, 'encuestas');
        
        header('Location: encuestas_disponibles.php?exito=Encuesta+respondida+con+éxito');
        exit();
        
    } catch (Exception $e) {
        $conexion->rollBack();
        $error = "Error al guardar respuestas: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Responder Encuesta - SIEDUCRES</title>
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
            max-width: 800px; margin: 0 auto;
        }
        .encuesta-header {
            background: white; border-radius: 16px; padding: 30px;
            margin-bottom: 30px; box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            border-left: 4px solid var(--primary-cyan);
        }
        h1 { color: var(--primary-cyan); margin-bottom: 10px; }
        h2 { color: var(--primary-purple); font-size: 18px; margin: 20px 0; }
        .pregunta-card {
            background: white; border-radius: 12px; padding: 20px;
            margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .pregunta-texto {
            font-weight: 600; margin-bottom: 15px;
            color: var(--text-dark);
        }
        .obligatoria {
            color: var(--primary-pink); font-size: 12px;
            margin-left: 10px;
        }
        .opcion-item {
            margin: 10px 0; display: flex; align-items: center;
        }
        .opcion-item input[type="radio"],
        .opcion-item input[type="checkbox"] {
            margin-right: 10px; width: 18px; height: 18px;
        }
        .escala-item {
            display: inline-block; margin: 0 5px; text-align: center;
        }
        .escala-item input {
            width: 50px; padding: 8px; text-align: center;
        }
        .btn-enviar {
            background: var(--primary-lime); color: #333;
            padding: 15px 40px; border: none; border-radius: 8px;
            font-weight: 600; cursor: pointer; width: 100%;
            font-size: 16px; margin-top: 20px;
        }
        .btn-enviar:hover {
            opacity: 0.9;
        }
        .btn-volver {
            background: #ccc; color: #333; padding: 10px 20px;
            border: none; border-radius: 8px; font-weight: 600;
            text-decoration: none; display: inline-block; margin-top: 10px;
        }
        .error {
            background: #f8d7da; color: #721c24; padding: 15px;
            border-radius: 8px; margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="encuesta-header">
            <h1><?php echo htmlspecialchars($encuesta['titulo']); ?></h1>
            <p style="color: #666; margin: 15px 0;"><?php echo nl2br(htmlspecialchars($encuesta['descripcion'])); ?></p>
            <?php if (!empty($encuesta['instrucciones'])): ?>
                <div style="background: #f9f9f9; padding: 15px; border-radius: 8px;">
                    <strong>📋 Instrucciones:</strong><br>
                    <?php echo nl2br(htmlspecialchars($encuesta['instrucciones'])); ?>
                </div>
            <?php endif; ?>
            <p style="color: #999; margin-top: 15px;">
                ⏰ Esta encuesta cierra el <?php echo date('d/m/Y', strtotime($encuesta['fecha_cierre'])); ?>
            </p>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <?php foreach ($preguntas as $index => $p): ?>
            <div class="pregunta-card">
                <div class="pregunta-texto">
                    <?php echo ($index + 1) . '. ' . htmlspecialchars($p['pregunta']); ?>
                    <?php if ($p['obligatoria']): ?>
                        <span class="obligatoria">*Obligatoria</span>
                    <?php endif; ?>
                </div>
                
                <?php if ($p['tipo'] === 'texto'): ?>
                    <textarea name="pregunta_<?php echo $p['id']; ?>" 
                              style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 5px;" 
                              rows="3" <?php echo $p['obligatoria'] ? 'required' : ''; ?>></textarea>
                
                <?php elseif ($p['tipo'] === 'si_no'): ?>
                    <div class="opcion-item">
                        <input type="radio" name="pregunta_<?php echo $p['id']; ?>" value="Sí" id="si_<?php echo $p['id']; ?>" <?php echo $p['obligatoria'] ? 'required' : ''; ?>>
                        <label for="si_<?php echo $p['id']; ?>">Sí</label>
                    </div>
                    <div class="opcion-item">
                        <input type="radio" name="pregunta_<?php echo $p['id']; ?>" value="No" id="no_<?php echo $p['id']; ?>">
                        <label for="no_<?php echo $p['id']; ?>">No</label>
                    </div>
                
                <?php elseif ($p['tipo'] === 'escala_1_5'): ?>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <label class="escala-item">
                            <input type="radio" name="pregunta_<?php echo $p['id']; ?>" value="<?php echo $i; ?>" <?php echo $p['obligatoria'] ? 'required' : ''; ?>>
                            <span><?php echo $i; ?></span>
                        </label>
                        <?php endfor; ?>
                    </div>
                
                <?php elseif ($p['tipo'] === 'escala_1_10'): ?>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
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
                        foreach ($opciones as $opcion):
                ?>
                    <div class="opcion-item">
                        <input type="radio" name="pregunta_<?php echo $p['id']; ?>" value="<?php echo htmlspecialchars($opcion); ?>" 
                               id="opt_<?php echo $p['id'] . '_' . md5($opcion); ?>" <?php echo $p['obligatoria'] ? 'required' : ''; ?>>
                        <label for="opt_<?php echo $p['id'] . '_' . md5($opcion); ?>"><?php echo htmlspecialchars($opcion); ?></label>
                    </div>
                <?php 
                        endforeach;
                    endif;
                
                elseif ($p['tipo'] === 'casilla_verificacion' && $p['opciones']):
                    $opciones = json_decode($p['opciones'], true);
                    if (is_array($opciones)):
                        foreach ($opciones as $opcion):
                ?>
                    <div class="opcion-item">
                        <input type="checkbox" name="pregunta_<?php echo $p['id']; ?>[]" value="<?php echo htmlspecialchars($opcion); ?>" 
                               id="chk_<?php echo $p['id'] . '_' . md5($opcion); ?>">
                        <label for="chk_<?php echo $p['id'] . '_' . md5($opcion); ?>"><?php echo htmlspecialchars($opcion); ?></label>
                    </div>
                <?php 
                        endforeach;
                    endif;
                endif; ?>
            </div>
            <?php endforeach; ?>
            
            <button type="submit" class="btn-enviar">📤 Enviar Respuestas</button>
        </form>
        
        <div style="text-align: center; margin-top: 20px;">
            <a href="encuestas_disponibles.php" class="btn-volver">↩️ Volver a encuestas</a>
        </div>
    </div>
</body>
</html>
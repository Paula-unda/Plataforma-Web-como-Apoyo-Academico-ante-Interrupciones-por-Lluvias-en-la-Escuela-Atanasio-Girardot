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
$usuario_rol = $_SESSION['usuario_rol'];

// Obtener grado y sección si es estudiante
$grado = '';
$seccion = '';
if ($usuario_rol === 'Estudiante') {
    $stmt = $conexion->prepare("SELECT grado, seccion FROM estudiantes WHERE usuario_id = ?");
    $stmt->execute([$usuario_id]);
    $est = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($est) {
        $grado = $est['grado'];
        $seccion = $est['seccion'];
    }
}

// Obtener encuestas disponibles
$sql = "
    SELECT e.*,
           (SELECT COUNT(*) FROM respuestas_encuesta WHERE encuesta_id = e.id AND usuario_id = ?) as ya_respondio
    FROM encuestas e
    WHERE e.activo = true 
    AND e.fecha_cierre >= CURRENT_DATE
    AND (
        e.dirigido_a = 'todos' 
        OR e.dirigido_a = ?
";

$params = [$usuario_id, $usuario_rol];

if ($usuario_rol === 'Estudiante' && $grado) {
    $sql .= " OR (e.dirigido_a = 'estudiantes' AND (e.grado IS NULL OR e.grado = ?) AND (e.seccion IS NULL OR e.seccion = ?))";
    $params[] = $grado;
    $params[] = $seccion;
} else {
    $sql .= " OR e.dirigido_a = ?";
    $params[] = $usuario_rol;
}

$sql .= ") ORDER BY e.fecha_publicacion DESC";

$stmt = $conexion->prepare($sql);
$stmt->execute($params);
$encuestas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Encuestas Disponibles - SIEDUCRES</title>
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
            max-width: 1000px; margin: 0 auto;
        }
        h1 { color: var(--primary-cyan); margin-bottom: 30px; }
        .encuesta-card {
            background: white; border-radius: 16px; padding: 25px;
            margin-bottom: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            border-left: 4px solid var(--primary-cyan);
            transition: transform 0.2s;
        }
        .encuesta-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.15);
        }
        .encuesta-titulo {
            font-size: 20px; font-weight: 600; color: var(--primary-purple);
            margin-bottom: 10px;
        }
        .encuesta-descripcion {
            color: #666; margin-bottom: 15px; line-height: 1.6;
        }
        .encuesta-meta {
            display: flex; gap: 20px; margin-bottom: 15px;
            font-size: 14px; color: #999;
        }
        .btn-responder {
            background: var(--primary-lime); color: #333;
            padding: 10px 25px; border: none; border-radius: 8px;
            font-weight: 600; cursor: pointer; text-decoration: none;
            display: inline-block;
        }
        .btn-responder:hover {
            opacity: 0.9;
        }
        .badge-respondida {
            background: #28a745; color: white; padding: 5px 15px;
            border-radius: 20px; font-size: 14px; display: inline-block;
        }
        .badge-vencida {
            background: #dc3545; color: white; padding: 5px 15px;
            border-radius: 20px; font-size: 14px; display: inline-block;
        }
        .volver {
            margin-top: 30px; text-align: center;
        }
        .volver a {
            color: var(--primary-pink); text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Encuestas Disponibles</h1>
        
        <?php if (empty($encuestas)): ?>
            <div style="text-align: center; padding: 50px; background: white; border-radius: 16px;">
                <p style="color: #999; font-size: 18px;">No hay encuestas disponibles en este momento</p>
            </div>
        <?php else: ?>
            <?php foreach ($encuestas as $e): ?>
            <div class="encuesta-card">
                <div class="encuesta-titulo">
                    <?php echo htmlspecialchars($e['titulo']); ?>
                </div>
                <div class="encuesta-descripcion">
                    <?php echo nl2br(htmlspecialchars($e['descripcion'])); ?>
                </div>
                <?php if (!empty($e['instrucciones'])): ?>
                    <div style="background: #f9f9f9; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
                        <strong>Instrucciones:</strong> <?php echo nl2br(htmlspecialchars($e['instrucciones'])); ?>
                    </div>
                <?php endif; ?>
                <div class="encuesta-meta">
                    <span>Publicada: <?php echo date('d/m/Y', strtotime($e['fecha_publicacion'])); ?></span>
                    <span>Cierra: <?php echo date('d/m/Y', strtotime($e['fecha_cierre'])); ?></span>
                </div>
                
                <?php if ($e['ya_respondio'] > 0): ?>
                    <span class="badge-respondida">Ya respondida</span>
                <?php elseif (strtotime($e['fecha_cierre']) < time()): ?>
                    <span class="badge-vencida">Vencida</span>
                <?php else: ?>
                    <a href="responder_encuesta.php?id=<?php echo $e['id']; ?>" class="btn-responder">Responder Encuesta</a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <div class="volver">
            <a href="<?php 
                if ($usuario_rol === 'Administrador') echo '../admin/index.php';
                elseif ($usuario_rol === 'Docente') echo '../docente/index.php';
                elseif ($usuario_rol === 'Estudiante') echo '../estudiante/index.php';
                elseif ($usuario_rol === 'Representante') echo '../representante/index.php';
            ?>">← Volver al Panel</a>
        </div>
    </div>
</body>
</html>
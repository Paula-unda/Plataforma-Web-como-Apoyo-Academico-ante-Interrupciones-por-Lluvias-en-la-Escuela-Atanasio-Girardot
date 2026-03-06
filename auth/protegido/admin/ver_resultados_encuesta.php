<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/encuestas_funciones.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Administrador') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$conexion = getConexion();
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

// Obtener estadísticas
$stats = calcularEstadisticasEncuesta($conexion, $encuesta_id);

// Obtener respuestas detalladas
$respuestas = obtenerRespuestasEncuesta($conexion, $encuesta_id);

// Total de usuarios que deberían responder
$total_potencial = 0;
if ($encuesta['dirigido_a'] == 'todos') {
    $total_potencial = $conexion->query("SELECT COUNT(*) FROM usuarios WHERE activo = true")->fetchColumn();
} elseif ($encuesta['dirigido_a'] == 'estudiantes') {
    $sql = "SELECT COUNT(*) FROM estudiantes e JOIN usuarios u ON e.usuario_id = u.id WHERE u.activo = true";
    if ($encuesta['grado']) {
        $sql .= " AND e.grado = '" . $encuesta['grado'] . "'";
    }
    if ($encuesta['seccion']) {
        $sql .= " AND e.seccion = '" . $encuesta['seccion'] . "'";
    }
    $total_potencial = $conexion->query($sql)->fetchColumn();
} elseif ($encuesta['dirigido_a'] == 'docentes') {
    $total_potencial = $conexion->query("SELECT COUNT(*) FROM docentes")->fetchColumn();
} elseif ($encuesta['dirigido_a'] == 'representantes') {
    $total_potencial = $conexion->query("SELECT COUNT(DISTINCT representante_id) FROM representantes_estudiantes")->fetchColumn();
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Resultados de Encuesta - SIEDUCRES</title>
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
            max-width: 1200px; margin: 0 auto;
        }
        .header {
            background: white; border-radius: 16px; padding: 30px;
            margin-bottom: 30px; box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        h1 { color: var(--primary-cyan); }
        .stats-grid {
            display: grid; grid-template-columns: repeat(4, 1fr);
            gap: 20px; margin: 30px 0;
        }
        .stat-card {
            background: white; padding: 20px; border-radius: 12px;
            text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            border-left: 4px solid var(--primary-cyan);
        }
        .stat-number {
            font-size: 36px; font-weight: 700; color: var(--primary-cyan);
        }
        .pregunta-card {
            background: white; border-radius: 12px; padding: 20px;
            margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .pregunta-titulo {
            font-size: 18px; font-weight: 600; color: var(--primary-purple);
            margin-bottom: 15px; padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-cyan);
        }
        .respuesta-barra {
            display: flex; align-items: center; margin: 10px 0;
        }
        .barra {
            height: 30px; background: var(--primary-cyan); border-radius: 5px;
            margin-right: 10px; transition: width 0.3s;
        }
        .respuesta-texto {
            flex: 1; color: #666;
        }
        .porcentaje {
            width: 60px; text-align: right; font-weight: 600;
        }
        table {
            width: 100%; border-collapse: collapse; background: white;
            border-radius: 12px; overflow: hidden;
        }
        th {
            background: var(--primary-cyan); color: white; padding: 12px;
            text-align: left;
        }
        td { padding: 12px; border-bottom: 1px solid var(--border); }
        .btn-exportar {
            background: var(--primary-lime); color: #333; padding: 12px 24px;
            border: none; border-radius: 8px; font-weight: 600;
            cursor: pointer; text-decoration: none; display: inline-block;
            margin-top: 20px;
        }
        .btn-volver {
            background: #ccc; color: #333; padding: 12px 24px;
            border: none; border-radius: 8px; font-weight: 600;
            text-decoration: none; display: inline-block; margin-left: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Resultados: <?php echo htmlspecialchars($encuesta['titulo']); ?></h1>
            <p style="color: #666; margin-top: 10px;"><?php echo htmlspecialchars($encuesta['descripcion']); ?></p>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_respondieron']; ?></div>
                    <div>Respondieron</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_potencial; ?></div>
                    <div>Población objetivo</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo round(($stats['total_respondieron'] / max($total_potencial, 1)) * 100); ?>%</div>
                    <div>Tasa de respuesta</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($preguntas); ?></div>
                    <div>Preguntas</div>
                </div>
            </div>
            
            <a href="exportar_resultados.php?id=<?php echo $encuesta_id; ?>" class="btn-exportar">📄 Exportar a PDF</a>
            <a href="encuestas.php" class="btn-volver">Volver</a>
        </div>
        
        <?php foreach ($preguntas as $p): ?>
        <div class="pregunta-card">
            <div class="pregunta-titulo">
                <?php echo htmlspecialchars($p['pregunta']); ?>
                <span style="font-size: 14px; color: #666; margin-left: 10px;">
                    (<?php echo $p['tipo']; ?>)
                </span>
            </div>
            
            <?php if (isset($stats['preguntas'][$p['id']])): ?>
                <?php 
                $total_respuestas_preg = array_sum(array_column($stats['preguntas'][$p['id']]['respuestas'], 'total'));
                ?>
                
                <?php foreach ($stats['preguntas'][$p['id']]['respuestas'] as $resp): 
                    $porcentaje = $total_respuestas_preg > 0 ? round(($resp['total'] / $total_respuestas_preg) * 100, 1) : 0;
                ?>
                <div class="respuesta-barra">
                    <div class="barra" style="width: <?php echo $porcentaje * 3; ?>px;"></div>
                    <div class="respuesta-texto"><?php echo htmlspecialchars($resp['respuesta'] ?: 'Sin respuesta'); ?></div>
                    <div class="porcentaje"><?php echo $porcentaje; ?>% (<?php echo $resp['total']; ?>)</div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color: #999; text-align: center; padding: 20px;">No hay respuestas para esta pregunta</p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        
        <div class="pregunta-card">
            <div class="pregunta-titulo">📝 Respuestas Detalladas</div>
            <table>
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Rol</th>
                        <th>Fecha</th>
                        <th>Respuestas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $usuarios_agrupados = [];
                    foreach ($respuestas as $r) {
                        $usuarios_agrupados[$r['usuario_id']]['nombre'] = $r['usuario_nombre'];
                        $usuarios_agrupados[$r['usuario_id']]['rol'] = $r['usuario_rol'];
                        $usuarios_agrupados[$r['usuario_id']]['fecha'] = $r['fecha_respuesta'];
                        $usuarios_agrupados[$r['usuario_id']]['respuestas'][] = $r;
                    }
                    
                    foreach ($usuarios_agrupados as $uid => $data): 
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($data['nombre']); ?></strong></td>
                        <td><?php echo ucfirst($data['rol']); ?></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($data['fecha'])); ?></td>
                        <td>
                            <details>
                                <summary>Ver respuestas (<?php echo count($data['respuestas']); ?>)</summary>
                                <ul style="margin-top: 10px;">
                                    <?php foreach ($data['respuestas'] as $r): ?>
                                    <li><strong>P<?php echo $r['pregunta_id']; ?>:</strong> <?php echo htmlspecialchars($r['respuesta']); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
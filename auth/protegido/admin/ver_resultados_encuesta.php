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
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Resultados de Encuesta - SIEDUCRES</title>
    <?php require_once '../includes/header_onesignal.php'; ?> 
    <?php require_once '../includes/favicon.php'; ?>
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
            --border: #E0E0E0;
            --surface: #FFFFFF;
            --background: #F5F5F5;
            --text-dark: #333333;
            --text-muted: #666666;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: var(--background);
            padding: 20px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* Header */
        .header-page {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 0 20px 0;
            background: var(--surface);
            border-radius: 16px 16px 0 0;
        }
        
        .header-page h1 {
            color: var(--primary-cyan);
            font-size: 24px;
        }
        
        @media (min-width: 768px) {
            .header-page h1 {
                font-size: 28px;
            }
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
        }
        
        /* Header de resultados - IGUAL QUE ANTES */
        .header {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        h1 {
            color: var(--primary-cyan);
            font-size: 28px;
        }
        
        @media (max-width: 768px) {
            h1 {
                font-size: 24px;
            }
        }
        
        /* Stats grid - RESPONSIVE pero manteniendo diseño */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin: 30px 0;
        }
        
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
        }
        
        @media (max-width: 600px) {
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            border-left: 4px solid var(--primary-cyan);
        }
        
        .stat-number {
            font-size: 36px;
            font-weight: 700;
            color: var(--primary-cyan);
        }
        
        @media (max-width: 768px) {
            .stat-number {
                font-size: 28px;
            }
        }
        
        /* Preguntas - IGUAL QUE ANTES */
        .pregunta-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .pregunta-titulo {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary-purple);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-cyan);
        }
        
        @media (max-width: 768px) {
            .pregunta-titulo {
                font-size: 16px;
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
        }
        
        /* Respuesta barra - FUNCIONAL EN TODOS LOS TAMAÑOS */
        .respuesta-barra {
            display: flex;
            align-items: center;
            margin: 10px 0;
            flex-wrap: wrap;
        }
        
        @media (max-width: 768px) {
            .respuesta-barra {
                background: #f8f9fa;
                padding: 12px;
                border-radius: 8px;
                margin: 8px 0;
            }
        }
        
        .barra {
            height: 30px;
            background: var(--primary-cyan);
            border-radius: 5px;
            margin-right: 10px;
            transition: width 0.3s;
        }
        
        @media (max-width: 768px) {
            .barra {
                height: 24px;
                margin-right: 0;
                margin-bottom: 8px;
                width: 100% !important; /* Forzar que ocupe todo el ancho en móvil */
                max-width: 100%;
            }
        }
        
        .respuesta-texto {
            flex: 1;
            color: #666;
        }
        
        @media (max-width: 768px) {
            .respuesta-texto {
                flex: auto;
                width: 100%;
                margin-bottom: 8px;
                font-size: 14px;
            }
        }
        
        .porcentaje {
            width: 80px;
            text-align: right;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .porcentaje {
                width: auto;
                text-align: left;
                font-size: 14px;
                font-weight: 700;
                color: var(--primary-cyan);
            }
        }
        
        /* Botones - RESPONSIVE */
        .btn-exportar {
            background: var(--primary-lime);
            color: #333;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-top: 20px;
            margin-right: 10px;
        }
        
        .btn-volver {
            background: #ccc;
            color: #333;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            margin-top: 20px;
        }
        
        @media (max-width: 768px) {
            .btn-exportar, .btn-volver {
                display: block;
                width: 100%;
                text-align: center;
                margin: 10px 0;
            }
        }
        
        /* Tabla - RESPONSIVE */
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
        }
        
        th {
            background: var(--primary-cyan);
            color: white;
            padding: 12px;
            text-align: left;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid var(--border);
        }
        
        @media (max-width: 768px) {
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            table {
                min-width: 600px;
            }
            
            th, td {
                padding: 8px;
                font-size: 13px;
            }
        }
        
        details summary {
            cursor: pointer;
            color: var(--primary-cyan);
            font-weight: 600;
        }
        
        details ul {
            margin-top: 10px;
            padding-left: 20px;
        }
        
        details li {
            margin: 5px 0;
            color: var(--text-muted);
        }
        
        /* Footer */
        .footer {
            margin-top: 40px;
            text-align: center;
            color: var(--text-muted);
            font-size: 13px;
        }
        body {
            padding-top: 60px;  /* ← ALTURA DEL HEADER */
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header_comun.php'; ?>
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
            
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="exportar_resultados.php?id=<?php echo $encuesta_id; ?>" class="btn-exportar">📄 Exportar a PDF</a>
                <a href="encuestas.php" class="btn-volver">← Volver</a>
            </div>
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
            <div class="table-responsive">
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
        
        <div class="footer">
            <p>SIEDUCRES - Plataforma Educativa</p>
        </div>
    </div>
</body>
</html>
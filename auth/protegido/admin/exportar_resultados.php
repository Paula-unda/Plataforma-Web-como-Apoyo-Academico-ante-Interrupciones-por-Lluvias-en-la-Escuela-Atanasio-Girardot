<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/encuestas_funciones.php';
require_once '../../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Administrador') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$conexion = getConexion();
$encuesta_id = $_GET['id'] ?? 0;

// Obtener datos
$stmt = $conexion->prepare("SELECT * FROM encuestas WHERE id = ?");
$stmt->execute([$encuesta_id]);
$encuesta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$encuesta) {
    header('Location: encuestas.php?error=Encuesta+no+encontrada');
    exit();
}

$preguntas = obtenerPreguntasEncuesta($conexion, $encuesta_id);
$stats = calcularEstadisticasEncuesta($conexion, $encuesta_id);
$respuestas = obtenerRespuestasEncuesta($conexion, $encuesta_id);

// Generar HTML para PDF
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Resultados de Encuesta</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; }
        h1 { color: #4BC4E7; font-size: 24px; text-align: center; }
        h2 { color: #9b8afb; font-size: 18px; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th { background: #4BC4E7; color: white; padding: 8px; text-align: left; }
        td { padding: 8px; border-bottom: 1px solid #ddd; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #4BC4E7; padding-bottom: 10px; }
        .stat { display: inline-block; margin: 10px; padding: 10px; background: #f5f5f5; border-left: 3px solid #4BC4E7; }
        .pregunta { margin: 20px 0; padding: 15px; background: #f9f9f9; border-radius: 5px; }
        .respuesta-item { margin: 5px 0; }
        .porcentaje { background: #4BC4E7; height: 20px; border-radius: 3px; margin: 5px 0; }
        .footer { text-align: center; color: #999; font-size: 10px; margin-top: 30px; border-top: 1px solid #ddd; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>RESULTADOS DE ENCUESTA</h1>
        <p><strong><?php echo htmlspecialchars($encuesta['titulo']); ?></strong></p>
        <p><?php echo htmlspecialchars($encuesta['descripcion']); ?></p>
        <p>Generado el <?php echo date('d/m/Y H:i'); ?></p>
    </div>
    
    <div class="stat">
        <strong>Total respuestas:</strong> <?php echo $stats['total_respondieron']; ?>
    </div>
    
    <?php foreach ($preguntas as $p): ?>
    <div class="pregunta">
        <h3><?php echo htmlspecialchars($p['pregunta']); ?></h3>
        <p><em>Tipo: <?php echo $p['tipo']; ?></em></p>
        
        <?php if (isset($stats['preguntas'][$p['id']])): 
            $total = array_sum(array_column($stats['preguntas'][$p['id']]['respuestas'], 'total'));
            foreach ($stats['preguntas'][$p['id']]['respuestas'] as $resp):
                $porcentaje = $total > 0 ? round(($resp['total'] / $total) * 100, 1) : 0;
        ?>
        <div class="respuesta-item">
            <div><strong><?php echo htmlspecialchars($resp['respuesta'] ?: 'Sin respuesta'); ?></strong> (<?php echo $resp['total']; ?> respuestas - <?php echo $porcentaje; ?>%)</div>
            <div class="porcentaje" style="width: <?php echo $porcentaje * 3; ?>px;"></div>
        </div>
        <?php endforeach; endif; ?>
    </div>
    <?php endforeach; ?>
    
    <h2>Respuestas Detalladas</h2>
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
            
            foreach ($usuarios_agrupados as $data): 
            ?>
            <tr>
                <td><?php echo htmlspecialchars($data['nombre']); ?></td>
                <td><?php echo ucfirst($data['rol']); ?></td>
                <td><?php echo date('d/m/Y H:i', strtotime($data['fecha'])); ?></td>
                <td>
                    <?php foreach ($data['respuestas'] as $r): ?>
                    <strong>P<?php echo $r['pregunta_id']; ?>:</strong> <?php echo htmlspecialchars($r['respuesta']); ?><br>
                    <?php endforeach; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="footer">
        SIEDUCRES - Plataforma Educativa
    </div>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$nombre_archivo = "encuesta_{$encuesta_id}_" . date('Y-m-d') . ".pdf";
$dompdf->stream($nombre_archivo, array("Attachment" => true));
exit();
?>
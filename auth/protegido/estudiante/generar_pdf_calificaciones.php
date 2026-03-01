<?php
session_start();
require_once '../../funciones.php';

// ✅ Cargar DomPDF (desde vendor de auth)
require_once '../../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Verificar sesión
if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Estudiante') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$estudiante_id = $_SESSION['usuario_id'];
$nombre_estudiante = $_SESSION['usuario_nombre'] ?? 'Estudiante';

// Obtener calificaciones
$calificaciones = [];
$pendientes = [];
$promedio = 0;

try {
    $conexion = getConexion();
    
    // Calificadas
    $query_calificadas = "
        SELECT 
            e.calificacion,
            e.observaciones,
            e.fecha_entrega,
            a.titulo as actividad_titulo
        FROM entregas_estudiantes e
        INNER JOIN actividades a ON e.actividad_id = a.id
        WHERE e.estudiante_id = " . (int)$estudiante_id . "
        AND e.calificacion IS NOT NULL
        ORDER BY e.fecha_entrega DESC
    ";
    
    $stmt = $conexion->query($query_calificadas);
    $calificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pendientes
    $query_pendientes = "
        SELECT 
            a.titulo as actividad_titulo,
            a.fecha_entrega
        FROM actividades a
        WHERE a.activo = true
        AND a.id NOT IN (
            SELECT actividad_id FROM entregas_estudiantes 
            WHERE estudiante_id = " . (int)$estudiante_id . "
        )
        ORDER BY a.fecha_entrega DESC
    ";
    
    $stmt = $conexion->query($query_pendientes);
    $pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular promedio
    if (count($calificaciones) > 0) {
        $suma = array_sum(array_column($calificaciones, 'calificacion'));
        $promedio = round($suma / count($calificaciones), 2);
    }
    
} catch (Exception $e) {
    error_log("Error PDF: " . $e->getMessage());
}

// Configurar DomPDF
$options = new Options();
$options->set('defaultFont', 'Arial');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);

// HTML del PDF
$html = '
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #9CAF44; padding-bottom: 20px; }
        .header h1 { color: #9CAF44; margin: 0; font-size: 24px; }
        .header p { color: #666; margin: 5px 0; }
        .info { margin-bottom: 20px; }
        .info-table { width: 100%; margin-bottom: 20px; }
        .info-table td { padding: 8px; border: 1px solid #ddd; }
        .info-label { font-weight: bold; background: #f5f5f5; width: 30%; }
        .resumen { margin: 20px 0; }
        .resumen-table { width: 100%; border-collapse: collapse; }
        .resumen-table th { background: #9CAF44; color: white; padding: 10px; text-align: left; }
        .resumen-table td { padding: 8px; border: 1px solid #ddd; }
        .resumen-table tr:nth-child(even) { background: #f9f9f9; }
        .footer { margin-top: 40px; text-align: center; font-size: 10px; color: #999; border-top: 1px solid #ddd; padding-top: 10px; }
        .nota-alta { color: #28a745; font-weight: bold; }
        .nota-media { color: #14b7c0; font-weight: bold; }
        .nota-baja { color: #f36c7b; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <h1> SIEDUCRES</h1>
        <p>Informe de Calificaciones</p>
        <p>' . date('d/m/Y') . '</p>
    </div>
    
    <div class="info">
        <table class="info-table">
            <tr>
                <td class="info-label">Estudiante:</td>
                <td>' . htmlspecialchars($nombre_estudiante) . '</td>
            </tr>
            <tr>
                <td class="info-label">Fecha de Emisión:</td>
                <td>' . date('d/m/Y H:i:s') . '</td>
            </tr>
            <tr>
                <td class="info-label">Total Calificadas:</td>
                <td>' . count($calificaciones) . '</td>
            </tr>
            <tr>
                <td class="info-label">Promedio General:</td>
                <td><strong>' . number_format($promedio, 2) . '/20</strong></td>
            </tr>
        </table>
    </div>
    
    <div class="resumen">
        <h3 style="color: #9CAF44; border-bottom: 1px solid #9CAF44; padding-bottom: 10px;"> Actividades Calificadas</h3>
        ' . (count($calificaciones) > 0 ? '
        <table class="resumen-table">
            <thead>
                <tr>
                    <th>Actividad</th>
                    <th>Fecha</th>
                    <th>Nota</th>
                    <th>Retroalimentación</th>
                </tr>
            </thead>
            <tbody>
                ' . implode('', array_map(function($cal) {
                    $nota = floatval($cal['calificacion']);
                    $clase = $nota >= 16 ? 'nota-alta' : ($nota >= 12 ? 'nota-media' : 'nota-baja');
                    return '
                    <tr>
                        <td>' . htmlspecialchars($cal['actividad_titulo']) . '</td>
                        <td>' . ($cal['fecha_entrega'] ? date('d/m/Y', strtotime($cal['fecha_entrega'])) : 'Sin fecha') . '</td>
                        <td class="' . $clase . '">' . number_format($nota, 2) . '/20</td>
                        <td>' . htmlspecialchars($cal['observaciones'] ?? 'Sin comentarios') . '</td>
                    </tr>
                    ';
                }, $calificaciones)) . '
            </tbody>
        </table>
        ' : '<p style="color: #999; text-align: center; padding: 20px;">No hay calificaciones registradas</p>') . '
    </div>
    
    <div class="resumen">
        <h3 style="color: #f36c7b; border-bottom: 1px solid #f36c7b; padding-bottom: 10px;"> Actividades Pendientes</h3>
        ' . (count($pendientes) > 0 ? '
        <table class="resumen-table">
            <thead>
                <tr>
                    <th>Actividad</th>
                    <th>Fecha Límite</th>
                </tr>
            </thead>
            <tbody>
                ' . implode('', array_map(function($pend) {
                    return '
                    <tr>
                        <td>' . htmlspecialchars($pend['actividad_titulo']) . '</td>
                        <td>' . ($pend['fecha_entrega'] ? date('d/m/Y', strtotime($pend['fecha_entrega'])) : 'Sin fecha') . '</td>
                    </tr>
                    ';
                }, $pendientes)) . '
            </tbody>
        </table>
        ' : '<p style="color: #999; text-align: center; padding: 20px;">¡Todo al día!</p>') . '
    </div>
    
    <div class="footer">
        <p>Este documento es generado automáticamente por SIEDUCRES</p>
        <p>© ' . date('Y') . ' - Escuela Atanasio Girardot</p>
    </div>
</body>
</html>
';

// Generar PDF
$dompdf->loadHtml($html);
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();

// Descargar PDF
$dompdf->stream('calificaciones_' . date('Y-m-d') . '.pdf', [
    'Attachment' => true,
    'Filename' => 'calificaciones_' . date('Y-m-d') . '.pdf'
]);
?>
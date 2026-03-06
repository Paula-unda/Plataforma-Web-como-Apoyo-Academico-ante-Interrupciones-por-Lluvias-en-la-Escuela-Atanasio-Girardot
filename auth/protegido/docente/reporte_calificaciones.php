<?php
session_start();
require_once '../../funciones.php';

// ✅ Cargar DomPDF (desde vendor de auth)
require_once '../../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Verificar sesión
if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Docente') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$tipo = $_GET['tipo'] ?? 'general'; // general o individual
$estudiante_id = isset($_GET['estudiante']) ? (int)$_GET['estudiante'] : 0;

try {
    $conexion = getConexion();
    
    // Obtener datos del docente
    $stmt_docente = $conexion->prepare("
        SELECT u.nombre, d.grado, d.seccion 
        FROM docentes d
        JOIN usuarios u ON d.usuario_id = u.id
        WHERE d.usuario_id = ?
    ");
    $stmt_docente->execute([$usuario_id]);
    $docente = $stmt_docente->fetch(PDO::FETCH_ASSOC);
    
    if (!$docente) {
        throw new Exception("Docente no encontrado");
    }
    
    // Variables para el HTML
    $nombre_docente = $docente['nombre'];
    $grado = $docente['grado'];
    $seccion = $docente['seccion'];
    
    // ============================================
    // REPORTE GENERAL - CONSULTA CORREGIDA
    // ============================================
    if ($tipo === 'general') {
        
        // Estadísticas generales (esta parte está bien)
        $stats = $conexion->prepare("
            SELECT 
                COUNT(DISTINCT u.id) as total_estudiantes,
                COUNT(DISTINCT a.id) as total_actividades,
                COUNT(DISTINCT ee.id) as total_entregas,
                COUNT(DISTINCT CASE WHEN ee.estado = 'enviado' THEN ee.id END) as pendientes_calificar,
                COUNT(DISTINCT CASE WHEN ee.fecha_entrega > a.fecha_entrega THEN ee.id END) as entregas_atrasadas,
                ROUND(AVG(ee.calificacion)::numeric, 2) as promedio_general,
                -- ✅ Nueva métrica: Total de entregas esperadas
                (COUNT(DISTINCT a.id) * COUNT(DISTINCT u.id)) as entregas_esperadas
            FROM actividades a
            CROSS JOIN usuarios u
            INNER JOIN estudiantes e ON u.id = e.usuario_id
            LEFT JOIN entregas_estudiantes ee ON a.id = ee.actividad_id AND u.id = ee.estudiante_id
            WHERE a.docente_id = ? 
                AND a.activo = true
                AND u.rol = 'Estudiante'
                AND e.grado = ? 
                AND e.seccion = ?
        ");
        $stats->execute([$usuario_id, $grado, $seccion]);
        $estadisticas = $stats->fetch(PDO::FETCH_ASSOC);
        
        // ✅ CONSULTA CORREGIDA - Lista de estudiantes con sus estadísticas
        $estudiantes = $conexion->prepare("
            WITH actividades_docente AS (
                SELECT id, fecha_entrega
                FROM actividades
                WHERE docente_id = ? AND activo = true
            ),
            entregas_estudiante AS (
                SELECT 
                    u.id as estudiante_id,
                    u.nombre,
                    COUNT(DISTINCT ad.id) as total_actividades,
                    COUNT(DISTINCT ee.id) as entregas,
                    COALESCE(SUM(ee.calificacion), 0) as suma_calificaciones,
                    -- ✅ Pendientes por calificar (estado 'enviado')
                    COUNT(DISTINCT CASE WHEN ee.estado = 'enviado' THEN ee.id END) as pendientes_calificar,
                    -- ✅ Entregas atrasadas
                    COUNT(DISTINCT CASE WHEN ee.fecha_entrega > ad.fecha_entrega THEN ee.id END) as atrasadas,
                    -- ✅ Notas por rango
                    COUNT(DISTINCT CASE WHEN ee.calificacion >= 16 THEN ee.id END) as notas_altas,
                    COUNT(DISTINCT CASE WHEN ee.calificacion BETWEEN 12 AND 15.9 THEN ee.id END) as notas_medias,
                    COUNT(DISTINCT CASE WHEN ee.calificacion < 12 AND ee.calificacion IS NOT NULL THEN ee.id END) as notas_bajas
                FROM usuarios u
                INNER JOIN estudiantes e ON u.id = e.usuario_id
                CROSS JOIN actividades_docente ad
                LEFT JOIN entregas_estudiantes ee ON ad.id = ee.actividad_id AND u.id = ee.estudiante_id
                WHERE u.rol = 'Estudiante' 
                    AND e.grado = ? 
                    AND e.seccion = ?
                GROUP BY u.id, u.nombre
            )
            SELECT 
                nombre,
                total_actividades,
                entregas,
                ROUND((suma_calificaciones / NULLIF(total_actividades, 0))::numeric, 2) as promedio_real,
                ROUND((suma_calificaciones / NULLIF(entregas, 0))::numeric, 2) as promedio_entregadas,
                -- ✅ Pendientes = actividades no entregadas
                (total_actividades - entregas) as pendientes,
                atrasadas,
                notas_altas,
                notas_medias,
                notas_bajas
            FROM entregas_estudiante
            ORDER BY nombre
        ");
        $estudiantes->execute([$usuario_id, $grado, $seccion]);
        $lista_estudiantes = $estudiantes->fetchAll(PDO::FETCH_ASSOC);
        
        // ✅ DEBUG: Verificar datos
        error_log("=== DEBUG REPORTE GENERAL ===");
        error_log("Total estudiantes: " . count($lista_estudiantes));
        foreach ($lista_estudiantes as $est) {
            error_log("Estudiante: {$est['nombre']} - Total Act: {$est['total_actividades']} - Entregas: {$est['entregas']} - Pendientes: {$est['pendientes']} - Atrasadas: {$est['atrasadas']}");
        }
        
        // Generar HTML
        $html = generarHTMLGeneral($nombre_docente, $grado, $seccion, $estadisticas, $lista_estudiantes);
    
    // ============================================
    // REPORTE INDIVIDUAL
    // ============================================
    // REPORTE INDIVIDUAL - CONSULTA CORREGIDA
    } elseif ($tipo === 'individual' && $estudiante_id > 0) {
        
        // Datos del estudiante
        $est = $conexion->prepare("
            SELECT u.nombre, e.grado, e.seccion
            FROM usuarios u
            JOIN estudiantes e ON u.id = e.usuario_id
            WHERE u.id = ? AND u.rol = 'Estudiante'
        ");
        $est->execute([$estudiante_id]);
        $estudiante = $est->fetch(PDO::FETCH_ASSOC);
        
        if (!$estudiante) {
            throw new Exception("Estudiante no encontrado");
        }
        
        // ✅ CONSULTA CORREGIDA - Actividades del estudiante
        $actividades = $conexion->prepare("
            SELECT 
                a.titulo,
                a.tipo,
                a.fecha_entrega as fecha_limite,
                ee.fecha_entrega,
                ee.calificacion,
                ee.observaciones,
                ee.estado,
                CASE 
                    WHEN ee.fecha_entrega IS NULL THEN 'Sin entregar'
                    WHEN ee.fecha_entrega::date > a.fecha_entrega::date THEN 'Atrasada'
                    ELSE 'A tiempo'
                END as condicion,
                -- ✅ Calcular días de atraso para depuración
                CASE 
                    WHEN ee.fecha_entrega IS NOT NULL AND ee.fecha_entrega::date > a.fecha_entrega::date 
                    THEN (ee.fecha_entrega::date - a.fecha_entrega::date)
                    ELSE 0
                END as dias_atraso
            FROM actividades a
            LEFT JOIN entregas_estudiantes ee ON a.id = ee.actividad_id AND ee.estudiante_id = ?
            WHERE a.docente_id = ? AND a.activo = true
            ORDER BY a.fecha_entrega DESC
        ");
        $actividades->execute([$estudiante_id, $usuario_id]);
        $lista_actividades = $actividades->fetchAll(PDO::FETCH_ASSOC);
        
        // ✅ DEBUG: Verificar qué datos llegan
        error_log("=== DEBUG REPORTE INDIVIDUAL ===");
        error_log("Estudiante ID: $estudiante_id");
        error_log("Total actividades: " . count($lista_actividades));
        foreach ($lista_actividades as $act) {
            error_log("Actividad: {$act['titulo']} - Fecha límite: {$act['fecha_limite']} - Fecha entrega: {$act['fecha_entrega']} - Condición: {$act['condicion']} - Días atraso: {$act['dias_atraso']}");
        }
        
        // Promedio (sin cambios)
        $prom = $conexion->prepare("
            SELECT ROUND(AVG(calificacion)::numeric, 2) as promedio
            FROM entregas_estudiantes
            WHERE estudiante_id = ? AND calificacion IS NOT NULL
        ");
        $prom->execute([$estudiante_id]);
        $promedio = $prom->fetchColumn() ?: 'N/A';
        
        // Generar HTML
        $html = generarHTMLIndividual($nombre_docente, $grado, $seccion, $estudiante, $lista_actividades, $promedio);
    }
    
    // Configurar Dompdf
    $options = new Options();
    $options->set('defaultFont', 'Arial');
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    // Descargar PDF
    $nombre_archivo = 'reporte_' . $tipo . '_' . date('Y-m-d') . '.pdf';
    $dompdf->stream($nombre_archivo, ['Attachment' => true]);
    
} catch (Exception $e) {
    error_log("Error PDF: " . $e->getMessage());
    die('Error al generar el reporte: ' . $e->getMessage());
}

function generarHTMLIndividual($docente, $grado, $seccion, $estudiante, $actividades, $promedio) {
    
    // Calcular estadísticas adicionales
    $total_actividades = count($actividades);
    $entregadas = 0;
    $calificadas = 0;
    $atrasadas = 0;
    $a_tiempo = 0;
    $sin_entregar = 0;
    $suma_notas = 0;
    
    foreach ($actividades as $act) {
        if ($act['fecha_entrega']) {
            $entregadas++;
            if ($act['condicion'] === 'Atrasada') {
                $atrasadas++;
            } else {
                $a_tiempo++;
            }
        } else {
            $sin_entregar++;
        }
        
        if ($act['calificacion']) {
            $calificadas++;
            $suma_notas += $act['calificacion'];
        }
    }
    
    $promedio_calculado = $calificadas > 0 ? round($suma_notas / $calificadas, 2) : 0;
    
    // Generar filas de actividades
    $filas = '';
    foreach ($actividades as $act) {
        $nota = $act['calificacion'] ? number_format($act['calificacion'], 2) . '/20' : '—';
        
        // Determinar clase de color para la nota
        $clase_nota = '';
        if ($act['calificacion'] >= 16) {
            $clase_nota = 'nota-alta';
        } elseif ($act['calificacion'] >= 12) {
            $clase_nota = 'nota-media';
        } elseif ($act['calificacion'] > 0) {
            $clase_nota = 'nota-baja';
        }
        
        // Estado y condición
        $condicion = $act['condicion'] ?? 'Sin entregar';
        
        // Color para la condición
        $color_condicion = '#666'; // gris por defecto
        $icono_condicion = '⏳';
        if ($condicion === 'Atrasada') {
            $color_condicion = '#EF5E8E'; // pink
            $icono_condicion = '⚠️';
        } elseif ($condicion === 'A tiempo') {
            $color_condicion = '#4BC4E7'; // cyan
            $icono_condicion = '✅';
        } elseif ($condicion === 'Sin entregar') {
            $color_condicion = '#999'; // gris
            $icono_condicion = '❌';
        }
        
        // Fecha de entrega formateada
        $fecha_entrega = $act['fecha_entrega'] ? date('d/m/Y H:i', strtotime($act['fecha_entrega'])) : '—';
        
        // Mostrar días de atraso si aplica
        $dias_atraso_texto = '';
        if ($act['condicion'] === 'Atrasada' && isset($act['dias_atraso']) && $act['dias_atraso'] > 0) {
            $dias_atraso_texto = "<br><small style='color: #EF5E8E;'>({$act['dias_atraso']} día(s) de atraso)</small>";
        }
        
        $filas .= '
        <tr>
            <td style="font-weight: bold;">' . htmlspecialchars($act['titulo']) . '<br><small style="color: #666;">' . ucfirst($act['tipo']) . '</small></td>
            <td>' . date('d/m/Y', strtotime($act['fecha_limite'])) . '</td>
            <td>' . $fecha_entrega . $dias_atraso_texto . '</td>
            <td style="color: ' . $color_condicion . '; font-weight: bold;">' . $icono_condicion . ' ' . $condicion . '</td>
            <td class="' . $clase_nota . '" style="font-weight: bold; text-align: center;">' . $nota . '</td>
            <td style="max-width: 200px;">' . nl2br(htmlspecialchars($act['observaciones'] ?? '—')) . '</td>
        </tr>';
    }
    
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: Arial, sans-serif; font-size: 12px; }
            
            .header { 
                text-align: center; 
                margin-bottom: 30px; 
                border-bottom: 2px solid #C2D54E; 
                padding-bottom: 20px; 
            }
            .header h1 { 
                color: #C2D54E; 
                margin: 0; 
                font-size: 24px;
            }
            .header p { 
                color: #666; 
                margin: 5px 0; 
            }
            
            /* Tarjetas de información */
            .info-card {
                background: #f8f9fa;
                border-radius: 12px;
                padding: 20px;
                margin: 20px 0;
                border-left: 4px solid #4BC4E7;
            }
            .info-grid {
                display: flex;
                flex-wrap: wrap;
                gap: 20px;
            }
            .info-item {
                flex: 1;
                min-width: 200px;
            }
            .info-label {
                font-weight: bold;
                color: #666;
                font-size: 11px;
                text-transform: uppercase;
                margin-bottom: 4px;
            }
            .info-value {
                font-size: 18px;
                font-weight: bold;
                color: #333;
            }
            
            /* Estadísticas rápidas */
            .stats-grid {
                display: flex;
                gap: 15px;
                margin: 25px 0;
                flex-wrap: wrap;
            }
            .stat-box {
                background: white;
                border-radius: 10px;
                padding: 15px;
                flex: 1;
                min-width: 120px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                border-top: 3px solid;
            }
            .stat-box.total { border-color: #4BC4E7; }
            .stat-box.entregadas { border-color: #C2D54E; }
            .stat-box.atrasadas { border-color: #EF5E8E; }
            .stat-box.promedio { border-color: #9B8AFB; }
            
            .stat-label {
                font-size: 11px;
                color: #666;
                text-transform: uppercase;
            }
            .stat-number {
                font-size: 24px;
                font-weight: bold;
                margin: 5px 0;
            }
            .stat-detail {
                font-size: 10px;
                color: #999;
            }
            
            /* Tabla de actividades */
            .resumen-table { 
                width: 100%; 
                border-collapse: collapse; 
                margin: 20px 0; 
                font-size: 11px;
            }
            .resumen-table th { 
                background: #C2D54E; 
                color: white; 
                padding: 10px; 
                text-align: left; 
                font-weight: 600;
            }
            .resumen-table td { 
                padding: 10px; 
                border: 1px solid #ddd; 
                vertical-align: top;
            }
            .resumen-table tr:nth-child(even) { 
                background: #f9f9f9; 
            }
            .resumen-table tr:hover {
                background: #f0f0f0;
            }
            
            /* Colores para notas */
            .nota-alta { 
                color: #28a745; 
                font-weight: bold; 
                background: rgba(40, 167, 69, 0.1);
                padding: 3px 8px;
                border-radius: 4px;
            }
            .nota-media { 
                color: #4BC4E7; 
                font-weight: bold;
                background: rgba(75, 196, 231, 0.1);
                padding: 3px 8px;
                border-radius: 4px;
            }
            .nota-baja { 
                color: #EF5E8E; 
                font-weight: bold;
                background: rgba(239, 94, 142, 0.1);
                padding: 3px 8px;
                border-radius: 4px;
            }
            
            .footer { 
                margin-top: 40px; 
                text-align: center; 
                color: #999; 
                font-size: 10px; 
                border-top: 1px solid #ddd; 
                padding-top: 10px; 
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1> SIEDUCRES - REPORTE INDIVIDUAL</h1>
            <p>Docente: ' . htmlspecialchars($docente) . ' | ' . $grado . ' ' . $seccion . '</p>
            <p>Fecha de generación: ' . date('d/m/Y H:i:s') . '</p>
        </div>
        
        <!-- Información del estudiante -->
        <div class="info-card">
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Estudiante</div>
                    <div class="info-value">' . htmlspecialchars($estudiante['nombre']) . '</div>
                </div>
                <div class="info-item">
                    <div class="info-label">Grado/Sección</div>
                    <div class="info-value">' . $estudiante['grado'] . ' ' . $estudiante['seccion'] . '</div>
                </div>
                <div class="info-item">
                    <div class="info-label">Promedio General</div>
                    <div class="info-value" style="color: ' . ($promedio_calculado >= 16 ? '#28a745' : ($promedio_calculado >= 12 ? '#4BC4E7' : '#EF5E8E')) . ';">' . number_format($promedio_calculado, 2) . '/20</div>
                </div>
            </div>
        </div>
        
        <!-- Estadísticas rápidas -->
        <div class="stats-grid">
            <div class="stat-box total">
                <div class="stat-label">Total Actividades</div>
                <div class="stat-number">' . $total_actividades . '</div>
                <div class="stat-detail">asignadas</div>
            </div>
            <div class="stat-box entregadas">
                <div class="stat-label">Entregadas</div>
                <div class="stat-number">' . $entregadas . '/' . $total_actividades . '</div>
                <div class="stat-detail">
                    <span style="color: #4BC4E7;"> ' . $a_tiempo . ' a tiempo</span>
                </div>
            </div>
            <div class="stat-box atrasadas">
                <div class="stat-label">Atrasadas</div>
                <div class="stat-number" style="color: #EF5E8E;">' . $atrasadas . '</div>
                <div class="stat-detail">
                    <span style="color: #EF5E8E;"> ' . $atrasadas . ' atrasadas</span>
                </div>
            </div>
            <div class="stat-box promedio">
                <div class="stat-label">Sin entregar</div>
                <div class="stat-number" style="color: #999;">' . $sin_entregar . '</div>
                <div class="stat-detail">pendientes</div>
            </div>
        </div>
        
        <!-- Detalle de actividades -->
        <div style="margin-top: 30px;">
            <h3 style="color: #4BC4E7; border-bottom: 2px solid #4BC4E7; padding-bottom: 10px; margin-bottom: 20px;">
                 Detalle de Actividades
            </h3>
            
            <table class="resumen-table">
                <thead>
                    <tr>
                        <th>Actividad</th>
                        <th>Fecha Límite</th>
                        <th>Fecha Entrega</th>
                        <th>Condición</th>
                        <th>Calificación</th>
                        <th>Retroalimentación</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $filas . '
                </tbody>
            </table>
        </div>
        
        <!-- Leyenda -->
        <div style="margin-top: 20px; padding: 15px; background: #f5f5f5; border-radius: 8px; font-size: 10px;">
            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                <div><span style="display: inline-block; width: 12px; height: 12px; background: #28a745; border-radius: 3px; margin-right: 5px;"></span> Nota alta (16-20)</div>
                <div><span style="display: inline-block; width: 12px; height: 12px; background: #4BC4E7; border-radius: 3px; margin-right: 5px;"></span> Nota media (12-15.9)</div>
                <div><span style="display: inline-block; width: 12px; height: 12px; background: #EF5E8E; border-radius: 3px; margin-right: 5px;"></span> Nota baja (0-11.9)</div>
                <div><span style="display: inline-block; width: 12px; height: 12px; background: #f8d7da; border-radius: 3px; margin-right: 5px;"></span> Entrega atrasada</div>
            </div>
        </div>
        
        <div class="footer">
            <p>Documento generado automáticamente por el sistema SIEDUCRES</p>
            <p>© ' . date('Y') . ' - Escuela Atanasio Girardot</p>
        </div>
    </body>
    </html>';
}
function generarHTMLGeneral($docente, $grado, $seccion, $stats, $estudiantes) {
    $filas = '';
    
    // ✅ CORREGIDO: Usar $estudiantes (nombre del parámetro)
    foreach ($estudiantes as $est) {
        $promedio_real = $est['promedio_real'] ?? 0;
        $clase_prom = $promedio_real >= 16 ? 'alta' : ($promedio_real >= 12 ? 'media' : 'baja');
        
        $filas .= '
        <tr>
            <td>' . htmlspecialchars($est['nombre']) . '</td>
            <td>' . $est['total_actividades'] . '</td>
            <td>' . $est['entregas'] . '/' . $est['total_actividades'] . '</td>
            <td class="promedio-' . $clase_prom . '">' . number_format($promedio_real, 2) . '/20</td>
            <td>' . ($est['promedio_entregadas'] ? number_format($est['promedio_entregadas'], 2) . '/20' : '—') . '</td>
            <td>' . $est['pendientes'] . '</td>
            <td>' . $est['atrasadas'] . '</td>
        </tr>';
    }
    
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: Arial, sans-serif; font-size: 12px; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #9CAF44; padding-bottom: 20px; }
            .header h1 { color: #9b8afb; margin: 0; }
            .header p { color: #666; margin: 5px 0; }
            .resumen { margin: 20px 0; }
            .resumen-table { width: 100%; border-collapse: collapse; margin: 10px 0; }
            .resumen-table th { background: #9b8afb; color: white; padding: 10px; text-align: left; }
            .resumen-table td { padding: 8px; border: 1px solid #ddd; }
            .resumen-table tr:nth-child(even) { background: #f9f9f9; }
            .estadisticas { display: flex; gap: 20px; margin: 20px 0; }
            .stat-box { background: #f5f5f5; padding: 15px; border-radius: 8px; flex: 1; text-align: center; }
            .stat-box h3 { margin: 0; color: #9b8afb; }
            .stat-box .numero { font-size: 24px; font-weight: bold; }
            .promedio-alta { color: #28a745; font-weight: bold; }
            .promedio-media { color: #14b7c0; font-weight: bold; }
            .promedio-baja { color: #f36c7b; font-weight: bold; }
            .footer { margin-top: 40px; text-align: center; color: #999; font-size: 10px; border-top: 1px solid #ddd; padding-top: 10px; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>SIEDUCRES - REPORTE GENERAL</h1>
            <p>Docente: ' . htmlspecialchars($docente) . ' | ' . $grado . ' ' . $seccion . '</p>
            <p>Fecha: ' . date('d/m/Y H:i') . '</p>
        </div>
        
        <div class="estadisticas">
            <div class="stat-box">
                <h3>Actividades</h3>
                <div class="numero">' . $stats['total_actividades'] . '</div>
            </div>
            <div class="stat-box">
                <h3>Estudiantes</h3>
                <div class="numero">' . $stats['total_estudiantes'] . '</div>
            </div>
            <div class="stat-box">
                <h3>Por calificar</h3>
                <div class="numero">' . $stats['pendientes_calificar'] . '</div>
            </div>
            <div class="stat-box">
                <h3>Atrasadas</h3>
                <div class="numero">' . $stats['entregas_atrasadas'] . '</div>
            </div>
        </div>
        
        <div class="resumen">
            <h3>Detalle por Estudiante</h3>
            <table class="resumen-table">
                <thead>
                    <tr>
                        <th>Estudiante</th>
                        <th>Total Act.</th>
                        <th>Entregas</th>
                        <th>Promedio Real</th>
                        <th>Prom. Entregadas</th>
                        <th>Pendientes</th>
                        <th>Atrasadas</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $filas . '
                </tbody>
            </table>
        </div>
        
        <div class="footer">
            <p>Generado automáticamente por SIEDUCRES - ' . date('Y') . '</p>
        </div>
    </body>
    </html>';
}
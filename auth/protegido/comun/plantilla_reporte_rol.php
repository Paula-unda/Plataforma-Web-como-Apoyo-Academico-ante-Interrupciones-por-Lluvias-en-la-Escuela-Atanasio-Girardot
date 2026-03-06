<?php
// Las variables vienen de reportes.php a través de $pdf_data
if (!isset($pdf_data)) {
    $pdf_data = [
        'metricas' => [],
        'actividades' => [],
        'contenidos' => [],
        'periodo_activo' => null,
        'usuario_rol' => 'Usuario',
        'usuario_nombre' => 'Usuario'
    ];
}

// Extraer variables para facilitar su uso
$metricas = $pdf_data['metricas'];
$actividades = $pdf_data['actividades'];
$contenidos = $pdf_data['contenidos'];
$periodo_activo = $pdf_data['periodo_activo'];
$usuario_rol = $pdf_data['usuario_rol'];
$usuario_nombre = $pdf_data['usuario_nombre'];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reporte - SIEDUCRES</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            font-size: 12px; 
            margin: 20px;
        }
        .header { 
            text-align: center; 
            margin-bottom: 20px; 
            border-bottom: 2px solid #4BC4E7; 
            padding-bottom: 10px;
        }
        h1 { 
            color: #4BC4E7; 
            font-size: 24px; 
            margin: 0 0 5px 0;
        }
        h2 { 
            color: #9b8afb; 
            font-size: 18px; 
            margin-top: 25px;
            margin-bottom: 10px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
        .info-generacion {
            color: #666;
            font-size: 11px;
            margin-bottom: 10px;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 15px 0; 
            font-size: 11px;
        }
        th { 
            background: #4BC4E7; 
            color: white; 
            padding: 8px; 
            text-align: left; 
            font-weight: bold;
        }
        td { 
            padding: 8px; 
            border-bottom: 1px solid #ddd; 
        }
        .metricas-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin: 20px 0;
        }
        .metrica {
            background: #f5f5f5;
            padding: 10px;
            text-align: center;
            border-radius: 5px;
            border-left: 3px solid #4BC4E7;
        }
        .metrica .valor {
            font-size: 20px;
            font-weight: bold;
            color: #4BC4E7;
        }
        .metrica .etiqueta {
            font-size: 11px;
            color: #666;
        }
        .footer { 
            margin-top: 30px; 
            text-align: center; 
            color: #999; 
            font-size: 10px; 
            border-top: 1px solid #ddd; 
            padding-top: 10px; 
        }
        .mensaje-vacio {
            text-align: center;
            padding: 20px;
            color: #999;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📊 REPORTE SIEDUCRES</h1>
        <div class="info-generacion">
            Generado el <?php echo date('d/m/Y H:i'); ?> por <?php echo htmlspecialchars($usuario_nombre); ?><br>
            Rol: <?php echo $usuario_rol; ?>
            <?php if ($periodo_activo): ?>
                | Período: <?php echo $periodo_activo['nombre']; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- MÉTRICAS -->
    <?php if (!empty($metricas)): ?>
    <h2>📈 Resumen</h2>
    <div class="metricas-grid">
        <?php foreach ($metricas as $etiqueta => $valor): ?>
        <div class="metrica">
            <div class="valor"><?php echo $valor; ?></div>
            <div class="etiqueta"><?php echo ucfirst(str_replace('_', ' ', $etiqueta)); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <!-- ACTIVIDADES -->
    <h2>📝 Actividades</h2>
    <?php if (empty($actividades)): ?>
        <p class="mensaje-vacio">No hay actividades para mostrar en este período</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Actividad</th>
                <th>Tipo</th>
                <th>Grado</th>
                <th>Sección</th>
                <th>Entregas</th>
                <th>Calificadas</th>
                <th>Promedio</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($actividades as $a): ?>
            <tr>
                <td><?php echo htmlspecialchars($a['titulo']); ?></td>
                <td><?php echo $a['tipo']; ?></td>
                <td><?php echo $a['grado']; ?></td>
                <td><?php echo $a['seccion']; ?></td>
                <td><?php echo $a['entregas']; ?></td>
                <td><?php echo $a['calificadas']; ?></td>
                <td><?php echo $a['promedio'] ?: '-'; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    
    <!-- CONTENIDOS -->
    <h2>📚 Contenidos</h2>
    <?php if (empty($contenidos)): ?>
        <p class="mensaje-vacio">No hay contenidos para mostrar en este período</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Contenido</th>
                <th>Asignatura</th>
                <th>Grado</th>
                <th>Estudiantes</th>
                <th>% Visto</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($contenidos as $c): ?>
            <tr>
                <td><?php echo htmlspecialchars($c['titulo']); ?></td>
                <td><?php echo $c['asignatura']; ?></td>
                <td><?php echo $c['grado']; ?></td>
                <td><?php echo $c['estudiantes']; ?></td>
                <td><?php echo $c['promedio_visto']; ?>%</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    
    <div class="footer">
        <p>SIEDUCRES - Plataforma Educativa</p>
    </div>
</body>
</html>
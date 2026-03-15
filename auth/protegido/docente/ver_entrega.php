<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/onesignal_config.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Docente') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$conexion = getConexion();
$docente_id = $_SESSION['usuario_id'];
$entrega_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($entrega_id <= 0) {
    header('Location: calificaciones.php?error=ID+de+entrega+inválido');
    exit();
}

// Obtener datos de la entrega con toda la información
$stmt = $conexion->prepare("
    SELECT 
        ee.*,
        a.id as actividad_id,
        a.titulo as actividad_titulo,
        a.descripcion as actividad_descripcion,
        a.tipo as actividad_tipo,
        a.fecha_entrega as actividad_fecha_limite,
        u.id as estudiante_id,
        u.nombre as estudiante_nombre,
        u.correo as estudiante_correo,
        e.grado as estudiante_grado,
        e.seccion as estudiante_seccion
    FROM entregas_estudiantes ee
    INNER JOIN actividades a ON ee.actividad_id = a.id
    INNER JOIN usuarios u ON ee.estudiante_id = u.id
    LEFT JOIN estudiantes e ON u.id = e.usuario_id
    WHERE ee.id = ? AND a.docente_id = ?
");
$stmt->execute([$entrega_id, $docente_id]);
$entrega = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entrega) {
    header('Location: calificaciones.php?error=Entrega+no+encontrada');
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle de Entrega - <?php echo htmlspecialchars($entrega['estudiante_nombre']); ?></title>
    <?php require_once '../includes/favicon.php'; ?>
    <?php require_once '../includes/header_onesignal.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary-cyan: #4BC4E7;
            --primary-pink: #EF5E8E;
            --primary-lime: #C2D54E;
            --primary-purple: #9B8AFB;
            --text-dark: #333333;
            --text-muted: #666666;
            --border: #E0E0E0;
            --surface: #FFFFFF;
            --background: #F5F5F5;
        }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--background);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 24px;
            height: 60px;
            background-color: var(--surface);
            border-bottom: 1px solid var(--border);
        }
        .logo { height: 40px; }
        .banner {
            height: 100px;
            overflow: hidden;
        }
        .banner-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .banner-content {
            text-align: center;
            padding: 20px;
        }
        .banner-title {
            font-size: 36px;
            font-weight: 700;
            color: var(--text-dark);
        }
        .main-content {
            flex: 1;
            padding: 40px 20px;
            max-width: 900px;
            margin: 0 auto;
            width: 100%;
        }
        .back-link {
            display: inline-block;
            color: var(--primary-pink);
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            margin-bottom: 20px;
            transition: transform 0.2s;
        }
        .back-link:hover {
            transform: translateX(-4px);
        }
        .card {
            background: var(--surface);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .card-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 16px;
            color: var(--primary-cyan);
            border-bottom: 2px solid var(--primary-cyan);
            padding-bottom: 8px;
        }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }
        .info-item {
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid var(--primary-cyan);
        }
        .info-label {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 4px;
        }
        .info-value {
            font-size: 16px;
            font-weight: 600;
            word-break: break-word;
        }
        .descripcion-box {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
            line-height: 1.6;
            white-space: pre-line;
            border-left: 4px solid var(--primary-purple);
        }
        .comentario-box {
            background: #fff8e7;
            padding: 16px;
            border-radius: 8px;
            border-left: 4px solid #ffc107;
            margin-top: 16px;
            white-space: pre-line;
        }
        .archivo-box {
            background: #f0f0f0;
            padding: 16px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .btn-download {
            background: var(--primary-cyan);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: transform 0.2s;
        }
        .btn-download:hover {
            transform: translateY(-2px);
        }
        .btn-return {
            background: var(--primary-pink);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            display: inline-block;
            margin-top: 16px;
        }
        .footer {
            height: 50px;
            background-color: var(--surface);
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 24px;
            font-size: 13px;
            color: var(--text-muted);
        }
        @media (max-width: 768px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header_comun.php'; ?>

    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner" class="banner-image">
    </div>


    <div class="banner-content">
        <h1 class="banner-title">Detalle de Entrega</h1>
    </div>

    <main class="main-content">
        <!-- Datos del estudiante -->
        <div class="card">
            <h2 class="card-title">👤 Estudiante</h2>
            <div class="grid-2">
                <div class="info-item">
                    <div class="info-label">Nombre</div>
                    <div class="info-value"><?php echo htmlspecialchars($entrega['estudiante_nombre']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Correo</div>
                    <div class="info-value"><?php echo htmlspecialchars($entrega['estudiante_correo']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Grado/Sección</div>
                    <div class="info-value"><?php echo $entrega['estudiante_grado'] . ' ' . $entrega['estudiante_seccion']; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Estado de entrega</div>
                    <div class="info-value">
                        <?php 
                        $estado = $entrega['estado'] ?? 'pendiente';
                        $color = $estado === 'calificado' ? '#28a745' : ($estado === 'enviado' ? '#ffc107' : '#6c757d');
                        ?>
                        <span style="color: <?php echo $color; ?>; font-weight: 700;">
                            <?php echo ucfirst($estado); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Datos de la actividad -->
        <div class="card">
            <h2 class="card-title">📝 Actividad</h2>
            <div class="grid-2">
                <div class="info-item">
                    <div class="info-label">Título</div>
                    <div class="info-value"><?php echo htmlspecialchars($entrega['actividad_titulo']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Tipo</div>
                    <div class="info-value"><?php echo ucfirst($entrega['actividad_tipo']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Fecha límite</div>
                    <div class="info-value"><?php echo date('d/m/Y', strtotime($entrega['actividad_fecha_limite'])); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Fecha entrega</div>
                    <div class="info-value">
                        <?php 
                        if ($entrega['fecha_entrega']) {
                            echo date('d/m/Y H:i', strtotime($entrega['fecha_entrega']));
                            if (strtotime($entrega['fecha_entrega']) > strtotime($entrega['actividad_fecha_limite'])) {
                                echo ' <span style="color: #dc3545; font-size: 12px;">(Atrasada)</span>';
                            }
                        } else {
                            echo 'No entregada';
                        }
                        ?>
                    </div>
                </div>
            </div>
            
            <div style="margin-top: 16px;">
                <div class="info-label">📋 Instrucciones</div>
                <div class="descripcion-box">
                    <?php echo nl2br(htmlspecialchars($entrega['actividad_descripcion'])); ?>
                </div>
            </div>
        </div>

        <!-- Archivo entregado -->
        <?php if (!empty($entrega['archivo_entregado'])): ?>
        <div class="card">
            <h2 class="card-title">📎 Archivo entregado</h2>
            <div class="archivo-box">
                <span style="font-size: 40px;">📄</span>
                <div style="flex: 1;">
                    <div style="font-weight: 600;"><?php echo htmlspecialchars(basename($entrega['archivo_entregado'])); ?></div>
                    <div style="font-size: 12px; color: #666; margin-top: 4px;">
                        Entregado el: <?php echo date('d/m/Y H:i', strtotime($entrega['fecha_entrega'])); ?>
                    </div>
                </div>
                <a href="../../uploads/entregas/<?php echo $entrega['archivo_entregado']; ?>" 
                   class="btn-download" download>
                    ⬇️ Descargar
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Comentario del estudiante -->
        <?php if (!empty($entrega['comentario'])): ?>
        <div class="card">
            <h2 class="card-title">💬 Comentario del estudiante</h2>
            <div class="comentario-box">
                <?php echo nl2br(htmlspecialchars($entrega['comentario'])); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Calificación (si existe) -->
        <?php if ($entrega['calificacion'] !== null): ?>
        <div class="card">
            <h2 class="card-title">⭐ Calificación</h2>
            <div style="display: flex; align-items: center; gap: 30px; flex-wrap: wrap;">
                <div style="background: var(--primary-purple); color: white; padding: 20px; border-radius: 50%; width: 100px; height: 100px; display: flex; align-items: center; justify-content: center;">
                    <span style="font-size: 32px; font-weight: 700;"><?php echo $entrega['calificacion']; ?></span>
                </div>
                <?php if (!empty($entrega['observaciones'])): ?>
                <div style="flex: 1;">
                    <div style="font-weight: 600; margin-bottom: 8px;">Observaciones:</div>
                    <div style="background: #f8f9fa; padding: 16px; border-radius: 8px;">
                        <?php echo nl2br(htmlspecialchars($entrega['observaciones'])); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Botones de acción -->
        <div style="text-align: center; margin-top: 30px;">
            <a href="calificaciones.php?estudiante=<?php echo $entrega['estudiante_id']; ?>" class="btn-return">
                Volver a calificaciones
            </a>
        </div>
    </main>

    <footer class="footer">
        <span>v2.0.0</span>
        <span>Soporte Técnico</span>
    </footer>
</body>
</html>
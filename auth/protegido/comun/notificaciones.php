<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/notificaciones_funciones.php';

if (!sesionActiva()) {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$conexion = getConexion();
$usuario_id = $_SESSION['usuario_id'];
$usuario_rol = $_SESSION['usuario_rol'];

// Si es representante, puede filtrar por estudiante
$estudiante_id = $_GET['estudiante_id'] ?? null;
$mostrar_para = $usuario_id;

if ($usuario_rol === 'Representante' && $estudiante_id) {
    // Verificar que el estudiante le pertenezca
    $stmt = $conexion->prepare("
        SELECT 1 FROM representantes_estudiantes 
        WHERE representante_id = ? AND estudiante_id = ?
    ");
    $stmt->execute([$usuario_id, $estudiante_id]);
    if ($stmt->fetch()) {
        $mostrar_para = $estudiante_id;
    }
}

// Marcar como leída
if (isset($_GET['marcar_leida'])) {
    marcarNotificacionesLeidas($conexion, $mostrar_para, $_GET['marcar_leida']);
    header('Location: notificaciones.php' . ($estudiante_id ? "?estudiante_id=$estudiante_id" : ''));
    exit();
}

// Marcar todas como leídas
if (isset($_GET['marcar_todas'])) {
    marcarNotificacionesLeidas($conexion, $mostrar_para);
    header('Location: notificaciones.php' . ($estudiante_id ? "?estudiante_id=$estudiante_id" : ''));
    exit();
}

// Obtener notificaciones
$notificaciones = obtenerTodasNotificaciones($conexion, $mostrar_para, 100);
$no_leidas = obtenerNotificacionesNoLeidas($conexion, $mostrar_para);

// Obtener nombre para mostrar
$nombre_mostrar = $_SESSION['usuario_nombre'];
if ($mostrar_para != $usuario_id) {
    $stmt = $conexion->prepare("SELECT nombre FROM usuarios WHERE id = ?");
    $stmt->execute([$mostrar_para]);
    $nombre_mostrar = $stmt->fetchColumn() . " (tu representado)";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notificaciones - SIEDUCRES</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        .header { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #4BC4E7; margin-bottom: 10px; }
        .stats { display: flex; gap: 20px; margin: 15px 0; }
        .stat { background: #f0f0f0; padding: 8px 16px; border-radius: 20px; font-size: 14px; }
        .btn { background: #4BC4E7; color: white; padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; }
        .notificacion { background: white; border-radius: 12px; padding: 20px; margin-bottom: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border-left: 4px solid #4BC4E7; }
        .notificacion.no-leida { background: #e3f2fd; }
        .notificacion-header { display: flex; justify-content: space-between; margin-bottom: 10px; }
        .tipo { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .tipo-actividad { background: #4BC4E7; color: white; }
        .tipo-calificacion { background: #EF5E8E; color: white; }
        .tipo-foro { background: #C2D54E; color: #333; }
        .tipo-encuesta { background: #9b8afb; color: white; }
        .fecha { color: #999; font-size: 12px; }
        .acciones { margin-top: 10px; text-align: right; }
        .btn-marcar { color: #4BC4E7; text-decoration: none; font-size: 13px; }
        .empty { text-align: center; padding: 50px; color: #999; }
        body {
            padding-top: 60px;  /* ← ALTURA DEL HEADER */
        }
        /* Enlace volver */
        .back-link {
            display: block;
            color: #EF5E8E;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header_comun.php'; ?>
    <!-- 🔴 FLECHA DE VOLVER SEGÚN EL ROL -->
    <?php
    // Determinar la página de inicio según el rol
    $pagina_inicio = '';
    switch ($usuario_rol) {
        case 'Administrador':
            $pagina_inicio = '../admin/index.php';
            break;
        case 'Docente':
            $pagina_inicio = '../docente/index.php';
            break;
        case 'Estudiante':
            $pagina_inicio = '../estudiante/index.php';
            break;
        case 'Representante':
            $pagina_inicio = '../representante/index.php';
            break;
        default:
            $pagina_inicio = '../../index.php';
    }
    ?>
    <div style="max-width: 1400px; margin: 15px 0 15px 40px; padding: 0; width: 100%; text-align: left;">
        <a href="<?php echo $pagina_inicio; ?>" 
        style="display: inline-block; color: #EF5E8E; text-decoration: none; font-weight: 500; font-size: 14px; transition: transform 0.2s;"
        onmouseover="this.style.transform='translateX(-4px)'" 
        onmouseout="this.style.transform='translateX(0)'">
            ← Volver al Panel Principal
        </a>
    </div>
    <div class="container">
        <div class="header">
            <h1>🔔 Notificaciones</h1>
            
            <p>para <?php echo htmlspecialchars($nombre_mostrar); ?></p>
            <div class="stats">
                <span class="stat">📊 Total: <?php echo count($notificaciones); ?></span>
                <span class="stat">🆕 No leídas: <?php echo count($no_leidas); ?></span>
            </div>
            <?php if (count($no_leidas) > 0): ?>
                <a href="?marcar_todas=1<?php echo $estudiante_id ? "&estudiante_id=$estudiante_id" : ''; ?>" class="btn">✓ Marcar todas como leídas</a>
            <?php endif; ?>
        </div>

        <?php if (empty($notificaciones)): ?>
            <div class="empty">
                <p>No hay notificaciones</p>
            </div>
        <?php else: ?>
            <?php foreach ($notificaciones as $n): ?>
                <div class="notificacion <?php echo !$n['leido'] ? 'no-leida' : ''; ?>">
                    <div class="notificacion-header">
                        <span class="tipo tipo-<?php echo $n['tipo']; ?>"><?php echo ucfirst($n['tipo']); ?></span>
                        <span class="fecha"><?php echo date('d/m/Y H:i', strtotime($n['fecha_envio'])); ?></span>
                    </div>
                    <h3><?php echo htmlspecialchars($n['titulo']); ?></h3>
                    <p><?php echo htmlspecialchars($n['mensaje']); ?></p>
                    <?php if (!$n['leido']): ?>
                        <div class="acciones">
                            <a href="?marcar_leida=<?php echo $n['id']; ?><?php echo $estudiante_id ? "&estudiante_id=$estudiante_id" : ''; ?>" class="btn-marcar">Marcar como leída</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
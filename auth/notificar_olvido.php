<?php
require_once 'funciones.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = sanitizar($_POST['correo'] ?? '');
    $mensaje = '';

    if (filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        try {
            $conexion = getConexion();
            
            // Verificar si el correo existe
            $stmt = $conexion->prepare("SELECT id, nombre FROM usuarios WHERE correo = ?");
            $stmt->execute([$correo]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($usuario) {
                // Buscar un administrador
                $stmt_admin = $conexion->prepare("
                    SELECT id FROM usuarios WHERE rol = 'Administrador' AND activo = true LIMIT 1
                ");
                $stmt_admin->execute();
                $admin = $stmt_admin->fetch(PDO::FETCH_ASSOC);
                
                if ($admin) {
                    // Incluir funciones de notificación
                    require_once 'protegido/includes/notificaciones_funciones.php';
                    
                    // Enviar notificación al admin
                    enviarNotificacion(
                        $conexion,
                        $admin['id'],
                        "🔐 Solicitud de recuperación de contraseña",
                        "El usuario " . $usuario['nombre'] . " (" . $correo . ") ha solicitado restablecer su contraseña.",
                        'sistema',
                        $usuario['id'],
                        'usuarios'
                    );
                    
                    $mensaje = "✅ Solicitud enviada. El administrador será notificado para ayudarte a restablecer tu contraseña.";
                } else {
                    $mensaje = "❌ No hay administradores disponibles.";
                }
            } else {
                $mensaje = "❌ El correo no está registrado en el sistema.";
            }
            
        } catch (Exception $e) {
            error_log("Error en notificar_olvido: " . $e->getMessage());
            $mensaje = "❌ Error al procesar la solicitud.";
        }
    } else {
        $mensaje = "❌ Por favor ingrese un correo válido.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recuperar Contraseña - SIEDUCRES</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f5f5f5; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .btn-warning { background-color: #4BC4E7; border-color: #4BC4E7; color: white; }
        .btn-warning:hover { background-color: #3ab3d6; border-color: #3ab3d6; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center vh-100 bg-light">
    <div class="card" style="max-width: 450px; width: 100%;">
        <div class="card-header bg-white text-center border-0 pt-4">
            <h5 class="text-dark">🔐 ¿Olvidaste tu contraseña?</h5>
        </div>
        <div class="card-body px-4 pb-4">
            <?php if (!empty($mensaje)): ?>
                <div class="alert <?php echo strpos($mensaje, '✅') === 0 ? 'alert-success' : 'alert-danger'; ?>">
                    <?= $mensaje ?>
                </div>
                <a href="login.php" class="btn btn-outline-primary w-100">← Volver al inicio</a>
            <?php else: ?>
                <p class="text-muted mb-4">Ingresa tu correo electrónico. El administrador será notificado para ayudarte a restablecer tu contraseña.</p>
                <form method="POST">
                    <div class="mb-3">
                        <label for="correo" class="form-label fw-semibold">Correo registrado</label>
                        <input type="email" name="correo" id="correo" class="form-control" placeholder="ejemplo@correo.com" required>
                    </div>
                    <button type="submit" class="btn btn-warning w-100 py-2">Enviar solicitud</button>
                    <div class="text-center mt-3">
                        <a href="login.php" class="text-decoration-none text-muted small">← Volver al login</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
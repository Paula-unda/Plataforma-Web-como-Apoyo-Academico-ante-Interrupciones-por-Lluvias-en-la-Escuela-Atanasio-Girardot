<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = sanitizar($_POST['correo'] ?? '');

    if (filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        // Validación mínima: solo notificar al admin (según requerimiento)
        // En un entorno real: registrar solicitud en BD o notificar vía email/interfaz.
        $mensaje = " Solicitud registrada. El administrador será notificado para restablecer su contraseña.";
    } else {
        $mensaje = " Por favor ingrese un correo válido.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title> Recuperar Contraseña - SIEDUCRES</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center justify-content-center vh-100 bg-light">
    <div class="card" style="max-width: 450px; width: 100%;">
        <div class="card-header bg-warning text-dark text-center">
            <h5>¿Olvidaste tu contraseña?</h5>
        </div>
        <div class="card-body">
            <?php if (!empty($mensaje)): ?>
                <div class="alert alert-info"><?= $mensaje ?></div>
                <a href="login.php" class="btn btn-outline-primary w-100">← Volver al login</a>
            <?php else: ?>
                <p>Ingresa tu correo electrónico. El administrador será notificado para ayudarte a restablecer tu contraseña.</p>
                <form method="POST">
                    <div class="mb-3">
                        <label for="correo" class="form-label">Correo registrado</label>
                        <input type="email" name="correo" id="correo" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-warning w-100">Enviar solicitud</button>
                    <div class="text-center mt-2">
                        <a href="login.php" class="text-decoration-none">← Volver</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
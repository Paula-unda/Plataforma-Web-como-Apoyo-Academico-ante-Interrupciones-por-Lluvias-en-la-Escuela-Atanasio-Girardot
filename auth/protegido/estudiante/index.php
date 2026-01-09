<?php
session_start();
require_once '../../funciones.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Estudiante') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}
?>
<!DOCTYPE html>
<html>
<head><title>Estudiante - SIEDUCRES</title></head>
<body>
    <h1>✅ Bienvenido, estudiante <?= htmlspecialchars($_SESSION['usuario_nombre']) ?></h1>
    <p>Tu rol: <?= htmlspecialchars($_SESSION['usuario_rol']) ?></p>

</body>
</html>
<!-- Dentro del <body> -->
<a href="/auth/logout.php">🔓 Cerrar sesión</a>
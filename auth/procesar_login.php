<?php
require_once 'conexion.php';
require_once 'funciones.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit();
}

$correo = sanitizar($_POST['correo'] ?? '');
$contrasena = $_POST['contrasena'] ?? '';

if (empty($correo) || empty($contrasena)) {
    header('Location: login.php?error=Por+favor+ingrese+correo+y+contraseña.');
    exit();
}

try {
    $pdo = getConexion();

    // Consulta preparada para evitar SQL Injection
    $stmt = $pdo->prepare("
        SELECT id, nombre, correo, contrasena, rol, estadoCuenta 
        FROM usuarios 
        WHERE correo = :correo
    ");
    $stmt->execute([':correo' => $correo]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        header('Location: login.php?error=Correo+o+contraseña+incorrectos.');
        exit();
    }

    if ($usuario['estadoCuenta'] !== 'activa') {
        header('Location: login.php?error=Su+cuenta+no+está+activa.+Contacte+al+administrador.');
        exit();
    }

    if (!verificarContrasena($contrasena, $usuario['contrasena'])) {
        header('Location: login.php?error=Correo+o+contraseña+incorrectos.');
        exit();
    }

    // ✅ Éxito: iniciar sesión
    iniciarSesion($usuario['id'], $usuario['nombre'], $usuario['correo'], $usuario['rol']);
    redirigirPorRol($usuario['rol']);

} catch (Exception $e) {
    error_log("Error en login: " . $e->getMessage());
    header('Location: login.php?error=Error+interno.+Intente+de+nuevo.');
    exit();
}
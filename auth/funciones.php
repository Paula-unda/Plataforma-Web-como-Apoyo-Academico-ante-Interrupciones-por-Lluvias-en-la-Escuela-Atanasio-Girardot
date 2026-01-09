<?php


/**
 * Sanitiza entrada contra XSS
 */
function sanitizar($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Verifica y hashea contraseña con bcrypt
 */
function verificarContrasena($passPlana, $hashAlmacenado) {
    return password_verify($passPlana, $hashAlmacenado);
}

function hashearContrasena($passPlana) {
    return password_hash($passPlana, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Inicia sesión segura
 */
function iniciarSesion($id, $nombre, $correo, $rol) {
    session_start();
    $_SESSION['usuario_id'] = $id;
    $_SESSION['usuario_nombre'] = $nombre;
    $_SESSION['usuario_correo'] = $correo;
    $_SESSION['usuario_rol'] = $rol;
    // Regenerar ID para evitar fijación de sesión
    session_regenerate_id(true);
}

/**
 * Verifica que la sesión esté activa
 * @return bool
 */
function sesionActiva() {
    return isset($_SESSION['usuario_id']);
}

/**
 * Redirige según rol
 */
function redirigirPorRol($rol) {
    $base = '/auth/protegido/';  
    switch ($rol) {
        case 'Administrador':
            header("Location: {$base}admin/");
            break;
        case 'Docente':
            header("Location: {$base}docente/");
            break;
        case 'Estudiante':
            header("Location: {$base}estudiante/");
            break;
        case 'Representante':
            header("Location: {$base}representante/");
            break;
        default:
            header("Location: /auth/login.php?error=Rol+no+soportado.");
            exit();
    }
    exit();
}
/**
 * Obtiene conexión a la base de datos
 * @return PDO
 */
function getConexion() {
    static $pdo = null;
    if ($pdo === null) {
        $host = $_ENV['DB_HOST'] ?? 'db';
        $port = $_ENV['DB_PORT'] ?? '5432';
        $dbname = $_ENV['DB_NAME'] ?? 'sieducres';
        $user = $_ENV['DB_USER'] ?? 'postgres';
        $pass = $_ENV['DB_PASS'] ?? 'postgres';

        try {
            $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => false, // Mejor explícito
            ]);
        } catch (PDOException $e) {
            error_log("Fallo conexión BD: " . $e->getMessage());
            throw new Exception("Error interno: servicio no disponible.");
        }
    }
    return $pdo;
}
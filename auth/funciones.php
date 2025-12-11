<?php
// auth/funciones.php

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
    $base = '/sieducres/auth/protegido/';
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
            header("Location: {$base}index.php");
    }
    exit();
}
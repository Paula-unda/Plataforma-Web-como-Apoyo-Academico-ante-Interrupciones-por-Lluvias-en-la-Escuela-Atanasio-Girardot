<?php
// auth/actualizar_hashes.php
// ✅ Script para actualizar todas las contraseñas a formato compatible con password_hash()
require_once 'funciones.php';

try {
    $pdo = getConexion();
    
    // Obtener todos los usuarios con contrasena_temporal NO vacía
    $stmt = $pdo->prepare("
        SELECT id, correo, contrasena_temporal, contrasena
        FROM usuarios 
        WHERE contrasena_temporal IS NOT NULL 
          AND contrasena_temporal != ''
        ORDER BY id
    ");
    $stmt->execute();
    $usuarios = $stmt->fetchAll();

    echo "🔍 Encontrados " . count($usuarios) . " usuarios con contrasena_temporal.\n\n";

    $actualizados = 0;
    $fallidos = 0;

    foreach ($usuarios as $u) {
        $id = $u['id'];
        $correo = $u['correo'];
        $temporal = $u['contrasena_temporal'];
        $hash_actual = $u['contrasena'];

        // Si ya tiene un hash válido de PHP ($2y$...), saltar
        if (preg_match('/^\$2y\$[0-9]{2}\$/', $hash_actual)) {
            echo "✅ [$correo] Ya tiene hash válido. Salteado.\n";
            continue;
        }

        // Generar nuevo hash con PHP (compatible con password_verify)
        $nuevo_hash = password_hash($temporal, PASSWORD_BCRYPT, ['cost' => 12]);

        if ($nuevo_hash === false) {
            echo "❌ [$correo] ERROR: No se pudo hashear '$temporal'\n";
            $fallidos++;
            continue;
        }

        // Actualizar en BD
        $upd = $pdo->prepare("
            UPDATE usuarios 
            SET contrasena = :hash 
            WHERE id = :id
        ");
        $upd->execute([
            ':hash' => $nuevo_hash,
            ':id' => $id
        ]);

        echo "✨ [$correo] Actualizado → hash: " . substr($nuevo_hash, 0, 29) . "...\n";
        $actualizados++;
    }

    echo "\n📊 Resumen:\n";
    echo "   ✅ Actualizados: $actualizados\n";
    echo "   ❌ Fallidos: $fallidos\n";
    echo "   📌 Total procesados: " . count($usuarios) . "\n";

    // Verificar integridad
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total, 
               COUNT(*) FILTER (WHERE contrasena LIKE '\$2y\$%') as validas
        FROM usuarios
    ");
    $stmt->execute();
    $res = $stmt->fetch();
    echo "\n🛡️ Integridad de la BD:\n";
    echo "   Contraseñas con formato válido (\$2y\$): {$res['validas']} / {$res['total']}\n";

    if ($res['validas'] < $res['total']) {
        echo "   ⚠️ Algunos usuarios aún no tienen hash válido (pueden ser sin contrasena_temporal).\n";
    } else {
        echo "   ✅ ¡Todas las contraseñas son compatibles con password_verify()!\n";
    }

} catch (Exception $e) {
    die("💥 Error: " . $e->getMessage() . "\n");
}
<?php
session_start();
require_once '../../funciones.php';
require_once '../includes/onesignal_config.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Administrador') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$conexion = getConexion();
$mensaje = '';
$error = '';

// Procesar restauración
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    
    if ($_POST['accion'] === 'restaurar') {
        $eliminado_id = $_POST['eliminado_id'] ?? 0;
        
        try {
            $conexion->beginTransaction();
            
            // Obtener datos del usuario eliminado
            $stmt = $conexion->prepare("SELECT * FROM usuarios_eliminados WHERE id = ?");
            $stmt->execute([$eliminado_id]);
            $eliminado = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$eliminado) {
                throw new Exception('Registro no encontrado en papelera');
            }
            
            // Verificar si el correo ya existe (por si acaso)
            $stmt_check = $conexion->prepare("SELECT COUNT(*) FROM usuarios WHERE correo = ?");
            $stmt_check->execute([$eliminado['correo']]);
            if ($stmt_check->fetchColumn() > 0) {
                // Si el correo ya existe, modificarlo
                $nuevo_correo = $eliminado['correo'] . '.restaurado.' . time();
                $correo_final = $nuevo_correo;
            } else {
                $correo_final = $eliminado['correo'];
            }
            
            // Restaurar usuario principal
            $stmt_restore = $conexion->prepare("
                INSERT INTO usuarios (id, nombre, correo, contrasena, contrasena_temporal, rol, activo, creado_en)
                VALUES (?, ?, ?, ?, ?, ?, true, CURRENT_TIMESTAMP)
                ON CONFLICT (id) DO UPDATE SET
                    nombre = EXCLUDED.nombre,
                    correo = EXCLUDED.correo,
                    contrasena = EXCLUDED.contrasena,
                    contrasena_temporal = EXCLUDED.contrasena_temporal,
                    rol = EXCLUDED.rol,
                    activo = true
            ");
            $stmt_restore->execute([
                $eliminado['usuario_id'],
                $eliminado['nombre'],
                $correo_final,
                $eliminado['contrasena'],
                $eliminado['contrasena_temporal'],
                $eliminado['rol'],
            ]);
            
            // Restaurar según rol
            if ($eliminado['rol'] === 'Estudiante' && $eliminado['grado']) {
                $stmt_est = $conexion->prepare("
                    INSERT INTO estudiantes (usuario_id, grado, seccion)
                    VALUES (?, ?, ?)
                    ON CONFLICT (usuario_id) DO UPDATE SET
                        grado = EXCLUDED.grado,
                        seccion = EXCLUDED.seccion
                ");
                $stmt_est->execute([$eliminado['usuario_id'], $eliminado['grado'], $eliminado['seccion']]);
            }
            
            if ($eliminado['rol'] === 'Docente' && $eliminado['grado']) {
                $stmt_doc = $conexion->prepare("
                    INSERT INTO docentes (usuario_id, grado, seccion)
                    VALUES (?, ?, ?)
                    ON CONFLICT (usuario_id) DO UPDATE SET
                        grado = EXCLUDED.grado,
                        seccion = EXCLUDED.seccion
                ");
                $stmt_doc->execute([
                    $eliminado['usuario_id'], 
                    $eliminado['grado'], 
                    $eliminado['seccion']
                ]);
            }
            
            if ($eliminado['rol'] === 'Representante') {
                // Solo restaurar relaciones con estudiantes (la tabla representantes NO existe)
                if ($eliminado['estudiantes_asignados']) {
                    $estudiantes = json_decode($eliminado['estudiantes_asignados'], true);
                    if (is_array($estudiantes)) {
                        foreach ($estudiantes as $est) {
                            // Verificar que el estudiante existe antes de asignarlo
                            $check_est = $conexion->prepare("SELECT id FROM usuarios WHERE id = ?");
                            $check_est->execute([$est['id']]);
                            if ($check_est->fetch()) {
                                $stmt_rel = $conexion->prepare("
                                    INSERT INTO representantes_estudiantes (representante_id, estudiante_id)
                                    VALUES (?, ?)
                                    ON CONFLICT DO NOTHING
                                ");
                                $stmt_rel->execute([
                                    $eliminado['usuario_id'],
                                    $est['id']
                                ]);
                            }
                        }
                    }
                }
            }
            
            // Marcar como restaurado en papelera
            $stmt_update = $conexion->prepare("
                UPDATE usuarios_eliminados 
                SET restaurado = true, 
                    restaurado_por = ?, 
                    fecha_restauracion = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt_update->execute([$_SESSION['usuario_id'], $eliminado_id]);
            
            // Log de restauración
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $stmt_log = $conexion->prepare("
                INSERT INTO logs_eliminaciones 
                (usuario_eliminado_id, usuario_eliminado_nombre, eliminado_por, ip_address, user_agent, accion)
                VALUES (?, ?, ?, ?, ?, 'RESTAURAR')
            ");
            $stmt_log->execute([
                $eliminado['usuario_id'], 
                $eliminado['nombre'], 
                $_SESSION['usuario_id'], 
                $ip_address, 
                $user_agent
            ]);
            
            $conexion->commit();
            $mensaje = "✅ Usuario restaurado exitosamente" . ($correo_final !== $eliminado['correo'] ? " (correo modificado: $correo_final)" : "");
            
        } catch (Exception $e) {
            $conexion->rollBack();
            $error = "❌ Error al restaurar: " . $e->getMessage();
        }
    }
    
    if ($_POST['accion'] === 'eliminar_permanente') {
        $eliminado_id = $_POST['eliminado_id'] ?? 0;
        
        try {
            $stmt = $conexion->prepare("DELETE FROM usuarios_eliminados WHERE id = ?");
            $stmt->execute([$eliminado_id]);
            $mensaje = "✅ Registro eliminado permanentemente de la papelera";
        } catch (Exception $e) {
            $error = "❌ Error: " . $e->getMessage();
        }
    }
}

// Obtener usuarios en papelera
$eliminados = $conexion->query("
    SELECT e.*, u.nombre as eliminado_por_nombre, 
           (SELECT COUNT(*) FROM usuarios_eliminados WHERE restaurado = false) as total_pendientes
    FROM usuarios_eliminados e
    LEFT JOIN usuarios u ON e.eliminado_por = u.id
    WHERE e.restaurado = false
    ORDER BY e.fecha_eliminacion DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Papelera de Usuarios - SIEDUCRES</title>
    <?php require_once '../includes/favicon.php'; ?>
    <?php require_once '../includes/header_onesignal.php'; ?> 
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-cyan: #4BC4E7;
            --primary-pink: #EF5E8E;
            --primary-lime: #c3d54dff;
            --primary-purple: #9B8AFB;
            --white: #FFFFFF;
            --canvas-bg: #F5F5F5;
            --text-main: #000000;
            --text-muted: #666666;
            --border-dark: #000000;
            --border-light: #CCCCCC;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--canvas-bg);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header responsive */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 16px;
            height: 60px;
            background-color: var(--white);
            border-bottom: 1px solid var(--border-light);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            position: relative;
            z-index: 100;
        }

        @media (min-width: 768px) {
            .header {
                padding: 0 24px;
            }
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .logo {
            height: 32px;
            width: auto;
        }

        @media (min-width: 768px) {
            .logo {
                height: 40px;
            }
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .icon-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: #F0F0F0;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.2s;
            flex-shrink: 0;
        }

        .icon-btn:hover {
            background-color: #E0E0E0;
        }

        .icon-btn img {
            width: 20px;
            height: 20px;
            object-fit: contain;
            display: block;
        }

        .icon-btn svg {
            width: 20px;
            height: 20px;
            display: block;
        }

        .menu-dropdown {
            position: absolute;
            top: 60px;
            right: 16px;
            background: white;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            display: none;
            min-width: 180px;
            z-index: 1000;
        }

        @media (min-width: 768px) {
            .menu-dropdown {
                right: 24px;
            }
        }

        .menu-item {
            padding: 12px 16px;
            font-size: 14px;
            color: var(--text-main);
            text-decoration: none;
            display: block;
        }

        .menu-item:hover {
            background-color: #F8F8F8;
        }

        /* Banner responsive */
        .banner {
            position: relative;
            height: 80px;
            overflow: hidden;
        }

        @media (min-width: 768px) {
            .banner {
                height: 100px;
            }
        }

        .banner-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: top;
        }

        .banner-content {
            text-align: center;
            position: relative;
            z-index: 2;
            max-width: 800px;
            padding: 16px;
            margin: 0 auto;
        }

        .banner-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 8px;
        }

        @media (min-width: 768px) {
            .banner-title {
                font-size: 36px;
            }
        }

        /* Contenido principal */
        .main-content {
            flex: 1;
            padding: 20px 16px;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        @media (min-width: 768px) {
            .main-content {
                padding: 40px 20px;
            }
        }

        /* Stats bar responsive */
        .stats-bar {
            background: var(--white);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            border-left: 4px solid var(--primary-pink);
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        @media (min-width: 768px) {
            .stats-bar {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 20px;
            }
        }

        .stats-number {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-pink);
        }

        @media (min-width: 768px) {
            .stats-number {
                font-size: 32px;
            }
        }

        .btn-primary {
            background-color: var(--primary-cyan);
            color: var(--text-main);
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.2s;
            display: block;
            text-align: center;
            width: 100%;
        }

        @media (min-width: 768px) {
            .btn-primary {
                display: inline-block;
                width: auto;
                padding: 10px 20px;
            }
        }

        .btn-primary:hover {
            transform: translateY(-2px);
        }

        /* Tabla responsive */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -16px;
            padding: 0 16px;
        }

        table {
            width: 100%;
            min-width: 800px;
            border-collapse: collapse;
            background: var(--white);
            border: 1px solid var(--border-dark);
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
        }

        th {
            background-color: var(--primary-purple);
            font-weight: 700;
            font-size: 13px;
            padding: 12px;
            text-align: center;
            border: 1px solid var(--border-dark);
            color: white;
        }

        td {
            padding: 12px;
            border: 1px solid var(--border-dark);
            text-align: center;
            font-size: 13px;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        /* Badges de roles */
        .badge-rol {
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 11px;
            white-space: nowrap;
        }

        .badge-estudiante {
            background-color: var(--primary-cyan);
            color: white;
        }

        .badge-docente {
            background-color: var(--primary-purple);
            color: white;
        }

        .badge-representante {
            background-color: var(--primary-pink);
            color: white;
        }

        .badge-admin {
            background-color: #333;
            color: white;
        }

        /* Botones de acción */
        .acciones-container {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        @media (min-width: 768px) {
            .acciones-container {
                flex-direction: row;
                gap: 5px;
            }
        }

        .btn-restore {
            background-color: var(--primary-lime);
            color: #333;
            padding: 8px 12px;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            font-size: 12px;
            width: 100%;
        }

        .btn-delete-perm {
            background-color: var(--primary-pink);
            color: white;
            padding: 8px 12px;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            font-size: 12px;
            width: 100%;
        }

        @media (min-width: 768px) {
            .btn-restore, .btn-delete-perm {
                width: auto;
                padding: 6px 12px;
            }
        }

        /* Mensajes */
        .mensaje-exito,
        .mensaje-error {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .mensaje-exito {
            background-color: var(--primary-lime);
            color: #333;
        }

        .mensaje-error {
            background-color: #f8d7da;
            color: #721c24;
        }

        /* Modal responsive */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            padding: 16px;
        }

        .modal-content {
            background: white;
            padding: 24px;
            border-radius: 16px;
            max-width: 400px;
            width: 100%;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .modal-content h3 {
            color: var(--primary-pink);
            margin-bottom: 15px;
            font-size: 20px;
        }

        .modal-buttons {
            display: flex;
            flex-direction: column;
            gap: 8px;
            justify-content: center;
            margin-top: 20px;
        }

        @media (min-width: 768px) {
            .modal-buttons {
                flex-direction: row;
                gap: 10px;
            }
        }

        .modal-btn-confirm {
            background-color: var(--primary-pink);
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: transform 0.2s;
            width: 100%;
        }

        .modal-btn-cancel {
            background-color: #ccc;
            color: #333;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: transform 0.2s;
            width: 100%;
        }

        @media (min-width: 768px) {
            .modal-btn-confirm,
            .modal-btn-cancel {
                width: auto;
            }
        }

        .modal-btn-confirm:hover,
        .modal-btn-cancel:hover {
            transform: translateY(-2px);
        }

        /* Footer */
        .footer {
            height: 50px;
            background-color: var(--white);
            border-top: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 16px;
            font-size: 12px;
            color: var(--text-muted);
            position: sticky;
            bottom: 0;
        }

        @media (min-width: 768px) {
            .footer {
                padding: 0 24px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header_comun.php'; ?>
    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner" class="banner-image" onerror="this.style.display='none'">
    </div>

    <div class="banner-content">
        <h1 class="banner-title">🗑️ Papelera de Reciclaje</h1>
    </div>

    <main class="main-content">
        <?php if ($mensaje): ?>
            <div class="mensaje-exito"><?php echo $mensaje; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mensaje-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="stats-bar">
            <div>
                <span style="font-size: 16px;">Usuarios en papelera:</span>
                <span class="stats-number"><?php echo count($eliminados); ?></span>
            </div>
            <div>
                <a href="gestion_usuarios.php" class="btn-primary">← Volver a Usuarios</a>
            </div>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Correo</th>
                        <th>Rol</th>
                        <th>Grado/Sec</th>
                        <th>Eliminado por</th>
                        <th>Fecha</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($eliminados)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px;">
                                La papelera está vacía
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($eliminados as $e): ?>
                        <tr>
                            <td><?php echo $e['usuario_id']; ?></td>
                            <td style="text-align: left;"><?php echo htmlspecialchars($e['nombre']); ?></td>
                            <td style="text-align: left;"><?php echo htmlspecialchars($e['correo']); ?></td>
                            <td>
                                <span class="badge-rol 
                                    <?php 
                                        if ($e['rol'] === 'Estudiante') echo 'badge-estudiante';
                                        elseif ($e['rol'] === 'Docente') echo 'badge-docente';
                                        elseif ($e['rol'] === 'Representante') echo 'badge-representante';
                                        else echo 'badge-admin';
                                    ?>">
                                    <?php echo $e['rol']; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($e['grado']): ?>
                                    <?php echo $e['grado'] . ' - ' . $e['seccion']; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?php echo $e['eliminado_por_nombre'] ?? 'Desconocido'; ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($e['fecha_eliminacion'])); ?></td>
                            <td>
                                <div class="acciones-container">
                                    <button class="btn-restore" 
                                            onclick="confirmarRestaurar(<?php echo $e['id']; ?>, '<?php echo addslashes(htmlspecialchars($e['nombre'])); ?>')">
                                        ↪️ Restaurar
                                    </button>
                                    
                                    <button class="btn-delete-perm" 
                                            onclick="confirmarEliminarPerm(<?php echo $e['id']; ?>, '<?php echo addslashes(htmlspecialchars($e['nombre'])); ?>')">
                                        🗑️ Eliminar
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- Modal de confirmación para RESTAURAR -->
    <div class="modal" id="modalRestaurar">
        <div class="modal-content">
            <h3>↩️ Confirmar Restauración</h3>
            <p id="modal-mensaje-restaurar">¿Estás seguro de que deseas restaurar este usuario?</p>
            <p style="font-size: 12px; color: #999; margin-top: 10px; margin-bottom: 15px;">
                El usuario volverá a estar activo en el sistema.
            </p>
            
            <form method="POST" id="formRestaurar">
                <input type="hidden" name="accion" value="restaurar">
                <input type="hidden" name="eliminado_id" id="restaurar_id">
                
                <div class="modal-buttons">
                    <button type="button" class="modal-btn-cancel" onclick="cerrarModalRestaurar()">Cancelar</button>
                    <button type="submit" class="modal-btn-confirm">Sí, restaurar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de confirmación para ELIMINAR PERMANENTE -->
    <div class="modal" id="modalEliminarPerm">
        <div class="modal-content">
            <h3>🗑️ Confirmar Eliminación Permanente</h3>
            <p id="modal-mensaje-eliminar">¿Estás seguro de que deseas eliminar permanentemente este usuario?</p>
            <p style="font-size: 12px; color: #999; margin-top: 10px; margin-bottom: 15px;">
                Esta acción no se puede deshacer.
            </p>
            
            <form method="POST" id="formEliminarPerm">
                <input type="hidden" name="accion" value="eliminar_permanente">
                <input type="hidden" name="eliminado_id" id="eliminar_perm_id">
                
                <div class="modal-buttons">
                    <button type="button" class="modal-btn-cancel" onclick="cerrarModalEliminarPerm()">Cancelar</button>
                    <button type="submit" class="modal-btn-confirm">Sí, eliminar</button>
                </div>
            </form>
        </div>
    </div>

    <footer class="footer">
        <span>v2.0.0</span>
        <span>Soporte Técnico</span>
    </footer>

    <script>
        // Toggle menú hamburguesa
        document.getElementById('menu-toggle').addEventListener('click', function(e) {
            e.stopPropagation();
            const dropdown = document.getElementById('dropdown');
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        });

        document.addEventListener('click', function(e) {
            const dropdown = document.getElementById('dropdown');
            const toggle = document.getElementById('menu-toggle');
            if (!toggle.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });

        // Modal de confirmación para RESTAURAR
        function confirmarRestaurar(id, nombre) {
            document.getElementById('restaurar_id').value = id;
            document.getElementById('modal-mensaje-restaurar').innerHTML = `¿Estás seguro de que deseas restaurar al usuario <strong>"${nombre}"</strong>?`;
            document.getElementById('modalRestaurar').style.display = 'flex';
        }

        function cerrarModalRestaurar() {
            document.getElementById('modalRestaurar').style.display = 'none';
        }

        // Modal de confirmación para ELIMINAR PERMANENTE
        function confirmarEliminarPerm(id, nombre) {
            document.getElementById('eliminar_perm_id').value = id;
            document.getElementById('modal-mensaje-eliminar').innerHTML = `¿Estás seguro de que deseas eliminar PERMANENTEMENTE al usuario <strong>"${nombre}"</strong>?`;
            document.getElementById('modalEliminarPerm').style.display = 'flex';
        }

        function cerrarModalEliminarPerm() {
            document.getElementById('modalEliminarPerm').style.display = 'none';
        }

        // Cerrar modales si se hace clic fuera
        window.onclick = function(event) {
            const modalRestaurar = document.getElementById('modalRestaurar');
            const modalEliminar = document.getElementById('modalEliminarPerm');
            
            if (event.target === modalRestaurar) {
                modalRestaurar.style.display = 'none';
            }
            if (event.target === modalEliminar) {
                modalEliminar.style.display = 'none';
            }
        }
    </script>
</body>
</html>
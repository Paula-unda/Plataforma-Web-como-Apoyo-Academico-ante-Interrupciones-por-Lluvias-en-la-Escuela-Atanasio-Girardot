<?php
session_start();
require_once '../../funciones.php';

if (!sesionActiva() || $_SESSION['usuario_rol'] !== 'Estudiante') {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$estudiante_id = $_SESSION['usuario_id'];

// ✅ INICIALIZAR VARIABLES
$calificaciones = [];
$pendientes = [];
$total_calificadas = 0;
$total_pendientes = 0;
$promedio = 0;

try {
    $conexion = getConexion();
    
    // ✅ QUERY 1: Calificadas (SIN asignatura - esa columna no existe)
    $query_calificadas = "
        SELECT 
            e.id,
            e.estudiante_id,
            e.calificacion,
            e.observaciones,
            e.fecha_entrega,
            a.titulo as actividad_titulo
        FROM entregas_estudiantes e
        INNER JOIN actividades a ON e.actividad_id = a.id
        WHERE e.estudiante_id = " . (int)$estudiante_id . "
        AND e.calificacion IS NOT NULL
        ORDER BY e.fecha_entrega DESC
    ";
    
    // ✅ QUERY 2: Pendientes (SIN asignatura)
    $query_pendientes = "
        SELECT 
            a.id,
            a.titulo as actividad_titulo,
            a.fecha_entrega,
            NULL as calificacion,
            NULL as observaciones
        FROM actividades a
        WHERE a.activo = true
        AND a.id NOT IN (
            SELECT actividad_id FROM entregas_estudiantes 
            WHERE estudiante_id = " . (int)$estudiante_id . "
        )
        ORDER BY a.fecha_entrega DESC
    ";
    
    // Ejecutar queries
    $stmt = $conexion->query($query_calificadas);
    $calificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $conexion->query($query_pendientes);
    $pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("=== CALIFICACIONES ===");
    error_log("Calificadas: " . count($calificaciones));
    error_log("Pendientes: " . count($pendientes));
    
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
}

// Estadísticas
$total_calificadas = count($calificaciones);
$total_pendientes = count($pendientes);

if ($total_calificadas > 0) {
    $suma = array_sum(array_column($calificaciones, 'calificacion'));
    $promedio = round($suma / $total_calificadas, 2);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calificaciones - SIEDUCRES</title>
    <?php require_once '../includes/favicon.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --text-dark: #333333; --text-muted: #666666; --border: #E0E0E0;
            --surface: #FFFFFF; --background: #F5F5F5;
            --header-green: #4BC4E7; --success: #4BC4E7; --warning: #ffc107;
            --primary: #4a90e2;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--background); color: var(--text-dark); min-height: 100vh; display: flex; flex-direction: column; }
        
        .header { display: flex; justify-content: space-between; align-items: center; padding: 0 24px; height: 60px; background-color: var(--surface); border-bottom: 1px solid var(--border); }
        .logo { height: 40px; }
        .header-right { display: flex; align-items: center; gap: 16px; }
        .icon-btn { width: 36px; height: 36px; border-radius: 50%; background-color: #F0F0F0; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .icon-btn img { width: 20px; height: 20px; object-fit: contain; }
        .menu-dropdown { position: absolute; top: 60px; right: 24px; background: white; border: 1px solid var(--border); border-radius: 8px; display: none; }
        .menu-item { padding: 10px 16px; font-size: 14px; color: var(--text-dark); text-decoration: none; display: block; }
        
        .banner { position: relative; height: 100px; overflow: hidden; }
        .banner-image { width: 100%; height: 100%; object-fit: cover; }
        .banner-content { text-align: center; position: relative; z-index: 2; max-width: 800px; padding: 20px; margin: 0 auto; }
        .banner-title { font-size: 36px; font-weight: 700; margin-bottom: 8px; }
        .banner-subtitle { font-size: 18px; color: var(--text-muted); }
        
        .main-content { flex: 1; display: flex; flex-direction: column; align-items: center; padding: 40px 20px; }
        .content-container { width: 100%; max-width: 1200px; }
        
        .btn-regresar { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: white; border: 1px solid var(--border); border-radius: 8px; text-decoration: none; color: var(--text-dark); margin-bottom: 20px; }
        
        .resumen-card { background: var(--surface); border-radius: 16px; padding: 32px; margin-bottom: 32px; box-shadow: 0 6px 16px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; }
        .estadistica { text-align: center; }
        .estadistica-numero { font-size: 36px; font-weight: 700; color: var(--header-green); }
        .estadistica-label { font-size: 13px; color: var(--text-muted); }
        
        .btn-export { background: var(--primary); color: white; padding: 12px 24px; border-radius: 8px; border: none; cursor: pointer; font-weight: 600; }
        
        .tabs { display: flex; gap: 8px; margin-bottom: 24px; }
        .tab { padding: 12px 24px; background: white; border: 1px solid var(--border); border-radius: 8px; cursor: pointer; font-weight: 600; }
        .tab.active { background: var(--header-green); color: white; border-color: var(--header-green); }
        
        .tabla-card { background: var(--surface); border-radius: 16px; padding: 32px; box-shadow: 0 6px 16px rgba(0,0,0,0.05); overflow-x: auto; }
        .calificaciones-table { width: 100%; border-collapse: collapse; }
        .calificaciones-table thead { background: var(--header-green); color: white; }
        .calificaciones-table th, .calificaciones-table td { padding: 16px; text-align: left; border-bottom: 1px solid var(--border); }
        .calificaciones-table tbody tr:hover { background: #f8f9fa; cursor: pointer; }
        
        .badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-excelente { background: rgba(40,167,69,0.1); color: var(--success); }
        .badge-bueno { background: rgba(74,144,226,0.1); color: var(--primary); }
        .badge-pendiente { background: rgba(255,193,7,0.1); color: var(--warning); }
        
        .no-datos { text-align: center; padding: 60px 20px; }
        .no-datos-icon { font-size: 64px; margin-bottom: 20px; opacity: 0.3; }
        
        .footer { height: 50px; background: var(--surface); border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; padding: 0 24px; font-size: 13px; }
        
        /* Modal */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; border-radius: 16px; padding: 32px; max-width: 600px; width: 90%; position: relative; }
        .modal-close { position: absolute; top: 16px; right: 16px; background: none; border: none; font-size: 24px; cursor: pointer; }
        .modal-info { margin-bottom: 16px; }
        .modal-info-label { font-size: 14px; color: var(--text-muted); }
        .modal-info-value { font-size: 16px; font-weight: 600; }
        .modal-actions { display: flex; gap: 12px; margin-top: 24px; }
        .btn-modal { padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; }
        .btn-modal-primary { background: var(--primary); color: white; }
        .btn-modal-secondary { background: #f0f0f0; }
        
        @media (max-width: 768px) {
            .resumen-card { flex-direction: column; text-align: center; }
            .tabs { flex-direction: column; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-left">
            <img src="../../../assets/logo.svg" alt="SIEDUCRES" class="logo">
        </div>
        <div class="header-right">
            <div class="icon-btn">
                <img src="../../../assets/icon-bell.svg" alt="Notificaciones">
            </div>
            <div class="icon-btn">
                <img src="../../../assets/icon-user.svg" alt="Perfil">
            </div>
            <div class="icon-btn" id="menu-toggle">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="#333333">
                    <path d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/>
                </svg>
            </div>
            <div class="menu-dropdown" id="dropdown">
                <a href="../../logout.php" class="menu-item">Cerrar sesión</a>
            </div>
        </div>
    </header>

    <div class="banner">
        <img src="../../../assets/banner-top.svg" alt="Banner" class="banner-image">
    </div>
    
    <div class="banner-content">
        <h1 class="banner-title">Calificaciones</h1>
        <p class="banner-subtitle">Revisa tus notas y retroalimentación</p>
    </div>

    <main class="main-content">
        <div class="content-container">
            <a href="index.php" class="btn-regresar">← Volver al Panel</a>

            <div class="resumen-card">
                <div>
                    <h2>Tu Desempeño</h2>
                    <p style="color: var(--text-muted);">Resumen de calificaciones</p>
                </div>
                <div style="display: flex; gap: 32px;">
                    <div class="estadistica">
                        <div class="estadistica-numero"><?php echo $total_calificadas; ?></div>
                        <div class="estadistica-label">Calificadas</div>
                    </div>
                    <div class="estadistica">
                        <div class="estadistica-numero"><?php echo $total_pendientes; ?></div>
                        <div class="estadistica-label">Pendientes</div>
                    </div>
                    <div class="estadistica">
                        <div class="estadistica-numero"><?php echo number_format($promedio, 1); ?>/20</div>
                        <div class="estadistica-label">Promedio</div>
                    </div>
                </div>
                <button class="btn-export" onclick="exportarPDF()"> Descargar PDF</button>
            </div>

            <div class="tabs">
                <div class="tab active" onclick="mostrarTab('calificadas')"> Calificadas (<?php echo $total_calificadas; ?>)</div>
                <div class="tab" onclick="mostrarTab('pendientes')"> Pendientes (<?php echo $total_pendientes; ?>)</div>
            </div>

            <div id="tab-calificadas" class="tabla-card">
                <h3 style="margin-bottom: 24px;">Actividades Calificadas</h3>
                <?php if (count($calificaciones) > 0): ?>
                    <table class="calificaciones-table">
                        <thead>
                            <tr>
                                <th>Actividad</th>
                                <th>Asignatura</th>
                                <th>Fecha</th>
                                <th>Nota</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($calificaciones as $cal): ?>
                                <tr onclick="verDetalle(<?php echo htmlspecialchars(json_encode($cal)); ?>)">
                                    <td><strong><?php echo htmlspecialchars($cal['actividad_titulo']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($cal['asignatura'] ?? 'General'); ?></td>
                                    <td><?php echo $cal['fecha_entrega'] ? date('d/m/Y', strtotime($cal['fecha_entrega'])) : 'Sin fecha'; ?></td>
                                    <td><span class="badge badge-bueno"><?php echo number_format($cal['calificacion'], 2); ?>/20</span></td>
                                    <td><button class="btn-modal btn-modal-secondary" style="padding: 6px 12px;" onclick="event.stopPropagation(); verDetalle(<?php echo htmlspecialchars(json_encode($cal)); ?>)">Ver</button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-datos">
                        <h3 style="color: var(--text-muted);">Actualmente no hay calificaciones disponibles</h3>
                        <p style="color: var(--text-muted);">Vuelva a consultar más tarde.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div id="tab-pendientes" class="tabla-card" style="display: none;">
                <h3 style="margin-bottom: 24px;">Actividades Pendientes</h3>
                <?php if (count($pendientes) > 0): ?>
                    <table class="calificaciones-table">
                        <thead>
                            <tr>
                                <th>Actividad</th>
                                <th>Asignatura</th>
                                <th>Fecha Límite</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendientes as $pend): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($pend['actividad_titulo']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($pend['asignatura'] ?? 'General'); ?></td>
                                    <td><?php echo $pend['fecha_entrega'] ? date('d/m/Y', strtotime($pend['fecha_entrega'])) : 'Sin fecha'; ?></td>
                                    <td><span class="badge badge-pendiente"> Pendiente</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-datos">
                        <h3 style="color: var(--text-muted);">¡Todo al día!</h3>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Modal -->
    <div id="modalDetalle" class="modal">
        <div class="modal-content">
            <button class="modal-close" onclick="cerrarModal()">&times;</button>
            <h2 id="modalTitulo" style="margin-bottom: 24px;">Detalle</h2>
            <div class="modal-info">
                <div class="modal-info-label">Nota</div>
                <div class="modal-info-value" id="modalNota">-</div>
            </div>
            <div class="modal-info">
                <div class="modal-info-label">Fecha</div>
                <div class="modal-info-value" id="modalFecha">-</div>
            </div>
            <div class="modal-info">
                <div class="modal-info-label">Retroalimentación</div>
                <div class="modal-info-value" id="modalRetro" style="font-weight: 400;">-</div>
            </div>
            <div class="modal-actions">
                <button class="btn-modal btn-modal-primary" onclick="exportarPDF()"> PDF</button>
                <button class="btn-modal btn-modal-secondary" onclick="cerrarModal()">Cerrar</button>
            </div>
        </div>
    </div>

    <footer class="footer">
        <span>SIEDUCRES v1.0</span>
        <span>Soporte Técnico</span>
    </footer>

    <script>
        document.getElementById('menu-toggle').addEventListener('click', function() {
            const d = document.getElementById('dropdown');
            d.style.display = d.style.display === 'block' ? 'none' : 'block';
        });

        function mostrarTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tabla-card').forEach(c => c.style.display = 'none');
            event.target.classList.add('active');
            document.getElementById('tab-' + tab).style.display = 'block';
        }

        function verDetalle(cal) {
            document.getElementById('modalTitulo').textContent = cal.actividad_titulo;
            document.getElementById('modalNota').textContent = cal.calificacion + '/20';
            document.getElementById('modalFecha').textContent = cal.fecha_entrega ? new Date(cal.fecha_entrega).toLocaleDateString('es-ES') : 'Sin fecha';
            document.getElementById('modalRetro').textContent = cal.observaciones || 'Sin retroalimentación';
            document.getElementById('modalDetalle').classList.add('active');
        }

        function cerrarModal() {
            document.getElementById('modalDetalle').classList.remove('active');
        }

        // Exportar PDF
        function exportarPDF() {
            // Abrir el generador de PDF en nueva pestaña
            window.open('generar_pdf_calificaciones.php', '_blank');
        }

        document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarModal(); });
    </script>
</body>
</html>
<?php
session_start();
require_once '../../funciones.php';

// ✅ Ambos roles pueden crear temas
if (!sesionActiva() || !in_array($_SESSION['usuario_rol'], ['Estudiante', 'Docente', 'Administrador'])) {
    header('Location: ../../login.php?error=Acceso+no+autorizado.');
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$usuario_rol = $_SESSION['usuario_rol'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo = trim($_POST['titulo'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $grado_tema = null;
    $seccion_tema = null;
    
    if (empty($titulo) || empty($descripcion)) {
        header('Location: crear_tema.php?error=Todos+los+campos+son+obligatorios');
        exit();
    }
    
    try {
        $conexion = getConexion();
        
        // 🔥 OBTENER GRADO Y SECCIÓN SEGÚN EL ROL
        if ($usuario_rol === 'Estudiante') {
            // Estudiante: obtener de tabla estudiantes
            $stmt = $conexion->prepare("SELECT grado, seccion FROM estudiantes WHERE usuario_id = ?");
            $stmt->execute([$usuario_id]);
            $datos = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($datos) {
                $grado_tema = $datos['grado'];
                $seccion_tema = $datos['seccion'];
            }
        } elseif ($usuario_rol === 'Docente') {
            // Docente: toma del formulario (por ahora, mientras no tengan asignación)
            $grado_tema = $_POST['grado'] ?? null;
            $seccion_tema = $_POST['seccion'] ?? null;
            
            if (empty($grado_tema) || empty($seccion_tema)) {
                header('Location: crear_tema.php?error=Debes+seleccionar+grado+y+sección');
                exit();
            }
        }
        
        // Insertar tema CON grado y sección
        $query = "INSERT INTO foros_temas (titulo, descripcion, autor_id, grado, seccion) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conexion->prepare($query);
        $stmt->execute([$titulo, $descripcion, $usuario_id, $grado_tema, $seccion_tema]);
        
        header('Location: foro.php?exito=Tema+creado+correctamente');
        exit();
        
    } catch (Exception $e) {
        error_log("Error crear tema: " . $e->getMessage());
        header('Location: crear_tema.php?error=Error+al+crear+el+tema');
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nuevo Tema - Foro</title>
    <?php require_once '../includes/favicon.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #F8F9FA; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); width: 100%; max-width: 600px; }
        h1 { color: #000; margin-bottom: 24px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 8px; color: #333; }
        input, textarea, select { width: 100%; padding: 12px; border: 1px solid #E0E0E0; border-radius: 8px; font-family: 'Inter', sans-serif; }
        textarea { min-height: 150px; resize: vertical; }
        .btn { background: #00CED1; color: #000; padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; width: 100%; }
        .btn:hover { opacity: 0.9; }
        .btn-cancel { background: #E0E0E0; margin-top: 12px; display: block; text-align: center; text-decoration: none; color: #333; }
        .error-message { color: #dc3545; margin-bottom: 20px; padding: 10px; background: #f8d7da; border-radius: 8px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>📝 Crear Nuevo Tema</h1>
        
        <?php if (isset($_GET['error'])): ?>
            <div class="error-message">❌ <?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Título</label>
                <input type="text" name="titulo" required placeholder="Ej: Dudas sobre la Tarea 1">
            </div>
            
            <div class="form-group">
                <label>Descripción</label>
                <textarea name="descripcion" required placeholder="Describe tu tema o pregunta..."></textarea>
            </div>
            
            <?php if ($_SESSION['usuario_rol'] === 'Docente'): ?>
            <!-- Solo los docentes ven estos campos -->
            <div class="form-group">
                <label>Grado *</label>
                <select name="grado" required>
                    <option value="">Seleccionar grado</option>
                    <option value="1ro">1ro</option>
                    <option value="2do">2do</option>
                    <option value="3ero">3ero</option>
                    <option value="4to">4to</option>
                    <option value="5to">5to</option>
                    <option value="6to">6to</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Sección *</label>
                <select name="seccion" required>
                    <option value="">Seleccionar sección</option>
                    <option value="A">A</option>
                    <option value="B">B</option>
                    <option value="C">C</option>
                    <option value="D">D</option>
                    <option value="U">Única</option>
                </select>
            </div>
            <?php endif; ?>
            
            <button type="submit" class="btn">📤 Publicar Tema</button>
            <a href="foro.php" class="btn-cancel btn">Cancelar</a>
        </form>
    </div>
</body>
</html>
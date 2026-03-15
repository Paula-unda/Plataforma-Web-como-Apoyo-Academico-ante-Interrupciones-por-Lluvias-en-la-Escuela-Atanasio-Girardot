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
    session_regenerate_id(true);
}

/**
 * Verifica que la sesión esté activa
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
                PDO::ATTR_PERSISTENT => false,
            ]);
        } catch (PDOException $e) {
            error_log("Fallo conexión BD: " . $e->getMessage());
            throw new Exception("Error interno: servicio no disponible.");
        }
    }
    return $pdo;
}

// En funciones.php
function formatearFecha($fecha_db) {
    // Si la fecha ya tiene zona horaria incluida (como en tu caso), la usa directamente
    $timestamp = strtotime($fecha_db);
    if ($timestamp === false) {
        return $fecha_db;
    }
    return date('d/m/Y H:i:s', $timestamp);
}
/**
 * Obtiene los contenidos educativos
 */
function obtenerContenidos($estudiante_id = null) {
    $conexion = getConexion();
    
    try {
        if (!$estudiante_id) {
            $query = "
                SELECT c.*, u.nombre as docente_nombre, 'Visible' as estado
                FROM contenidos c
                LEFT JOIN usuarios u ON c.docente_id = u.id
                WHERE c.activo = true
                ORDER BY c.fecha_publicacion DESC
            ";
            $stmt = $conexion->query($query);
            return $stmt->fetchAll();
        }

        $query_estudiante = "SELECT TRIM(grado) as grado, TRIM(seccion) as seccion FROM estudiantes WHERE usuario_id = :estudiante_id";
        $stmt_estudiante = $conexion->prepare($query_estudiante);
        $stmt_estudiante->execute(['estudiante_id' => $estudiante_id]);
        $estudiante = $stmt_estudiante->fetch();
        
        if (!$estudiante || empty($estudiante['grado'])) {
            $query = "
                SELECT c.*, u.nombre as docente_nombre, 'Visible' as estado
                FROM contenidos c
                LEFT JOIN usuarios u ON c.docente_id = u.id
                WHERE c.activo = true
                ORDER BY c.fecha_publicacion DESC
            ";
            $stmt = $conexion->query($query);
            return $stmt->fetchAll();
        }

        $query = "
            SELECT c.*, u.nombre as docente_nombre, 'Visible' as estado
            FROM contenidos c
            LEFT JOIN usuarios u ON c.docente_id = u.id
            WHERE c.activo = true 
                AND (
                    (c.grado IS NULL AND c.seccion IS NULL)
                    OR 
                    (TRIM(c.grado) = :grado AND (c.seccion IS NULL OR TRIM(c.seccion) = :seccion))
                    OR 
                    (TRIM(c.grado) = :grado AND TRIM(c.seccion) = :seccion)
                )
            ORDER BY c.fecha_publicacion DESC
        ";
        $stmt = $conexion->prepare($query);
        $stmt->execute([
            'grado' => trim($estudiante['grado']),
            'seccion' => trim($estudiante['seccion'] ?? '')
        ]);
        
        return $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Error al obtener contenidos: " . $e->getMessage());
        return array();
    }
}

function obtenerContenidoPorId($content_id) {
    $conexion = getConexion();
    
    try {
        $query = "
            SELECT DISTINCT c.*, u.nombre as docente_nombre
            FROM contenidos c
            LEFT JOIN usuarios u ON c.docente_id = u.id
            WHERE c.id = :id AND c.activo = true
        ";
        $stmt = $conexion->prepare($query);
        $stmt->execute(['id' => $content_id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error al obtener contenido: " . $e->getMessage());
        return false;
    }
}
/**
 * Obtiene el progreso de un estudiante en un contenido específico
 */
function obtenerProgresoContenido($estudiante_id, $contenido_id) {
    $conexion = getConexion();
    
    try {
        // ✅ TABLA CORRECTA: progreso_contenido (singular)
        // ✅ COLUMNA CORRECTA: porcentaje_visto
        $query = "
            SELECT porcentaje_visto, ultima_visualizacion 
            FROM progreso_contenido 
            WHERE estudiante_id = $1 AND contenido_id = $2
        ";
        $stmt = $conexion->prepare($query);
        $stmt->execute([$estudiante_id, $contenido_id]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($resultado) {
            // ✅ Guardar la fecha en sesión
            $_SESSION['progreso_fecha_' . $contenido_id] = $resultado['ultima_visualizacion'];
            error_log("✅ Progreso encontrado: " . $resultado['porcentaje_visto'] . "%");
            return floatval($resultado['porcentaje_visto']);
        }
        
        error_log("❌ Sin progreso para contenido ID: " . $contenido_id);
        return 0.0;
    } catch (PDOException $e) {
        error_log("Error al obtener progreso: " . $e->getMessage());
        return 0.0;
    }
}
/**
 * Obtiene la fecha de última visualización desde la sesión
 */
function obtenerFechaUltimaVisualizacion($contenido_id) {
    if (isset($_SESSION['progreso_fecha_' . $contenido_id])) {
        return $_SESSION['progreso_fecha_' . $contenido_id];
    }
    return null;
}

/**
 * Actualiza el progreso de visualización de un contenido
 */
function actualizarProgresoContenido($estudiante_id, $contenido_id, $porcentaje) {
    $conexion = getConexion();
    
    try {
        $query_check = "
            SELECT id FROM progreso_contenido 
            WHERE estudiante_id = $1 AND contenido_id = $2
        ";
        $stmt_check = $conexion->prepare($query_check);
        $stmt_check->execute([$estudiante_id, $contenido_id]);
        
        if ($stmt_check->fetch()) {
            $query = "
                UPDATE progreso_contenido 
                SET porcentaje_visto = $1, 
                    ultima_visualizacion = CURRENT_TIMESTAMP,
                    completado = CASE WHEN $1 >= 100 THEN true ELSE false END
                WHERE estudiante_id = $2 AND contenido_id = $3
            ";
            $stmt = $conexion->prepare($query);
            $stmt->execute([$porcentaje, $estudiante_id, $contenido_id]);
        } else {
            $query = "
                INSERT INTO progreso_contenido (estudiante_id, contenido_id, porcentaje_visto, completado, ultima_visualizacion)
                VALUES ($1, $2, $3, CASE WHEN $3 >= 100 THEN true ELSE false END, CURRENT_TIMESTAMP)
            ";
            $stmt = $conexion->prepare($query);
            $stmt->execute([$estudiante_id, $contenido_id, $porcentaje]);
        }
        
        $_SESSION['progreso_fecha_' . $contenido_id] = date('Y-m-d H:i:s');
        
        return true;
    } catch (PDOException $e) {
        error_log("Error al actualizar progreso: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtiene todos los contenidos con su progreso para un estudiante
 * ORDENADOS POR FECHA MÁS RECIENTE PRIMERO (VERSIÓN CORREGIDA)
 */
function obtenerContenidosConProgreso($estudiante_id) {
    $conexion = getConexion();
    
    try {
        $query_estudiante = "SELECT grado, seccion FROM estudiantes WHERE usuario_id = :estudiante_id";
        $stmt_estudiante = $conexion->prepare($query_estudiante);
        $stmt_estudiante->execute(['estudiante_id' => $estudiante_id]);
        $estudiante = $stmt_estudiante->fetch();
        
        $grado = trim($estudiante['grado'] ?? '');
        $seccion = trim($estudiante['seccion'] ?? '');
        
        $query = "
            SELECT DISTINCT
                c.id,
                c.titulo,
                c.descripcion,
                c.fecha_publicacion,
                c.archivo_adjunto,
                c.enlace,
                c.asignatura,
                c.grado,
                c.seccion,
                u.nombre as docente_nombre,
                COALESCE(p.porcentaje_visto, 0) as porcentaje_visto,
                COALESCE(p.completado, false) as completado,
                p.ultima_visualizacion
            FROM contenidos c
            LEFT JOIN usuarios u ON c.docente_id = u.id
            LEFT JOIN (
                SELECT DISTINCT contenido_id, estudiante_id, porcentaje_visto, completado, ultima_visualizacion
                FROM progreso_contenido
                WHERE estudiante_id = :estudiante_id AND material_id IS NULL
            ) p ON p.contenido_id = c.id
            WHERE c.activo = true 
                AND (
                    (c.grado IS NULL AND c.seccion IS NULL)
                    OR 
                    (TRIM(c.grado) = :grado AND (c.seccion IS NULL OR TRIM(c.seccion) = :seccion))
                    OR 
                    (TRIM(c.grado) = :grado AND TRIM(c.seccion) = :seccion)
                )
        ";
        
        $stmt = $conexion->prepare($query);
        $stmt->execute([
            'estudiante_id' => $estudiante_id,
            'grado' => $grado,
            'seccion' => $seccion
        ]);
        
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 🔴 ORDENAMIENTO MULTINIVEL: primero por fecha, luego por ID
        usort($resultados, function($a, $b) {
            // Convertir fechas a timestamp para comparar
            $fecha_a = strtotime($a['fecha_publicacion'] ?? '1970-01-01');
            $fecha_b = strtotime($b['fecha_publicacion'] ?? '1970-01-01');
            
            // Si las fechas son iguales, ordenar por ID descendente (más nuevo primero)
            if ($fecha_a == $fecha_b) {
                return $b['id'] - $a['id']; // Mayor ID primero (asumiendo que IDs más grandes son más recientes)
            }
            
            // Ordenar por fecha descendente (más reciente primero)
            return $fecha_b - $fecha_a;
        });
        
        // 📊 LOG PARA VERIFICAR EL ORDEN
        error_log("=== CONTENIDOS ORDENADOS CORRECTAMENTE ===");
        foreach ($resultados as $i => $r) {
            error_log(($i+1) . ". ID: " . $r['id'] . " | Fecha: " . $r['fecha_publicacion'] . " | Título: " . $r['titulo']);
        }
        
        return $resultados;
        
    } catch (PDOException $e) {
        error_log("Error al obtener contenidos con progreso: " . $e->getMessage());
        return array();
    }
}
/**
 * Obtiene las actividades para un estudiante
 */
function obtenerActividadesEstudiante($estudiante_id) {
    $conexion = getConexion();
    
    try {
        // Obtener grado y sección del estudiante
        $query_est = "SELECT grado, seccion FROM estudiantes WHERE usuario_id = ?";
        $stmt_est = $conexion->prepare($query_est);
        $stmt_est->execute([$estudiante_id]);
        $estudiante = $stmt_est->fetch(PDO::FETCH_ASSOC);
        
        if (!$estudiante) {
            error_log("❌ Estudiante no encontrado: " . $estudiante_id);
            return array();
        }
        
        $grado = $estudiante['grado'];
        $seccion = $estudiante['seccion'];
        
        error_log("✅ Estudiante ID: $estudiante_id - Grado: $grado - Sección: $seccion");
        
        // 🔍 DEPURACIÓN 1: Ver TODAS las actividades del docente
        error_log("🔍 DEPURACIÓN: Buscando actividades del docente...");
        $query_docente = "
            SELECT id, titulo, grado, seccion, docente_id, activo
            FROM actividades 
            WHERE docente_id = (SELECT id FROM usuarios WHERE rol = 'Docente' LIMIT 1)
            ORDER BY id DESC
        ";
        // Nota: Ajusta esta consulta según tu lógica real de docente
        
        // 🔍 DEPURACIÓN 2: Ver actividades de ESTE grado/sección
        $query_mismo_grado = "
            SELECT id, titulo, grado, seccion, activo
            FROM actividades 
            WHERE grado = ? AND seccion = ? AND activo = true
        ";
        $stmt_mismo = $conexion->prepare($query_mismo_grado);
        $stmt_mismo->execute([$grado, $seccion]);
        $act_mismo_grado = $stmt_mismo->fetchAll(PDO::FETCH_ASSOC);
        error_log("📊 Actividades del grado $grado-$seccion: " . count($act_mismo_grado));
        foreach ($act_mismo_grado as $act) {
            error_log("   - ID: {$act['id']} | '{$act['titulo']}' | Grado: {$act['grado']}-{$act['seccion']}");
        }
        
        // 🔍 DEPURACIÓN 3: Ver TODAS las actividades activas
        $query_todas = "
            SELECT id, titulo, grado, seccion, activo
            FROM actividades 
            WHERE activo = true
            ORDER BY id DESC
            LIMIT 20
        ";
        $stmt_todas = $conexion->prepare($query_todas);
        $stmt_todas->execute();
        $todas_actividades = $stmt_todas->fetchAll(PDO::FETCH_ASSOC);
        error_log("📊 TOTAL actividades activas en BD: " . count($todas_actividades));
        foreach ($todas_actividades as $act) {
            error_log("   - ID: {$act['id']} | '{$act['titulo']}' | Grado: {$act['grado']}-{$act['seccion']}");
        }
        
        // 🔴 CONSULTA ORIGINAL (la que estás usando)
        $query = "
            SELECT 
                a.id, a.titulo, a.descripcion, a.tipo, a.fecha_entrega,
                a.grado, a.seccion, a.activo, u.nombre as docente_nombre,
                'pendiente' as estado_final
            FROM actividades a
            LEFT JOIN usuarios u ON a.docente_id = u.id
            WHERE a.activo = true 
                AND a.grado = ? 
                AND a.seccion = ?
            ORDER BY a.fecha_entrega ASC
        ";
        
        $stmt = $conexion->prepare($query);
        $stmt->execute([$grado, $seccion]);
        $actividades = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("📊 Actividades encontradas con filtro original: " . count($actividades));
        
        // 🔴 AGREGAR INFORMACIÓN DE ENTREGAS
        foreach ($actividades as &$actividad) {
            $act_id = $actividad['id'];
            $entrega = obtenerEntregaEstudiante($act_id, $estudiante_id);
            
            if ($entrega) {
                $actividad['estado_entrega'] = $entrega['estado'];
                $actividad['calificacion'] = $entrega['calificacion'];
                $actividad['observaciones'] = $entrega['observaciones'];
                $actividad['archivo_entregado'] = $entrega['archivo_entregado'];
                $actividad['fecha_entrega_estudiante'] = $entrega['fecha_entrega'];
                
                if ($entrega['calificacion'] !== null && $entrega['calificacion'] !== '') {
                    $actividad['estado_final'] = 'calificado';
                } elseif ($entrega['estado'] === 'enviado') {
                    $actividad['estado_final'] = 'enviado';
                } elseif ($entrega['estado'] === 'atrasado') {
                    $actividad['estado_final'] = 'atrasado';
                } else {
                    $actividad['estado_final'] = $entrega['estado'] ?? 'pendiente';
                }
            } else {
                if (!empty($actividad['fecha_entrega']) && strtotime($actividad['fecha_entrega']) < time()) {
                    $actividad['estado_final'] = 'atrasado';
                }
                $actividad['calificacion'] = null;
                $actividad['estado_entrega'] = null;
            }
        }
        
        return $actividades;
        
    } catch (PDOException $e) {
        error_log("❌ ERROR obtenerActividadesEstudiante: " . $e->getMessage());
        return array();
    }
}
/**
 * Obtiene los contenidos vinculados a una actividad (VERSIÓN CORREGIDA)
 */
function obtenerContenidosDeActividad($actividad_id) {
    $conexion = getConexion();
    
    try {
        $query = "
            SELECT c.*
            FROM actividades_contenidos ac
            INNER JOIN contenidos c ON ac.contenido_id = c.id
            WHERE ac.actividad_id = :id
        ";
        
        $stmt = $conexion->prepare($query);
        $stmt->execute(['id' => $actividad_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Error en obtenerContenidosDeActividad: " . $e->getMessage());
        return array();
    }
}
/**
 * Registra una entrega de estudiante
 */
function registrarEntregaEstudiante($actividad_id, $estudiante_id, $archivo = '', $enlace = '', $comentario = '') {
    $conexion = getConexion();
    
    try {
        $query_check = "
            SELECT id FROM entregas_estudiantes 
            WHERE actividad_id = :actividad_id AND estudiante_id = :estudiante_id
        ";
        $stmt_check = $conexion->prepare($query_check);
        $stmt_check->execute([
            ':actividad_id' => $actividad_id,
            ':estudiante_id' => $estudiante_id
        ]);
        
        if ($stmt_check->fetch()) {
            $query = "
                UPDATE entregas_estudiantes 
                SET 
                    archivo_entregado = :archivo,
                    comentario = :comentario,
                    fecha_entrega = CURRENT_TIMESTAMP,
                    estado = 'enviado'
                WHERE actividad_id = :actividad_id AND estudiante_id = :estudiante_id
            ";
        } else {
            $query = "
                INSERT INTO entregas_estudiantes 
                (actividad_id, estudiante_id, archivo_entregado, comentario, estado, fecha_entrega)
                VALUES (:actividad_id, :estudiante_id, :archivo, :comentario, 'enviado', CURRENT_TIMESTAMP)
            ";
        }
        
        $stmt = $conexion->prepare($query);
        return $stmt->execute([
            ':actividad_id' => $actividad_id,
            ':estudiante_id' => $estudiante_id,
            ':archivo' => $archivo,
            ':comentario' => $comentario
        ]);
        
    } catch (PDOException $e) {
        error_log("Error registrarEntregaEstudiante: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtiene una actividad específica por ID
 */
function obtenerActividadPorId($actividad_id, $estudiante_id) {
    $conexion = getConexion();
    
    try {
        $query_actividad = "
            SELECT * FROM actividades 
            WHERE id = :id AND activo = true 
            LIMIT 1
        ";
        
        $stmt = $conexion->prepare($query_actividad);
        $stmt->execute([':id' => $actividad_id]);
        $actividad = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$actividad) {
            return null;
        }
        
        if (!empty($actividad['docente_id'])) {
            $stmt = $conexion->prepare("SELECT nombre FROM usuarios WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $actividad['docente_id']]);
            $docente = $stmt->fetch(PDO::FETCH_ASSOC);
            $actividad['docente_nombre'] = $docente['nombre'] ?? 'No especificado';
        } else {
            $actividad['docente_nombre'] = 'No especificado';
        }

        $stmt = $conexion->prepare("
            SELECT estado, archivo_entregado, fecha_entrega, calificacion, observaciones, comentario
            FROM entregas_estudiantes 
            WHERE actividad_id = :actividad_id AND estudiante_id = :estudiante_id
            ORDER BY fecha_entrega DESC 
            LIMIT 1
        ");
        $stmt->execute([
            ':actividad_id' => $actividad_id,
            ':estudiante_id' => $estudiante_id
        ]);
        $entrega = $stmt->fetch(PDO::FETCH_ASSOC);

        $estado_final = 'pendiente';
        if ($entrega) {
            $estado_final = $entrega['estado'];
            $actividad['estado_entrega'] = $entrega['estado'];
            $actividad['archivo_entregado'] = $entrega['archivo_entregado'];
            $actividad['fecha_entrega_estudiante'] = $entrega['fecha_entrega'];
            $actividad['calificacion'] = $entrega['calificacion'];
            $actividad['observaciones'] = $entrega['observaciones'];
            $actividad['comentario'] = $entrega['comentario'];
        } else {
            if (!empty($actividad['fecha_entrega']) && strtotime($actividad['fecha_entrega']) < time()) {
                $estado_final = 'atrasado';
            }
        }
        
        $actividad['estado_final'] = $estado_final;
        
        return $actividad;
        
    } catch (PDOException $e) {
        error_log("❌ ERROR EXCEPTION: " . $e->getMessage());
        return null;
    }
}

/**
 * Obtiene la entrega de un estudiante
 */
function obtenerEntregaEstudiante($actividad_id, $estudiante_id) {
    $conexion = getConexion();
    
    try {
        $query = "
            SELECT * FROM entregas_estudiantes
            WHERE actividad_id = :actividad_id AND estudiante_id = :estudiante_id
            ORDER BY fecha_entrega DESC
            LIMIT 1
        ";
        
        $stmt = $conexion->prepare($query);
        $stmt->execute([
            ':actividad_id' => $actividad_id,
            ':estudiante_id' => $estudiante_id
        ]);
        
        $entrega = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $entrega;
        
    } catch (PDOException $e) {
        error_log("❌ ERROR obtenerEntregaEstudiante: " . $e->getMessage());
        return null;
    }
}

/**
 * Obtiene la actividad vinculada a un contenido (VERSIÓN CORREGIDA)
 */
function obtenerActividadPorContenido($contenido_id, $estudiante_id = null) {
    $conexion = getConexion();
    
    try {
        error_log("=== obtenerActividadPorContenido ===");
        error_log("Contenido ID: " . $contenido_id);
        
        // 1. VERIFICAR SI HAY RELACIÓN
        $check = $conexion->prepare("
            SELECT actividad_id FROM actividades_contenidos 
            WHERE contenido_id = ?
        ");
        $check->execute([$contenido_id]);
        $relacion = $check->fetch(PDO::FETCH_ASSOC);
        
        if (!$relacion) {
            error_log("❌ No hay relación en actividades_contenidos");
            return null;
        }
        
        $actividad_id = $relacion['actividad_id'];
        error_log("✅ Relación encontrada - Actividad ID: " . $actividad_id);
        
        // 2. OBTENER LA ACTIVIDAD
        $query = "SELECT * FROM actividades WHERE id = ? AND activo = true";
        $stmt = $conexion->prepare($query);
        $stmt->execute([$actividad_id]);
        $actividad = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($actividad) {
            error_log("✅ Actividad ENCONTRADA - Título: " . $actividad['titulo']);
            return $actividad;
        } else {
            error_log("❌ Actividad NO encontrada o inactiva");
            return null;
        }
        
    } catch (PDOException $e) {
        error_log("❌ ERROR: " . $e->getMessage());
        return null;
    }
}

/**
 * Obtiene el progreso detallado de un contenido
 */
function obtenerProgresoDetallado($estudiante_id, $contenido_id) {
    $conexion = getConexion();
    
    try {
        // Obtener materiales adicionales completados
        $stmt_mat = $conexion->prepare("
            SELECT material_id, completado
            FROM progreso_contenido 
            WHERE estudiante_id = ? AND contenido_id = ? AND material_id IS NOT NULL
        ");
        $stmt_mat->execute([$estudiante_id, $contenido_id]);
        $materiales = $stmt_mat->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener progreso principal
        $stmt_princ = $conexion->prepare("
            SELECT principales_completados 
            FROM progreso_contenido 
            WHERE estudiante_id = ? AND contenido_id = ? AND material_id IS NULL
        ");
        $stmt_princ->execute([$estudiante_id, $contenido_id]);
        $principal = $stmt_princ->fetch(PDO::FETCH_ASSOC);
        
        // Obtener total de recursos principales
        $stmt_cont = $conexion->prepare("SELECT enlace, archivo_adjunto FROM contenidos WHERE id = ?");
        $stmt_cont->execute([$contenido_id]);
        $cont = $stmt_cont->fetch(PDO::FETCH_ASSOC);
        
        $recursos_principales = 0;
        if (!empty($cont['enlace'])) $recursos_principales++;
        if (!empty($cont['archivo_adjunto'])) $recursos_principales++;
        
        $resultado = [
            'video_completado' => false,
            'documento_completado' => false,
            'materiales_completados' => [],
            'porcentaje_total' => 0
        ];
        
        // Determinar qué principales están completados
        if ($principal && isset($principal['principales_completados'])) {
            $completados = (int)$principal['principales_completados'];
            
            if ($recursos_principales >= 1 && $completados >= 1) {
                // Si hay video, marcarlo como completado
                if (!empty($cont['enlace'])) {
                    $resultado['video_completado'] = true;
                }
            }
            
            if ($recursos_principales >= 2 && $completados >= 2) {
                // Si hay documento, marcarlo como completado
                if (!empty($cont['archivo_adjunto'])) {
                    $resultado['documento_completado'] = true;
                }
            }
        }
        
        // Materiales adicionales
        foreach ($materiales as $m) {
            $resultado['materiales_completados'][$m['material_id']] = $m['completado'];
        }
        
        return $resultado;
        
    } catch (PDOException $e) {
        error_log("Error en obtenerProgresoDetallado: " . $e->getMessage());
        return [
            'video_completado' => false,
            'documento_completado' => false,
            'materiales_completados' => [],
            'porcentaje_total' => 0
        ];
    }
}
/**
 * Reinicia una entrega para permitir que el estudiante la modifique.
 * Cambia el estado a 'pendiente' o elimina el registro según prefieras.
 */
function reiniciarEntrega($conexion, $entrega_id, $docente_id, $motivo = 'Reapertura por docente') {
    try {
        // Opción 1: Cambiar el estado a 'pendiente' para que se pueda modificar
        $stmt = $conexion->prepare("
            UPDATE entregas_estudiantes 
            SET estado = 'pendiente' 
            WHERE id = ?
        ");
        $stmt->execute([$entrega_id]);

        // Opcional: Guardar un log de esta acción para auditoría
        $stmt_log = $conexion->prepare("
            INSERT INTO logs_acciones (usuario_id, accion, descripcion, fecha)
            VALUES (?, 'reabrir_entrega', ?, NOW())
        ");
        $stmt_log->execute([$docente_id, $motivo]);

        return ['success' => true, 'mensaje' => 'Entrega reabierta correctamente.'];

    } catch (Exception $e) {
        error_log("Error al reiniciar entrega: " . $e->getMessage());
        return ['success' => false, 'error' => 'Error al reabrir la entrega.'];
    }
}
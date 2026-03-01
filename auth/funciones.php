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
 * VERSIÓN CORREGIDA - SIN DUPLICADOS
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
        
        // ✅ IMPORTANTE: Usar DISTINCT para evitar duplicados
        // ✅ O usar subconsulta para el progreso
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
                COALESCE(p.completado, false) as completado
            FROM contenidos c
            LEFT JOIN usuarios u ON c.docente_id = u.id
            LEFT JOIN (
                SELECT DISTINCT contenido_id, estudiante_id, porcentaje_visto, completado
                FROM progreso_contenido
                WHERE estudiante_id = :estudiante_id
            ) p ON p.contenido_id = c.id
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
            'estudiante_id' => $estudiante_id,
            'grado' => $grado,
            'seccion' => $seccion
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
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
        $query = "
            SELECT 
                a.id, a.titulo, a.descripcion, a.tipo, a.fecha_entrega,
                a.grado, a.seccion, a.activo, u.nombre as docente_nombre,
                'pendiente' as estado_final
            FROM actividades a
            LEFT JOIN usuarios u ON a.docente_id = u.id
            WHERE a.activo = true
            ORDER BY a.fecha_entrega ASC
        ";
        
        $stmt = $conexion->prepare($query);
        $stmt->execute();
        $actividades = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
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
 * Obtiene los contenidos vinculados a una actividad
 */
function obtenerContenidosDeActividad($actividad_id) {
    $conexion = getConexion();
    
    try {
        $query = "
            SELECT c.*
            FROM actividades_contenidos ac
            INNER JOIN contenidos c ON ac.contenido_id = c.id
            WHERE ac.actividad_id = $1 AND c.activo = true
            ORDER BY c.fecha_publicacion DESC
        ";
        $stmt = $conexion->prepare($query);
        $stmt->execute([$actividad_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error al obtener contenidos de actividad: " . $e->getMessage());
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
 * Obtiene la actividad vinculada a un contenido
 */
function obtenerActividadPorContenido($contenido_id, $estudiante_id = null) {
    $conexion = getConexion();
    
    try {
        // ✅ DEBUG - Parámetros de entrada
        error_log("=== obtenerActividadPorContenido ===");
        error_log("Contenido ID recibido: " . $contenido_id);
        error_log("Estudiante ID recibido: " . ($estudiante_id ?? 'NULL'));
        
        // ✅ QUERY - Verificar relación primero
        $check_query = "SELECT * FROM actividades_contenidos WHERE contenido_id = $1";
        $check_stmt = $conexion->prepare($check_query);
        $check_stmt->execute([$contenido_id]);
        $check_result = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        error_log("Relación en actividades_contenidos: " . ($check_result ? 'EXISTS' : 'NOT EXISTS'));
        if ($check_result) {
            error_log("actividad_id encontrado: " . ($check_result['actividad_id'] ?? 'NULL'));
        }
        
        // ✅ QUERY PRINCIPAL
        $query = "
            SELECT 
                a.id,
                a.titulo,
                a.descripcion,
                a.tipo,
                a.fecha_entrega,
                a.grado,
                a.seccion,
                a.activo,
                e.estado as estado_entrega,
                e.calificacion
            FROM actividades a
            INNER JOIN actividades_contenidos ac ON a.id = ac.actividad_id
            LEFT JOIN entregas_estudiantes e ON e.actividad_id = a.id AND e.estudiante_id = $1
            WHERE ac.contenido_id = $2 
              AND a.activo = true
            LIMIT 1
        ";
        
        error_log("Ejecutando query con estudiante_id=" . ($estudiante_id ?? 'NULL') . ", contenido_id=" . $contenido_id);
        
        $stmt = $conexion->prepare($query);
        $stmt->execute([$estudiante_id, $contenido_id]);
        
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // ✅ DEBUG - Resultado
        error_log("Resultado de query: " . ($resultado ? 'ENCONTRADO' : 'NO ENCONTRADO'));
        if ($resultado) {
            error_log("Actividad ID: " . ($resultado['id'] ?? 'SIN ID'));
            error_log("Actividad Titulo: " . ($resultado['titulo'] ?? 'SIN TITULO'));
            error_log("Actividad Activo: " . ($resultado['activo'] ?? 'SIN ESTADO'));
        } else {
            // ✅ DEBUG ADICIONAL - Verificar si la actividad existe pero está inactiva
            $check_activo = "SELECT id, titulo, activo FROM actividades WHERE id IN (SELECT actividad_id FROM actividades_contenidos WHERE contenido_id = $1)";
            $check_activo_stmt = $conexion->prepare($check_activo);
            $check_activo_stmt->execute([$contenido_id]);
            $check_activo_result = $check_activo_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($check_activo_result) {
                error_log("⚠️ Actividad existe pero activo = " . ($check_activo_result['activo'] ? 'true' : 'false'));
            } else {
                error_log("❌ No hay actividad vinculada a este contenido");
            }
        }
        
        return $resultado;
        
    } catch (PDOException $e) {
        error_log("❌ ERROR obtenerActividadPorContenido: " . $e->getMessage());
        return null;
    }
}
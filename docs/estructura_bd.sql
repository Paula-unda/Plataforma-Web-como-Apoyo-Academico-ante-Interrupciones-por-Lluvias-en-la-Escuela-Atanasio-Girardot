-- docs/estructura_bd.sql

-- Tabla de usuarios
CREATE TABLE usuarios (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    correo VARCHAR(150) UNIQUE NOT NULL,
    contrasena TEXT NOT NULL,
    contrasena_temporal VARCHAR(50),
    rol VARCHAR(20) NOT NULL CHECK (rol IN ('Administrador', 'Docente', 'Estudiante', 'Representante')),
    activo BOOLEAN DEFAULT true,
    telefono VARCHAR(20),
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla de estudiantes 
CREATE TABLE estudiantes (
    usuario_id INTEGER PRIMARY KEY REFERENCES usuarios(id) ON DELETE CASCADE,
    grado VARCHAR(10),
    seccion VARCHAR(5)
);

-- Tabla de relación representantes-estudiantes 
CREATE TABLE representantes_estudiantes (
    representante_id INTEGER REFERENCES usuarios(id) ON DELETE CASCADE,
    estudiante_id INTEGER REFERENCES usuarios(id) ON DELETE CASCADE,
    PRIMARY KEY (representante_id, estudiante_id)
);




-- Extensión requerida para funciones criptográficas
-- Se utiliza para el cifrado seguro de contraseñas mediante bcrypt (crypt + gen_salt)
-- Requerida por el sistema SIEDUCRES
CREATE EXTENSION IF NOT EXISTS pgcrypto;


-- Insertar datos de Administrador para iniciar sesión
INSERT INTO usuarios (
    nombre,
    correo,
    contrasena,
    contrasena_temporal,
    rol,
    telefono
)
VALUES (
    'Admin SIEDUCRES',
    'admin@sieducres.edu.ve',
    crypt('A2026siudecres+', gen_salt('bf')),
    'A2026siudecres+',
    'Administrador'
); 

-- Tabla de contenidos educativos
CREATE TABLE contenidos (
    id SERIAL PRIMARY KEY,
    titulo VARCHAR(255) NOT NULL,
    descripcion TEXT NOT NULL,
    fecha_publicacion DATE NOT NULL DEFAULT CURRENT_DATE,
    archivo_adjunto VARCHAR(255),
    enlace VARCHAR(500),
    asignatura VARCHAR(100) NOT NULL,
    grado VARCHAR(10),
    seccion VARCHAR(5),
    docente_id INTEGER REFERENCES usuarios(id) ON DELETE CASCADE,
    activo BOOLEAN DEFAULT true,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Índices para mejorar el rendimiento
CREATE INDEX idx_contenidos_docente ON contenidos(docente_id);
CREATE INDEX idx_contenidos_grado_seccion ON contenidos(grado, seccion);
CREATE INDEX idx_contenidos_fecha ON contenidos(fecha_publicacion DESC);

-- Insertar datos de ejemplo para pruebas
INSERT INTO contenidos (titulo, descripcion, fecha_publicacion, enlace, asignatura, grado, seccion, docente_id)
VALUES 
    (
        'Introducción a la Matemática',
        'Clase de recuperación sobre operaciones básicas y fracciones.',
        '2025-09-05',
        'https://ejemplo.com/matematicas-clase1',
        'Matemáticas',
        '1ero',
        'A',
        1
    ),
    (
        'Historia de Venezuela',
        'Repaso sobre la independencia y figuras históricas importantes.',
        '2025-09-06',
        'https://ejemplo.com/historia-clase1',
        'Historia',
        '2do',
        'B',
        1
    ),
    (
        'Ciencias Naturales',
        'Clase sobre el sistema solar y los planetas.',
        '2025-09-07',
        'https://ejemplo.com/ciencias-clase1',
        'Ciencias',
        '3ero',
        'C',
        1
    );

    'Administrador',
    '+58 4161234567'
);


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
    'Administrador',
    '+58 4161234567'
);

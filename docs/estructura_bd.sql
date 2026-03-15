--
-- PostgreSQL database dump
-- VERSIÓN LIMPIA: Solo estructura de tablas + usuario Administrador
--

--
-- Configuración inicial de la base de datos (Zona horaria y configuraciones generales)
--
SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--
CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;
COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';

SET default_tablespace = '';
SET default_table_access_method = heap;

--
-- Estructura de la tabla: actividades (sin datos)
--
CREATE TABLE public.actividades (
    id integer NOT NULL,
    titulo character varying(255) NOT NULL,
    descripcion text NOT NULL,
    tipo character varying(20) NOT NULL,
    fecha_entrega date NOT NULL,
    fecha_publicacion date DEFAULT CURRENT_DATE,
    activo boolean DEFAULT true,
    docente_id integer NOT NULL,
    grado character varying(10),
    seccion character varying(5),
    creado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT actividades_tipo_check CHECK (((tipo)::text = ANY ((ARRAY['examen'::character varying, 'tarea'::character varying, 'indicacion'::character varying])::text[])))
);
ALTER TABLE public.actividades OWNER TO postgres;

--
-- Estructura de la tabla: actividades_contenidos (sin datos)
--
CREATE TABLE public.actividades_contenidos (
    actividad_id integer NOT NULL,
    contenido_id integer NOT NULL
);
ALTER TABLE public.actividades_contenidos OWNER TO postgres;

--
-- Secuencia para actividades.id
--
CREATE SEQUENCE public.actividades_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER TABLE public.actividades_id_seq OWNER TO postgres;
ALTER SEQUENCE public.actividades_id_seq OWNED BY public.actividades.id;

--
-- Estructura de la tabla: contenidos (sin datos)
--
CREATE TABLE public.contenidos (
    id integer NOT NULL,
    titulo character varying(255) NOT NULL,
    descripcion text NOT NULL,
    fecha_publicacion date DEFAULT CURRENT_DATE NOT NULL,
    archivo_adjunto character varying(255),
    enlace character varying(500),
    asignatura character varying(100) NOT NULL,
    grado character varying(10),
    seccion character varying(5),
    docente_id integer,
    activo boolean DEFAULT true,
    creado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    videos_adicionales text
);
ALTER TABLE public.contenidos OWNER TO postgres;

--
-- Secuencia para contenidos.id
--
CREATE SEQUENCE public.contenidos_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER TABLE public.contenidos_id_seq OWNER TO postgres;
ALTER SEQUENCE public.contenidos_id_seq OWNED BY public.contenidos.id;

--
-- Estructura de la tabla: docentes (sin datos)
--
CREATE TABLE public.docentes (
    usuario_id integer NOT NULL,
    grado character varying(10),
    seccion character varying(5)
);
ALTER TABLE public.docentes OWNER TO postgres;

--
-- Estructura de la tabla: encuestas (sin datos)
--
CREATE TABLE public.encuestas (
    id integer NOT NULL,
    titulo character varying(255) NOT NULL,
    descripcion text NOT NULL,
    instrucciones text,
    fecha_publicacion date DEFAULT CURRENT_DATE NOT NULL,
    fecha_cierre date NOT NULL,
    activo boolean DEFAULT true,
    creado_por integer,
    creado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    dirigido_a character varying(20) NOT NULL,
    grado character varying(10),
    seccion character varying(5),
    total_preguntas integer DEFAULT 0,
    total_respuestas integer DEFAULT 0,
    CONSTRAINT encuestas_dirigido_a_check CHECK (((dirigido_a)::text = ANY ((ARRAY['todos'::character varying, 'estudiantes'::character varying, 'docentes'::character varying, 'representantes'::character varying])::text[])))
);
ALTER TABLE public.encuestas OWNER TO postgres;

--
-- Secuencia para encuestas.id
--
CREATE SEQUENCE public.encuestas_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER TABLE public.encuestas_id_seq OWNER TO postgres;
ALTER SEQUENCE public.encuestas_id_seq OWNED BY public.encuestas.id;

--
-- Estructura de la tabla: entregas_estudiantes (sin datos)
--
CREATE TABLE public.entregas_estudiantes (
    id integer NOT NULL,
    actividad_id integer NOT NULL,
    estudiante_id integer NOT NULL,
    archivo_entregado character varying(255),
    enlace_entregado character varying(500),
    comentario text,
    fecha_entrega timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    calificacion numeric(4,2),
    observaciones text,
    estado character varying(20) DEFAULT 'pendiente'::character varying NOT NULL,
    CONSTRAINT entregas_estudiantes_estado_check CHECK (((estado)::text = ANY ((ARRAY['pendiente'::character varying, 'enviado'::character varying, 'atrasado'::character varying, 'calificado'::character varying])::text[])))
);
ALTER TABLE public.entregas_estudiantes OWNER TO postgres;

--
-- Secuencia para entregas_estudiantes.id
--
CREATE SEQUENCE public.entregas_estudiantes_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER TABLE public.entregas_estudiantes_id_seq OWNER TO postgres;
ALTER SEQUENCE public.entregas_estudiantes_id_seq OWNED BY public.entregas_estudiantes.id;

--
-- Estructura de la tabla: estudiantes (sin datos)
--
CREATE TABLE public.estudiantes (
    usuario_id integer NOT NULL,
    grado character varying(10),
    seccion character varying(5)
);
ALTER TABLE public.estudiantes OWNER TO postgres;

--
-- Estructura de la tabla: foros_respuestas (sin datos)
--
CREATE TABLE public.foros_respuestas (
    id integer NOT NULL,
    tema_id integer NOT NULL,
    autor_id integer NOT NULL,
    contenido text NOT NULL,
    es_privado boolean DEFAULT false,
    destinatario_tipo character varying(20),
    destinatario_id text,
    fecha_creacion timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    puede_borrar_hasta timestamp without time zone
);
ALTER TABLE public.foros_respuestas OWNER TO postgres;

--
-- Secuencia para foros_respuestas.id
--
CREATE SEQUENCE public.foros_respuestas_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER TABLE public.foros_respuestas_id_seq OWNER TO postgres;
ALTER SEQUENCE public.foros_respuestas_id_seq OWNED BY public.foros_respuestas.id;

--
-- Estructura de la tabla: foros_temas (sin datos)
--
CREATE TABLE public.foros_temas (
    id integer NOT NULL,
    titulo character varying(200) NOT NULL,
    descripcion text NOT NULL,
    autor_id integer NOT NULL,
    fecha_creacion timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    grado character varying(10),
    seccion character varying(5)
);
ALTER TABLE public.foros_temas OWNER TO postgres;

--
-- Secuencia para foros_temas.id
--
CREATE SEQUENCE public.foros_temas_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER TABLE public.foros_temas_id_seq OWNER TO postgres;
ALTER SEQUENCE public.foros_temas_id_seq OWNED BY public.foros_temas.id;

--
-- Estructura de la tabla: historial_academico (sin datos)
--
CREATE TABLE public.historial_academico (
    id integer NOT NULL,
    estudiante_id integer NOT NULL,
    periodo_id integer NOT NULL,
    grado character varying(10) NOT NULL,
    seccion character varying(5) NOT NULL,
    resumen text,
    promedio_general numeric(4,2) DEFAULT 0,
    total_actividades integer DEFAULT 0,
    actividades_completadas integer DEFAULT 0,
    porcentaje_asistencia numeric(5,2) DEFAULT 0,
    total_contenidos integer DEFAULT 0,
    contenidos_completados integer DEFAULT 0,
    progreso_promedio numeric(5,2) DEFAULT 0,
    datos_detalle jsonb,
    generado_por integer,
    generado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    actualizado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE public.historial_academico OWNER TO postgres;

--
-- Secuencia para historial_academico.id
--
CREATE SEQUENCE public.historial_academico_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER TABLE public.historial_academico_id_seq OWNER TO postgres;
ALTER SEQUENCE public.historial_academico_id_seq OWNED BY public.historial_academico.id;

--
-- Estructura de la tabla: logs_eliminaciones (sin datos)
--
CREATE TABLE public.logs_eliminaciones (
    id integer NOT NULL,
    usuario_eliminado_id integer,
    usuario_eliminado_nombre character varying(100),
    eliminado_por integer,
    fecha_eliminacion timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    ip_address character varying(45),
    user_agent text,
    accion character varying(20)
);
ALTER TABLE public.logs_eliminaciones OWNER TO postgres;

--
-- Secuencia para logs_eliminaciones.id
--
CREATE SEQUENCE public.logs_eliminaciones_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER TABLE public.logs_eliminaciones_id_seq OWNER TO postgres;
ALTER SEQUENCE public.logs_eliminaciones_id_seq OWNED BY public.logs_eliminaciones.id;

--
-- Estructura de la tabla: logs_encuestas (sin datos)
--
CREATE TABLE public.logs_encuestas (
    id integer NOT NULL,
    encuesta_id integer,
    usuario_id integer,
    accion character varying(50) NOT NULL,
    detalles text,
    fecha_log timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT logs_encuestas_accion_check CHECK (((accion)::text = ANY ((ARRAY['crear'::character varying, 'editar'::character varying, 'eliminar'::character varying, 'responder'::character varying, 'exportar'::character varying])::text[])))
);
ALTER TABLE public.logs_encuestas OWNER TO postgres;

--
-- Secuencia para logs_encuestas.id
--
CREATE SEQUENCE public.logs_encuestas_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER TABLE public.logs_encuestas_id_seq OWNER TO postgres;
ALTER SEQUENCE public.logs_encuestas_id_seq OWNED BY public.logs_encuestas.id;

--
-- Estructura de la tabla: logs_historial (sin datos)
--
CREATE TABLE public.logs_historial (
    id integer NOT NULL,
    usuario_id integer,
    periodo_id integer,
    grado character varying(10),
    seccion character varying(5),
    total_estudiantes integer,
    fecha_generacion timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE public.logs_historial OWNER TO postgres;

--
-- Secuencia para logs_historial.id
--
CREATE SEQUENCE public.logs_historial_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER TABLE public.logs_historial_id_seq OWNER TO postgres;
ALTER SEQUENCE public.logs_historial_id_seq OWNED BY public.logs_historial.id;

--
-- Estructura de la tabla: materiales (sin datos)
--
CREATE TABLE public.materiales (
    id integer NOT NULL,
    contenido_id integer,
    titulo character varying(255) NOT NULL,
    tipo character varying(20) NOT NULL,
    url character varying(500),
    archivo character varying(255),
    orden integer DEFAULT 0,
    activo boolean DEFAULT true,
    creado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT materiales_tipo_check CHECK (((tipo)::text = ANY ((ARRAY['video'::character varying, 'audio'::character varying, 'documento'::character varying, 'enlace'::character varying])::text[])))
);
ALTER TABLE public.materiales OWNER TO postgres;

--
-- Secuencia para materiales.id
--
CREATE SEQUENCE public.materiales_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER TABLE public.materiales_id_seq OWNER TO postgres;
ALTER SEQUENCE public.materiales_id_seq OWNED BY public.materiales.id;

--
-- Estructura de la tabla: notificaciones (sin datos)
--
CREATE TABLE public.notificaciones (
    id integer NOT NULL,
    usuario_id integer NOT NULL,
    titulo character varying(200) NOT NULL,
    mensaje text NOT NULL,
    tipo character varying(50) NOT NULL,
    referencia_id integer,
    referencia_tipo character varying(50),
    leido boolean DEFAULT false,
    fecha_envio timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    fecha_lectura timestamp without time zone,
    prioridad character varying(20) DEFAULT 'normal'::character varying,
    CONSTRAINT notificaciones_prioridad_check CHECK (((prioridad)::text = ANY ((ARRAY['baja'::character varying, 'normal'::character varying, 'alta'::character varying])::text[]))),
    CONSTRAINT notificaciones_tipo_check CHECK (((tipo)::text = ANY ((ARRAY['actividad'::character varying, 'calificacion'::character varying, 'contenido'::character varying, 'foro'::character varying, 'encuesta'::character varying, 'sistema'::character varying])::text[])))
);
ALTER TABLE public.notificaciones OWNER TO postgres;

--
-- Secuencia para notificaciones.id
--
CREATE SEQUENCE public.notificaciones_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER TABLE public.notificaciones_id_seq OWNER TO postgres;
ALTER SEQUENCE public.notificaciones_id_seq OWNED BY public.notificaciones.id;

--
-- Estructura de la tabla: periodos_escolares (sin datos)
--
CREATE TABLE public.periodos_escolares (
    id integer NOT NULL,
    nombre character varying(100) NOT NULL,
    lapso integer NOT NULL,
    "año_escolar" character varying(20) NOT NULL,
    fecha_inicio date NOT NULL,
    fecha_fin date NOT NULL,
    activo boolean DEFAULT true,
    creado_por integer,
    creado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT periodos_escolares_lapso_check CHECK ((lapso = ANY (ARRAY[1, 2, 3])))
);
ALTER TABLE public.periodos_escolares OWNER TO postgres;

--
-- Secuencia para periodos_escolares.id
--
CREATE SEQUENCE public.periodos_escolares_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER TABLE public.periodos_escolares_id_seq OWNER TO postgres;
ALTER SEQUENCE public.periodos_escolares_id_seq OWNED BY public.periodos_escolares.id;

--
-- Estructura de la tabla: preguntas_encuesta (sin datos)
--
CREATE TABLE public.preguntas_encuesta (
    id integer NOT NULL,
    encuesta_id integer NOT NULL,
    pregunta text NOT NULL,
    tipo character varying(30) NOT NULL,
    opciones jsonb,
    obligatoria boolean DEFAULT true,
    orden integer DEFAULT 0,
    creado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT preguntas_encuesta_tipo_check CHECK (((tipo)::text = ANY ((ARRAY['texto'::character varying, 'opcion_multiple'::character varying, 'casilla_verificacion'::character varying, 'escala_1_5'::character varying, 'escala_1_10'::character varying, 'si_no'::character varying])::text[])))
);
ALTER TABLE public.preguntas_encuesta OWNER TO postgres;

--
-- Secuencia para preguntas_encuesta.id
--
CREATE SEQUENCE public.preguntas_encuesta_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER TABLE public.preguntas_encuesta_id_seq OWNER TO postgres;
ALTER SEQUENCE public.preguntas_encuesta_id_seq OWNED BY public.preguntas_encuesta.id;

--
-- Estructura de la tabla: progreso_contenido (sin datos)
--
CREATE TABLE public.progreso_contenido (
    id integer NOT NULL,
    estudiante_id integer,
    contenido_id integer,
    porcentaje_visto numeric(5,2) DEFAULT 0,
    ultima_visualizacion timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    completado boolean DEFAULT false,
    material_id integer,
    tipo character varying(50) DEFAULT NULL::character varying,
    principales_completados integer DEFAULT 0,
    CONSTRAINT progreso_contenido_porcentaje_visto_check CHECK (((porcentaje_visto >= (0)::numeric) AND (porcentaje_visto <= (100)::numeric)))
);
ALTER TABLE public.progreso_contenido OWNER TO postgres;

--
-- Secuencia para progreso_contenido.id
--
CREATE SEQUENCE public.progreso_contenido_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER TABLE public.progreso_contenido_id_seq OWNER TO postgres;
ALTER SEQUENCE public.progreso_contenido_id_seq OWNED BY public.progreso_contenido.id;

--
-- Estructura de la tabla: representantes_estudiantes (sin datos)
--
CREATE TABLE public.representantes_estudiantes (
    representante_id integer NOT NULL,
    estudiante_id integer NOT NULL
);
ALTER TABLE public.representantes_estudiantes OWNER TO postgres;

--
-- Estructura de la tabla: respuestas_encuesta (sin datos)
--
CREATE TABLE public.respuestas_encuesta (
    id integer NOT NULL,
    encuesta_id integer NOT NULL,
    pregunta_id integer NOT NULL,
    usuario_id integer NOT NULL,
    respuesta text NOT NULL,
    respuesta_json jsonb,
    fecha_respuesta timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    ip_address character varying(45),
    user_agent text
);
ALTER TABLE public.respuestas_encuesta OWNER TO postgres;

--
-- Secuencia para respuestas_encuesta.id
--
CREATE SEQUENCE public.respuestas_encuesta_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER TABLE public.respuestas_encuesta_id_seq OWNER TO postgres;
ALTER SEQUENCE public.respuestas_encuesta_id_seq OWNED BY public.respuestas_encuesta.id;

--
-- Estructura de la tabla: usuarios (CON DATO DEL ADMIN)
--
CREATE TABLE public.usuarios (
    id integer NOT NULL,
    nombre character varying(100) NOT NULL,
    correo character varying(150) NOT NULL,
    contrasena text NOT NULL,
    contrasena_temporal character varying(50),
    rol character varying(20) NOT NULL,
    activo boolean DEFAULT true,
    creado_en timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    external_id_onesignal character varying(100),
    CONSTRAINT usuarios_rol_check CHECK (((rol)::text = ANY ((ARRAY['Administrador'::character varying, 'Docente'::character varying, 'Estudiante'::character varying, 'Representante'::character varying])::text[])))
);
ALTER TABLE public.usuarios OWNER TO postgres;

--
-- Secuencia para usuarios.id
--
CREATE SEQUENCE public.usuarios_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER TABLE public.usuarios_id_seq OWNER TO postgres;
ALTER SEQUENCE public.usuarios_id_seq OWNED BY public.usuarios.id;

--
-- Estructura de la tabla: usuarios_eliminados (sin datos)
--
CREATE TABLE public.usuarios_eliminados (
    id integer NOT NULL,
    usuario_id integer NOT NULL,
    nombre character varying(100) NOT NULL,
    correo character varying(150) NOT NULL,
    contrasena text NOT NULL,
    contrasena_temporal character varying(50),
    rol character varying(20) NOT NULL,
    telefono character varying(20),
    grado character varying(10),
    seccion character varying(5),
    especialidad character varying(100),
    profesion character varying(100),
    telefono_trabajo character varying(20),
    direccion text,
    estudiantes_asignados jsonb,
    representantes_asignados jsonb,
    eliminado_por integer NOT NULL,
    fecha_eliminacion timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    motivo_eliminacion text,
    backup_completo jsonb,
    restaurado boolean DEFAULT false,
    restaurado_por integer,
    fecha_restauracion timestamp without time zone
);
ALTER TABLE public.usuarios_eliminados OWNER TO postgres;

--
-- Secuencia para usuarios_eliminados.id
--
CREATE SEQUENCE public.usuarios_eliminados_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER TABLE public.usuarios_eliminados_id_seq OWNER TO postgres;
ALTER SEQUENCE public.usuarios_eliminados_id_seq OWNED BY public.usuarios_eliminados.id;

--
-- Configuración de los valores por defecto para las columnas "id"
--
ALTER TABLE ONLY public.actividades ALTER COLUMN id SET DEFAULT nextval('public.actividades_id_seq'::regclass);
ALTER TABLE ONLY public.contenidos ALTER COLUMN id SET DEFAULT nextval('public.contenidos_id_seq'::regclass);
ALTER TABLE ONLY public.encuestas ALTER COLUMN id SET DEFAULT nextval('public.encuestas_id_seq'::regclass);
ALTER TABLE ONLY public.entregas_estudiantes ALTER COLUMN id SET DEFAULT nextval('public.entregas_estudiantes_id_seq'::regclass);
ALTER TABLE ONLY public.foros_respuestas ALTER COLUMN id SET DEFAULT nextval('public.foros_respuestas_id_seq'::regclass);
ALTER TABLE ONLY public.foros_temas ALTER COLUMN id SET DEFAULT nextval('public.foros_temas_id_seq'::regclass);
ALTER TABLE ONLY public.historial_academico ALTER COLUMN id SET DEFAULT nextval('public.historial_academico_id_seq'::regclass);
ALTER TABLE ONLY public.logs_eliminaciones ALTER COLUMN id SET DEFAULT nextval('public.logs_eliminaciones_id_seq'::regclass);
ALTER TABLE ONLY public.logs_encuestas ALTER COLUMN id SET DEFAULT nextval('public.logs_encuestas_id_seq'::regclass);
ALTER TABLE ONLY public.logs_historial ALTER COLUMN id SET DEFAULT nextval('public.logs_historial_id_seq'::regclass);
ALTER TABLE ONLY public.materiales ALTER COLUMN id SET DEFAULT nextval('public.materiales_id_seq'::regclass);
ALTER TABLE ONLY public.notificaciones ALTER COLUMN id SET DEFAULT nextval('public.notificaciones_id_seq'::regclass);
ALTER TABLE ONLY public.periodos_escolares ALTER COLUMN id SET DEFAULT nextval('public.periodos_escolares_id_seq'::regclass);
ALTER TABLE ONLY public.preguntas_encuesta ALTER COLUMN id SET DEFAULT nextval('public.preguntas_encuesta_id_seq'::regclass);
ALTER TABLE ONLY public.progreso_contenido ALTER COLUMN id SET DEFAULT nextval('public.progreso_contenido_id_seq'::regclass);
ALTER TABLE ONLY public.respuestas_encuesta ALTER COLUMN id SET DEFAULT nextval('public.respuestas_encuesta_id_seq'::regclass);
ALTER TABLE ONLY public.usuarios ALTER COLUMN id SET DEFAULT nextval('public.usuarios_id_seq'::regclass);
ALTER TABLE ONLY public.usuarios_eliminados ALTER COLUMN id SET DEFAULT nextval('public.usuarios_eliminados_id_seq'::regclass);



--
-- Reinicio de las secuencias para que empiecen desde 1
--
SELECT pg_catalog.setval('public.actividades_id_seq', 1, false);
SELECT pg_catalog.setval('public.contenidos_id_seq', 1, false);
SELECT pg_catalog.setval('public.encuestas_id_seq', 1, false);
SELECT pg_catalog.setval('public.entregas_estudiantes_id_seq', 1, false);
SELECT pg_catalog.setval('public.foros_respuestas_id_seq', 1, false);
SELECT pg_catalog.setval('public.foros_temas_id_seq', 1, false);
SELECT pg_catalog.setval('public.historial_academico_id_seq', 1, false);
SELECT pg_catalog.setval('public.logs_eliminaciones_id_seq', 1, false);
SELECT pg_catalog.setval('public.logs_encuestas_id_seq', 1, false);
SELECT pg_catalog.setval('public.logs_historial_id_seq', 1, false);
SELECT pg_catalog.setval('public.materiales_id_seq', 1, false);
SELECT pg_catalog.setval('public.notificaciones_id_seq', 1, false);
SELECT pg_catalog.setval('public.periodos_escolares_id_seq', 1, false);
SELECT pg_catalog.setval('public.preguntas_encuesta_id_seq', 1, false);
SELECT pg_catalog.setval('public.progreso_contenido_id_seq', 1, false);
SELECT pg_catalog.setval('public.respuestas_encuesta_id_seq', 1, false);
SELECT pg_catalog.setval('public.usuarios_id_seq', 2, true); -- Se reinicia a 2 porque el Admin es el ID 1
SELECT pg_catalog.setval('public.usuarios_eliminados_id_seq', 1, false);

--
-- Definición de Claves Primarias y Únicas
--
ALTER TABLE ONLY public.actividades_contenidos ADD CONSTRAINT actividades_contenidos_pkey PRIMARY KEY (actividad_id, contenido_id);
ALTER TABLE ONLY public.actividades ADD CONSTRAINT actividades_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.contenidos ADD CONSTRAINT contenidos_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.docentes ADD CONSTRAINT docentes_pkey PRIMARY KEY (usuario_id);
ALTER TABLE ONLY public.encuestas ADD CONSTRAINT encuestas_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.entregas_estudiantes ADD CONSTRAINT entregas_estudiantes_actividad_id_estudiante_id_key UNIQUE (actividad_id, estudiante_id);
ALTER TABLE ONLY public.entregas_estudiantes ADD CONSTRAINT entregas_estudiantes_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.estudiantes ADD CONSTRAINT estudiantes_pkey PRIMARY KEY (usuario_id);
ALTER TABLE ONLY public.foros_respuestas ADD CONSTRAINT foros_respuestas_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.foros_temas ADD CONSTRAINT foros_temas_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.historial_academico ADD CONSTRAINT historial_academico_estudiante_id_periodo_id_key UNIQUE (estudiante_id, periodo_id);
ALTER TABLE ONLY public.historial_academico ADD CONSTRAINT historial_academico_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.logs_eliminaciones ADD CONSTRAINT logs_eliminaciones_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.logs_encuestas ADD CONSTRAINT logs_encuestas_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.logs_historial ADD CONSTRAINT logs_historial_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.materiales ADD CONSTRAINT materiales_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.notificaciones ADD CONSTRAINT notificaciones_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.periodos_escolares ADD CONSTRAINT periodos_escolares_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.preguntas_encuesta ADD CONSTRAINT preguntas_encuesta_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.progreso_contenido ADD CONSTRAINT progreso_contenido_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.representantes_estudiantes ADD CONSTRAINT representantes_estudiantes_pkey PRIMARY KEY (representante_id, estudiante_id);
ALTER TABLE ONLY public.respuestas_encuesta ADD CONSTRAINT respuestas_encuesta_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.respuestas_encuesta ADD CONSTRAINT respuestas_encuesta_pregunta_id_usuario_id_key UNIQUE (pregunta_id, usuario_id);
ALTER TABLE ONLY public.usuarios ADD CONSTRAINT usuarios_correo_key UNIQUE (correo);
ALTER TABLE ONLY public.usuarios_eliminados ADD CONSTRAINT usuarios_eliminados_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.usuarios ADD CONSTRAINT usuarios_external_id_onesignal_key UNIQUE (external_id_onesignal);
ALTER TABLE ONLY public.usuarios ADD CONSTRAINT usuarios_pkey PRIMARY KEY (id);

--
-- Creación de Índices para mejorar el rendimiento
--
CREATE INDEX idx_actividades_docente ON public.actividades USING btree (docente_id);
CREATE INDEX idx_actividades_grado_seccion ON public.actividades USING btree (grado, seccion);
CREATE INDEX idx_contenidos_docente ON public.contenidos USING btree (docente_id);
CREATE INDEX idx_contenidos_fecha ON public.contenidos USING btree (fecha_publicacion DESC);
CREATE INDEX idx_contenidos_grado_seccion ON public.contenidos USING btree (grado, seccion);
CREATE INDEX idx_eliminados_correo ON public.usuarios_eliminados USING btree (correo);
CREATE INDEX idx_eliminados_fecha ON public.usuarios_eliminados USING btree (fecha_eliminacion DESC);
CREATE INDEX idx_eliminados_restaurado ON public.usuarios_eliminados USING btree (restaurado);
CREATE INDEX idx_eliminados_rol ON public.usuarios_eliminados USING btree (rol);
CREATE INDEX idx_eliminados_usuario_id ON public.usuarios_eliminados USING btree (usuario_id);
CREATE INDEX idx_encuestas_activas ON public.encuestas USING btree (activo, fecha_cierre);
CREATE INDEX idx_encuestas_publico ON public.encuestas USING btree (dirigido_a, grado, seccion);
CREATE INDEX idx_entregas_actividad ON public.entregas_estudiantes USING btree (actividad_id);
CREATE INDEX idx_entregas_estudiante ON public.entregas_estudiantes USING btree (estudiante_id);
CREATE INDEX idx_historial_estudiante ON public.historial_academico USING btree (estudiante_id);
CREATE INDEX idx_historial_grado_seccion ON public.historial_academico USING btree (grado, seccion);
CREATE INDEX idx_historial_periodo ON public.historial_academico USING btree (periodo_id);
CREATE INDEX idx_notificaciones_fecha ON public.notificaciones USING btree (fecha_envio DESC);
CREATE INDEX idx_notificaciones_leido ON public.notificaciones USING btree (leido);
CREATE INDEX idx_notificaciones_usuario ON public.notificaciones USING btree (usuario_id);
CREATE INDEX idx_periodos_activo ON public.periodos_escolares USING btree (activo);
CREATE INDEX idx_periodos_fechas ON public.periodos_escolares USING btree (fecha_inicio, fecha_fin);
CREATE INDEX idx_preguntas_encuesta ON public.preguntas_encuesta USING btree (encuesta_id);
CREATE INDEX idx_progreso_contenido ON public.progreso_contenido USING btree (contenido_id);
CREATE INDEX idx_progreso_estudiante ON public.progreso_contenido USING btree (estudiante_id);
CREATE INDEX idx_respuestas_destinatario ON public.foros_respuestas USING btree (destinatario_id);
CREATE INDEX idx_respuestas_encuesta ON public.respuestas_encuesta USING btree (encuesta_id);
CREATE INDEX idx_respuestas_fecha ON public.foros_respuestas USING btree (fecha_creacion);
CREATE INDEX idx_respuestas_privacidad ON public.foros_respuestas USING btree (es_privado, destinatario_tipo);
CREATE INDEX idx_respuestas_usuario ON public.respuestas_encuesta USING btree (usuario_id);
CREATE UNIQUE INDEX unique_progreso_material ON public.progreso_contenido USING btree (estudiante_id, contenido_id, material_id) WHERE (material_id IS NOT NULL);
CREATE UNIQUE INDEX unique_progreso_principal ON public.progreso_contenido USING btree (estudiante_id, contenido_id) WHERE (material_id IS NULL);

--
-- Definición de Claves Foráneas (Relaciones entre tablas)
--
ALTER TABLE ONLY public.actividades_contenidos ADD CONSTRAINT actividades_contenidos_actividad_id_fkey FOREIGN KEY (actividad_id) REFERENCES public.actividades(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.actividades_contenidos ADD CONSTRAINT actividades_contenidos_contenido_id_fkey FOREIGN KEY (contenido_id) REFERENCES public.contenidos(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.actividades ADD CONSTRAINT actividades_docente_id_fkey FOREIGN KEY (docente_id) REFERENCES public.usuarios(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.contenidos ADD CONSTRAINT contenidos_docente_id_fkey FOREIGN KEY (docente_id) REFERENCES public.usuarios(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.docentes ADD CONSTRAINT docentes_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.encuestas ADD CONSTRAINT encuestas_creado_por_fkey FOREIGN KEY (creado_por) REFERENCES public.usuarios(id) ON DELETE SET NULL;
ALTER TABLE ONLY public.entregas_estudiantes ADD CONSTRAINT entregas_estudiantes_actividad_id_fkey FOREIGN KEY (actividad_id) REFERENCES public.actividades(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.entregas_estudiantes ADD CONSTRAINT entregas_estudiantes_estudiante_id_fkey FOREIGN KEY (estudiante_id) REFERENCES public.usuarios(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.estudiantes ADD CONSTRAINT estudiantes_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.foros_respuestas ADD CONSTRAINT foros_respuestas_autor_id_fkey FOREIGN KEY (autor_id) REFERENCES public.usuarios(id);
ALTER TABLE ONLY public.foros_respuestas ADD CONSTRAINT foros_respuestas_tema_id_fkey FOREIGN KEY (tema_id) REFERENCES public.foros_temas(id);
ALTER TABLE ONLY public.foros_temas ADD CONSTRAINT foros_temas_autor_id_fkey FOREIGN KEY (autor_id) REFERENCES public.usuarios(id);
ALTER TABLE ONLY public.historial_academico ADD CONSTRAINT historial_academico_estudiante_id_fkey FOREIGN KEY (estudiante_id) REFERENCES public.estudiantes(usuario_id) ON DELETE CASCADE;
ALTER TABLE ONLY public.historial_academico ADD CONSTRAINT historial_academico_generado_por_fkey FOREIGN KEY (generado_por) REFERENCES public.usuarios(id);
ALTER TABLE ONLY public.historial_academico ADD CONSTRAINT historial_academico_periodo_id_fkey FOREIGN KEY (periodo_id) REFERENCES public.periodos_escolares(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.logs_eliminaciones ADD CONSTRAINT logs_eliminaciones_eliminado_por_fkey FOREIGN KEY (eliminado_por) REFERENCES public.usuarios(id);
ALTER TABLE ONLY public.logs_encuestas ADD CONSTRAINT logs_encuestas_encuesta_id_fkey FOREIGN KEY (encuesta_id) REFERENCES public.encuestas(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.logs_encuestas ADD CONSTRAINT logs_encuestas_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id) ON DELETE SET NULL;
ALTER TABLE ONLY public.logs_historial ADD CONSTRAINT logs_historial_periodo_id_fkey FOREIGN KEY (periodo_id) REFERENCES public.periodos_escolares(id);
ALTER TABLE ONLY public.logs_historial ADD CONSTRAINT logs_historial_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id);
ALTER TABLE ONLY public.materiales ADD CONSTRAINT materiales_contenido_id_fkey FOREIGN KEY (contenido_id) REFERENCES public.contenidos(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.notificaciones ADD CONSTRAINT notificaciones_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.periodos_escolares ADD CONSTRAINT periodos_escolares_creado_por_fkey FOREIGN KEY (creado_por) REFERENCES public.usuarios(id);
ALTER TABLE ONLY public.preguntas_encuesta ADD CONSTRAINT preguntas_encuesta_encuesta_id_fkey FOREIGN KEY (encuesta_id) REFERENCES public.encuestas(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.progreso_contenido ADD CONSTRAINT progreso_contenido_contenido_id_fkey FOREIGN KEY (contenido_id) REFERENCES public.contenidos(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.progreso_contenido ADD CONSTRAINT progreso_contenido_estudiante_id_fkey FOREIGN KEY (estudiante_id) REFERENCES public.estudiantes(usuario_id) ON DELETE CASCADE;
ALTER TABLE ONLY public.progreso_contenido ADD CONSTRAINT progreso_contenido_material_id_fkey FOREIGN KEY (material_id) REFERENCES public.materiales(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.representantes_estudiantes ADD CONSTRAINT representantes_estudiantes_estudiante_id_fkey FOREIGN KEY (estudiante_id) REFERENCES public.usuarios(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.representantes_estudiantes ADD CONSTRAINT representantes_estudiantes_representante_id_fkey FOREIGN KEY (representante_id) REFERENCES public.usuarios(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.respuestas_encuesta ADD CONSTRAINT respuestas_encuesta_encuesta_id_fkey FOREIGN KEY (encuesta_id) REFERENCES public.encuestas(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.respuestas_encuesta ADD CONSTRAINT respuestas_encuesta_pregunta_id_fkey FOREIGN KEY (pregunta_id) REFERENCES public.preguntas_encuesta(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.respuestas_encuesta ADD CONSTRAINT respuestas_encuesta_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.usuarios_eliminados ADD CONSTRAINT usuarios_eliminados_eliminado_por_fkey FOREIGN KEY (eliminado_por) REFERENCES public.usuarios(id);
ALTER TABLE ONLY public.usuarios_eliminados ADD CONSTRAINT usuarios_eliminados_restaurado_por_fkey FOREIGN KEY (restaurado_por) REFERENCES public.usuarios(id);

--
-- PostgreSQL database dump complete
--

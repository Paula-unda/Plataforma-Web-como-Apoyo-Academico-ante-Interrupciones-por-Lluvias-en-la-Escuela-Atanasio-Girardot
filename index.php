<?php
session_start();
// Si ya está logueado, redirigir a su panel
if (isset($_SESSION['usuario_id'])) {
    require_once 'auth/funciones.php';
    redirigirPorRol($_SESSION['usuario_rol']);
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIEDUCRES - Plataforma Educativa</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    
    <!-- Fuentes -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome para iconos educativos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            /* Paleta de colores SIEDUCRES */
            --primary-cyan: #4BC4E7;
            --primary-pink: #EF5E8E;
            --primary-lime: #C2D54E;
            --primary-purple: #9b8afb;
            --text-dark: #333333;
            --text-muted: #666666;
            --text-light: #999999;
            --surface: #FFFFFF;
            --background: #F5F5F5;
            --border: #E0E0E0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--background);
            color: var(--text-dark);
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* ===================================================== */
        /* HEADER DE NAVEGACIÓN */
        /* ===================================================== */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            z-index: 1000;
            padding: 15px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .nav-logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-logo img {
            height: 40px;
        }

        .nav-logo span {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary-cyan);
        }

        .nav-links {
            display: flex;
            gap: 30px;
            align-items: center;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--text-dark);
            font-weight: 500;
            transition: color 0.3s;
        }

        .nav-links a:hover {
            color: var(--primary-cyan);
        }

        .nav-btn {
            background: var(--primary-cyan);
            color: white !important;
            padding: 10px 24px;
            border-radius: 50px;
            transition: transform 0.3s !important;
        }

        .nav-btn:hover {
            transform: translateY(-2px);
            background: #3ab3d6;
        }
        /* ===================================================== */
        /* ANIMACIÓN DE FORMAS GEOMÉTRICAS (TODO DERECHA) */
        /* ===================================================== */
        .floating-shapes {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            pointer-events: none;
            overflow: hidden;
        }

        .forma {
            position: absolute;
            opacity: 0.25;
            animation: floatFormas linear infinite;
        }

        /* Formas geométricas */
        .forma-cuadrado {
            width: 30px;
            height: 30px;
            background: var(--primary-cyan);
            border-radius: 8px;
        }

        .forma-cuadrado-2 {
            width: 25px;
            height: 25px;
            background: var(--primary-pink);
            border-radius: 6px;
        }

        .forma-cuadrado-3 {
            width: 40px;
            height: 40px;
            background: var(--primary-purple);
            border-radius: 10px;
        }

        .forma-circulo {
            width: 28px;
            height: 28px;
            background: var(--primary-lime);
            border-radius: 50%;
        }

        .forma-circulo-2 {
            width: 22px;
            height: 22px;
            background: var(--primary-cyan);
            border-radius: 50%;
        }

        .forma-medio-circulo {
            width: 35px;
            height: 17px;
            background: var(--primary-pink);
            border-radius: 35px 35px 0 0;
        }

        @keyframes floatFormas {
            0% {
                transform: translateY(-100px) rotate(0deg);
                opacity: 0.25;
            }
            100% {
                transform: translateY(calc(100vh + 100px)) rotate(720deg);
                opacity: 0.25;
            }
        }

        /* ===================================================== */
        /* ANIMACIÓN DE ICONOS EDUCATIVOS (TODO DERECHA) */
        /* ===================================================== */
        .floating-icons {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 2;
            pointer-events: none;
            overflow: hidden;
        }

        .icono {
            position: absolute;
            opacity: 0.7;
            animation: floatIconos linear infinite;
            display: flex;
            align-items: center;
            justify-content: center;
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.2));
        }

        .icono i {
            font-size: 32px;
        }

        /* Colores vibrantes para iconos */
        .icono-cyan i { color: var(--primary-cyan); }
        .icono-pink i { color: var(--primary-pink); }
        .icono-lime i { color: var(--primary-lime); }
        .icono-purple i { color: var(--primary-purple); }

        @keyframes floatIconos {
            0% {
                transform: translateY(-100px) rotate(0deg) scale(1);
                opacity: 0.7;
            }
            50% {
                transform: translateY(50vh) rotate(180deg) scale(1.1);
                opacity: 0.8;
            }
            100% {
                transform: translateY(calc(100vh + 100px)) rotate(360deg) scale(1);
                opacity: 0.7;
            }
        }

        /* ===================================================== */
        /* TODAS LAS POSICIONES EN EL LADO DERECHO (50%-100%) */
        /* ===================================================== */

        /* Formas - lado derecho */
        .forma-1 { left: 55%; animation-duration: 10s; }
        .forma-2 { left: 60%; animation-duration: 13s; animation-delay: 1s; }
        .forma-3 { left: 65%; animation-duration: 8s; animation-delay: 2s; }
        .forma-4 { left: 70%; animation-duration: 15s; animation-delay: 0.5s; }
        .forma-5 { left: 75%; animation-duration: 11s; animation-delay: 3s; }
        .forma-6 { left: 80%; animation-duration: 14s; animation-delay: 1.5s; }
        .forma-7 { left: 85%; animation-duration: 9s; animation-delay: 2.5s; }
        .forma-8 { left: 90%; animation-duration: 12s; animation-delay: 0.2s; }

        /* Iconos - también del lado derecho (mezclados con formas pero más vibrantes) */
        .icono-1 { left: 52%; animation-duration: 9s; animation-delay: 0s; }
        .icono-2 { left: 58%; animation-duration: 11s; animation-delay: 2s; }
        .icono-3 { left: 63%; animation-duration: 13s; animation-delay: 1s; }
        .icono-4 { left: 68%; animation-duration: 8s; animation-delay: 3s; }
        .icono-5 { left: 73%; animation-duration: 12s; animation-delay: 0.5s; }
        .icono-6 { left: 78%; animation-duration: 10s; animation-delay: 2.5s; }
        .icono-7 { left: 83%; animation-duration: 14s; animation-delay: 1.2s; }
        .icono-8 { left: 88%; animation-duration: 9s; animation-delay: 3.5s; }
        .icono-9 { left: 93%; animation-duration: 11s; animation-delay: 0.2s; }
        .icono-10 { left: 98%; animation-duration: 13s; animation-delay: 2.8s; }
        .icono-11 { left: 56%; animation-duration: 10s; animation-delay: 1.8s; }
        .icono-12 { left: 66%; animation-duration: 12s; animation-delay: 3.2s; }
        /* ===================================================== */
        /* HERO SECTION */
        /* ===================================================== */
        .hero {
            position: relative;
            z-index: 2;
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 80px 40px;
            background: linear-gradient(135deg, rgba(75,196,231,0.05) 0%, rgba(155,138,251,0.05) 100%);
        }

        .hero-content {
            max-width: 600px;
        }

        .hero h1 {
            font-size: 56px;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 20px;
            color: var(--text-dark);
        }

        .hero h1 span {
            color: var(--primary-cyan);
        }

        .hero p {
            font-size: 18px;
            color: var(--text-muted);
            margin-bottom: 30px;
        }

        .hero-buttons {
            display: flex;
            gap: 20px;
        }

        .btn-primary {
            background: var(--primary-cyan);
            color: white;
            padding: 15px 40px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: transform 0.3s;
            display: inline-block;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
        }

        .btn-secondary {
            background: white;
            color: var(--text-dark);
            padding: 15px 40px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            border: 1px solid var(--border);
            transition: transform 0.3s;
        }

        .btn-secondary:hover {
            transform: translateY(-3px);
            border-color: var(--primary-cyan);
        }

        /* ===================================================== */
        /* SECCIÓN QUIÉNES SOMOS */
        /* ===================================================== */
        .section {
            position: relative;
            z-index: 2;
            padding: 100px 40px;
            background: white;
        }

        .section-header {
            text-align: center;
            max-width: 700px;
            margin: 0 auto 60px;
        }

        .section-header h2 {
            font-size: 40px;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--text-dark);
        }

        .section-header h2 span {
            color: var(--primary-cyan);
        }

        .section-header p {
            font-size: 18px;
            color: var(--text-muted);
        }

        .about-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
        }

        .about-image {
            position: relative;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }

        .about-image img {
            width: 100%;
            height: auto;
            display: block;
        }

        .about-content h3 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--primary-purple);
        }

        .about-content p {
            margin-bottom: 20px;
            color: var(--text-muted);
            font-size: 16px;
        }

        /* ===================================================== */
        /* SECCIÓN DOCENTES */
        /* ===================================================== */
        .teachers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .teacher-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            border: 1px solid var(--border);
            transition: transform 0.3s;
        }

        .teacher-card:hover {
            transform: translateY(-10px);
        }

        .teacher-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, var(--primary-cyan), var(--primary-purple));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 40px;
            font-weight: 700;
        }

        .teacher-card h3 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .teacher-card p {
            color: var(--text-muted);
            font-size: 14px;
        }

        /* ===================================================== */
        /* SECCIÓN CARACTERÍSTICAS */
        /* ===================================================== */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .feature-card {
            text-align: center;
            padding: 40px 30px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
        }

        .feature-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            background: rgba(75,196,231,0.1);
            color: var(--primary-cyan);
        }

        .feature-card h3 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .feature-card p {
            color: var(--text-muted);
            font-size: 14px;
            line-height: 1.6;
        }

        /* ===================================================== */
        /* FOOTER */
        /* ===================================================== */
        .footer {
            position: relative;
            z-index: 2;
            background: #2d2d2d;
            color: white;
            padding: 60px 40px 20px;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 40px;
            max-width: 1200px;
            margin: 0 auto 40px;
        }

        .footer-col h4 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: white;
        }

        .footer-col p {
            color: #999;
            line-height: 1.8;
            margin-bottom: 10px;
        }

        .footer-col a {
            color: #999;
            text-decoration: none;
            display: block;
            margin-bottom: 10px;
            transition: color 0.3s;
        }

        .footer-col a:hover {
            color: var(--primary-cyan);
        }

        .footer-bottom {
            text-align: center;
            padding-top: 40px;
            border-top: 1px solid #444;
            color: #999;
            font-size: 14px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .navbar {
                padding: 15px 20px;
            }

            .nav-links {
                display: none;
            }

            .hero h1 {
                font-size: 40px;
            }

            .about-grid {
                grid-template-columns: 1fr;
            }

            .section {
                padding: 60px 20px;
            }

            .section-header h2 {
                font-size: 32px;
            }
        }
        /* ===================================================== */
        /* BLOQUES DE COLOR PARA SECCIONES */
        /* ===================================================== */
        .section-white {
            background-color: #FFFFFF;
            position: relative;
            z-index: 2;
            padding: 100px 40px;
        }

        .section-gray {
            background-color: #F8F9FA; /* Gris muy suave */
            position: relative;
            z-index: 2;
            padding: 100px 40px;
            border-top: 1px solid #E0E0E0;
            border-bottom: 1px solid #E0E0E0;
        }

        /* Para móvil */
        @media (max-width: 768px) {
            .section-white, .section-gray {
                padding: 60px 20px;
            }
        }
    </style>
</head>
<body>
    <!-- FORMAS GEOMÉTRICAS (lado derecho) -->
    <div class="floating-shapes">
        <div class="forma forma-cuadrado forma-1"></div>
        <div class="forma forma-cuadrado-2 forma-2"></div>
        <div class="forma forma-cuadrado-3 forma-3"></div>
        <div class="forma forma-circulo forma-4"></div>
        <div class="forma forma-circulo-2 forma-5"></div>
        <div class="forma forma-medio-circulo forma-6"></div>
        <div class="forma forma-cuadrado forma-7"></div>
        <div class="forma forma-circulo forma-8"></div>
    </div>

    <!-- ICONOS EDUCATIVOS (también lado derecho) -->
    <div class="floating-icons">
        <!-- Libros -->
        <div class="icono icono-1 icono-cyan"><i class="fas fa-book"></i></div>
        <div class="icono icono-2 icono-pink"><i class="fas fa-book-open"></i></div>
        <div class="icono icono-3 icono-lime"><i class="fas fa-book-reader"></i></div>
        
        <!-- Lápices -->
        <div class="icono icono-4 icono-purple"><i class="fas fa-pencil"></i></div>
        <div class="icono icono-5 icono-cyan"><i class="fas fa-pencil-alt"></i></div>
        <div class="icono icono-6 icono-pink"><i class="fas fa-feather"></i></div>
        
        <!-- Material escolar -->
        <div class="icono icono-7 icono-lime"><i class="fas fa-ruler"></i></div>
        <div class="icono icono-8 icono-purple"><i class="fas fa-ruler-combined"></i></div>
        <div class="icono icono-9 icono-cyan"><i class="fas fa-calculator"></i></div>
        
        <!-- Ciencia y tecnología -->
        <div class="icono icono-10 icono-pink"><i class="fas fa-flask"></i></div>
        <div class="icono icono-11 icono-lime"><i class="fas fa-laptop"></i></div>
        <div class="icono icono-12 icono-purple"><i class="fas fa-graduation-cap"></i></div>
    </div>

    <!-- NAVBAR -->
    <nav class="navbar">
        <div class="nav-logo">
            <img src="assets/logo.svg" alt="SIEDUCRES" onerror="this.src='https://via.placeholder.com/120x40?text=SIEDUCRES'">
            <span>SIEDUCRES</span>
        </div>
        <div class="nav-links">
            <a href="#inicio">Inicio</a>
            <a href="#quienes-somos">Quiénes Somos</a>
            <a href="#contacto">Contacto</a>
            <a href="auth/login.php" class="nav-btn">Iniciar Sesión</a>
        </div>
    </nav>

        <!-- HERO SECTION (blanco) -->
    <section id="inicio" class="hero">
        <div class="hero-content">
            <h1>
                <span>SIEDUCRES</span><br>
                Educación sin interrupciones
            </h1>
            <p>Plataforma educativa diseñada para la recuperación de clases interrumpidas. Conectamos estudiantes, docentes y representantes.</p>
            <div class="hero-buttons">
                <a href="auth/login.php" class="btn-primary">Comenzar</a>
                <a href="#quienes-somos" class="btn-secondary">Conocer más</a>
            </div>
        </div>
    </section>

    <!-- QUIÉNES SOMOS (GRIS) - MODIFICADO -->
    <section id="quienes-somos" class="section-gray">
        <div class="section-header">
            <h2><span>¿Quiénes</span> somos?</h2>
            <p>Conoce nuestra institución.</p>
        </div>
        
        <div class="about-grid">
            <div class="about-image">
                <img src="assets/css/js/img/foto_escuela.jpeg" alt="Nuestra Escuela">
            </div>
            <div class="about-content">
                <h3>Escuela “Atanasio Girardot”</h3>
                <p>La Escuela “Atanasio Girardot”, fundada en 1943, es una institución educativa pública ubicada en la comunidad de Masparrito, municipio Rojas del estado Barinas. Desde hace décadas brinda educación a niños y niñas desde maternal y preescolar hasta sexto grado, siendo un espacio importante para la formación de los estudiantes de la comunidad.</p>
                <p>Actualmente la escuela atiende a más de cien estudiantes y cuenta con un equipo docente que trabaja de manera cercana con las familias para acompañar el proceso educativo y fortalecer el aprendizaje de los niños y niñas del sector.</p>
            </div>
        </div>
    </section>

    <!-- CARACTERÍSTICAS (BLANCO) -->
    <section class="section-white">
        <div class="section-header">
            <h2><span>¿Qué</span> ofrecemos?</h2>
            <p>Todo lo que necesitas para una educación sin límites</p>
        </div>

        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">📚</div>
                <h3>Contenidos Digitales</h3>
                <p>Accede a materiales educativos desde cualquier dispositivo, en cualquier momento.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📝</div>
                <h3>Actividades Interactivas</h3>
                <p>Realiza tareas, exámenes y recibe retroalimentación inmediata.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">💬</div>
                <h3>Foros de Discusión</h3>
                <p>Participa en debates académicos con compañeros y docentes.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📊</div>
                <h3>Seguimiento Académico</h3>
                <p>Visualiza tu progreso, calificaciones y estadísticas en tiempo real.</p>
            </div>
        </div>
    </section>

    <!-- FOOTER (se mantiene igual) -->
    <footer id="contacto" class="footer">
        <div class="footer-grid">
            <div class="footer-col">
                <h4>SIEDUCRES</h4>
                <p>Plataforma educativa para la recuperación de clases interrumpidas por condiciones climáticas.</p>
            </div>
            <div class="footer-col">
                <h4>Enlaces rápidos</h4>
                <a href="auth/login.php">Iniciar Sesión</a>
            </div>
            <div class="footer-col">
                <h4>Contacto</h4>
                <p>✉️ sieducres@gmail.com</p>
                <p>📍 Barinas, Venezuela</p>
            </div>
            <div class="footer-col">
                <h4>Horario de consulta</h4>
                <p>Lunes a Viernes</p>
                <p>8:00 am - 4:00 pm</p>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; 2026 SIEDUCRES - Todos los derechos reservados</p>
        </div>
    </footer>
</body>
</html>
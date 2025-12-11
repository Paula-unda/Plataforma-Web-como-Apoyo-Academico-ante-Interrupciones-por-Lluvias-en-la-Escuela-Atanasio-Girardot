# Plataforma de Continuidad Educativa

Este proyecto consiste en una **plataforma web** desarrollada para garantizar la **continuidad académica** ante interrupciones no previstas como lluvias u otros imprevistos.  
Permite a **docentes**, **estudiantes** y **representantes** acceder a contenidos, actividades, notificaciones y reportes desde cualquier lugar.

---

## Funcionalidades principales

- 📂 **Carga y consulta de contenidos académicos**
- ✅ **Asignación, resolución y revisión de actividades**
- 📢 **Comunicación mediante notificaciones automáticas**
- 📊 **Consulta de historial académico**
- 💬 **Participación en foros y encuestas de mejora**

---
## 🛠 Tecnologías utilizadas

- **Backend**: PHP 8.2  
- **Base de datos**: PostgreSQL 17 (con PgAdmin 4 para gestión gráfica)  
- **Infraestructura**: Docker y Docker Compose (para entorno portable y reproducible)  
- **Frontend**: Bootstrap 5 + Sistema de diseño *SieducresUI* (colores, tipografía y componentes definidos en JSON)  
- **Entorno de desarrollo**: Visual Studio Code (Windows)

---

## ▶️ Cómo ejecutar el proyecto

1. **Clona el repositorio**
   ```bash
   git clone https://github.com/Paula-unda/Plataforma-Web-como-Apoyo-Academico-ante-Interrupciones-por-Lluvias-en-la-Escuela-Atanasio-Girardot.git
   cd Plataforma-Web-como-Apoyo-Academico-ante-Interrupciones-por-Lluvias-en-la-Escuela-Atanasio-Girardot/sieducres/docker
2. **Levanta los contenedores**
   ```bash
   docker-compose up -d
 3.**Accede a los servicios**

- 🔐 **Login (módulo de autenticación)**  
  👉 <http://localhost:8080/auth/login.php>  
  Credenciales de prueba: `admin@sieducres.edu` / `admin123`

- 🐘 **PgAdmin (gestión de la base de datos)**  
  👉 <http://localhost:5050>  
  Credenciales: `admin@sieducres.edu` / `admin123`

✅ **Requisito previo**: [Docker Desktop](https://www.docker.com/products/docker-desktop/) instalado y ejecutándose.

---
## Equipo de desarrollo

- **Andrés Mora** – V-32.297.424  
- **Alex Suárez** – V-12.342.934  
- **Paula Unda** – V-32.139.35

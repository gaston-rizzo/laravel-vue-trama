# TRAMA — Portal de noticias y CMS editorial

TRAMA es un proyecto de portfolio que combina un portal periodístico público con un sistema privado de gestión editorial (CMS).

Está desarrollado con Laravel 13, Vue 3 e Inertia.js 2 e implementa un flujo editorial completo: creación de noticias, revisión, devolución con observaciones, programación, publicación, versionado, comentarios, moderación y administración del sistema.

También incorpora Server-Side Rendering (SSR) selectivo para la portada, procesamiento mediante colas, publicaciones programadas con Laravel Scheduler y moderación local de comentarios mediante Node.js y modelos ONNX.

---

## Funciones principales

### Portal público

- Portada periodística.
- Noticias principales, urgentes y destacadas.
- Ranking de noticias más leídas.
- Navegación por secciones.
- Página individual de cada noticia.
- Noticias relacionadas.
- Perfiles públicos de periodistas.
- Búsqueda por texto y categoría, incluyendo coincidencias en etiquetas.
- Registro e inicio de sesión.
- Verificación de correo electrónico.
- Recuperación de contraseña.
- Comentarios y respuestas.
- Me gusta y reportes sobre comentarios.
- Historial privado de comentarios.
- Páginas institucionales, legales y de contacto.
- Publicidades con registro de impresiones y clics.

### CMS editorial

- Dashboard interno.
- Creación y edición de noticias.
- Gestión de borradores.
- Envío a revisión.
- Devolución con observaciones.
- Corrección y reenvío.
- Programación de publicaciones.
- Publicación manual y automática.
- Archivo de noticias.
- Historial y comparación de versiones.
- Restauración de versiones anteriores como borrador.
- Gestión de categorías y etiquetas.
- Gestión de portadas.
- Búsqueda editorial FULLTEXT.
- Moderación de comentarios.
- Gestión de empleados y lectores.
- Bloqueo y reactivación de cuentas.
- Revisiones administrativas de usuarios.
- Administración de publicidades.

---

## Tecnologías principales

### Backend

- PHP 8.3 o superior.
- Laravel 13.
- Eloquent ORM.
- Laravel Fortify.
- Laravel Queue.
- Laravel Scheduler.
- MySQL 8.

### Frontend

- Vue 3.
- Inertia.js 2.
- Vite 7.
- Ziggy.
- Chart.js.
- Vue Datepicker.

### Procesamiento adicional

- Node.js.
- ONNX Runtime.
- Hugging Face Tokenizers.
- Sharp.
- Franc.

---

## Estructura del repositorio

La entrega se organiza en las siguientes carpetas:

- src/ — código fuente de la aplicación Laravel y Vue.
- database/ — base de datos SQL de prueba.
- docs/ — documentación del proyecto.

En la raíz también se incluyen videos de demostración del portal público y de las distintas áreas internas del sistema.

---

## Requisitos

Para ejecutar TRAMA en un entorno local se necesita:

- PHP 8.3 o superior.
- Composer.
- Node.js 20.19 o superior, o Node.js 22.12 o superior.
- npm.
- MySQL 8.
- Las extensiones de PHP requeridas por Laravel y por el proyecto.

Para probar los flujos que envían correos electrónicos también es necesario configurar una cuenta SMTP.

---

### Modelos de moderación

Los modelos ONNX utilizados por los procesos de moderación y análisis de imágenes no se incluyen directamente en el repositorio debido a su tamaño.

Se distribuyen por separado y deben ubicarse dentro de:

```text
src/resources/models/

---

## Instalación

Entrá en la carpeta src:

```bash
cd src
```

Instalá las dependencias de PHP:

```bash
composer install
```

Instalá las dependencias de Node.js:

```bash
npm install
```

Creá el archivo .env a partir de .env.example.

En Windows:

```bash
copy .env.example .env
```

En Linux o macOS:

```bash
cp .env.example .env
```

Generá la clave de la aplicación:

```bash
php artisan key:generate
```

Después configurá en .env la conexión a MySQL y, si querés probar los flujos de correo, los datos SMTP.

La variable TRAMA_NODE_BINARY debe indicar la ruta del ejecutable de Node.js instalado en el equipo. TRAMA utiliza Node.js tanto en la moderación automática de comentarios como en procesos relacionados con el análisis y tratamiento de imágenes.

---

## Base de datos

El repositorio incluye una base de datos de prueba completa en:

...\trama\database\trama_mysql.sql

El archivo crea y utiliza la base de datos trama.

También es posible generar la base desde cero mediante las migraciones y los seeders incluidos en el proyecto.

Desde la carpeta src:

```bash
php artisan optimize:clear
php artisan migrate:fresh --seed
```

migrate:fresh elimina todas las tablas de la base configurada, vuelve a ejecutar las migraciones y finalmente carga los datos de prueba.

Antes de utilizarlo sobre una base existente, hacé una copia de seguridad si contiene información que quieras conservar.

---

## Reloj editorial de demostración

TRAMA incluye un reloj de referencia para que las fechas utilizadas por campañas, métricas, publicaciones programadas y otros datos de demostración no dependan directamente de la fecha real del equipo.

La fecha y la hora inicial se configuran en .env mediante:

    TRAMA_REFERENCE_DATE=2026-07-19
    TRAMA_REFERENCE_TIME=15:30:00

La hora configurada funciona como punto de partida. Después, TRAMA puede continuar desde el último instante editorial guardado entre sesiones.

---

## Ejecutar el proyecto

Para iniciar el servidor de Laravel:

```bash
php artisan serve
```

En otra terminal, iniciá Vite:

```bash
npm run dev
```

La moderación de comentarios necesita un proceso de cola activo:

```bash
php artisan queue:work --queue=moderation,default --tries=3 --timeout=70
```

Para procesar las publicaciones programadas durante el desarrollo, mantené también activo el Scheduler:

```bash
php artisan schedule:work
```

El proyecto incluye además el comando:

```bash
composer run dev
```

Este comando compila primero la versión SSR del frontend y luego inicia de forma conjunta:

- El servidor de Laravel.
- El proceso de la cola para moderation y default.
- Vite para el frontend.
- El servidor SSR de Inertia.
- El Scheduler de Laravel.

---

## SSR

TRAMA utiliza Server-Side Rendering únicamente en la portada.

Para generar los archivos del frontend y el bundle SSR:

```bash
npm run build
```

Para iniciar el servidor SSR:

```bash
php artisan inertia:start-ssr
```

Para comprobar su estado:

```bash
php artisan inertia:check-ssr
```

Para detenerlo:

```bash
php artisan inertia:stop-ssr
```

La activación general se controla mediante INERTIA_SSR_ENABLED en el archivo .env.

Las demás pantallas se renderizan mediante Inertia y Vue en el navegador.

---

## Arquitectura

Laravel funciona como núcleo del backend y Vue 3 construye la interfaz del portal y del CMS.

Inertia.js conecta ambas partes, permitiendo utilizar las rutas, controladores, validaciones, sesiones y permisos de Laravel junto con los componentes Vue sin desarrollar una API REST independiente para cada pantalla.

TRAMA utiliza una página raíz de Inertia y un puente propio para resolver las distintas pantallas de la aplicación:

    app/Support/TramaBridge.php
    resources/js/Pages/Trama.vue

El flujo general es:

    Navegador
        ↓
    Rutas de Laravel
        ↓
    Controladores
        ↓
    Servicios
        ↓
    Eloquent / MySQL
        ↓
    TramaBridge
        ↓
    Inertia
        ↓
    Vue 3

---

## Roles

TRAMA separa las responsabilidades en cuatro roles:

- Administrador: gestiona usuarios, empleados, categorías, etiquetas, publicidades, bloqueos y revisiones administrativas.
- Editor: gestiona el flujo editorial, revisa contenido, solicita cambios, programa, publica, archiva y modera comentarios.
- Periodista: crea noticias, trabaja con borradores, envía contenido a revisión y corrige devoluciones.
- Lector: participa en el portal mediante comentarios, respuestas, Me gusta y reportes.

El administrador no participa del flujo de redacción, revisión o publicación de noticias y tampoco modera comentarios.

---

## Flujo editorial

Las noticias pueden pasar por los siguientes estados:

    draft
    review
    needs_changes
    scheduled
    published
    archived

El flujo principal permite que un periodista cree un borrador y lo envíe a revisión. El editor puede publicarlo, programarlo o devolverlo con observaciones. Si se solicitan cambios, el periodista puede corregirlo y enviarlo nuevamente a revisión.

Las publicaciones programadas son procesadas automáticamente por Laravel Scheduler cuando llega la fecha y hora correspondiente según el reloj editorial de TRAMA.

---

## Autenticación

TRAMA utiliza Laravel Fortify como infraestructura de autenticación.

El sistema incluye:

- inicio y cierre de sesión;
- registro de lectores;
- verificación de correo electrónico;
- recuperación y restablecimiento de contraseña;
- actualización de perfil;
- actualización de contraseña;
- limitación de intentos de inicio de sesión.

El registro público utiliza un flujo personalizado: la cuenta se crea como lector, se envía el correo de verificación y el usuario debe confirmar su dirección antes de poder iniciar sesión.

El límite de acceso es de 5 intentos por minuto por combinación de correo electrónico e IP.

---

## Comentarios y moderación

Los comentarios nuevos pasan primero por un proceso de moderación.

El análisis se ejecuta mediante una cola de Laravel y un conjunto de scripts de Node.js que evalúan señales relacionadas con idioma, enlaces, amenazas, toxicidad y spam.

Los posibles resultados son:

- approved — el comentario puede publicarse;
- pending — requiere revisión humana;
- rejected — el comentario es rechazado.

Los errores técnicos no producen una aprobación automática.

Cada comentario utiliza además una revisión de moderación para evitar que un resultado antiguo sobrescriba el análisis de una versión más reciente del mismo comentario.

---

## Versionado editorial

TRAMA conserva el estado actual de las noticias y un historial de revisiones.

Las revisiones permiten registrar, entre otros datos:

- contenido anterior;
- estado anterior y posterior;
- usuario responsable;
- acción realizada;
- campos modificados;
- restauraciones.

Restaurar una versión histórica no reemplaza directamente una publicación activa. La versión restaurada vuelve al flujo editorial como borrador para que pueda revisarse nuevamente.

---

## Búsqueda

TRAMA dispone de una búsqueda pública para noticias publicadas y una búsqueda interna para el CMS.

La búsqueda pública permite buscar por texto y categoría. Las coincidencias de texto también pueden encontrarse a través de las etiquetas asociadas a las noticias.

La búsqueda editorial utiliza el campo articles.search_text y un índice FULLTEXT que reúne información del título, subtítulo, bajada, cuerpo, categoría y etiquetas.

Si fuera necesario reconstruir este índice puede utilizarse:

```bash
php artisan articles:rebuild-search-index
```

---

## Datos de prueba

El proyecto incluye usuarios, noticias, comentarios, publicidades y actividad de prueba para poder recorrer las funciones principales del portal y del CMS.

Las cuentas de demostración utilizan direcciones reservadas para pruebas y una contraseña común. El detalle completo de los datos y cuentas de prueba se encuentra en la documentación incluida con el proyecto.

---

## Documentación

La carpeta docs contiene documentación complementaria sobre el funcionamiento interno y los principales flujos de TRAMA.

También se incluyen videos del sitio en funcionamiento para mostrar el portal público y las áreas correspondientes a administrador, editor y periodista.

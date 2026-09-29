# TRAMA — Portal de noticias y CMS editorial

TRAMA es un proyecto de portfolio que combina un portal periodístico público con un sistema privado de gestión editorial (CMS).

Está desarrollado con **Laravel 13**, **Vue 3** e **Inertia.js 2** e incluye autenticación, juunto con un flujo editorial completo: creación de noticias, revisión, devolución con observaciones, programación, publicación, versionado, comentarios, moderación y administración del sistema.

También incorpora **Server-Side Rendering (SSR)** para la portada, procesamiento mediante colas, publicaciones programadas con Laravel Scheduler y moderación local de comentarios mediante Node.js y modelos ONNX.

---

## Tecnologías principales

### Backend

- PHP.
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

## Arquitectura general

Laravel funciona como núcleo del backend y Vue 3 construye la interfaz del portal y del CMS.

Inertia.js conecta ambas partes, permitiendo utilizar las rutas, controladores, validaciones, sesiones y permisos de Laravel junto con componentes Vue sin desarrollar una API REST independiente para cada pantalla.

TRAMA utiliza una página raíz de Inertia y un puente propio para resolver las distintas pantallas:

```text
app/Support/TramaBridge.php
resources/js/Pages/Trama.vue
```

El flujo general es:

```text
Navegador
    |
    v
Rutas de Laravel
    |
    v
Controladores
    |
    v
Servicios
    |
    v
Eloquent / MySQL
    |
    v
TramaBridge
    |
    v
Inertia
    |
    v
Vue 3
```

La portada utiliza Server-Side Rendering (SSR) mediante `resources/js/ssr.js`. El resto de las pantallas se renderiza mediante Inertia y Vue en el navegador.

---

## Estructura principal del proyecto

```text
TRAMA/
├── app/
│   ├── Actions/
│   │   └── Fortify/
│   ├── Console/
│   │   └── Commands/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/
│   │   │   ├── Auth/
│   │   │   └── Public/
│   │   ├── Middleware/
│   │   ├── Requests/
│   │   │   ├── Admin/
│   │   │   └── Editorial/
│   │   ├── Resources/
│   │   │   └── Editorial/
│   │   └── Responses/
│   │       └── Fortify/
│   ├── Jobs/
│   ├── Logging/
│   ├── Mail/
│   ├── Models/
│   ├── Notifications/
│   ├── Observers/
│   ├── Providers/
│   ├── Rules/
│   ├── Services/
│   │   ├── Categories/
│   │   ├── Editorial/
│   │   └── Images/
│   └── Support/
│
├── bootstrap/
├── config/
│
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
│       ├── data/
│       └── Support/
│
├── docs/
│   ├── ampliacion-gestion-editorial.md
│   ├── convencion-comentarios.md
│   ├── datos-de-prueba.md
│   ├── ejecucion-local.md
│   ├── flujo-editorial.md
│   ├── moderacion-automatica-comentarios.md
│   └── resultado-datos-de-prueba.md
│
├── lang/
│   └── es/
│
├── public/
│   └── images/
│       ├── articles/
│       ├── banners/
│       ├── brand/
│       ├── categories/
│       └── team/
│
├── resources/
│   ├── css/
│   │   ├── admin/
│   │   └── app.css
│   ├── js/
│   │   ├── Components/
│   │   │   ├── Admin/
│   │   │   ├── Editor/
│   │   │   ├── News/
│   │   │   └── Shared/
│   │   ├── Layouts/
│   │   ├── Pages/
│   │   │   ├── Admin/
│   │   │   ├── Auth/
│   │   │   ├── Public/
│   │   │   ├── Error.vue
│   │   │   └── Trama.vue
│   │   ├── Support/
│   │   ├── app.js
│   │   ├── bootstrap.js
│   │   ├── inertia-pages.js
│   │   └── ssr.js
│   ├── models/
│   │   ├── comment-spam/
│   │   ├── comment-threat-detoxify/
│   │   ├── comment-toxicity/
│   │   └── image-safety/
│   └── views/
│       ├── advertiser-demo.blade.php
│       ├── app.blade.php
│       ├── emails/
│       └── errors/
│
├── routes/
│   ├── console.php
│   └── web.php
│
├── scripts/
│   ├── comments/
│   └── images/
│
├── storage/
│
├── tests/
│   ├── Feature/
│   └── Unit/
│
├── artisan
├── composer.json
├── package.json
└── vite.config.js
```

Las dependencias instaladas y los archivos generados en tiempo de ejecución no forman parte de esta estructura resumida.

---

## Requisitos

Para reproducir el entorno actual de TRAMA se necesita:

- PHP **8.4.1 o superior** para utilizar el `composer.lock` actual.
- Composer.
- Node.js **20.19.0 o superior dentro de la rama 20.x**, o **22.12.0 o superior**.
- npm.
- MySQL 8.
- Las extensiones de PHP requeridas por Laravel y por el proyecto.

`composer.json` declara PHP `^8.3`, pero el `composer.lock` actual incluye dependencias de Symfony que requieren PHP 8.4.1 o superior.

Para probar los flujos que envían correos electrónicos también debe configurarse una cuenta SMTP.

---

## Modelos de moderación

TRAMA utiliza modelos ONNX para la moderación automática de comentarios y para el análisis de seguridad de imágenes.

Los archivos binarios de estos modelos **no se versionan dentro del código fuente debido a su tamaño**. Las carpetas esperadas por la aplicación se conservan en:

```text
resources/models/
├── comment-spam/
├── comment-threat-detoxify/
├── comment-toxicity/
└── image-safety/
```

Los modelos se distribuirán por separado mediante una **GitHub Release** del repositorio.

Después de descargarlos, deben ubicarse dentro de las carpetas correspondientes bajo `resources/models/`.

---

## Configuración del entorno

Creá el archivo `.env` a partir de `.env.example`.

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

### Base de datos

El archivo `.env.example` utiliza esta configuración de referencia:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=trama
DB_USERNAME=user
DB_PASSWORD=password
```

Adaptá usuario, contraseña, puerto y nombre de base de datos al entorno local.

### Node.js

TRAMA utiliza Node.js desde procesos iniciados por Laravel para la moderación automática de comentarios y el procesamiento de imágenes.

La ruta del ejecutable se configura mediante:

```env
TRAMA_NODE_BINARY="C:/Program Files/nodejs/node.exe"
```

La ruta anterior es solo el ejemplo incluido para Windows. Cada entorno debe apuntar a su propio ejecutable de Node.js.

### Correo electrónico

Para probar la verificación de correo, recuperación de contraseña y demás flujos que envían emails, configurá las variables SMTP del archivo `.env`.

### SSR

La configuración de ejemplo incluye:

```env
INERTIA_SSR_ENABLED=true
INERTIA_SSR_URL=http://127.0.0.1:13714
INERTIA_SSR_ENSURE_BUNDLE_EXISTS=true
INERTIA_SSR_BUNDLE=bootstrap/ssr/ssr.js
```

---

## Instalación

Instalá las dependencias de PHP:

```bash
composer install
```

Instalá las dependencias de Node.js:

```bash
npm install
```

Creá y configurá `.env` si todavía no lo hiciste:

```bash
cp .env.example .env
php artisan key:generate
```

En Windows, reemplazá `cp` por:

```bash
copy .env.example .env
```

Después configurá la conexión MySQL, la ruta de Node.js y, si corresponde, SMTP.

---

## Base de datos y datos de prueba

La estructura de la base se define mediante las migraciones incluidas en:

```text
database/migrations/
```

Los datos de prueba se generan mediante los seeders de:

```text
database/seeders/
```

Para reconstruir completamente la base de desarrollo:

```bash
php artisan optimize:clear
php artisan migrate:fresh --seed
```

`migrate:fresh` elimina todas las tablas de la base configurada antes de ejecutar nuevamente las migraciones y los seeders.

El conjunto de prueba actual genera:

```text
Usuarios registrados:          2.500
Empleados:                         6
Noticias:                         90
Vistas de noticias:           64.212
Comentarios + respuestas:      1.395
  principales:                   918
  respuestas:                    477
  aprobados:                   1.200
  rechazados:                    184
  pendientes:                     11
Likes:                         2.410
Reportes:                        153
Revisiones administrativas:      103
Revisiones editoriales:          300
Feedback / devoluciones:          23
Mensajes de contacto:             19
Impresiones de banners:       35.298
Clicks de banners:               145
CTR global aproximado:          0,41 %
```

El detalle completo se encuentra en [`docs/resultado-datos-de-prueba.md`](docs/resultado-datos-de-prueba.md).

---

## Ejecución en desarrollo

TRAMA puede iniciarse de forma manual o mediante el script integrado de Composer.

### Inicio integrado

La forma más directa es:

```bash
composer run dev
```

Antes de iniciar los procesos, el script ejecuta:

```bash
npm run build:ssr
```

para generar el bundle utilizado por Inertia SSR.

Después inicia simultáneamente:

| Proceso | Comando |
|---|---|
| Laravel | `php artisan serve` |
| Cola de trabajos | `php artisan queue:listen --queue=moderation,default --tries=3 --timeout=70` |
| Vite | `npm run dev` |
| Inertia SSR | `php artisan inertia:start-ssr` |
| Scheduler | `php artisan schedule:work` |

El script utiliza `--kill-others`: si uno de los procesos finaliza o falla, `concurrently` detiene también los demás.

### Inicio manual

Los procesos pueden ejecutarse por separado:

```bash
php artisan serve
```

```bash
npm run dev
```

```bash
npm run build:ssr
php artisan inertia:start-ssr
```

```bash
php artisan queue:work --queue=moderation,default --tries=3 --timeout=70
```

```bash
php artisan schedule:work
```

Por defecto, la aplicación queda disponible en:

```text
http://127.0.0.1:8000
```

La guía completa de ejecución local está en [`docs/ejecucion-local.md`](docs/ejecucion-local.md).

---

## SSR

TRAMA utiliza Server-Side Rendering únicamente para la portada.

El archivo fuente del SSR es:

```text
resources/js/ssr.js
```

Para generar solamente el bundle SSR:

```bash
npm run build:ssr
```

Para generar tanto los assets del frontend como el bundle SSR:

```bash
npm run build
```

El script `build` ejecuta:

```text
vite build
vite build --ssr
```

El bundle SSR se genera normalmente en:

```text
bootstrap/ssr/ssr.js
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

---

## Reloj editorial de demostración

TRAMA utiliza un reloj editorial de referencia para que las fechas de publicaciones programadas, campañas, métricas y otros datos de prueba mantengan coherencia independientemente de la fecha real del equipo.

La fecha y hora inicial se configuran mediante:

```env
TRAMA_REFERENCE_DATE=2026-07-19
TRAMA_REFERENCE_TIME=15:30:00
```

La hora configurada funciona como punto de partida. Después, `TramaClock` continúa desde el último instante editorial guardado entre sesiones.

---

## Roles

TRAMA separa las responsabilidades en cuatro roles:

- **Administrador:** gestiona usuarios, empleados, categorías, etiquetas, publicidades, bloqueos y revisiones administrativas.
- **Editor:** gestiona el flujo editorial, revisa contenido, solicita cambios, programa, publica, archiva y modera comentarios.
- **Periodista:** crea noticias, trabaja con borradores, envía contenido a revisión y corrige devoluciones.
- **Lector:** participa en el portal mediante comentarios, respuestas, Me gusta y reportes.

El administrador no participa del flujo de redacción, revisión o publicación de noticias y tampoco modera comentarios.

---

## Flujo editorial

Las noticias pueden pasar por los siguientes estados:

```text
draft
review
needs_changes
scheduled
published
archived
```

El flujo principal permite que un periodista cree un borrador y lo envíe a revisión.

El editor puede:

- publicarlo;
- programarlo;
- devolverlo con observaciones.

Si se solicitan cambios, el periodista puede corregir el contenido y enviarlo nuevamente a revisión.

Las publicaciones programadas son procesadas mediante:

```bash
php artisan articles:publish-scheduled
```

Laravel Scheduler registra este comando para ejecutarlo cada minuto y evita superposiciones mediante `withoutOverlapping()`.

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

El registro público utiliza un flujo personalizado: la cuenta se crea como lector, se envía el correo de verificación y el usuario debe confirmar su dirección antes de iniciar sesión.

El límite de acceso es de **5 intentos por minuto** por combinación de correo electrónico e IP.

---

## Comentarios y moderación

Los comentarios nuevos pasan por moderación antes de publicarse.

El procesamiento utiliza:

```text
Laravel Queue
    |
    v
app/Jobs/ModerateComment.php
    |
    v
scripts/comments/moderate-comment.mjs
    |
    v
Node.js + modelos ONNX
```

El pipeline evalúa señales relacionadas con:

- idioma;
- enlaces;
- amenazas;
- toxicidad;
- spam.

Los estados principales son:

```text
processing
approved
pending
rejected
```

Los errores técnicos no producen una aprobación automática. Cuando la infraestructura de moderación falla de forma definitiva, el comentario queda disponible para revisión humana.

Cada comentario utiliza además una revisión de moderación para evitar que un resultado antiguo sobrescriba el análisis de una versión más reciente.

La documentación completa está en [`docs/moderacion-automatica-comentarios.md`](docs/moderacion-automatica-comentarios.md).

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

La búsqueda pública permite buscar por texto y categoría. Las coincidencias de texto también pueden encontrarse mediante las etiquetas asociadas a las noticias.

La búsqueda editorial utiliza `articles.search_text` y un índice FULLTEXT que reúne información del título, subtítulo, bajada, cuerpo, categoría y etiquetas.

Para reconstruir ese índice:

```bash
php artisan articles:rebuild-search-index
```

---

## Publicidades

TRAMA incluye administración de campañas publicitarias y registro de actividad.

El sistema permite trabajar con:

- campañas;
- ubicaciones de banners;
- imágenes procesadas;
- impresiones;
- clics;
- CTR;
- filtros y reportes dentro del panel administrativo.

Las imágenes de banners se almacenan en:

```text
public/images/banners/
```

La ubicación física puede configurarse mediante `TRAMA_BANNERS_PATH`.

---

## Procesamiento de imágenes

TRAMA utiliza scripts de Node.js para tareas de procesamiento de imágenes.

Las portadas de noticias se almacenan en:

```text
public/images/articles/
```

La ruta puede configurarse mediante:

```env
TRAMA_ARTICLE_COVERS_PATH=public/images/articles
```

El análisis de seguridad utiliza el modelo ubicado en:

```text
resources/models/image-safety/
```

---

## Documentación adicional

La carpeta [`docs/`](docs/) contiene documentación complementaria del proyecto:

- [`ampliacion-gestion-editorial.md`](docs/ampliacion-gestion-editorial.md): ampliaciones del sistema de gestión editorial.
- [`convencion-comentarios.md`](docs/convencion-comentarios.md): convenciones utilizadas para comentarios dentro del código.
- [`datos-de-prueba.md`](docs/datos-de-prueba.md): generación y organización de los datos de prueba.
- [`ejecucion-local.md`](docs/ejecucion-local.md): instalación, requisitos y ejecución del entorno local.
- [`flujo-editorial.md`](docs/flujo-editorial.md): estados y operaciones del flujo editorial.
- [`moderacion-automatica-comentarios.md`](docs/moderacion-automatica-comentarios.md): funcionamiento del pipeline automático de moderación.
- [`resultado-datos-de-prueba.md`](docs/resultado-datos-de-prueba.md): resumen cuantitativo generado por los seeders.

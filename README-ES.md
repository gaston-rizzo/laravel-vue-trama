# TRAMA

TRAMA es un proyecto de portfolio que combina un portal periodístico público con un sistema privado de gestión editorial (CMS).

Está desarrollado con **Laravel 13**, **Vue 3**, **Inertia.js 2** y **MySQL 8**. Incluye publicación y revisión de noticias, programación editorial, comentarios y moderación, gestión de usuarios, publicidades, búsquedas y versionado de contenido.

## Tecnologías principales

- PHP 8.4.1+
- Laravel 13
- Vue 3
- Inertia.js 2
- MySQL 8
- Vite 7
- Node.js
- Laravel Queue y Scheduler
- ONNX Runtime

## Estructura del repositorio

```text
trama/
├── README-ES.md
├── README-EN.md
├── database/
├── docs/
├── src/
└── videos/
```

- `database/`: contiene la base de datos SQL de prueba.
- `docs/`: contiene la documentación del proyecto, incluyendo archivos Markdown (`.md`) con información complementaria y resultados de los datos de prueba.
- `src/`: contiene el código fuente de Laravel y Vue.
- `videos/`: contiene los videos que muestran el sitio web en funcionamiento.

## Puesta en marcha

Desde `src/`:

```bash
composer install
npm install
```

Creá el archivo `.env` a partir de `.env.example` y configurá la conexión a MySQL. Si querés probar los flujos de correo, configurá también los datos SMTP.

Después generá la clave de la aplicación:

```bash
php artisan key:generate
```

La base de datos de prueba incluida se encuentra en:

```text
database/trama_mysql.sql
```

También puede generarse desde cero mediante las migraciones y los seeders del proyecto.

## Ejecución en desarrollo

El proyecto incluye un script de Composer para iniciar los procesos principales del entorno de desarrollo:

```bash
composer run dev
```

También pueden ejecutarse por separado:

```bash
php artisan serve
npm run dev
php artisan queue:work --queue=moderation,default --tries=3 --timeout=70
```

TRAMA utiliza Server-Side Rendering (SSR) selectivo para la portada. Si el bundle SSR todavía no fue generado, ejecutá:

```bash
npm run build
```

Después iniciá el servidor SSR:

```bash
php artisan inertia:start-ssr
```

El SSR y el Scheduler no forman parte de `composer run dev`, por lo que el Scheduler también debe iniciarse por separado:

```bash
php artisan schedule:work
```

## Documentación

La documentación técnica y el detalle de instalación, arquitectura, roles, flujo editorial, moderación, datos de prueba y publicación se encuentran en:

```text
docs/
```

El proyecto incluye además archivos Markdown (`.md`) dentro de `docs/` con documentación complementaria y resultados de los datos de prueba.

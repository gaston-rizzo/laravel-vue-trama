# TRAMA

TRAMA is a portfolio project that combines a public news portal with a private editorial management system (CMS).

It is developed with **Laravel 13**, **Vue 3**, **Inertia.js 2**, and **MySQL 8**. It includes user authentication and management, a news review and publishing workflow, scheduled publications, comments and moderation, advertising, search, and content versioning.

## Main technologies

- PHP 8.4.1+
- Laravel 13
- Laravel Fortify
- Vue 3
- Inertia.js 2
- MySQL 8
- Vite 7
- Node.js
- Laravel Queue and Scheduler
- ONNX Runtime

## Repository structure

```text
trama/
├── README-ES.md
├── README-EN.md
├── database/
├── docs/
├── src/
└── videos/
```

- `database/`: contains the test SQL database.
- `docs/`: contains the project documentation, including Markdown (`.md`) files with complementary information and test data results.
- `src/`: contains the Laravel and Vue source code.
- `videos/`: contains the videos that show the website in operation.

## Getting started

From `src/`:

```bash
composer install
npm install
```

Create the `.env` file from `.env.example` and configure the MySQL connection. If you want to test email flows, also configure the SMTP settings.

Then generate the application key:

```bash
php artisan key:generate
```

The included test database is located at:

```text
database/trama_mysql.sql
```

It can also be generated from scratch using the project's migrations and seeders.

## Development

The project includes a Composer script to start the main development processes:

```bash
composer run dev
```

They can also be run separately:

```bash
php artisan serve
npm run dev
php artisan queue:work --queue=moderation,default --tries=3 --timeout=70
```

TRAMA uses selective Server-Side Rendering (SSR) for the homepage. If the SSR bundle has not been generated yet, run:

```bash
npm run build
```

Then start the SSR server:

```bash
php artisan inertia:start-ssr
```

SSR and the Scheduler are not part of `composer run dev`, so the Scheduler must also be started separately:

```bash
php artisan schedule:work
```

## Documentation

The technical documentation and detailed information about installation, architecture, roles, editorial workflow, moderation, test data, and deployment are located in:

```text
docs/
```

The source code also includes Markdown (.md) files inside src/TRAMA/docs/, with complementary documentation.

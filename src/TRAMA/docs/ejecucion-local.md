# Ejecución local de TRAMA

Esta guía explica cómo levantar TRAMA en un entorno local de desarrollo con Laravel, Vue/Vite, Inertia SSR, la cola de trabajos y Laravel Scheduler.

## Requisitos previos

Para reproducir el entorno actual de TRAMA se necesita:

- PHP 8.4.1 o superior. El entorno documentado fue verificado con PHP 8.5.8.
- Composer.
- Node.js 20.19.0 o superior dentro de la rama 20.x, o Node.js 22.12.0 o superior.
- npm.
- MySQL 8.
- Las extensiones de PHP requeridas por Laravel y por el proyecto.
- Las dependencias PHP instaladas con `composer install`.
- Las dependencias JavaScript instaladas con `npm install`.
- El archivo `.env` configurado.

> `composer.json` declara PHP `^8.3`, pero el `composer.lock` actual contiene dependencias que requieren PHP 8.4.1 o superior.

Si se quieren probar los flujos que envían correos electrónicos, también debe configurarse una cuenta SMTP.

## Seleccionar la versión de PHP

Cada desarrollador debe asegurarse de que la terminal utilice una versión compatible de PHP. La ubicación exacta del ejecutable depende de cómo y dónde esté instalado PHP en cada equipo.

En Git Bash, si fuera necesario priorizar temporalmente una instalación concreta de PHP, puede agregarse su directorio al `PATH`:

```bash
export PATH="/ruta/a/php:$PATH"
```

Por ejemplo, si PHP estuviera instalado en `C:\php-8.5.8`, Git Bash podría utilizar:

```bash
export PATH="/c/php-8.5.8:$PATH"
```

Este segundo comando es solo un ejemplo de una instalación concreta y no debe copiarse literalmente si PHP está instalado en otra ubicación.

Después puede comprobarse la versión activa con:

```bash
php -v
```

El cambio del `PATH` es opcional y específico de cada entorno local.

## Configuración de Node.js

La variable `TRAMA_NODE_BINARY` del archivo `.env` debe apuntar al ejecutable de Node.js instalado en el equipo.

Ejemplo en Windows:

```env
TRAMA_NODE_BINARY="C:/Program Files/nodejs/node.exe"
```

TRAMA utiliza esta ruta cuando Laravel necesita iniciar los scripts de Node.js usados por la moderación automática de comentarios y por el procesamiento de imágenes.

Esta variable no configura Vite ni el servidor SSR.

---

## Inicio manual

Para ejecutar por separado todos los procesos de desarrollo pueden mantenerse abiertas hasta cinco terminales en la raíz del proyecto.

### 1. Laravel

```bash
php artisan serve
```

Si se quiere invocar directamente una instalación concreta de PHP en Windows, debe utilizarse la ruta correspondiente a ese equipo:

```text
<ruta-a-php>\php.exe artisan serve
```

Por ejemplo, si PHP estuviera instalado en `C:\php-8.5.8`:

```text
C:\php-8.5.8\php.exe artisan serve
```

Este ejemplo no debe copiarse literalmente si PHP está instalado en otra ubicación.

Por defecto, la aplicación queda disponible en:

```text
http://127.0.0.1:8000
```

### 2. Vite / Vue

```bash
npm run dev
```

Vite sirve los recursos JavaScript y CSS del frontend durante el desarrollo.

### 3. Inertia SSR

TRAMA utiliza Server-Side Rendering de forma selectiva para `Public/Home`.

Antes de iniciar el servidor SSR debe existir el bundle compilado:

```bash
npm run build:ssr
```

Este comando compila `resources/js/ssr.js` y genera normalmente:

```text
bootstrap/ssr/ssr.js
```

Después puede iniciarse el servidor SSR:

```bash
php artisan inertia:start-ssr
```

Si se modifica código que participa en SSR, debe ejecutarse nuevamente:

```bash
npm run build:ssr
```

y reiniciarse el servidor SSR. `npm run dev` no recompila automáticamente ese bundle.

Para comprobar el estado del servidor SSR:

```bash
php artisan inertia:check-ssr
```

Para detenerlo:

```bash
php artisan inertia:stop-ssr
```

### 4. Cola de trabajos

```bash
php artisan queue:work --queue=moderation,default --tries=3 --timeout=70
```

El worker procesa los Jobs pendientes de las colas `moderation` y `default`. Entre ellos se encuentra la moderación automática de comentarios.

### 5. Laravel Scheduler

```bash
php artisan schedule:work
```

El Scheduler debe mantenerse activo durante el desarrollo cuando se quieran ejecutar las tareas programadas.

TRAMA registra `articles:publish-scheduled` para revisar periódicamente las noticias programadas y publicarlas cuando corresponde.

---

## Orden recomendado para inicio manual

1. Iniciar MySQL.
2. Ejecutar `npm run build:ssr` si el bundle SSR todavía no existe o si cambió código que participa en SSR.
3. Ejecutar `php artisan serve`.
4. Ejecutar `npm run dev`.
5. Ejecutar `php artisan inertia:start-ssr`.
6. Ejecutar `php artisan queue:work --queue=moderation,default --tries=3 --timeout=70`.
7. Ejecutar `php artisan schedule:work`.

Después, abrir:

```text
http://127.0.0.1:8000
```

---

## Inicio integrado con Composer

TRAMA incluye en `composer.json` un script de desarrollo que permite iniciar en una sola ejecución los procesos necesarios para trabajar con la aplicación:

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

El script utiliza `--kill-others`: si uno de los procesos finaliza o falla, `concurrently` detiene también los demás. Para diagnosticar un problema concreto puede utilizarse el inicio manual por terminales explicado anteriormente.

Por lo tanto, una vez que MySQL está activo y la instalación inicial del proyecto ya fue completada, puede levantarse el entorno de desarrollo con:

```bash
composer run dev
```

La ejecución manual explicada en las secciones anteriores sigue siendo útil cuando se necesita iniciar, detener o diagnosticar cada proceso por separado.

---

## Nota sobre producción

Los comandos de esta guía corresponden al entorno local de desarrollo.

Un despliegue de producción requiere una configuración diferente para el servidor web, los procesos de cola, el Scheduler y SSR.

En producción:

- el worker de colas debe mantenerse como un proceso persistente mediante el mecanismo de supervisión disponible en el servidor;
- el servidor SSR también debe permanecer activo y supervisado si se quiere conservar el pre-renderizado de `Public/Home`;
- el Scheduler no se mantiene con `schedule:work`: el sistema operativo o la plataforma debe ejecutar periódicamente:

```bash
php artisan schedule:run
```

En TRAMA, esa ejecución debe programarse cada minuto.

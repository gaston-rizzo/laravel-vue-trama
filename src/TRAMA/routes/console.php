<?php

/* ============================================================================
 * ROUTES: console.php
 * ============================================================================
 *
 * Define comandos breves y tareas programadas de TRAMA.
 *
 * Este archivo registra comandos que se ejecutan desde la consola y tareas que
 * Laravel puede disparar automáticamente con el scheduler. En TRAMA se usa para
 * publicar noticias programadas cuando llega su fecha de salida.
 *
 * Scheduler significa planificador de tareas: es el mecanismo de Laravel que
 * permite ejecutar comandos automáticamente cada cierto tiempo, por ejemplo cada
 * minuto, sin que una persona tenga que correrlos manualmente desde la terminal.
 * ============================================================================ */

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    // Comando auxiliar incluido por Laravel.
    // No forma parte del flujo de TRAMA; solo imprime una frase breve en consola.
    $this->comment(Inspiring::quote());
})->purpose('Muestra una frase breve en la consola.');

Schedule::command('articles:publish-scheduled')
    /*
     * Indica que Laravel debe revisar este comando cada minuto.
     *
     * IMPORTANTE:
     * Esta línea solamente registra la frecuencia. El scheduler no se inicia solo.
     *
     * En desarrollo local debe mantenerse otra terminal abierta ejecutando:
     *
     *      php artisan schedule:work
     *
     * Ese proceso permanece activo, revisa las tareas programadas cada minuto
     * y ejecuta este comando cuando corresponde.
     *
     * En producción, el servidor debe ejecutar cada minuto:
     *
     *      php artisan schedule:run
     *
     * Normalmente esto se configura mediante una tarea cron del servidor.
     *
     * Ejemplo:
     * Si una noticia está programada para las 10:30, el scheduler ejecutará
     * articles:publish-scheduled alrededor de las 10:30 y el comando comprobará
     * si esa noticia ya debe pasar de "scheduled" a "published".
     */
    ->everyMinute()
    // Evita que dos ejecuciones del scheduler corran al mismo tiempo.
    // Esto protege la publicación automática si una ejecución tarda más de lo normal.
    ->withoutOverlapping();

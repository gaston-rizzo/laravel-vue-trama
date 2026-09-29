<?php

/* ============================================================================
 * TRAIT: UsesTramaClock.php
 * ============================================================================
 *
 * Hace que los timestamps automáticos de Eloquent utilicen el reloj editorial
 * de TRAMA en lugar de la fecha y hora reales del sistema.
 *
 * Cualquier modelo que use este trait obtiene created_at y updated_at desde
 * TramaClock::now(). Así noticias, comentarios, usuarios, revisiones, reportes,
 * categorías, etiquetas, publicidades y demás entidades comparten exactamente
 * la misma cronología ficticia.
 *
 * Ejemplo:
 *
 *      Hora real:                    10/08/2026 14:40:20
 *      Reloj TRAMA de la sesión:     19/07/2026 15:30:20
 *
 *      Model::create([...])
 *      created_at / updated_at  ->   19/07/2026 15:30:20
 * ============================================================================ */

namespace App\Models\Concerns;

use App\Support\TramaClock;

trait UsesTramaClock
{
    /**
     * Laravel llama a este método internamente al generar created_at y updated_at.
     */
    public function freshTimestamp()
    {
        return TramaClock::now();
    }
}

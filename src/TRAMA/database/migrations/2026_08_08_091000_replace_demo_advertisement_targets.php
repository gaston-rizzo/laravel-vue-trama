<?php

/* ============================================================================
 * MIGRATION: replace_demo_advertisement_targets.php
 * ============================================================================
 *
 * Reemplaza las URLs ficticias example.com de los banners iniciales por
 * landings internas de demostración del propio proyecto.
 *
 * El endpoint /ads/{id}/click se conserva sin cambios para que TRAMA siga
 * registrando la métrica antes de redirigir al destino configurado.
 * ============================================================================ */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Actualiza solamente las cinco marcas ficticias incluidas en el portfolio.
     */
    public function up(): void
    {
        $targets = [
            'HORIZONTE' => '/demo/anunciantes/universidad-horizonte',
            'LUMEN AIR' => '/demo/anunciantes/lumen-air',
            'NEXO' => '/demo/anunciantes/banco-nexo',
            'NOCTURNE' => '/demo/anunciantes/nocturne',
            'SENDA' => '/demo/anunciantes/cafe-senda',
        ];

        foreach ($targets as $brand => $targetUrl) {
            DB::table('advertisements')
                ->where('brand', $brand)
                ->where('target_url', 'like', 'https://example.com/%')
                ->update(['target_url' => $targetUrl]);
        }
    }

    /**
     * Devuelve las URLs originales utilizadas por el seeder anterior.
     */
    public function down(): void
    {
        $targets = [
            'HORIZONTE' => 'https://example.com/horizonte',
            'LUMEN AIR' => 'https://example.com/lumen-air',
            'NEXO' => 'https://example.com/nexo',
            'NOCTURNE' => 'https://example.com/nocturne',
            'SENDA' => 'https://example.com/senda',
        ];

        foreach ($targets as $brand => $targetUrl) {
            DB::table('advertisements')
                ->where('brand', $brand)
                ->update(['target_url' => $targetUrl]);
        }
    }
};

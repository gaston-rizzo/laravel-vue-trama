<?php

/* ============================================================================
 * CONTROLLER: AdvertisementClickController.php
 * ============================================================================
 *
 * Registra clicks reales sobre publicidades del portal público.
 *
 * Este controller no se ejecuta al cargar una página ni al entregar banners a
 * Vue. Solo se usa cuando el usuario presiona una publicidad. El navegador abre
 * directamente el destino del anuncio y, en paralelo, llama a este endpoint
 * para sumar el click al registro diario correspondiente.
 *
 * De esta forma TRAMA conserva la medición sin obligar al usuario a navegar
 * primero por una URL intermedia antes de llegar al anunciante.
 * ============================================================================ */

namespace App\Http\Controllers\Public;

use App\Support\TramaClock;

use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use App\Models\AdvertisementDailyMetric;
use Illuminate\Http\Response;

class AdvertisementClickController extends Controller
{
    /**
     * Suma un click al banner seleccionado por el usuario.
     *
     * Laravel recibe el id de la publicidad desde /ads/{advertisement}/click,
     * busca o crea la fila diaria correspondiente y aumenta clicks_count en una
     * unidad. Después devuelve una respuesta vacía porque la navegación hacia el
     * anunciante ya la realiza directamente AdvertisementBanner.vue.
     *
     * No modifica impressions_count porque mostrar un banner y hacer click son
     * métricas distintas.
     */
    public function __invoke(Advertisement $advertisement): Response
    {
        // La métrica usa el día del reloj editorial. Aunque la PC se abra otro
        // día real, el click continúa perteneciendo al 19/07 de la demostración.
        $referenceDate = TramaClock::today();

        // Busca el registro de métricas de este banner para la fecha editorial.
        // Si todavía no existe, lo crea con ambos contadores en cero.
        $dailyMetric = AdvertisementDailyMetric::query()->firstOrCreate(
            [
                'advertisement_id' => $advertisement->id,
                'date' => $referenceDate->toDateString(),
            ],
            [
                'impressions_count' => 0,
                'clicks_count' => 0,
            ],
        );

        // Suma solamente el click. La actualización se realiza directamente
        // sobre la base para no depender del valor cargado en memoria.
        $dailyMetric->increment('clicks_count');

        // El endpoint se utiliza únicamente para tracking en segundo plano.
        // No devuelve una página ni redirige porque el banner ya abrió el destino.
        return response()
            ->noContent()
            ->header(
                'Cache-Control',
                'no-store, no-cache, must-revalidate'
            );
    }
}
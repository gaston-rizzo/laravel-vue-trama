<?php

/* ============================================================================
 * CONTROLLER: AdvertisementImpressionController.php
 * ============================================================================
 *
 * Registra impresiones reales sobre publicidades del portal publico.
 *
 * Este controller no se ejecuta al seleccionar banners en Laravel. Solo se usa
 * cuando Vue confirma que el banner cargo su imagen y entro en el viewport del
 * visitante. Asi las metricas no suman piezas que fueron enviadas en props pero
 * nunca llegaron a verse.
 * ============================================================================ */

namespace App\Http\Controllers\Public;

use App\Support\TramaClock;

use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use App\Models\AdvertisementDailyMetric;
use Illuminate\Http\Response;

class AdvertisementImpressionController extends Controller
{
    /**
     * Suma una impresion al banner mostrado al visitante.
     */
    public function __invoke(Advertisement $advertisement): Response
    {
        $referenceDate = TramaClock::today();

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

        $dailyMetric->increment('impressions_count');

        return response()
            ->noContent()
            ->header(
                'Cache-Control',
                'no-store, no-cache, must-revalidate'
            );
    }
}

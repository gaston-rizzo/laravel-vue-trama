<?php

/* ============================================================================
 * MODEL: AdvertisementDailyMetric.php
 * ============================================================================
 *
 * Representa las métricas de un banner en una fecha concreta.
 *
 * Cada registro resume cuántas veces se mostró una publicidad y cuántos clicks
 * recibió durante un día. Estos datos se utilizan para generar el reporte
 * interno de banners sin guardar la IP, el navegador ni otros datos personales
 * del visitante.
 * ============================================================================ */

namespace App\Models;

use App\Models\Concerns\UsesTramaClock;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvertisementDailyMetric extends Model
{
    use UsesTramaClock;
    /**
     * Campos que pueden escribirse desde seeders y servicios de métricas.
     *
     * advertisement_id identifica el banner, date identifica el día del reporte,
     * impressions_count guarda cuántas veces se mostró y clicks_count guarda
     * cuántas veces recibió click.
     *
     * @var list<string>
     */
    protected $fillable = [
        'advertisement_id',
        'date',
        'impressions_count',
        'clicks_count',
    ];

    /**
     * Indica cómo Laravel debe convertir los campos al leerlos de la base.
     *
     * date llega como fecha y los contadores llegan como enteros para poder
     * calcular porcentajes sin conversiones manuales.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Día al que pertenece el reporte.
            'date' => 'date',
            // Cantidad de veces que se mostró el banner ese día.
            'impressions_count' => 'integer',
            // Cantidad de clicks recibidos ese día.
            'clicks_count' => 'integer',
        ];
    }

    /**
     * Define la relación con el banner al que pertenece esta métrica.
     *
     * advertisement_daily_metrics.advertisement_id apunta al id de
     * advertisements. Cada fila diaria pertenece a un único banner.
     */
    public function advertisement(): BelongsTo
    {
        return $this->belongsTo(Advertisement::class);
    }

    /**
     * Calcula el porcentaje de clicks sobre impresiones.
     *
     * Fórmula: clicks / impresiones * 100. El resultado se redondea a dos
     * decimales para mostrarlo en reportes del panel administrativo.
     */
    public function clickThroughRatePercentage(): float
    {
        // Si no hubo impresiones, no se puede dividir por cero.
        if ($this->impressions_count === 0) {
            return 0.0;
        }

        // Devuelve el porcentaje de clicks sobre la cantidad de veces mostrado.
        return round(($this->clicks_count / $this->impressions_count) * 100, 2);
    }
}

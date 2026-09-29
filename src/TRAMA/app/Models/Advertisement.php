<?php

/* ============================================================================
 * MODEL: Advertisement.php
 * ============================================================================
 *
 * Representa un banner publicitario administrado desde TRAMA.
 *
 * Guarda los datos principales de una publicidad: nombre interno, marca,
 * ubicación dentro del sitio, archivo de imagen, destino interno o externo,
 * fechas de vigencia y estado activo. También define la relación con las métricas diarias
 * usadas para el reporte del panel administrativo.
 * ============================================================================ */

namespace App\Models;

use App\Models\Concerns\UsesTramaClock;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Advertisement extends Model
{
    use UsesTramaClock;
    /**
     * Campos que puede cargar el panel administrativo o los seeders.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'brand',
        'placement',
        'image_path',
        'target_url',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    /**
     * Indica cómo Laravel debe convertir ciertos campos al leerlos de la base.
     *
     * Sin estos casts, starts_at y ends_at llegarían como texto, e is_active
     * podría llegar como 0 o 1. Con estos casts, el código recibe fechas de
     * Laravel y un booleano real para trabajar con más claridad.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Fecha y hora en que la publicidad empieza a estar disponible.
            'starts_at' => 'datetime',
            // Fecha y hora hasta la que la publicidad se considera vigente.
            'ends_at' => 'datetime',
            // Indica si el banner está habilitado para mostrarse.
            'is_active' => 'boolean',
        ];
    }

    /**
     * Define la relación con los registros diarios de métricas del banner.
     *
     * Laravel usa esta relación para saber que un banner puede tener muchas
     * filas en la tabla advertisement_daily_metrics. Cada fila corresponde a
     * una fecha específica y guarda dos valores principales: impresiones
     * (impressions_count) y clicks (clicks_count).
     *
     * Este método no calcula métricas ni ejecuta la consulta por sí solo.
     * Solamente describe la relación entre tablas. La consulta se ejecuta
     * cuando el código accede a esta relación o la carga con with().
     */
    public function metricRecordsByDay(): HasMany
    {
        return $this->hasMany(AdvertisementDailyMetric::class);
    }

    /**
     * Arma la URL pública de la imagen del banner.
     *
     * image_path guarda solo el nombre del archivo, por ejemplo
     * "banco-nexo-cuenta-digital-agosto-2026-1456x180.webp". La carpeta pública base se toma desde
     * config/trama.php, que a su vez puede configurarse desde el .env.
     *
     * El resultado es la ruta que Vue puede usar en el atributo src de un img.
     */
    public function imageUrl(): string
    {
        // Ruta pública base donde se sirven los banners, por ejemplo:
        // /images/banners
        $bannerBaseUrl = rtrim(config('trama.advertisements.banner_url'), '/');

        // Une la carpeta pública con el archivo guardado para este banner.
        return $bannerBaseUrl.'/'.$this->image_path;
    }
}

<?php

/* ============================================================================
 * SUPPORT: AdvertisementPlacement.php
 * ============================================================================
 *
 * Define las ubicaciones válidas para los banners de TRAMA.
 *
 * El panel administrativo, los seeders y el frontend usan estos valores para
 * referirse siempre a los mismos espacios publicitarios sin escribir strings
 * distintas en cada parte del proyecto.
 *
 * Las dimensiones declaradas corresponden al archivo real que se almacena y
 * valida. El sitio puede mostrar después esas piezas a la mitad de tamaño
 * mediante CSS para conservar nitidez en pantallas de alta densidad.
 * ============================================================================ */

namespace App\Support;

class AdvertisementPlacement
{
    public const HEADER = 'header';
    public const ARTICLE_SIDEBAR_TOP = 'article_sidebar_top';
    public const ARTICLE_SIDEBAR_BOTTOM = 'article_sidebar_bottom';

    /**
     * Devuelve todas las ubicaciones que acepta el sistema de banners.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::HEADER,
            self::ARTICLE_SIDEBAR_TOP,
            self::ARTICLE_SIDEBAR_BOTTOM,
        ];
    }

    /**
     * Dimensiones reales exactas que debe respetar el archivo de cada ubicación.
     *
     * @return array{width: int, height: int}|null
     */
    public static function dimensions(string $placement): ?array
    {
        return [
            self::HEADER => ['width' => 1456, 'height' => 180],
            self::ARTICLE_SIDEBAR_TOP => ['width' => 600, 'height' => 500],
            self::ARTICLE_SIDEBAR_BOTTOM => ['width' => 600, 'height' => 1200],
        ][$placement] ?? null;
    }

    /**
     * Convierte una ubicación interna en un texto breve para el panel admin.
     */
    public static function label(string $placement): string
    {
        return [
            self::HEADER => 'Header',
            self::ARTICLE_SIDEBAR_TOP => 'Sidebar superior',
            self::ARTICLE_SIDEBAR_BOTTOM => 'Sidebar inferior',
        ][$placement] ?? $placement;
    }
}

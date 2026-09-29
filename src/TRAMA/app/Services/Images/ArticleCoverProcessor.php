<?php

/* ============================================================================
 * SERVICE: ArticleCoverProcessor.php
 * ============================================================================
 *
 * Gestiona el procesamiento y almacenamiento de las portadas de noticias de TRAMA.
 *
 * La validación de dimensiones, el filtro de seguridad sexual/gore y la
 * conversión a WebP se delegan en SafeImageProcessor, el procesador compartido
 * también por avatares y banners. Esta clase conserva únicamente las reglas
 * propias de las portadas: tamaño final de 1600 × 900 píxeles, carpeta pública
 * de almacenamiento y nomenclatura basada en el slug estable de la noticia.
 * ============================================================================ */

namespace App\Services\Images;

use App\Support\TramaLog;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use RuntimeException;
use Throwable;

class ArticleCoverProcessor
{
    /** Dimensiones finales uniformes de todas las portadas. */
    private const WIDTH = 1600;
    private const HEIGHT = 900;

    /** Calidad WebP elegida para equilibrar nitidez y peso del archivo. */
    private const WEBP_QUALITY = 80;

    public function __construct(
        private readonly SafeImageProcessor $imageProcessor,
    ) {
    }

    /**
     * Procesa una portada nueva y devuelve su URL pública.
     *
     * La fuente puede ser mayor a 1600 × 900. SafeImageProcessor corrige la
     * orientación y aplica un recorte centrado tipo cover, pero nunca agranda
     * una imagen que no alcance las dimensiones mínimas.
     */
    public function process(string $sourcePath, string $articleSlug): string
    {
        $directory = $this->coverDirectory();
        File::ensureDirectoryExists($directory);

        $filename = $this->nextAvailableFilename($articleSlug);
        $destinationPath = $directory.DIRECTORY_SEPARATOR.$filename;

        $this->imageProcessor->process(
            sourcePath: $sourcePath,
            destinationPath: $destinationPath,
            width: self::WIDTH,
            height: self::HEIGHT,
            quality: self::WEBP_QUALITY,
            exactSourceDimensions: false,
            overwrite: false,
        );

        return rtrim((string) config('trama.articles.cover_url'), '/').'/'.$filename;
    }

    /**
     * Copia la portada cuando la noticia recibe su slug definitivo.
     *
     * La versión anterior se conserva porque puede estar referenciada por el
     * historial editorial. Por esa misma razón la nueva copia nunca sobrescribe
     * un archivo ya existente y utiliza el siguiente nombre disponible.
     */
    public function copyExistingCover(string $currentCoverUrl, string $articleSlug): string
    {
        $coverUrl = rtrim((string) config('trama.articles.cover_url'), '/').'/' ;

        if (! Str::startsWith($currentCoverUrl, $coverUrl)) {
            $this->failCoverProcessing(
                'La ruta de la portada está fuera del directorio configurado.',
                __FUNCTION__,
                ['cover_url' => $currentCoverUrl],
            );
        }

        $directory = $this->coverDirectory();
        $sourcePath = $directory.DIRECTORY_SEPARATOR.basename($currentCoverUrl);

        if (! is_file($sourcePath)) {
            $this->failCoverProcessing(
                'No se encontró el archivo de la portada actual.',
                __FUNCTION__,
                ['cover_filename' => basename($currentCoverUrl)],
            );
        }

        $filename = $this->nextAvailableFilename($articleSlug);
        $destinationPath = $directory.DIRECTORY_SEPARATOR.$filename;

        if (! File::copy($sourcePath, $destinationPath)) {
            $this->failCoverProcessing(
                'No se pudo copiar la portada con el nombre definitivo.',
                __FUNCTION__,
                [
                    'source_filename' => basename($sourcePath),
                    'destination_filename' => basename($destinationPath),
                ],
            );
        }

        return $coverUrl.$filename;
    }

    /** Devuelve la carpeta física configurada para las portadas. */
    private function coverDirectory(): string
    {
        return (string) config('trama.articles.cover_path');
    }

    /**
     * Genera un nombre WebP disponible a partir del slug.
     *
     * Las portadas sí mantienen sufijos incrementales porque pueden existir varias
     * revisiones históricas del mismo artículo. Esta regla es independiente de la
     * nomenclatura determinista pedida para banners publicitarios.
     */
    private function nextAvailableFilename(string $articleSlug): string
    {
        $baseName = Str::slug($articleSlug) ?: 'noticia';
        $filename = $baseName.'.webp';
        $suffix = 2;

        while (File::exists($this->coverDirectory().DIRECTORY_SEPARATOR.$filename)) {
            $filename = $baseName.'-'.$suffix.'.webp';
            $suffix++;
        }

        return $filename;
    }

    /** Registra fallos propios de la administración física de portadas. */
    private function failCoverProcessing(
        string $logMessage,
        string $method,
        array $context = [],
        ?Throwable $previous = null,
    ): never {
        TramaLog::error(
            $logMessage,
            [
                'service' => self::class,
                'method' => $method,
                ...$context,
            ],
        );

        throw new RuntimeException(
            'No se pudo procesar la imagen de portada. Intentá nuevamente.',
            previous: $previous,
        );
    }
}

<?php

/* ============================================================================
 * SERVICE: CategoryCoverProcessor.php
 * ============================================================================
 *
 * Procesa y administra las imágenes públicas de las categorías de TRAMA.
 *
 * Reutiliza SafeImageProcessor para aplicar exactamente la misma validación de
 * seguridad sexual/gore que las portadas de noticias, además de corregir la
 * orientación, recortar en formato cover y convertir siempre a WebP 1600 × 900.
 *
 * El archivo final se guarda directamente dentro de public/images/categories
 * y su nombre se deriva del nombre visible de la categoría.
 * ============================================================================ */

namespace App\Services\Images;

use App\Support\TramaLog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CategoryCoverProcessor
{
    private const WIDTH = 1600;
    private const HEIGHT = 900;
    private const WEBP_QUALITY = 80;
    private const PUBLIC_URL = '/images/categories';

    public function __construct(
        private readonly SafeImageProcessor $imageProcessor,
    ) {
    }

    /** Procesa una imagen nueva y devuelve su URL pública definitiva. */
    public function process(string $sourcePath, string $categoryName): string
    {
        $destinationPath = $this->physicalPathForName($categoryName);
        File::ensureDirectoryExists($this->categoryDirectory());

        $this->imageProcessor->process(
            sourcePath: $sourcePath,
            destinationPath: $destinationPath,
            width: self::WIDTH,
            height: self::HEIGHT,
            quality: self::WEBP_QUALITY,
            exactSourceDimensions: false,
            overwrite: true,
        );

        return $this->publicUrlForName($categoryName);
    }

    /**
     * Renombra una imagen existente cuando cambia el nombre visible de la categoría.
     *
     * También permite migrar archivos históricos guardados dentro de la antigua
     * subcarpeta uploads hacia el directorio definitivo de categorías.
     */
    public function renameExisting(string $currentCoverUrl, string $categoryName): string
    {
        $targetUrl = $this->publicUrlForName($categoryName);
        $normalizedCurrentUrl = $this->normalizeManagedUrl($currentCoverUrl);

        if ($normalizedCurrentUrl === $targetUrl) {
            return $targetUrl;
        }

        $sourcePath = $this->physicalPathFromManagedUrl($normalizedCurrentUrl);
        $destinationPath = $this->physicalPathForName($categoryName);

        if (! File::exists($sourcePath)) {
            $this->fail(
                'No se encontró la imagen actual de la categoría para renombrarla.',
                __FUNCTION__,
                ['current_cover_url' => $normalizedCurrentUrl],
            );
        }

        File::ensureDirectoryExists($this->categoryDirectory());

        if (File::exists($destinationPath) && ! File::delete($destinationPath)) {
            $this->fail(
                'No se pudo liberar el nombre definitivo de la imagen de categoría.',
                __FUNCTION__,
                ['destination_path' => $destinationPath],
            );
        }

        if (! File::move($sourcePath, $destinationPath)) {
            $this->fail(
                'No se pudo renombrar la imagen de la categoría.',
                __FUNCTION__,
                [
                    'source_path' => $sourcePath,
                    'destination_path' => $destinationPath,
                ],
            );
        }

        return $targetUrl;
    }

    /** Elimina una imagen administrada cuando deja de tener una categoría asociada. */
    public function deleteManaged(?string $coverUrl): void
    {
        if (! is_string($coverUrl) || trim($coverUrl) === '') {
            return;
        }

        try {
            $path = $this->physicalPathFromManagedUrl($this->normalizeManagedUrl($coverUrl));

            if (File::exists($path)) {
                File::delete($path);
            }
        } catch (RuntimeException $exception) {
            TramaLog::error(
                'No se pudo eliminar una imagen administrada de categoría.',
                [
                    'service' => self::class,
                    'method' => __FUNCTION__,
                    'cover_url' => $coverUrl,
                    'exception_message' => $exception->getMessage(),
                ],
            );
        }
    }

    /** Devuelve la URL determinista basada en el nombre actual de la categoría. */
    public function publicUrlForName(string $categoryName): string
    {
        return self::PUBLIC_URL.'/'.$this->filenameForName($categoryName);
    }

    /** Devuelve la carpeta física definitiva de las imágenes de categorías. */
    private function categoryDirectory(): string
    {
        return public_path('images/categories');
    }

    /** Genera un nombre seguro: "América Latina" pasa a america-latina.webp. */
    private function filenameForName(string $categoryName): string
    {
        $baseName = Str::slug($categoryName) ?: 'categoria';

        return $baseName.'.webp';
    }

    /** Devuelve la ruta física del nombre final pedido para la categoría. */
    private function physicalPathForName(string $categoryName): string
    {
        return $this->categoryDirectory()
            .DIRECTORY_SEPARATOR
            .$this->filenameForName($categoryName);
    }

    /** Convierte una URL administrada de categorías en su ruta física segura. */
    private function physicalPathFromManagedUrl(string $coverUrl): string
    {
        $prefix = self::PUBLIC_URL.'/';

        if (! Str::startsWith($coverUrl, $prefix)) {
            $this->fail(
                'La imagen de categoría está fuera del directorio administrado.',
                __FUNCTION__,
                ['cover_url' => $coverUrl],
            );
        }

        $relative = Str::after($coverUrl, $prefix);

        if ($relative === '' || str_contains($relative, '..')) {
            $this->fail(
                'La ruta relativa de la imagen de categoría no es válida.',
                __FUNCTION__,
                ['cover_url' => $coverUrl],
            );
        }

        return $this->categoryDirectory()
            .DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /** Normaliza separadores y obliga a trabajar con una URL pública relativa. */
    private function normalizeManagedUrl(string $coverUrl): string
    {
        return '/'.ltrim(str_replace('\\', '/', trim($coverUrl)), '/');
    }

    /** Registra el detalle técnico y expone un mensaje seguro al formulario. */
    private function fail(
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
            'No se pudo administrar la imagen de la categoría. Intentá nuevamente.',
            previous: $previous,
        );
    }
}

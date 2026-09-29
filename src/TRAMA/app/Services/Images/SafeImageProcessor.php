<?php

/* ============================================================================
 * SERVICE: SafeImageProcessor.php
 * ============================================================================
 *
 * Procesador compartido para imágenes públicas administradas por TRAMA.
 *
 * Contiene las reglas que deben reutilizar portadas, avatares y banners:
 * validación de dimensiones, clasificación de seguridad, corrección de
 * orientación, recorte cuando corresponde y conversión optimizada a WebP.
 *
 * De esta forma los controladores no duplican la ejecución de Sharp ni la
 * política de contenido sexual/gore y todas las superficies públicas aplican
 * exactamente el mismo criterio de seguridad.
 * ============================================================================ */

namespace App\Services\Images;

use App\Support\TramaLog;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use JsonException;
use RuntimeException;
use Throwable;

class SafeImageProcessor
{
    /** Script genérico que genera el archivo WebP definitivo. */
    private const PROCESS_SCRIPT_PATH = 'scripts/images/process-image.mjs';

    /** Evita que un proceso de Sharp bloqueado deje esperando la petición. */
    private const PROCESS_TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly ArticleImageSafetyClassifier $imageSafetyClassifier,
        private readonly ArticleImageSafetyPolicy $imageSafetyPolicy,
    ) {
    }

    /**
     * Valida y genera una imagen WebP segura.
     *
     * Cuando $exactSourceDimensions es true, el archivo de entrada debe tener
     * exactamente el ancho y alto pedidos. Es el caso de las piezas publicitarias.
     * Cuando es false, se aceptan archivos mayores y Sharp realiza un recorte
     * centrado tipo cover sin agrandar imágenes pequeñas.
     */
    public function process(
        string $sourcePath,
        string $destinationPath,
        int $width,
        int $height,
        int $quality = 80,
        bool $exactSourceDimensions = false,
        bool $overwrite = false,
    ): void {
        $absoluteSourcePath = realpath($sourcePath);

        if ($absoluteSourcePath === false || ! is_file($absoluteSourcePath)) {
            $this->fail(
                'No se encontró la imagen original que debía procesarse.',
                __FUNCTION__,
                ['received_path' => $sourcePath],
                'No se pudo leer la imagen seleccionada. Intentá nuevamente.',
            );
        }

        $this->ensureSourceDimensions(
            $absoluteSourcePath,
            $width,
            $height,
            $exactSourceDimensions,
        );

        /*
         * El clasificador registra internamente cualquier detalle técnico. Acá se
         * transforma un fallo operativo en un mensaje genérico para que avatares,
         * banners y portadas no expongan rutas, procesos ni nombres de modelos.
         */
        try {
            $this->ensureImageIsSafe($absoluteSourcePath);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'La imagen seleccionada no cumple las reglas de seguridad de TRAMA.') {
                throw $exception;
            }

            throw new RuntimeException(
                'No se pudo verificar la seguridad de la imagen. Intentá nuevamente.',
                previous: $exception,
            );
        }

        $scriptPath = base_path(self::PROCESS_SCRIPT_PATH);

        if (! is_file($scriptPath)) {
            $this->fail(
                'No se encontró el script genérico de procesamiento de imágenes.',
                __FUNCTION__,
                ['expected_path' => $scriptPath],
            );
        }

        $directory = dirname($destinationPath);
        File::ensureDirectoryExists($directory);

        if (File::exists($destinationPath) && ! $overwrite) {
            $this->fail(
                'Se intentó generar una imagen sobre un archivo ya existente.',
                __FUNCTION__,
                ['destination_path' => $destinationPath],
            );
        }

        /*
         * process-image.mjs rechaza destinos existentes. Para reemplazos estables
         * (employee-{id}.webp y banners deterministas) se procesa primero a un
         * archivo temporal y recién después se sustituye el archivo anterior.
         */
        $workingDestination = $overwrite
            ? $destinationPath.'.tmp-'.Str::lower(Str::random(12)).'.webp'
            : $destinationPath;

        try {
            $result = Process::path(base_path())
                ->timeout(self::PROCESS_TIMEOUT_SECONDS)
                ->env([
                    // Node necesita estas variables cuando PHP lo ejecuta en Windows.
                    'SystemRoot' => getenv('SystemRoot'),
                    'PATH' => getenv('PATH'),
                ])
                ->run([
                    (string) config('trama.node_binary'),
                    $scriptPath,
                    $absoluteSourcePath,
                    $workingDestination,
                    (string) $width,
                    (string) $height,
                    (string) $quality,
                ]);
        } catch (Throwable $exception) {
            $this->cleanupTemporaryFile($workingDestination, $destinationPath);

            $this->fail(
                'No se pudo ejecutar el procesador de imágenes.',
                __FUNCTION__,
                [
                    'script_path' => $scriptPath,
                    'node_binary' => (string) config('trama.node_binary'),
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ],
                previous: $exception,
            );
        }

        if ($result->failed()) {
            $this->cleanupTemporaryFile($workingDestination, $destinationPath);

            $this->fail(
                'El procesador de imágenes terminó con un error.',
                __FUNCTION__,
                [
                    'exit_code' => $result->exitCode(),
                    'error_output' => trim($result->errorOutput()),
                ],
            );
        }

        try {
            $output = json_decode(
                trim($result->output()),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            $this->cleanupTemporaryFile($workingDestination, $destinationPath);

            $this->fail(
                'El procesador de imágenes devolvió una respuesta inválida.',
                __FUNCTION__,
                [
                    'process_output' => trim($result->output()),
                    'error_output' => trim($result->errorOutput()),
                ],
                previous: $exception,
            );
        }

        if (
            ! is_array($output)
            || ($output['format'] ?? null) !== 'webp'
            || (int) ($output['width'] ?? 0) !== $width
            || (int) ($output['height'] ?? 0) !== $height
            || ! File::exists($workingDestination)
        ) {
            $this->cleanupTemporaryFile($workingDestination, $destinationPath);

            $this->fail(
                'El archivo generado no coincide con las dimensiones o formato esperados.',
                __FUNCTION__,
                ['processor_output' => $output],
            );
        }

        if ($overwrite) {
            /*
             * El archivo temporal ya fue validado. Cuando existe una versión anterior
             * se la mueve primero a un respaldo temporal. Así, incluso si el rename
             * final falla en Windows, se puede restaurar el archivo que ya estaba en
             * producción en vez de dejar la ruta pública vacía.
             */
            $backupPath = null;

            if (File::exists($destinationPath)) {
                $backupPath = $destinationPath.'.backup-'.Str::lower(Str::random(12));

                if (! File::move($destinationPath, $backupPath)) {
                    $this->cleanupTemporaryFile($workingDestination, $destinationPath);

                    $this->fail(
                        'No se pudo preparar el reemplazo seguro de la imagen administrada.',
                        __FUNCTION__,
                        ['destination_path' => $destinationPath],
                    );
                }
            }

            if (! File::move($workingDestination, $destinationPath)) {
                $this->cleanupTemporaryFile($workingDestination, $destinationPath);

                // Restauración best-effort del archivo anterior si existía.
                if ($backupPath !== null && File::exists($backupPath)) {
                    File::move($backupPath, $destinationPath);
                }

                $this->fail(
                    'No se pudo sustituir la imagen administrada por su nueva versión.',
                    __FUNCTION__,
                    ['destination_path' => $destinationPath],
                );
            }

            if ($backupPath !== null && File::exists($backupPath)) {
                File::delete($backupPath);
            }
        }
    }

    /**
     * Comprueba las dimensiones visuales, teniendo en cuenta la orientación EXIF.
     */
    private function ensureSourceDimensions(
        string $sourcePath,
        int $requiredWidth,
        int $requiredHeight,
        bool $exact,
    ): void {
        $dimensions = @getimagesize($sourcePath);

        if ($dimensions === false) {
            $this->fail(
                'PHP no pudo obtener las dimensiones de la imagen seleccionada.',
                __FUNCTION__,
                ['source_path' => $sourcePath],
                'No se pudo leer la imagen seleccionada. Verificá el archivo e intentá nuevamente.',
            );
        }

        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];

        if (
            ($dimensions['mime'] ?? null) === 'image/jpeg'
            && function_exists('exif_read_data')
        ) {
            $exif = @exif_read_data($sourcePath);
            $orientation = (int) ($exif['Orientation'] ?? 1);

            if (in_array($orientation, [5, 6, 7, 8], true)) {
                [$width, $height] = [$height, $width];
            }
        }

        if ($exact && ($width !== $requiredWidth || $height !== $requiredHeight)) {
            throw new RuntimeException(
                "La imagen debe medir exactamente {$requiredWidth} × {$requiredHeight} px."
            );
        }

        if (! $exact && ($width < $requiredWidth || $height < $requiredHeight)) {
            throw new RuntimeException(
                "La imagen debe medir al menos {$requiredWidth} × {$requiredHeight} px."
            );
        }
    }

    /**
     * Ejecuta el clasificador común y bloquea contenido sexual o gore según la
     * misma política utilizada por las portadas de noticias.
     */
    private function ensureImageIsSafe(string $sourcePath): void
    {
        $classification = $this->imageSafetyClassifier->classify($sourcePath);
        $decision = $this->imageSafetyPolicy->evaluate($classification);

        if (! $decision['blocked']) {
            return;
        }

        TramaLog::error(
            'TRAMA rechazó una imagen pública por su clasificación de seguridad.',
            [
                'service' => self::class,
                'blocked_by' => $decision['blocked_by'],
                'probabilities' => $classification['probabilities'],
            ],
        );

        throw new RuntimeException(
            'La imagen seleccionada no cumple las reglas de seguridad de TRAMA.'
        );
    }

    /** Elimina únicamente el temporal utilizado para un reemplazo fallido. */
    private function cleanupTemporaryFile(string $workingPath, string $finalPath): void
    {
        if ($workingPath !== $finalPath && File::exists($workingPath)) {
            File::delete($workingPath);
        }
    }

    /**
     * Registra el detalle técnico y expone solamente un mensaje seguro al formulario.
     */
    private function fail(
        string $logMessage,
        string $method,
        array $context = [],
        string $userMessage = 'No se pudo procesar la imagen. Intentá nuevamente.',
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

        throw new RuntimeException($userMessage, previous: $previous);
    }
}
